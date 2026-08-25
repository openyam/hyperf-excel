<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel;

use Hyperf\View\RenderInterface;
use OpenYam\HyperfExcel\Concerns\FromView;
use OpenYam\HyperfExcel\Concerns\ShouldAutoSize;
use OpenYam\HyperfExcel\Concerns\WithChunkSize;
use OpenYam\HyperfExcel\Concerns\WithColumnFormatting;
use OpenYam\HyperfExcel\Concerns\WithColumnWidths;
use OpenYam\HyperfExcel\Concerns\WithCustomValueBinder;
use OpenYam\HyperfExcel\Concerns\WithDrawings;
use OpenYam\HyperfExcel\Concerns\WithEvents;
use OpenYam\HyperfExcel\Concerns\WithFormulaProtection;
use OpenYam\HyperfExcel\Concerns\WithHeadings;
use OpenYam\HyperfExcel\Concerns\WithMapping;
use OpenYam\HyperfExcel\Concerns\WithMultipleSheets;
use OpenYam\HyperfExcel\Concerns\WithProgress;
use OpenYam\HyperfExcel\Concerns\WithProperties;
use OpenYam\HyperfExcel\Concerns\WithStartCell;
use OpenYam\HyperfExcel\Concerns\WithStrictNullComparison;
use OpenYam\HyperfExcel\Concerns\WithStyles;
use OpenYam\HyperfExcel\Concerns\WithTitle;
use OpenYam\HyperfExcel\Events\AfterSheet;
use OpenYam\HyperfExcel\Events\BeforeExport;
use OpenYam\HyperfExcel\Events\BeforeSheet;
use OpenYam\HyperfExcel\Events\BeforeWriting;
use OpenYam\HyperfExcel\Exceptions\ExcelException;
use OpenYam\HyperfExcel\Exceptions\InvalidConcernException;
use OpenYam\HyperfExcel\Export\DelimitedExporter;
use OpenYam\HyperfExcel\Export\RowSource;
use OpenYam\HyperfExcel\Files\TemporaryFile;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;
use OpenYam\HyperfExcel\Result\OperationStatus;
use OpenYam\HyperfExcel\Result\Progress;
use OpenYam\HyperfExcel\Support\DelimitedSettings;
use OpenYam\HyperfExcel\Support\EventBus;
use OpenYam\HyperfExcel\Support\OperationContext;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Html;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Container\ContainerInterface;

final class Writer
{
    private readonly RowSource $rows;

