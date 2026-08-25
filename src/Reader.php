<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel;

use Hyperf\DbConnection\Db;
use OpenYam\HyperfExcel\Concerns\SkipsEmptyRows;
use OpenYam\HyperfExcel\Concerns\SkipsOnFailure;
use OpenYam\HyperfExcel\Concerns\SkipsUnknownSheets;
use OpenYam\HyperfExcel\Concerns\ToArray;
use OpenYam\HyperfExcel\Concerns\ToCollection;
use OpenYam\HyperfExcel\Concerns\ToModel;
use OpenYam\HyperfExcel\Concerns\WithCalculatedFormulas;
use OpenYam\HyperfExcel\Concerns\WithChunkReading;
use OpenYam\HyperfExcel\Concerns\WithColumnLimit;
use OpenYam\HyperfExcel\Concerns\WithHeadingRow;
use OpenYam\HyperfExcel\Concerns\WithLimit;
use OpenYam\HyperfExcel\Concerns\WithMultipleSheets;
use OpenYam\HyperfExcel\Concerns\WithProgress;
use OpenYam\HyperfExcel\Concerns\WithReadFilter;
use OpenYam\HyperfExcel\Concerns\WithSheetSelection;
use OpenYam\HyperfExcel\Concerns\WithStartRow;
use OpenYam\HyperfExcel\Events\AfterImport;
use OpenYam\HyperfExcel\Events\BeforeImport;
use OpenYam\HyperfExcel\Events\ImportFailed;
use OpenYam\HyperfExcel\Exceptions\InvalidConcernException;
use OpenYam\HyperfExcel\Exceptions\ValidationException;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Import\ChunkConsumer;
use OpenYam\HyperfExcel\Import\DelimitedImporter;
use OpenYam\HyperfExcel\Import\ProgressReporter;
use OpenYam\HyperfExcel\Import\SheetProcessor;
use OpenYam\HyperfExcel\Import\SpreadsheetReaderFactory;
use OpenYam\HyperfExcel\Import\WindowedImporter;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;
use OpenYam\HyperfExcel\Result\ImportResult;
use OpenYam\HyperfExcel\Result\OperationStatus;
use OpenYam\HyperfExcel\Result\Progress;
use OpenYam\HyperfExcel\Result\ReaderContext;
use OpenYam\HyperfExcel\Security\ArchiveGuard;
use OpenYam\HyperfExcel\Support\ChunkReadFilter;
use OpenYam\HyperfExcel\Support\DelimitedSettings;
use OpenYam\HyperfExcel\Support\EventBus;
use OpenYam\HyperfExcel\Support\FormatDetector;
use OpenYam\HyperfExcel\Support\OperationContext;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Reader
{
    private readonly SpreadsheetReaderFactory $readerFactory;

    private readonly SheetProcessor $sheetProcessor;

    private readonly ArchiveGuard $archiveGuard;

    private readonly ChunkConsumer $chunks;

    private readonly DelimitedImporter $delimitedImporter;

    private readonly WindowedImporter $windowedImporter;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly FileResolver $files,
        private readonly EventBus $events,
        ContainerInterface $container,
        private readonly array $config = [],
        private readonly ?OperationStatusStoreInterface $statuses = null,
    ) {
        $this->readerFactory = new SpreadsheetReaderFactory($config);
        $this->sheetProcessor = new SheetProcessor($container);
        $this->archiveGuard = new ArchiveGuard($config['security'] ?? []);
        $this->chunks = new ChunkConsumer($this->sheetProcessor, $config);
        $progress = new ProgressReporter($statuses);
        $this->delimitedImporter = new DelimitedImporter($this->sheetProcessor, $this->chunks, $progress, $events, $config);
        $this->windowedImporter = new WindowedImporter($this->readerFactory, $this->sheetProcessor, $this->chunks, $progress, $events);
    }

    public function read(object $import, string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): ImportResult
    {
        $resolved = $this->files->localize($file, $disk);
        try {
            $type = FormatDetector::detect($resolved->originalName, $readerType);
            $this->assertAllowedType($type);
            $this->archiveGuard->assertSafe($resolved->path, $type);
            if ($import instanceof WithChunkReading && $this->usesStreamingDelimitedReading($import, $type)) {
                $result = $this->delimitedImporter->read($resolved->path, $type, $import);
                $this->events->dispatch($import, new AfterImport($import, $result));
                return $result;
            }
            if ($this->usesWindowedReading($import)) {
                $result = $this->windowedImporter->read($resolved->path, $type, $import);
                $this->events->dispatch($import, new AfterImport($import, $result));
                return $result;
            }
            $metadataReader = $this->readerFactory->create($type, $import);
            $this->events->dispatch($import, new BeforeImport($import, new ReaderContext($type, $metadataReader->listWorksheetInfo($resolved->path), false)));
            $spreadsheet = $this->load($resolved->path, $type, $import);
            $runner = fn(): ImportResult => $this->process($spreadsheet, $import);
            $transaction = (string) ($this->config['transaction']['handler'] ?? 'db');
            $result = $transaction === 'db' && $import instanceof ToModel && ! $import instanceof WithChunkReading
                ? Db::connection($this->config['transaction']['connection'] ?? null)->transaction($runner)
                : $runner();
            $this->events->dispatch($import, new AfterImport($import, $result));
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->events->dispatch($import, new ImportFailed($import, $e));
            } catch (\Throwable) {
            }
            throw $e;
        } finally {
            if (isset($spreadsheet)) {
                $spreadsheet->disconnectWorksheets();
            }
            $resolved->delete();
        }
    }

    /** @return list<array<int, array<array-key, mixed>>> */
    public function toArray(object $import, string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): array
    {
        $resolved = $this->files->localize($file, $disk);
        try {
            $type = FormatDetector::detect($resolved->originalName, $readerType);
            $this->assertAllowedType($type);
            $this->archiveGuard->assertSafe($resolved->path, $type);
            if ($import instanceof WithMultipleSheets || $import instanceof WithSheetSelection) {
                return $this->selectedSheetsToArray($resolved->path, $type, $import);
            }
            $spreadsheet = $this->load($resolved->path, $type, $import);
            $result = [];
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $result[] = $this->sheetProcessor->extractRows($sheet, $import);
            }
            return $result;
        } finally {
            if (isset($spreadsheet)) {
                $spreadsheet->disconnectWorksheets();
            }
            $resolved->delete();
        }
    }

    private function load(string $path, string $type, object $import): Spreadsheet
    {
        return $this->readerFactory->create($type, $import)->load($path);
    }

    private function usesStreamingDelimitedReading(object $import, string $type): bool
    {
        return in_array($type, [Excel::CSV, Excel::TSV], true)
            && ! $import instanceof WithMultipleSheets
            && ! $import instanceof WithSheetSelection;
    }

    /** @return list<array<int, array<array-key, mixed>>> */
    private function selectedSheetsToArray(string $path, string $type, object $import): array
    {
        $info = $this->readerFactory->create($type, $import)->listWorksheetInfo($path);
        $result = [];
        foreach ($this->sheetImports($import) as $selector => $candidate) {
            $sheetImport = $this->sheetObject($candidate);
            $sheetInfo = $this->resolveSheetInfo($info, $selector);
            if ($sheetInfo === null) {
                if ($import instanceof SkipsUnknownSheets) {
                    $import->onUnknownSheet((string) $selector);
                    continue;
                }
                throw new InvalidConcernException(sprintf('Sheet [%s] does not exist.', $selector));
            }
            $sheetName = (string) $sheetInfo['worksheetName'];
            $reader = $this->readerFactory->create($type, $sheetImport);
            $reader->setLoadSheetsOnly([$sheetName]);
            $spreadsheet = $reader->load($path);
            try {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                if ($sheet === null) {
                    throw new InvalidConcernException(sprintf('Unable to load sheet [%s].', $sheetName));
                }
                $result[] = $this->sheetProcessor->extractRows($sheet, $sheetImport);
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }
        return $result;
    }

    private function usesWindowedReading(object $import): bool
    {
        if ($import instanceof WithChunkReading || $import instanceof WithSheetSelection) {
            return true;
        }
        if ($import instanceof WithMultipleSheets) {
            foreach ($import->sheets() as $sheetImport) {
                if ($sheetImport instanceof WithChunkReading) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<int|string, object> */
    private function sheetImports(object $import): array
    {
        if ($import instanceof WithMultipleSheets) {
            return $import->sheets();
        }
        if ($import instanceof WithSheetSelection) {
            $result = [];
            foreach ($import->sheets() as $selector) {
                $result[$selector] = $import;
            }
            return $result;
        }
        return [0 => $import];
    }

    /**
     * @param array<int, array<string, mixed>> $info
     * @return array<string, mixed>|null
     */
    private function resolveSheetInfo(array $info, string|int $selector): ?array
    {
        if (is_int($selector)) {
            return $info[$selector] ?? null;
        }
        foreach ($info as $sheetInfo) {
            if (($sheetInfo['worksheetName'] ?? null) === $selector) {
                return $sheetInfo;
            }
        }
        return null;
    }

    private function process(Spreadsheet $spreadsheet, object $import): ImportResult
    {
        $processed = $skipped = 0;
        $failures = [];
        $sheetResults = [];
        $sheetImports = $import instanceof WithMultipleSheets ? $import->sheets() : [0 => $import];
        foreach ($sheetImports as $selector => $candidate) {
            $sheetImport = $this->sheetObject($candidate);
            $sheet = is_int($selector) ? ($spreadsheet->getSheetCount() > $selector ? $spreadsheet->getSheet($selector) : null) : ($spreadsheet->sheetNameExists((string) $selector) ? $spreadsheet->getSheetByName((string) $selector) : null);
            if ($sheet === null) {
                if ($import instanceof SkipsUnknownSheets) {
                    $import->onUnknownSheet((string) $selector);
                    continue;
                }
                throw new InvalidConcernException(sprintf('Sheet [%s] does not exist.', $selector));
            }
            if ($sheetImport instanceof ToArray || $sheetImport instanceof ToCollection) {
                $rows = $this->sheetProcessor->extractRows($sheet, $sheetImport);
                $this->mergeChunkResult($this->chunks->consume($sheetImport, $rows, false), $processed, $skipped, $failures);
                $sheetCount = count($rows);
            } else {
                $sheetCount = 0;
                foreach ($this->sheetProcessor->rowBatches($sheet, $sheetImport, batchSize: $this->readBatchSize()) as $batch) {
                    $this->mergeChunkResult($this->chunks->consume($sheetImport, $batch, false), $processed, $skipped, $failures);
                    $sheetCount += count($batch);
                }
            }
            $sheetResults[$sheet->getTitle()] = $sheetCount;
        }
        return new ImportResult($processed, $skipped, $failures, $sheetResults);
    }

    /**
     * @param array{processed: int, skipped: int, failures: list<\OpenYam\HyperfExcel\Result\Failure>} $result
     * @param list<\OpenYam\HyperfExcel\Result\Failure> $failures
     */
    private function mergeChunkResult(array $result, int &$processed, int &$skipped, array &$failures): void
    {
        $processed += $result['processed'];
        $skipped += $result['skipped'];
        array_push($failures, ...$result['failures']);
    }

    private function assertAllowedType(string $type): void
    {
        $allowed = $this->config['security']['allowed_reader_types'] ?? [];
        if ($allowed !== [] && ! in_array($type, $allowed, true)) {
            throw new \OpenYam\HyperfExcel\Exceptions\ExcelException(sprintf('Spreadsheet reader type [%s] is not allowed.', $type));
        }
    }

    private function readBatchSize(): int
    {
        return max(1, (int) ($this->config['read_batch_size'] ?? $this->config['chunk_size'] ?? 1000));
    }

    private function sheetObject(mixed $candidate): object
    {
        if (! is_object($candidate)) {
            throw new InvalidConcernException('Every sheet import must be an object.');
        }
        return $candidate;
    }

}
