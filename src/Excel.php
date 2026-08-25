<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel;

use Hyperf\Collection\Collection;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponse;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Files\TemporaryFileStream;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;
use OpenYam\HyperfExcel\Queue\QueueDispatcher;
use OpenYam\HyperfExcel\Result\ImportResult;
use OpenYam\HyperfExcel\Result\OperationStatus;
use OpenYam\HyperfExcel\Result\QueuedOperation;
use OpenYam\HyperfExcel\Support\FormatDetector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Excel implements ExcelInterface
{
    public const XLSX = 'Xlsx';
    public const CSV = 'Csv';
    public const TSV = 'Tsv';
    public const ODS = 'Ods';
    public const XLS = 'Xls';
    public const SLK = 'Slk';
    public const GNUMERIC = 'Gnumeric';
    public const HTML = 'Html';
    public const PDF = 'Pdf';
    public const MPDF = 'Mpdf';
    public const DOMPDF = 'Dompdf';
    public const TCPDF = 'Tcpdf';

    public function __construct(
        private readonly Writer $writer,
        private readonly Reader $reader,
        private readonly FileResolver $files,
        private readonly HyperfResponse $response,
        private readonly QueueDispatcher $queue,
        private readonly ?OperationStatusStoreInterface $statuses = null,
    ) {}

    /** @param array<string, string|string[]> $headers */
    public function download(object $export, string $fileName, ?string $writerType = null, array $headers = []): ResponseInterface
    {
        $type = FormatDetector::detect($fileName, $writerType);
        $file = $this->writer->export($export, $type);
        try {
            $response = $this->response->download($file->path(), $fileName)
                ->withHeader('Content-Description', 'File Transfer')
                ->withHeader('Content-Type', $this->mimeType($type))
                ->withHeader('Content-Disposition', "attachment; filename*=UTF-8''" . rawurlencode($fileName))
                ->withHeader('Content-Transfer-Encoding', 'binary');
            foreach ($headers as $name => $value) {
                $response = $response->withHeader((string) $name, $value);
            }
            return $response->withBody(new TemporaryFileStream($file));
        } catch (\Throwable $e) {
            $file->delete();
            throw $e;
        }
    }

    /** @param array<string, mixed> $options */
    public function store(object $export, string $filePath, ?string $disk = null, ?string $writerType = null, array $options = []): bool
    {
        $file = $this->writer->export($export, FormatDetector::detect($filePath, $writerType));
        try {
            return $this->files->store($file, $filePath, $disk, $options);
        } finally {
            $file->delete();
        }
    }

    /** @param array<string, mixed> $options */
    public function queue(object $export, string $filePath, ?string $disk = null, ?string $writerType = null, array $options = []): QueuedOperation
    {
        return $this->queue->export($export, $filePath, $disk, $writerType, $options);
    }

    public function raw(object $export, string $writerType): string
    {
        $file = $this->writer->export($export, $writerType);
        try {
            return $file->contents();
        } finally {
            $file->delete();
        }
    }

    public function import(object $import, string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): ImportResult
    {
        return $this->reader->read($import, $file, $disk, $readerType);
    }

    /** @param array<string, mixed> $options */
    public function queueImport(object $import, string $filePath, ?string $disk = null, ?string $readerType = null, array $options = []): QueuedOperation
    {
        return $this->queue->import($import, $filePath, $disk, $readerType, $options);
    }

    public function queueStatus(string $operationId): ?OperationStatus
    {
        return $this->statuses?->find($operationId);
    }

    /** @return list<array<int, array<array-key, mixed>>> */
    public function toArray(object $import, string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): array
    {
        return $this->reader->toArray($import, $file, $disk, $readerType);
    }

    /** @return Collection<int, Collection<int, array<array-key, mixed>>> */
    public function toCollection(object $import, string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): Collection
    {
        return new Collection(array_map(static fn(array $sheet) => new Collection($sheet), $this->toArray($import, $file, $disk, $readerType)));
    }

    private function mimeType(string $type): string
    {
        return match ($type) {
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::XLS => 'application/vnd.ms-excel',
            self::CSV, self::TSV => 'text/csv; charset=UTF-8',
            self::ODS => 'application/vnd.oasis.opendocument.spreadsheet',
            self::PDF, self::DOMPDF, self::MPDF, self::TCPDF => 'application/pdf',
            self::HTML => 'text/html; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }
}
