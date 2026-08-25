<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Import;

use OpenYam\HyperfExcel\Concerns\SkipsEmptyRows;
use OpenYam\HyperfExcel\Concerns\ToModel;
use OpenYam\HyperfExcel\Concerns\WithChunkReading;
use OpenYam\HyperfExcel\Concerns\WithColumnLimit;
use OpenYam\HyperfExcel\Concerns\WithHeadingRow;
use OpenYam\HyperfExcel\Concerns\WithLimit;
use OpenYam\HyperfExcel\Concerns\WithReadFilter;
use OpenYam\HyperfExcel\Concerns\WithStartRow;
use OpenYam\HyperfExcel\Events\BeforeImport;
use OpenYam\HyperfExcel\Exceptions\ExcelException;
use OpenYam\HyperfExcel\Result\ImportResult;
use OpenYam\HyperfExcel\Result\ReaderContext;
use OpenYam\HyperfExcel\Support\DelimitedSettings;
use OpenYam\HyperfExcel\Support\EventBus;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/** @internal */
final class DelimitedImporter
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly SheetProcessor $sheets,
        private readonly ChunkConsumer $chunks,
        private readonly ProgressReporter $progress,
        private readonly EventBus $events,
        private readonly array $config = [],
    ) {}

    public function read(string $path, string $type, WithChunkReading $import): ImportResult
    {
        $settings = DelimitedSettings::resolve($this->config, $import, $type);
        $sheetName = 'Worksheet';
        $info = [['worksheetName' => $sheetName, 'totalRows' => null, 'totalColumns' => null]];
        $this->events->dispatch($import, new BeforeImport($import, new ReaderContext($type, $info, true)));
        $headingRow = $import instanceof WithHeadingRow ? max(1, $import->headingRow()) : null;
        $start = $import instanceof WithStartRow ? max(1, $import->startRow()) : 1;
        if ($headingRow !== null) {
            $start = max($start, $headingRow + 1);
        }
        $remaining = $import instanceof WithLimit ? max(0, $import->limit()) : PHP_INT_MAX;
        $chunkSize = max(1, $import->chunkSize());
        $columnLimit = $import instanceof WithColumnLimit ? Coordinate::columnIndexFromString($import->endColumn()) : null;
        $filter = $import instanceof WithReadFilter ? $import->readFilter() : null;
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new ExcelException('Unable to open delimited spreadsheet.');
        }
        $processed = $skipped = 0;
        $failures = [];
        $batch = [];
        $headings = null;
        $rowNumber = 0;
        $reported = false;
        try {
            while (($values = fgetcsv($stream, null, $settings->delimiter, $settings->enclosure, $settings->escapeCharacter)) !== false) {
                ++$rowNumber;
                $values = $this->decodeRow($values, $settings->inputEncoding, $rowNumber === 1);
                if ($columnLimit !== null) {
                    $values = array_slice($values, 0, $columnLimit);
                }
                if ($filter !== null) {
                    foreach ($values as $column => &$value) {
                        if (! $filter->readCell(Coordinate::stringFromColumnIndex($column + 1), $rowNumber, $sheetName)) {
                            $value = null;
                        }
                    }
                    unset($value);
                }
                if ($headingRow === $rowNumber) {
                    $headings = $this->sheets->formatHeadings($values, $import);
                    continue;
                }
                if ($rowNumber < $start) {
                    continue;
                }
                if ($remaining <= 0) {
                    break;
                }
                if ($headings !== null) {
                    $values = $this->sheets->combineHeadings($headings, $values);
                }
                if ($import instanceof SkipsEmptyRows && $this->sheets->isEmptyRow($values, $import)) {
                    continue;
                }
                $batch[$rowNumber] = $values;
                --$remaining;
                if (count($batch) >= $chunkSize) {
                    $this->consume($import, $batch, $processed, $skipped, $failures);
                    $batch = [];
                    $this->progress->report($import, 'import', $sheetName, $processed, $skipped, null);
                    $reported = true;
                }
            }
            if (! feof($stream)) {
                throw new ExcelException('Unable to read delimited spreadsheet row.');
            }
            if ($batch !== []) {
                $this->consume($import, $batch, $processed, $skipped, $failures);
                $this->progress->report($import, 'import', $sheetName, $processed, $skipped, null);
                $reported = true;
            }
            if (! $reported) {
                $this->progress->report($import, 'import', $sheetName, $processed, $skipped, null);
            }
        } finally {
            fclose($stream);
        }

        return new ImportResult($processed, $skipped, $failures, [$sheetName => $processed + $skipped]);
    }

    /**
     * @param array<int, array<array-key, mixed>> $rows
     * @param list<\OpenYam\HyperfExcel\Result\Failure> $failures
     */
    private function consume(WithChunkReading $import, array $rows, int &$processed, int &$skipped, array &$failures): void
    {
        $result = $this->chunks->consume($import, $rows, $import instanceof ToModel);
        $processed += $result['processed'];
        $skipped += $result['skipped'];
        array_push($failures, ...$result['failures']);
    }

    /**
     * @param list<string|null> $values
     * @return list<string|null>
     */
    private function decodeRow(array $values, string $encoding, bool $firstRow): array
    {
        foreach ($values as $index => $value) {
            if ($value !== null && strcasecmp($encoding, 'UTF-8') !== 0) {
                try {
                    $decoded = mb_convert_encoding($value, 'UTF-8', $encoding);
                } catch (\ValueError $e) {
                    throw new ExcelException(sprintf('Unable to convert delimited input from encoding [%s].', $encoding), previous: $e);
                }
                if ($decoded === false) {
                    throw new ExcelException(sprintf('Unable to convert delimited input from encoding [%s].', $encoding));
                }
                $values[$index] = $decoded;
            }
        }
        if ($firstRow && isset($values[0])) {
            $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0]) ?? $values[0];
        }
        return $values;
    }
}
