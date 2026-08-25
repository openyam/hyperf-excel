<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel;

use Hyperf\Collection\Collection;
use OpenYam\HyperfExcel\Result\ImportResult;
use OpenYam\HyperfExcel\Result\OperationStatus;
use OpenYam\HyperfExcel\Result\QueuedOperation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

interface ExcelInterface
{
    /** @param array<string, string|string[]> $headers */
    public function download(object $export, string $fileName, ?string $writerType = null, array $headers = []): ResponseInterface;

    /** @param array<string, mixed> $options */
    public function store(object $export, string $filePath, ?string $disk = null, ?string $writerType = null, array $options = []): bool;

    /** @param array<string, mixed> $options */
    public function queue(object $export, string $filePath, ?string $disk = null, ?string $writerType = null, array $options = []): QueuedOperation;

    public function raw(object $export, string $writerType): string;

    public function import(object $import, string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): ImportResult;

    /** @param array<string, mixed> $options */
    public function queueImport(object $import, string $filePath, ?string $disk = null, ?string $readerType = null, array $options = []): QueuedOperation;

    public function queueStatus(string $operationId): ?OperationStatus;

    /** @return list<array<int, array<array-key, mixed>>> */
    public function toArray(object $import, string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): array;

    /** @return Collection<int, Collection<int, array<array-key, mixed>>> */
    public function toCollection(object $import, string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): Collection;
}
