<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\Collection\Collection;
use Hyperf\Filesystem\FilesystemFactory;
use OpenYam\HyperfExcel\Concerns\FromArray;
use OpenYam\HyperfExcel\Concerns\ToCollection;
use OpenYam\HyperfExcel\Concerns\WithChunkReading;
use OpenYam\HyperfExcel\Concerns\WithChunkSize;
use OpenYam\HyperfExcel\Concerns\WithHeadingRow;
use OpenYam\HyperfExcel\Concerns\WithMultipleSheets;
use OpenYam\HyperfExcel\Concerns\WithProgress;
use OpenYam\HyperfExcel\Concerns\WithTitle;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Reader;
use OpenYam\HyperfExcel\Result\Progress;
use OpenYam\HyperfExcel\Support\EventBus;
use OpenYam\HyperfExcel\Writer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ProgressTest extends TestCase
{
    public function testMultiSheetImportReportsOperationLevelProgress(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-progress-') . '.xlsx';
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('First')->fromArray([['Value'], ['one'], ['two']]);
        $book->createSheet()->setTitle('Second')->fromArray([['Value'], ['three'], ['four']]);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        $first = $this->sheetImport();
        $second = $this->sheetImport();
        $import = new class ($first, $second) implements WithMultipleSheets {
            public function __construct(private readonly object $first, private readonly object $second) {}
            public function sheets(): array
            {
                return ['First' => $this->first, 'Second' => $this->second];
            }
        };

        try {
            $result = $this->reader()->read($import, $path);
        } finally {
            @unlink($path);
        }

        self::assertSame(4, $result->processed);
        self::assertSame(2, $first->progress[0]->processed);
        self::assertSame(4, $second->progress[0]->processed);
        self::assertSame(4, $first->progress[0]->total);
        self::assertSame(4, $second->progress[0]->total);
        self::assertSame('Second', $second->progress[0]->sheet);
    }

    public function testMultiSheetExportProgressDoesNotResetBetweenSheets(): void
    {
        $first = $this->sheetExport('First', [['one'], ['two']]);
        $second = $this->sheetExport('Second', [['three'], ['four']]);
        $export = new class ($first, $second) implements WithMultipleSheets {
            public function __construct(private readonly object $first, private readonly object $second) {}
            public function sheets(): array
            {
                return [$this->first, $this->second];
            }
        };

        $file = $this->writer()->export($export, Excel::XLSX);
        $file->delete();

        self::assertSame([2], array_column($first->progress, 'processed'));
        self::assertSame([4], array_column($second->progress, 'processed'));
        self::assertSame('Second', $second->progress[0]->sheet);
    }

    public function testDelimitedExportUsesConcernChunkSizeWithoutDuplicateFinalProgress(): void
    {
        $export = new class implements FromArray, WithChunkSize, WithProgress {
            /** @var Progress[] */
            public array $progress = [];
            public function array(): array
            {
                return [['one'], ['two'], ['three'], ['four']];
            }
            public function chunkSize(): int
            {
                return 2;
            }
            public function onProgress(Progress $progress): void
            {
                $this->progress[] = $progress;
            }
        };

        $file = $this->writer()->export($export, Excel::CSV);
        $file->delete();

        self::assertSame([2, 4], array_column($export->progress, 'processed'));
    }

    public function testEmptyDelimitedExportReportsZeroProgressOnce(): void
    {
        $export = new class implements FromArray, WithProgress {
            /** @var Progress[] */
            public array $progress = [];
            public function array(): array
            {
                return [];
            }
            public function onProgress(Progress $progress): void
            {
                $this->progress[] = $progress;
            }
        };

        $file = $this->writer()->export($export, Excel::CSV);
        $file->delete();

        self::assertCount(1, $export->progress);
        self::assertSame(0, $export->progress[0]->processed);
    }

    private function sheetImport(): object
    {
        return new class implements ToCollection, WithChunkReading, WithHeadingRow, WithProgress {
            /** @var Progress[] */
            public array $progress = [];
            public function headingRow(): int
            {
                return 1;
            }
            public function chunkSize(): int
            {
                return 2;
            }
            public function collection(Collection $rows): void {}
            public function onProgress(Progress $progress): void
            {
                $this->progress[] = $progress;
            }
        };
    }

    /** @param list<array<int, string>> $rows */
    private function sheetExport(string $title, array $rows): object
    {
        return new class ($title, $rows) implements FromArray, WithChunkSize, WithProgress, WithTitle {
            /** @var Progress[] */
            public array $progress = [];
            public function __construct(private readonly string $name, private readonly array $rows) {}
            public function array(): array
            {
                return $this->rows;
            }
            public function chunkSize(): int
            {
                return 2;
            }
            public function onProgress(Progress $progress): void
            {
                $this->progress[] = $progress;
            }
            public function title(): string
            {
                return $this->name;
            }
        };
    }

    private function reader(): Reader
    {
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        return new Reader($files, new EventBus($this->events()), $this->createStub(ContainerInterface::class), ['transaction' => ['handler' => 'null']]);
    }

    private function writer(): Writer
    {
        return new Writer(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), new EventBus($this->events()), $this->createStub(ContainerInterface::class));
    }

    private function events(): EventDispatcherInterface
    {
        return new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
    }
}
