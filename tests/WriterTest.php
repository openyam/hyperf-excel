<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\View\RenderInterface;
use OpenYam\HyperfExcel\Concerns\FromArray;
use OpenYam\HyperfExcel\Concerns\FromView;
use OpenYam\HyperfExcel\Concerns\ShouldAutoSize;
use OpenYam\HyperfExcel\Concerns\WithCustomCsvSettings;
use OpenYam\HyperfExcel\Concerns\WithCustomValueBinder;
use OpenYam\HyperfExcel\Concerns\WithEvents;
use OpenYam\HyperfExcel\Concerns\WithFormulaProtection;
use OpenYam\HyperfExcel\Concerns\WithHeadings;
use OpenYam\HyperfExcel\Concerns\WithMultipleSheets;
use OpenYam\HyperfExcel\Concerns\WithStyles;
use OpenYam\HyperfExcel\Concerns\WithTitle;
use OpenYam\HyperfExcel\Events\AfterSheet;
use OpenYam\HyperfExcel\Events\BeforeExport;
use OpenYam\HyperfExcel\Events\BeforeSheet;
use OpenYam\HyperfExcel\Events\BeforeWriting;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Exceptions\ExcelException;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Support\EventBus;
use OpenYam\HyperfExcel\Writer;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class WriterTest extends TestCase
{
    public function testItExportsConcernDrivenWorkbook(): void
    {
        $events = [];
        $dispatcher = new class ($events) implements EventDispatcherInterface {
            public array $events = [];
            public function __construct(array &$events)
            {
                $this->events = & $events;
            }
            public function dispatch(object $event): object
            {
                $this->events[] = $event;
                return $event;
            }
        };
        $container = $this->createStub(ContainerInterface::class);
        $writer = new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $container);
        $export = new class implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize {
            public function array(): array
            {
                return [[1, 'Alice'], [2, 'Bob']];
            }
            public function headings(): array
            {
                return ['id', 'name'];
            }
            public function title(): string
            {
                return 'Users';
            }
            public function styles(Worksheet $sheet): array
            {
                return ['A1:B1' => ['font' => ['bold' => true]]];
            }
        };

        $file = $writer->export($export, Excel::XLSX);
        $book = IOFactory::load($file->path());

        self::assertSame('Users', $book->getActiveSheet()->getTitle());
        self::assertSame('name', $book->getActiveSheet()->getCell('B1')->getValue());
        self::assertSame('Bob', $book->getActiveSheet()->getCell('B3')->getValue());
        self::assertTrue($book->getActiveSheet()->getStyle('A1')->getFont()->getBold());
        self::assertNotEmpty(array_filter($events, static fn($event) => $event instanceof BeforeExport));
        $book->disconnectWorksheets();
        $file->delete();
    }

    public function testItExportsCsv(): void
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));
        $file = $writer->export(new class implements FromArray {
            public function array(): array
            {
                return [['alpha', 'beta']];
            }
        }, Excel::CSV);
        self::assertStringContainsString('alpha', $file->contents());
        self::assertStringContainsString('beta', $file->contents());
        $file->delete();
    }

    public function testDelimitedSettingsAreIdenticalAcrossStreamingAndWorkbookPaths(): void
    {
        $settings = [
            'delimiter' => '|',
            'escape_character' => '',
            'use_bom' => true,
            'include_separator_line' => true,
        ];
        $streaming = new class ($settings) implements FromArray, WithCustomCsvSettings {
            public function __construct(private readonly array $settings) {}
            public function array(): array
            {
                return [['a|b', 'quote"value']];
            }
            public function getCsvSettings(): array
            {
                return $this->settings;
            }
        };
        $workbook = new class ($settings) implements FromArray, WithCustomCsvSettings, WithEvents {
            public function __construct(private readonly array $settings) {}
            public function array(): array
            {
                return [['a|b', 'quote"value']];
            }
            public function getCsvSettings(): array
            {
                return $this->settings;
            }
            public function registerEvents(): array
            {
                return [];
            }
        };

        $streamed = $this->writer()->export($streaming, Excel::CSV);
        $fromWorkbook = $this->writer()->export($workbook, Excel::CSV);
        try {
            self::assertSame($streamed->contents(), $fromWorkbook->contents());
            self::assertStringStartsWith("\xEF\xBB\xBFsep=|\n", $streamed->contents());
        } finally {
            $streamed->delete();
            $fromWorkbook->delete();
        }
    }

    public function testExcelCompatibleCsvUsesCanonicalDialect(): void
    {
        $export = new class implements FromArray, WithCustomCsvSettings {
            public function array(): array
            {
                return [['one', 'two']];
            }
            public function getCsvSettings(): array
            {
                return ['delimiter' => '|', 'excel_compatibility' => true];
            }
        };

        $file = $this->writer()->export($export, Excel::CSV);
        try {
            self::assertSame("\xEF\xBB\xBFsep=;\r\n\"one\";\"two\"\r\n", $file->contents());
        } finally {
            $file->delete();
        }
    }

    public function testStreamingCsvDispatchesLifecycleEventsInOrder(): void
    {
        $events = [];
        $dispatcher = new class ($events) implements EventDispatcherInterface {
            public function __construct(private array &$events) {}
            public function dispatch(object $event): object
            {
                $this->events[] = $event::class;
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));

        $file = $writer->export(new class implements FromArray {
            public function array(): array
            {
                return [['alpha']];
            }
        }, Excel::CSV);
        $file->delete();

        self::assertSame([
            BeforeExport::class,
            BeforeSheet::class,
            BeforeWriting::class,
            AfterSheet::class,
        ], $events);
    }

    public function testWithEventsUsesMutableWorkbookPathForCsv(): void
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));
        $export = new class implements FromArray, WithEvents {
            public function array(): array
            {
                return [['original']];
            }
            public function registerEvents(): array
            {
                return [BeforeWriting::class => static function (BeforeWriting $event): void {
                    $event->spreadsheet->getActiveSheet()->setCellValue('A1', 'changed');
                }];
            }
        };

        $file = $writer->export($export, Excel::CSV);

        self::assertStringContainsString('changed', $file->contents());
        $file->delete();
    }

    public function testStreamingEventFailureCleansUpTemporaryFile(): void
    {
        $root = sys_get_temp_dir() . '/hyperf-excel-event-failure-' . bin2hex(random_bytes(6));
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof BeforeWriting) {
                    throw new \RuntimeException('listener failed');
                }
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager($root), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));

        try {
            $writer->export(new class implements FromArray {
                public function array(): array
                {
                    return [['alpha']];
                }
            }, Excel::CSV);
            self::fail('Expected event listener exception was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('listener failed', $exception->getMessage());
            self::assertSame([], glob($root . '/*') ?: []);
        } finally {
            @rmdir($root);
        }
    }

    public function testCsvExportProtectsFormulaInjection(): void
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));
        $file = $writer->export(new class implements FromArray {
            public function array(): array
            {
                return [['=1+1', '+cmd', "  =2+2", "\t=3+3", 'safe']];
            }
        }, Excel::CSV);

        self::assertStringContainsString("'=1+1", $file->contents());
        self::assertStringContainsString("'+cmd", $file->contents());
        self::assertStringContainsString("'  =2+2", $file->contents());
        self::assertStringContainsString("'\t=3+3", $file->contents());
        $file->delete();
    }

    public function testFormulaProtectionIsConsistentAcrossCsvAndXlsxAndCanBeDisabled(): void
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));
        $protected = new class implements FromArray {
            public function array(): array
            {
                return [['=1+1']];
            }
        };
        $trusted = new class implements FromArray, WithFormulaProtection {
            public function array(): array
            {
                return [['=1+1']];
            }
            public function protectFormulas(): bool
            {
                return false;
            }
        };

        $csv = $writer->export($trusted, Excel::CSV);
        $xlsx = $writer->export($protected, Excel::XLSX);
        $trustedXlsx = $writer->export($trusted, Excel::XLSX);
        $book = IOFactory::load($xlsx->path());
        $trustedBook = IOFactory::load($trustedXlsx->path());

        try {
            self::assertStringContainsString('=1+1', $csv->contents());
            self::assertSame("'=1+1", $book->getActiveSheet()->getCell('A1')->getValue());
            self::assertSame('=1+1', $trustedBook->getActiveSheet()->getCell('A1')->getValue());
        } finally {
            $book->disconnectWorksheets();
            $trustedBook->disconnectWorksheets();
            $csv->delete();
            $xlsx->delete();
            $trustedXlsx->delete();
        }
    }

    public function testInvalidCsvSettingsUseDomainExceptionsAndCleanTemporaryFiles(): void
    {
        $root = sys_get_temp_dir() . '/hyperf-excel-invalid-csv-' . bin2hex(random_bytes(6));
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager($root), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));
        $streaming = new class implements FromArray, WithCustomCsvSettings {
            public function array(): array
            {
                return [['alpha']];
            }
            public function getCsvSettings(): array
            {
                return ['escape_character' => 'xx'];
            }
        };
        $workbook = new class implements FromArray, WithCustomCsvSettings, WithEvents {
            public function array(): array
            {
                return [['alpha']];
            }
            public function getCsvSettings(): array
            {
                return ['enclosure' => ''];
            }
            public function registerEvents(): array
            {
                return [];
            }
        };

        try {
            foreach ([$streaming, $workbook] as $export) {
                try {
                    $writer->export($export, Excel::CSV);
                    self::fail('Expected invalid CSV settings to fail.');
                } catch (ExcelException $exception) {
                    self::assertStringContainsString('CSV setting', $exception->getMessage());
                }
                self::assertSame([], glob($root . '/*') ?: []);
            }
        } finally {
            @rmdir($root);
        }
    }

    public function testItExportsPdfWithDefaultRenderer(): void
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $writer = new Writer(
            new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'),
            new EventBus($dispatcher),
            $this->createStub(ContainerInterface::class),
            ['pdf' => ['driver' => Excel::DOMPDF]],
        );
        $file = $writer->export(new class implements FromArray {
            public function array(): array
            {
                return [['PDF export']];
            }
        }, Excel::PDF);
        self::assertStringStartsWith('%PDF-', $file->contents());
        $file->delete();
    }

    public function testCustomValueBinderIsScopedToItsWorkbookAndWorksForCsv(): void
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));
        $globalBinder = Cell::getValueBinder();
        $export = new class implements FromArray, WithCustomValueBinder {
            public function array(): array
            {
                return [['alpha']];
            }
            public function bindValue(Cell $cell, mixed $value): bool
            {
                $cell->setValueExplicit(strtoupper((string) $value), DataType::TYPE_STRING);
                return true;
            }
        };

        $file = $writer->export($export, Excel::CSV);

        self::assertStringContainsString('ALPHA', $file->contents());
        self::assertSame($globalBinder, Cell::getValueBinder());
        $file->delete();
    }

    public function testItExportsRenderedViews(): void
    {
        $renderer = $this->createStub(RenderInterface::class);
        $renderer->method('getContents')->willReturn('<table><tr><td>Rendered</td><td>42</td></tr></table>');
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(RenderInterface::class)->willReturn($renderer);
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $container);
        $export = new class implements FromView {
            public function view(): string
            {
                return 'report';
            }
            public function viewData(): array
            {
                return ['id' => 42];
            }
        };

        $file = $writer->export($export, Excel::XLSX);
        $book = IOFactory::load($file->path());

        self::assertSame('Rendered', $book->getActiveSheet()->getCell('A1')->getValue());
        self::assertSame(42, $book->getActiveSheet()->getCell('B1')->getValue());
        $book->disconnectWorksheets();
        $file->delete();
    }

    public function testItExportsMultipleSheetsInOrder(): void
    {
        $first = new class implements FromArray, WithTitle {
            public function array(): array
            {
                return [['first']];
            }
            public function title(): string
            {
                return 'First';
            }
        };
        $second = new class implements FromArray, WithTitle {
            public function array(): array
            {
                return [['second']];
            }
            public function title(): string
            {
                return 'Second';
            }
        };
        $export = new class ($first, $second) implements WithMultipleSheets {
            public function __construct(private readonly object $first, private readonly object $second) {}
            public function sheets(): array
            {
                return [$this->first, $this->second];
            }
        };
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));

        $file = $writer->export($export, Excel::XLSX);
        $book = IOFactory::load($file->path());

        self::assertSame(['First', 'Second'], $book->getSheetNames());
        self::assertSame('second', $book->getSheet(1)->getCell('A1')->getValue());
        $book->disconnectWorksheets();
        $file->delete();
    }

    public function testStringSheetKeysProvideDefaultTitlesAndWithTitleWins(): void
    {
        $first = new class implements FromArray {
            public function array(): array
            {
                return [['first']];
            }
        };
        $second = new class implements FromArray, WithTitle {
            public function array(): array
            {
                return [['second']];
            }
            public function title(): string
            {
                return 'Explicit';
            }
        };
        $export = new class ($first, $second) implements WithMultipleSheets {
            public function __construct(private readonly object $first, private readonly object $second) {}
            public function sheets(): array
            {
                return ['Default' => $this->first, 'Ignored' => $this->second];
            }
        };
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $writer = new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));

        $file = $writer->export($export, Excel::XLSX);
        $book = IOFactory::load($file->path());

        self::assertSame(['Default', 'Explicit'], $book->getSheetNames());
        $book->disconnectWorksheets();
        $file->delete();
    }

    private function writer(): Writer
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        return new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($dispatcher), $this->createStub(ContainerInterface::class));
    }
}
