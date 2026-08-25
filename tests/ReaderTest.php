<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\Collection\Collection;
use Hyperf\Filesystem\FilesystemFactory;
use OpenYam\HyperfExcel\Concerns\RemembersRowNumber;
use OpenYam\HyperfExcel\Concerns\SkipsEmptyRows;
use OpenYam\HyperfExcel\Concerns\SkipsErrors;
use OpenYam\HyperfExcel\Concerns\SkipsOnError;
use OpenYam\HyperfExcel\Concerns\ToCollection;
use OpenYam\HyperfExcel\Concerns\WithChunkReading;
use OpenYam\HyperfExcel\Concerns\WithCustomCsvSettings;
use OpenYam\HyperfExcel\Concerns\WithEvents;
use OpenYam\HyperfExcel\Concerns\WithHeadingRow;
use OpenYam\HyperfExcel\Concerns\WithProgress;
use OpenYam\HyperfExcel\Events\BeforeImport;
use OpenYam\HyperfExcel\Events\ImportFailed;
use OpenYam\HyperfExcel\Exceptions\ExcelException;
use OpenYam\HyperfExcel\Exceptions\InvalidConcernException;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Reader;
use OpenYam\HyperfExcel\Result\Progress;
use OpenYam\HyperfExcel\Result\ReaderContext;
use OpenYam\HyperfExcel\Support\EventBus;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ReaderTest extends TestCase
{
    public function testItImportsCsvWithHeadingRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-reader-') . '.csv';
        file_put_contents($path, "Full Name,Email Address\nAlice,alice@example.com\n,\nBob,bob@example.com\n");
        /** @var Collection<array-key, mixed>|null $received */
        $received = null;
        $import = new class ($received) implements ToCollection, WithHeadingRow, SkipsEmptyRows {
            /** @var Collection<array-key, mixed>|null */
            public ?Collection $received = null;
            /** @param Collection<array-key, mixed>|null $received */
            public function __construct(?Collection &$received)
            {
                $this->received = & $received;
            }
            public function headingRow(): int
            {
                return 1;
            }
            public function collection(Collection $rows): void
            {
                $this->received = $rows;
            }
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $filesystems = $this->createStub(FilesystemFactory::class);
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $filesystems);
        $reader = new Reader($files, new EventBus($events), $this->createStub(ContainerInterface::class), ['transaction' => ['handler' => 'null']]);

        $result = $reader->read($import, $path);

        self::assertSame(2, $result->processed);
        self::assertSame('alice@example.com', $import->received?->first()['email_address']);
        @unlink($path);
    }

    public function testItReadsCsvInChunksAndReportsProgress(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-chunk-') . '.csv';
        file_put_contents($path, "Name,Email\nAlice,a@example.com\nBob,b@example.com\nCarol,c@example.com\n");
        $import = new class implements ToCollection, WithHeadingRow, WithChunkReading, WithProgress {
            public array $rows = [];
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
            public function collection(Collection $rows): void
            {
                array_push($this->rows, ...$rows->all());
            }
            public function onProgress(Progress $progress): void
            {
                $this->progress[] = $progress;
            }
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        $reader = new Reader($files, new EventBus($events), $this->createStub(ContainerInterface::class), ['transaction' => ['handler' => 'null']]);

        $result = $reader->read($import, $path);

        self::assertSame(3, $result->processed);
        self::assertSame(['Alice', 'Bob', 'Carol'], array_column($import->rows, 'name'));
        self::assertCount(2, $import->progress);
        self::assertSame(3, $import->progress[1]->processed);
        self::assertNull($import->progress[1]->total);
        @unlink($path);
    }

    public function testEmptyChunkedCsvReportsUnknownTotalOnce(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-empty-') . '.csv';
        file_put_contents($path, '');
        $import = new class implements ToCollection, WithChunkReading, WithProgress {
            /** @var Progress[] */
            public array $progress = [];
            public function chunkSize(): int
            {
                return 10;
            }
            public function collection(Collection $rows): void {}
            public function onProgress(Progress $progress): void
            {
                $this->progress[] = $progress;
            }
        };

        try {
            $result = $this->reader()->read($import, $path);
            self::assertSame(0, $result->processed);
            self::assertCount(1, $import->progress);
            self::assertNull($import->progress[0]->total);
        } finally {
            @unlink($path);
        }
    }

    public function testStreamingReaderContextMarksDimensionsAsUnknown(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-context-') . '.csv';
        file_put_contents($path, "one\ntwo\n");
        $import = new class implements ToCollection, WithChunkReading, WithEvents {
            public ?ReaderContext $context = null;
            public function chunkSize(): int
            {
                return 1;
            }
            public function collection(Collection $rows): void {}
            public function registerEvents(): array
            {
                return [BeforeImport::class => function (BeforeImport $event): void {
                    $this->context = $event->context;
                }];
            }
        };

        try {
            $this->reader()->read($import, $path);
            self::assertNotNull($import->context);
            self::assertNull($import->context->worksheets[0]['totalRows']);
            self::assertNull($import->context->worksheets[0]['totalColumns']);
        } finally {
            @unlink($path);
        }
    }

    public function testImportFailureListenerCannotMaskOriginalException(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-failure-') . '.csv';
        file_put_contents($path, "one\n");
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof ImportFailed) {
                    throw new \RuntimeException('listener failed');
                }
                return $event;
            }
        };
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        $reader = new Reader($files, new EventBus($events), $this->createStub(ContainerInterface::class), ['transaction' => ['handler' => 'null']]);

        try {
            $reader->read(new \stdClass(), $path);
            self::fail('Expected an invalid import concern.');
        } catch (InvalidConcernException $exception) {
            self::assertStringContainsString('Import must implement', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testHeadingRowsPreserveExtraColumnsAcrossCsvReadingPaths(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-extra-headings-') . '.csv';
        file_put_contents($path, "Name,column_3\nAlice,a@example.com,extra,tail\nBob\n");
        $regular = new class implements ToCollection, WithHeadingRow {
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
        $chunked = new class implements ToCollection, WithHeadingRow, WithChunkReading {
            public array $rows = [];
            public function headingRow(): int
            {
                return 1;
            }
            public function chunkSize(): int
            {
                return 1;
            }
            public function collection(Collection $rows): void
            {
                array_push($this->rows, ...$rows->all());
            }
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        $reader = new Reader($files, new EventBus($events), $this->createStub(ContainerInterface::class), ['transaction' => ['handler' => 'null']]);

        try {
            $reader->read($regular, $path);
            $reader->read($chunked, $path);

            $expected = [
                'name' => 'Alice',
                'column_3' => 'a@example.com',
                'column_3_2' => 'extra',
                'column_4' => 'tail',
            ];
            self::assertSame($expected, $regular->rows[0]);
            self::assertSame($expected, $chunked->rows[0]);
            self::assertSame(['name' => 'Bob'], $regular->rows[1]);
            self::assertSame(['name' => 'Bob'], $chunked->rows[1]);
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidStreamingInputEncodingUsesDomainException(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-invalid-encoding-') . '.csv';
        file_put_contents($path, "Name\nAlice\n");
        $import = new class implements ToCollection, WithChunkReading, WithCustomCsvSettings {
            public function chunkSize(): int
            {
                return 1;
            }
            public function getCsvSettings(): array
            {
                return ['input_encoding' => 'not-an-encoding'];
            }
            public function collection(Collection $rows): void {}
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        $reader = new Reader($files, new EventBus($events), $this->createStub(ContainerInterface::class), ['transaction' => ['handler' => 'null']]);

        try {
            $reader->read($import, $path);
            self::fail('Expected invalid encoding to fail.');
        } catch (ExcelException $exception) {
            self::assertStringContainsString('not-an-encoding', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidCsvDelimiterUsesDomainExceptionOnBothReadingPaths(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-invalid-delimiter-') . '.csv';
        file_put_contents($path, "Name\nAlice\n");
        $regular = new class implements ToCollection, WithCustomCsvSettings {
            public function getCsvSettings(): array
            {
                return ['delimiter' => '||'];
            }
            public function collection(Collection $rows): void {}
        };
        $chunked = new class implements ToCollection, WithChunkReading, WithCustomCsvSettings {
            public function chunkSize(): int
            {
                return 1;
            }
            public function getCsvSettings(): array
            {
                return ['delimiter' => '||'];
            }
            public function collection(Collection $rows): void {}
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        $reader = new Reader($files, new EventBus($events), $this->createStub(ContainerInterface::class), ['transaction' => ['handler' => 'null']]);

        try {
            foreach ([$regular, $chunked] as $import) {
                try {
                    $reader->read($import, $path);
                    self::fail('Expected invalid delimiter to fail.');
                } catch (ExcelException $exception) {
                    self::assertStringContainsString('delimiter', $exception->getMessage());
                }
            }
        } finally {
            @unlink($path);
        }
    }

    public function testErrorAndRowNumberTraitsRetainState(): void
    {
        $owner = new class implements SkipsOnError {
            use RemembersRowNumber;
            use SkipsErrors;
        };
        $error = new \RuntimeException('invalid row');
        $owner->rememberRowNumber(12);
        $owner->onError($error);

        self::assertSame(12, $owner->getRowNumber());
        self::assertSame([$error], $owner->errors());
    }

    private function reader(): Reader
    {
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        return new Reader($files, new EventBus($events), $this->createStub(ContainerInterface::class), ['transaction' => ['handler' => 'null']]);
    }
}
