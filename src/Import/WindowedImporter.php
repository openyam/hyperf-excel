<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Import;

use OpenYam\HyperfExcel\Concerns\SkipsUnknownSheets;
use OpenYam\HyperfExcel\Concerns\ToModel;
use OpenYam\HyperfExcel\Concerns\WithCalculatedFormulas;
use OpenYam\HyperfExcel\Concerns\WithChunkReading;
use OpenYam\HyperfExcel\Concerns\WithHeadingRow;
use OpenYam\HyperfExcel\Concerns\WithLimit;
use OpenYam\HyperfExcel\Concerns\WithMultipleSheets;
use OpenYam\HyperfExcel\Concerns\WithReadFilter;
use OpenYam\HyperfExcel\Concerns\WithSheetSelection;
use OpenYam\HyperfExcel\Concerns\WithStartRow;
use OpenYam\HyperfExcel\Events\BeforeImport;
use OpenYam\HyperfExcel\Exceptions\InvalidConcernException;
use OpenYam\HyperfExcel\Result\ImportResult;
use OpenYam\HyperfExcel\Result\ReaderContext;
use OpenYam\HyperfExcel\Support\ChunkReadFilter;
use OpenYam\HyperfExcel\Support\EventBus;

/** @internal */
final class WindowedImporter
{
    public function __construct(
        private readonly SpreadsheetReaderFactory $readers,
        private readonly SheetProcessor $sheets,
        private readonly ChunkConsumer $chunks,
        private readonly ProgressReporter $progress,
        private readonly EventBus $events,
    ) {}

    public function read(string $path, string $type, object $import): ImportResult
    {
        $info = $this->readers->create($type, $import)->listWorksheetInfo($path);
        $sheetImports = $this->sheetImports($import);
        $processed = $skipped = 0;
        $failures = [];
        $sheetResults = [];
        $total = $this->plannedTotal($info, $sheetImports);
        $this->events->dispatch($import, new BeforeImport($import, new ReaderContext($type, $info, true)));
        foreach ($sheetImports as $selector => $candidate) {
            $sheetImport = $this->sheetObject($candidate);
            if ($sheetImport instanceof WithCalculatedFormulas && $sheetImport instanceof WithChunkReading) {
                throw new InvalidConcernException('Calculated formulas cannot be evaluated during chunk reading because referenced cells may not be loaded.');
            }
            $sheetInfo = $this->resolveSheetInfo($info, $selector);
            if ($sheetInfo === null) {
                if ($import instanceof SkipsUnknownSheets) {
                    $import->onUnknownSheet((string) $selector);
                    continue;
                }
                throw new InvalidConcernException(sprintf('Sheet [%s] does not exist.', $selector));
            }
            $sheetName = (string) $sheetInfo['worksheetName'];
            $highest = (int) ($sheetInfo['totalRows'] ?? 0);
            $headingRow = $sheetImport instanceof WithHeadingRow ? max(1, $sheetImport->headingRow()) : null;
            $start = $sheetImport instanceof WithStartRow ? max(1, $sheetImport->startRow()) : 1;
            if ($headingRow !== null) {
                $start = max($start, $headingRow + 1);
            }
            $remaining = $sheetImport instanceof WithLimit ? max(0, $sheetImport->limit()) : PHP_INT_MAX;
            $chunkSize = $sheetImport instanceof WithChunkReading ? max(1, $sheetImport->chunkSize()) : max(1, $highest - $start + 1);
            $sheetCount = 0;
            for ($chunkStart = $start; $chunkStart <= $highest && $remaining > 0; $chunkStart += $chunkSize) {
                $chunkEnd = min($highest, $chunkStart + $chunkSize - 1);
                $reader = $this->readers->create($type, $sheetImport);
                $reader->setLoadSheetsOnly([$sheetName]);
                $inner = $sheetImport instanceof WithReadFilter ? $sheetImport->readFilter() : null;
                $reader->setReadFilter(new ChunkReadFilter($sheetName, $chunkStart, $chunkEnd, $headingRow, $inner));
                $spreadsheet = $reader->load($path);
                try {
                    $sheet = $spreadsheet->getSheetByName($sheetName);
                    if ($sheet === null) {
                        throw new InvalidConcernException(sprintf('Unable to load sheet [%s].', $sheetName));
                    }
                    $rows = $this->sheets->extractRows($sheet, $sheetImport, $chunkStart, $chunkEnd, $remaining);
                    $result = $this->chunks->consume($sheetImport, $rows, $sheetImport instanceof ToModel);
                    $processed += $result['processed'];
                    $skipped += $result['skipped'];
                    array_push($failures, ...$result['failures']);
                    $count = count($rows);
                    $remaining -= $count;
                    $sheetCount += $count;
                    $this->progress->report($sheetImport, 'import', $sheetName, $processed, $skipped, $total);
                } finally {
                    $spreadsheet->disconnectWorksheets();
                }
            }
            $sheetResults[$sheetName] = $sheetCount;
        }

        return new ImportResult($processed, $skipped, $failures, $sheetResults);
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

    /**
     * @param array<int, array<string, mixed>> $info
     * @param array<int|string, object> $sheetImports
     */
    private function plannedTotal(array $info, array $sheetImports): int
    {
        $total = 0;
        foreach ($sheetImports as $selector => $candidate) {
            $sheetImport = $this->sheetObject($candidate);
            $sheetInfo = $this->resolveSheetInfo($info, $selector);
            if ($sheetInfo === null) {
                continue;
            }
            $highest = (int) ($sheetInfo['totalRows'] ?? 0);
            $start = $sheetImport instanceof WithStartRow ? max(1, $sheetImport->startRow()) : 1;
            if ($sheetImport instanceof WithHeadingRow) {
                $start = max($start, max(1, $sheetImport->headingRow()) + 1);
            }
            $rows = max(0, $highest - $start + 1);
            if ($sheetImport instanceof WithLimit) {
                $rows = min($rows, max(0, $sheetImport->limit()));
            }
            $total += $rows;
        }
        return $total;
    }

    private function sheetObject(mixed $candidate): object
    {
        if (! is_object($candidate)) {
            throw new InvalidConcernException('Every sheet import must be an object.');
        }
        return $candidate;
    }
}
