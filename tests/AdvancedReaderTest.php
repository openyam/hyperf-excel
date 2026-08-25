<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\Collection\Collection;
use Hyperf\Filesystem\FilesystemFactory;
use OpenYam\HyperfExcel\Concerns\SkipsUnknownSheets;
use OpenYam\HyperfExcel\Concerns\ToCollection;
use OpenYam\HyperfExcel\Concerns\WithCalculatedFormulas;
use OpenYam\HyperfExcel\Concerns\WithChunkReading;
use OpenYam\HyperfExcel\Concerns\WithColumnLimit;
use OpenYam\HyperfExcel\Concerns\WithHeadingFormatter;
use OpenYam\HyperfExcel\Concerns\WithHeadingRow;
use OpenYam\HyperfExcel\Concerns\WithLimit;
use OpenYam\HyperfExcel\Concerns\WithMultipleSheets;
use OpenYam\HyperfExcel\Concerns\WithReadFilter;
use OpenYam\HyperfExcel\Concerns\WithSheetSelection;
use OpenYam\HyperfExcel\Events\BeforeImport;
use OpenYam\HyperfExcel\Exceptions\InvalidConcernException;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Reader;
use OpenYam\HyperfExcel\Support\EventBus;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class AdvancedReaderTest extends TestCase
{
    public function testItSelectsSheetsAndExposesReaderContext(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-sheets-') . '.xlsx';
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('First')->fromArray([['value'], ['ignored']]);
        $book->createSheet()->setTitle('Second')->fromArray([['value'], ['selected']]);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        $before = null;
        $events = new class ($before) implements EventDispatcherInterface {
            public ?BeforeImport $before = null;
            public function __construct(?BeforeImport &$before)
            {
                $this->before = &$before;
            }
            public function dispatch(object $event): object
            {
                if ($event instanceof BeforeImport) {
                    $this->before = $event;
                }
                return $event;
            }
        };
        $import = new class implements ToCollection, WithHeadingRow, WithSheetSelection {
            public array $rows = [];
            public function headingRow(): int
            {
                return 1;
            }
            public function sheets(): array
            {
                return ['Second'];
            }
            public function collection(Collection $rows): void
            {
                $this->rows = array_merge($this->rows, $rows->all());
            }
        };

        $result = $this->reader($events)->read($import, $path);

        self::assertSame(1, $result->processed);
        self::assertSame('selected', $import->rows[0]['value']);
        self::assertInstanceOf(BeforeImport::class, $before);
        self::assertTrue($before->context->chunked);
        self::assertCount(2, $before->context->worksheets);
        @unlink($path);
    }

    public function testItCanPreserveHeadingText(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-heading-') . '.csv';
        file_put_contents($path, "Full Name,E-mail\nAlice,a@example.com\n");
        $import = new class implements ToCollection, WithHeadingRow, WithHeadingFormatter {
            public array $rows = [];
            public function headingRow(): int
            {
                return 1;
            }
            public function headingFormatter(): string
            {
                return 'none';
            }
            public function collection(Collection $rows): void
            {
                $this->rows = $rows->all();
            }
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };

        $this->reader($events)->read($import, $path);

        self::assertSame('Alice', $import->rows[0]['Full Name']);
        self::assertSame('a@example.com', $import->rows[0]['E-mail']);
        @unlink($path);
    }

    public function testXlsxHeadingRowsPreserveExtraColumnsWithoutPaddingShortRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-xlsx-headings-') . '.xlsx';
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([
            ['Name', 'column_3'],
            ['Alice', 'a@example.com', 'extra', 'tail'],
            ['Bob'],
        ]);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $import = new class implements ToCollection, WithHeadingRow {
            public array $rows = [];
            public function headingRow(): int
            {
                return 1;
            }
            public function collection(Collection $rows): void
            {
                $this->rows = $rows->all();
            }
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };

        try {
            $this->reader($events)->read($import, $path);

            self::assertSame([
                'name' => 'Alice',
                'column_3' => 'a@example.com',
                'column_3_2' => 'extra',
                'column_4' => 'tail',
            ], $import->rows[0]);
            self::assertSame(['name' => 'Bob'], $import->rows[1]);
        } finally {
            @unlink($path);
        }
    }

    public function testToArrayReturnsOnlySelectedSheets(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-array-selection-') . '.xlsx';
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('First')->fromArray([['value'], ['ignored']]);
        $book->createSheet()->setTitle('Second')->fromArray([['value'], ['selected']]);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $import = new class implements WithHeadingRow, WithSheetSelection {
            public function headingRow(): int
            {
                return 1;
            }
            public function sheets(): array
            {
                return [1];
            }
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };

        $result = $this->reader($events)->toArray($import, $path);

        self::assertCount(1, $result);
        self::assertSame('selected', $result[0][2]['value']);
        @unlink($path);
    }

    public function testToArrayAppliesEachSheetImportConcerns(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-array-multiple-') . '.xlsx';
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('First')->fromArray([['ignored']]);
        $book->createSheet()->setTitle('Second')->fromArray([['Full Name'], ['Alice'], ['Bob']]);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $sheetImport = new class implements WithHeadingRow, WithHeadingFormatter, WithLimit {
            public function headingRow(): int
            {
                return 1;
            }
            public function headingFormatter(): string
            {
                return 'none';
            }
            public function limit(): int
            {
                return 1;
            }
        };
        $import = new class ($sheetImport) implements WithMultipleSheets {
            public function __construct(private readonly object $sheetImport) {}
            public function sheets(): array
            {
                return ['Second' => $this->sheetImport];
            }
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };

        $result = $this->reader($events)->toArray($import, $path);

        self::assertSame([2 => ['Full Name' => 'Alice']], $result[0]);
        @unlink($path);
    }

    public function testChunkReadingRejectsCalculatedFormulas(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-formulas-') . '.xlsx';
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([[1], ['=A1+1']]);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $import = new class implements ToCollection, WithCalculatedFormulas, WithChunkReading {
            public function chunkSize(): int
            {
                return 1;
            }
            public function collection(Collection $rows): void {}
        };

        try {
            $this->expectException(InvalidConcernException::class);
            $this->expectExceptionMessage('Calculated formulas');
            $this->reader(new class implements EventDispatcherInterface {
                public function dispatch(object $event): object
                {
                    return $event;
                }
            })->read($import, $path);
        } finally {
            @unlink($path);
        }
    }

    public function testUnknownSheetsCanBeSkipped(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-unknown-sheet-') . '.xlsx';
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Existing')->setCellValue('A1', 'value');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $sheetImport = new class implements ToCollection {
            public function collection(Collection $rows): void {}
        };
        $import = new class ($sheetImport) implements SkipsUnknownSheets, WithMultipleSheets {
            public array $unknown = [];
            public function __construct(private readonly object $sheetImport) {}
            public function sheets(): array
            {
                return ['Missing' => $this->sheetImport];
            }
            public function onUnknownSheet(string $sheetName): void
            {
                $this->unknown[] = $sheetName;
            }
        };

        try {
            $result = $this->reader(new class implements EventDispatcherInterface {
                public function dispatch(object $event): object
                {
                    return $event;
                }
            })->read($import, $path);
            self::assertSame(['Missing'], $import->unknown);
            self::assertSame(0, $result->processed);
        } finally {
            @unlink($path);
        }
    }

    public function testChunkedReadFilterColumnLimitAndRowLimitCompose(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-filter-limits-') . '.xlsx';
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([
            ['First', 'Second', 'Third'],
            ['one', 'hidden', 'excluded'],
            ['two', 'hidden', 'excluded'],
        ]);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $import = new class implements ToCollection, WithChunkReading, WithColumnLimit, WithHeadingRow, WithLimit, WithReadFilter {
            public array $rows = [];
            public function chunkSize(): int
            {
                return 1;
            }
            public function endColumn(): string
            {
                return 'B';
            }
            public function headingRow(): int
            {
                return 1;
            }
            public function limit(): int
            {
                return 1;
            }
            public function readFilter(): IReadFilter
            {
                return new class implements IReadFilter {
                    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                    {
                        return $row === 1 || $columnAddress === 'A';
                    }
                };
            }
            public function collection(Collection $rows): void
            {
                array_push($this->rows, ...$rows->all());
            }
        };

        try {
            $result = $this->reader(new class implements EventDispatcherInterface {
                public function dispatch(object $event): object
                {
                    return $event;
                }
            })->read($import, $path);
            self::assertSame([['first' => 'one']], $import->rows);
            self::assertSame(1, $result->processed);
        } finally {
            @unlink($path);
        }
    }

    private function reader(EventDispatcherInterface $events): Reader
    {
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        return new Reader($files, new EventBus($events), $this->createStub(ContainerInterface::class), ['transaction' => ['handler' => 'null']]);
    }
}
