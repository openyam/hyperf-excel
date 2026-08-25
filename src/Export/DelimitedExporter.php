<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Export;

use OpenYam\HyperfExcel\Concerns\WithChunkSize;
use OpenYam\HyperfExcel\Concerns\WithHeadings;
use OpenYam\HyperfExcel\Concerns\WithMapping;
use OpenYam\HyperfExcel\Events\AfterSheet;
use OpenYam\HyperfExcel\Events\BeforeExport;
use OpenYam\HyperfExcel\Events\BeforeSheet;
use OpenYam\HyperfExcel\Events\BeforeWriting;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Exceptions\ExcelException;
use OpenYam\HyperfExcel\Exceptions\InvalidConcernException;
use OpenYam\HyperfExcel\Files\TemporaryFile;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Support\DelimitedSettings;
use OpenYam\HyperfExcel\Support\EventBus;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** @internal */
final class DelimitedExporter
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly TemporaryFileManager $temporaryFiles,
        private readonly EventBus $events,
        private readonly RowSource $rows,
        private readonly array $config = [],
    ) {}

    public function export(object $export, string $writerType, callable $protectRow, callable $reportProgress): TemporaryFile
    {
        $file = $this->temporaryFiles->make('csv');
        $stream = fopen($file->path(), 'wb');
        if (! is_resource($stream)) {
            $file->delete();
            throw new ExcelException('Unable to create delimited export stream.');
        }
        $settings = DelimitedSettings::resolve($this->config, $export, $writerType);
        $delimiter = $settings->delimiter;
        $enclosure = $settings->enclosure;
        $escape = $settings->escapeCharacter;
        $eventBook = null;
        $successful = false;
        try {
            $eventBook = new Spreadsheet();
            $eventSheet = $eventBook->getActiveSheet();
            $this->events->dispatch($export, new BeforeExport($export, $eventBook));
            $this->events->dispatch($export, new BeforeSheet($export, $eventSheet));
            $this->events->dispatch($export, new BeforeWriting($export, $eventBook));
            [$delimiter, $enclosure, $lineEnding] = $this->writePreamble($stream, $settings, $writerType);
            if ($export instanceof WithHeadings) {
                foreach ($this->normalizeHeadings($export->headings()) as $heading) {
                    $this->writeCsvRow($stream, $protectRow($heading, $export), $delimiter, $enclosure, $escape, $lineEnding, $settings->excelCompatibility);
                }
            }
            $processed = 0;
            $lastReported = null;
            $chunkSize = $export instanceof WithChunkSize ? max(1, $export->chunkSize()) : max(1, (int) ($this->config['chunk_size'] ?? 1000));
            $forceEnclosure = $settings->excelCompatibility;
            $this->rows->each($export, function (mixed $row) use ($stream, $delimiter, $enclosure, $escape, $lineEnding, $forceEnclosure, $export, $protectRow, $reportProgress, $chunkSize, &$processed, &$lastReported): void {
                $mapped = $export instanceof WithMapping ? $export->map($row) : $this->rows->normalize($row);
                $this->writeCsvRow($stream, $protectRow($mapped, $export), $delimiter, $enclosure, $escape, $lineEnding, $forceEnclosure);
                ++$processed;
                if ($processed % $chunkSize === 0) {
                    $reportProgress($export, null, $processed);
                    $lastReported = $processed;
                }
            });
            if ($lastReported !== $processed) {
                $reportProgress($export, null, $processed);
            }
            $this->events->dispatch($export, new AfterSheet($export, $eventSheet));
            $successful = true;
            return $file;
        } finally {
            fclose($stream);
            $eventBook?->disconnectWorksheets();
            if (! $successful) {
                $file->delete();
            }
        }
    }

    public function exportSpreadsheet(Spreadsheet $spreadsheet, object $export, string $writerType): TemporaryFile
    {
        $file = $this->temporaryFiles->make('csv');
        $stream = fopen($file->path(), 'wb');
        if (! is_resource($stream)) {
            $file->delete();
            throw new ExcelException('Unable to create delimited export stream.');
        }
        $successful = false;
        try {
            $settings = DelimitedSettings::resolve($this->config, $export, $writerType);
            [$delimiter, $enclosure, $lineEnding] = $this->writePreamble($stream, $settings, $writerType);
            $sheet = $spreadsheet->getActiveSheet();
            $highestColumn = $sheet->getHighestDataColumn();
            $highestRow = $sheet->getHighestDataRow();
            foreach ($sheet->rangeToArrayYieldRows('A1:' . $highestColumn . $highestRow, '', true) as $row) {
                $this->writeCsvRow($stream, $row, $delimiter, $enclosure, $settings->escapeCharacter, $lineEnding, $settings->excelCompatibility);
            }
            $successful = true;
            return $file;
        } finally {
            fclose($stream);
            if (! $successful) {
                $file->delete();
            }
        }
    }

    /**
     * @param array<array-key, mixed> $headings
     * @return list<array<array-key, mixed>>
     */
    private function normalizeHeadings(array $headings): array
    {
        if (! isset($headings[0]) || ! is_array($headings[0])) {
            return [$headings];
        }
        $rows = [];
        foreach ($headings as $heading) {
            if (! is_array($heading)) {
                throw new InvalidConcernException('Every heading row must be an array.');
            }
            $rows[] = $heading;
        }
        return $rows;
    }

    /** @param resource $stream */
    private function writeBytes($stream, string $contents): void
    {
        $length = strlen($contents);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($stream, substr($contents, $written));
            if ($count === false || $count === 0) {
                throw new ExcelException('Unable to write the complete delimited export.');
            }
            $written += $count;
        }
    }

    /**
     * @param resource $stream
     * @param array<array-key, mixed> $row
     */
    private function writeCsvRow($stream, array $row, string $delimiter, string $enclosure, string $escape, string $lineEnding, bool $forceEnclosure): void
    {
        if ($forceEnclosure) {
            $values = array_map(static function (mixed $value) use ($enclosure): string {
                $value = $value === null || $value === false ? '' : (string) $value;
                return $enclosure . str_replace($enclosure, $enclosure . $enclosure, $value) . $enclosure;
            }, $row);
            $this->writeBytes($stream, implode($delimiter, $values) . $lineEnding);
            return;
        }
        if (fputcsv($stream, $row, $delimiter, $enclosure, $escape, $lineEnding) === false) {
            throw new ExcelException('Unable to write delimited export row.');
        }
    }

    /**
     * @param resource $stream
     * @return array{string, string, string}
     */
    private function writePreamble($stream, DelimitedSettings $settings, string $writerType): array
    {
        $delimiter = $settings->outputDelimiter();
        $enclosure = $settings->outputEnclosure();
        $lineEnding = $settings->outputLineEnding();
        if ($settings->shouldUseBom()) {
            $this->writeBytes($stream, "\xEF\xBB\xBF");
        }
        if ($settings->shouldIncludeSeparatorLine($writerType)) {
            $this->writeBytes($stream, 'sep=' . $delimiter . $lineEnding);
        }
        return [$delimiter, $enclosure, $lineEnding];
    }
}
