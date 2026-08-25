<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\Collection\Collection;
use Hyperf\Database\Query\Builder as QueryBuilder;
use Hyperf\Filesystem\FilesystemFactory;
use OpenYam\HyperfExcel\Concerns\FromCollection;
use OpenYam\HyperfExcel\Concerns\FromIterator;
use OpenYam\HyperfExcel\Concerns\FromQuery;
use OpenYam\HyperfExcel\Concerns\OnEachRow;
use OpenYam\HyperfExcel\Concerns\SkipsEmptyRows;
use OpenYam\HyperfExcel\Concerns\ToCollection;
use OpenYam\HyperfExcel\Concerns\WithChunkReading;
use OpenYam\HyperfExcel\Concerns\WithHeadingRow;
use OpenYam\HyperfExcel\Concerns\WithLimit;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Reader;
use OpenYam\HyperfExcel\Row;
use OpenYam\HyperfExcel\Support\EventBus;
use OpenYam\HyperfExcel\Writer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class PipelineParityTest extends TestCase
{
    public function testChunkedAndFullImportsProduceTheSameRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-parity-') . '.csv';
        file_put_contents($path, "Name,Email\nAlice,a@example.com\nBob,b@example.com\nCarol,c@example.com\n");
        $full = new class implements ToCollection, WithHeadingRow {
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
                return 2;
            }
            public function collection(Collection $rows): void
            {
                array_push($this->rows, ...$rows->all());
            }
        };

        $reader = $this->reader();
        $fullResult = $reader->read($full, $path);
        $chunkedResult = $reader->read($chunked, $path);

        self::assertSame($full->rows, $chunked->rows);
        self::assertSame($fullResult->processed, $chunkedResult->processed);
        self::assertSame($fullResult->skipped, $chunkedResult->skipped);
        @unlink($path);
    }

    public function testCollectionIteratorAndQueryExportsProduceTheSameCsv(): void
    {
        $rows = [[1, 'Alice'], [2, 'Bob']];
        $collection = new class ($rows) implements FromCollection {
            public function __construct(private readonly array $rows) {}
            public function collection(): Collection
            {
                return new Collection($this->rows);
            }
        };
        $iterator = new class ($rows) implements FromIterator {
            public function __construct(private readonly array $rows) {}
            public function iterator(): iterable
            {
                yield from $this->rows;
            }
        };
        $queryBuilder = new class ($rows) extends QueryBuilder {
            public function __construct(private readonly array $rows) {}
            public function cursor(): \Generator
            {
                yield from $this->rows;
            }
        };
        $query = new class ($queryBuilder) implements FromQuery {
            public function __construct(private readonly QueryBuilder $query) {}
            public function query(): QueryBuilder
            {
                return $this->query;
            }
        };

        $writer = $this->writer();
        $files = [
            $writer->export($collection, Excel::CSV),
            $writer->export($iterator, Excel::CSV),
            $writer->export($query, Excel::CSV),
        ];
        try {
            self::assertSame($files[0]->contents(), $files[1]->contents());
            self::assertSame($files[0]->contents(), $files[2]->contents());
        } finally {
            foreach ($files as $file) {
                $file->delete();
            }
        }
    }

    public function testFullImportBatchesRowsWithoutLosingPhysicalIndexesOrLimits(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-batches-') . '.csv';
        file_put_contents($path, "Value\none\n\ntwo\nthree\n");
        $import = new class implements OnEachRow, WithHeadingRow, WithLimit, SkipsEmptyRows {
            public array $rows = [];
            public function headingRow(): int
            {
                return 1;
            }
            public function limit(): int
            {
                return 2;
            }
            public function onRow(Row $row): void
            {
                $this->rows[$row->getIndex()] = $row->get('value');
            }
        };

        $result = $this->reader(['read_batch_size' => 2])->read($import, $path);

        self::assertSame([2 => 'one', 4 => 'two'], $import->rows);
        self::assertSame(2, $result->processed);
        self::assertSame(['Worksheet' => 2], $result->sheets);
        @unlink($path);
    }

    private function reader(array $config = []): Reader
    {
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        return new Reader($files, new EventBus($this->events()), $this->createStub(ContainerInterface::class), array_merge(['transaction' => ['handler' => 'null']], $config));
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