    private readonly DelimitedExporter $delimitedExporter;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly TemporaryFileManager $temporaryFiles,
        private readonly EventBus $events,
        private readonly ContainerInterface $container,
        private readonly array $config = [],
        private readonly ?OperationStatusStoreInterface $statuses = null,
    ) {
        $this->rows = new RowSource();
        $this->delimitedExporter = new DelimitedExporter($temporaryFiles, $events, $this->rows, $config);
    }

    public function export(object $export, string $writerType): TemporaryFile
    {
        return $this->performExport($export, $writerType);
    }

    private function performExport(object $export, string $writerType): TemporaryFile
    {
        if (in_array($writerType, [Excel::CSV, Excel::TSV], true)
            && ! $export instanceof FromView
            && ! $export instanceof WithMultipleSheets
            && ! $export instanceof WithEvents
            && ! $export instanceof WithCustomValueBinder) {
            return $this->delimitedExporter->export(
                $export,
                $writerType,
                fn(array $row, object $owner): array => $this->protectRow($row, $owner),
                fn(object $owner, ?string $sheet, int $processed) => $this->reportProgress($owner, $sheet, $processed),
            );
        }
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        try {
            if ($export instanceof WithCustomValueBinder) {
                $spreadsheet->setValueBinder($export);
            }
            $this->applyProperties($spreadsheet, $export);
            $this->events->dispatch($export, new BeforeExport($export, $spreadsheet));
            $exports = $export instanceof WithMultipleSheets ? $export->sheets() : [$export];
            $processed = 0;
            foreach ($exports as $key => $candidate) {
                $sheetExport = $this->sheetObject($candidate);
                $sheet = new Worksheet($spreadsheet);
                if (is_string($key)) {
                    $this->setSheetTitle($sheet, $key);
                }
                $spreadsheet->addSheet($sheet);
                $this->events->dispatch($sheetExport, new BeforeSheet($sheetExport, $sheet));
                $this->populateSheet($spreadsheet, $sheet, $sheetExport, $processed);
            }
            if ($spreadsheet->getSheetCount() === 0) {
                throw new InvalidConcernException('An export must contain at least one sheet.');
            }
            $spreadsheet->setActiveSheetIndex(0);
            $this->events->dispatch($export, new BeforeWriting($export, $spreadsheet));
            if (in_array($writerType, [Excel::CSV, Excel::TSV], true)) {
                return $this->delimitedExporter->exportSpreadsheet($spreadsheet, $export, $writerType);
            }
            $actualType = $this->resolvePdfType($writerType);
            $file = $this->temporaryFiles->make(strtolower($writerType === Excel::TSV ? 'csv' : ($this->extension($writerType))));
            try {
                $writer = IOFactory::createWriter($spreadsheet, $actualType);
                if ($writer instanceof \PhpOffice\PhpSpreadsheet\Writer\Csv) {
                    $csv = DelimitedSettings::resolve($this->config, $export, $writerType);
                    $writer->setDelimiter($csv->delimiter);
                    $writer->setEnclosure($csv->enclosure);
                    $writer->setUseBOM($csv->useBom);
                    $writer->setExcelCompatibility($csv->excelCompatibility);
                }
                $writer->save($file->path());
                return $file;
            } catch (\Throwable $e) {
                $file->delete();
                throw $e;
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function populateSheet(Spreadsheet $book, Worksheet $sheet, object $export, int &$processed): void
    {
        if ($export instanceof WithTitle) {
            $this->setSheetTitle($sheet, $export->title());
        }
        if ($export instanceof FromView) {
            $this->populateView($book, $sheet, $export);
        } else {
            $startCell = $export instanceof WithStartCell ? $export->startCell() : 'A1';
            [$startColumn, $startRow] = Coordinate::coordinateFromString($startCell);
            $rowNumber = (int) $startRow;
            if ($export instanceof WithHeadings) {
                $headings = array_map(fn(array $row): array => $this->protectRow($row, $export), $this->normalizeHeadings($export->headings()));
                $sheet->fromArray($headings, null, $startColumn . $rowNumber, $export instanceof WithStrictNullComparison);
                $rowNumber += count($headings);
            }
            /** @var list<array<array-key, mixed>> $batch */
            $batch = [];
            $chunkSize = $export instanceof WithChunkSize ? max(1, $export->chunkSize()) : max(1, (int) ($this->config['chunk_size'] ?? 1000));
            $flush = function () use (&$batch, &$rowNumber, &$processed, $sheet, $startColumn, $export): void {
                if ($batch === []) {
                    return;
                }
                $sheet->fromArray($batch, null, $startColumn . $rowNumber, $export instanceof WithStrictNullComparison);
                $rowNumber += count($batch);
                $processed += count($batch);
                $batch = [];
                $this->reportProgress($export, $sheet->getTitle(), $processed);
            };
            $this->rows->each($export, function (mixed $row) use (&$batch, $chunkSize, $flush, $export): void {
                $mapped = $export instanceof WithMapping ? $export->map($row) : $this->rows->normalize($row);
                $batch[] = $this->protectRow($mapped, $export);
                if (count($batch) >= $chunkSize) {
                    $flush();
                }
            });
            $flush();
        }
        if ($export instanceof WithColumnFormatting) {
            foreach ($export->columnFormats() as $column => $format) {
                $sheet->getStyle((string) $column . '1:' . (string) $column . $sheet->getHighestRow())->getNumberFormat()->setFormatCode((string) $format);
            }
        }
        if ($export instanceof WithColumnWidths) {
            foreach ($export->columnWidths() as $column => $width) {
                $sheet->getColumnDimension((string) $column)->setWidth((float) $width);
            }
        }
        if ($export instanceof ShouldAutoSize) {
            for ($column = 1; $column <= Coordinate::columnIndexFromString($sheet->getHighestColumn()); ++$column) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
            }
        }
        if ($export instanceof WithStyles) {
            foreach ($export->styles($sheet) as $range => $style) {
                $sheet->getStyle((string) $range)->applyFromArray($style);
            }
        }
        if ($export instanceof WithDrawings) {
            foreach ((array) $export->drawings() as $drawing) {
                $drawing->setWorksheet($sheet);
            }
        }
        $this->events->dispatch($export, new AfterSheet($export, $sheet));
    }

    private function populateView(Spreadsheet $book, Worksheet $target, FromView $export): void
    {
        $renderer = $this->container->get(RenderInterface::class);
        $html = $renderer->getContents($export->view(), $export->viewData());
        $temporary = (new Html())->loadFromString((string) $html);
        try {
            $source = $temporary->getActiveSheet();
            $target->fromArray($source->toArray(null, true, true, false));
            foreach ($source->getMergeCells() as $range) {
                $target->mergeCells($range);
            }
            foreach ($source->getRowDimensions() as $row => $dimension) {
                $target->getRowDimension($row)->setRowHeight($dimension->getRowHeight());
            }
            foreach ($source->getColumnDimensions() as $column => $dimension) {
                $target->getColumnDimension($column)->setWidth($dimension->getWidth());
            }
            foreach ($source->getCellCollection()->getCoordinates() as $coordinate) {
                $target->duplicateStyle($source->getStyle($coordinate), $coordinate);
            }
        } finally {
            $temporary->disconnectWorksheets();
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

    private function setSheetTitle(Worksheet $sheet, string $title): void
    {
        try {
            $sheet->setTitle($title);
        } catch (\Throwable $e) {
            throw new InvalidConcernException(sprintf('Invalid sheet title [%s]: %s', $title, $e->getMessage()), previous: $e);
        }
    }

    private function sheetObject(mixed $candidate): object
    {
        if (! is_object($candidate)) {
            throw new InvalidConcernException('Every sheet export must be an object.');
        }
        return $candidate;
    }

    private function applyProperties(Spreadsheet $spreadsheet, object $export): void
    {
        if (! $export instanceof WithProperties) {
            return;
        }
        $properties = $spreadsheet->getProperties();
        foreach ($export->properties() as $name => $value) {
            $method = 'set' . ucfirst((string) $name);
            if (method_exists($properties, $method)) {
                $properties->{$method}($value);
            }
        }
    }

    private function resolvePdfType(string $type): string
    {
        if ($type !== Excel::PDF && ! in_array($type, [Excel::DOMPDF, Excel::MPDF, Excel::TCPDF], true)) {
            return $type;
        }
        $driver = $type === Excel::PDF ? (string) ($this->config['pdf']['driver'] ?? Excel::DOMPDF) : $type;
        $class = ['Dompdf' => 'Dompdf\\Dompdf', 'Mpdf' => 'Mpdf\\Mpdf', 'Tcpdf' => 'TCPDF'][$driver] ?? '';
        if ($class === '' || ! class_exists($class)) {
            throw new ExcelException(sprintf('PDF driver [%s] is not installed.', $driver));
        }
        return $driver;
    }

    private function extension(string $type): string
    {
        return in_array($type, [Excel::PDF, Excel::DOMPDF, Excel::MPDF, Excel::TCPDF], true) ? 'pdf' : strtolower($type);
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<array-key, mixed>
     */
    private function protectRow(array $row, object $export): array
    {
        $enabled = (bool) ($this->config['security']['formula_injection_protection'] ?? true);
        if ($export instanceof WithFormulaProtection) {
            $enabled = $export->protectFormulas();
        }
        if (! $enabled) {
            return $row;
        }
        $prefix = (string) ($this->config['security']['formula_injection_prefix'] ?? "'");
        return array_map(static function (mixed $value) use ($prefix): mixed {
            return is_string($value) && preg_match('/^(?:[\t\r\n]|[\x00-\x20]*[=+\-@])/', $value) === 1 ? $prefix . $value : $value;
        }, $row);
    }

    private function reportProgress(object $export, ?string $sheet, int $processed): void
    {
        $operationId = OperationContext::id();
        if ($export instanceof WithProgress) {
            $export->onProgress(new Progress('export', $operationId, $sheet, $processed, 0, null));
        }
        $statuses = $this->statuses;
        if ($operationId !== null && $statuses !== null && ($status = $statuses->find($operationId)) !== null) {
            $statuses->save($status->markRunning($processed));
        }
    }
}
