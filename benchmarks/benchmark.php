<?php

declare(strict_types=1);

use Hyperf\Collection\Collection;
use Hyperf\Filesystem\FilesystemFactory;
use OpenYam\HyperfExcel\Concerns\FromIterator;
use OpenYam\HyperfExcel\Concerns\ToCollection;
use OpenYam\HyperfExcel\Concerns\WithChunkReading;
use OpenYam\HyperfExcel\Concerns\WithHeadings;
use OpenYam\HyperfExcel\Concerns\WithHeadingRow;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Reader;
use OpenYam\HyperfExcel\Support\EventBus;
use OpenYam\HyperfExcel\Writer;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$rowCount = max(1, (int) ($argv[1] ?? 50000));
$columnCount = max(1, (int) ($argv[2] ?? 20));
$runs = max(1, (int) ($argv[3] ?? 3));
$formats = match (strtolower((string) ($argv[4] ?? 'all'))) {
    'csv' => [Excel::CSV],
    'xlsx' => [Excel::XLSX],
    default => [Excel::CSV, Excel::XLSX],
};
$root = sys_get_temp_dir() . '/hyperf-excel-benchmark';
$temporaryFiles = new TemporaryFileManager($root);
$events = new EventBus(new class implements EventDispatcherInterface {
    public function dispatch(object $event): object
    {
        return $event;
    }
});
$container = new class implements ContainerInterface {
    public function get(string $id): mixed
    {
        throw new RuntimeException('Benchmark container service not available: ' . $id);
    }
    public function has(string $id): bool
    {
        return false;
    }
};
$filesystems = new class extends FilesystemFactory {
    public function __construct() {}
};
$writer = new Writer($temporaryFiles, $events, $container, ['chunk_size' => 1000]);
$reader = new Reader(
    new FileResolver($temporaryFiles, $filesystems),
    $events,
    $container,
    ['chunk_size' => 1000, 'read_batch_size' => 1000, 'transaction' => ['handler' => 'null']],
);

$results = [];
foreach ($formats as $format) {
    for ($run = 1; $run <= $runs; ++$run) {
        fwrite(STDERR, sprintf("[%s %d/%d] export\n", $format, $run, $runs));
        $export = new class ($rowCount, $columnCount) implements FromIterator, WithHeadings {
            public function __construct(private readonly int $rows, private readonly int $columns) {}
            public function headings(): array
            {
                return array_map(static fn(int $column): string => 'column_' . $column, range(1, $this->columns));
            }
            public function iterator(): iterable
            {
                for ($row = 1; $row <= $this->rows; ++$row) {
                    yield array_map(static fn(int $column): string => $row . ':' . $column, range(1, $this->columns));
                }
            }
        };
        resetPeakMemory();
        $started = hrtime(true);
        $file = $writer->export($export, $format);
        $results[$format]['export'][] = measurement($started);

        fwrite(STDERR, sprintf("[%s %d/%d] import\n", $format, $run, $runs));
        $import = new class implements ToCollection, WithHeadingRow, WithChunkReading {
            public int $processed = 0;
            public function headingRow(): int
            {
                return 1;
            }
            public function chunkSize(): int
            {
                return 1000;
            }
            public function collection(Collection $rows): void
            {
                $this->processed += $rows->count();
            }
        };
        resetPeakMemory();
        $started = hrtime(true);
        $result = $reader->read($import, $file->path(), readerType: $format);
        $results[$format]['import'][] = measurement($started) + ['processed' => $result->processed];
        $file->delete();
    }
}

$summary = ['rows' => $rowCount, 'columns' => $columnCount, 'runs' => $runs, 'results' => []];
foreach ($results as $format => $operations) {
    foreach ($operations as $operation => $measurements) {
        $times = array_column($measurements, 'seconds');
        $memory = array_column($measurements, 'peak_bytes');
        sort($times);
        sort($memory);
        $summary['results'][$format][$operation] = [
            'median_seconds' => $times[intdiv(count($times), 2)],
            'median_peak_bytes' => $memory[intdiv(count($memory), 2)],
        ];
    }
}
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

/** @return array{seconds: float, peak_bytes: int} */
function measurement(int $started): array
{
    return [
        'seconds' => round((hrtime(true) - $started) / 1_000_000_000, 4),
        'peak_bytes' => memory_get_peak_usage(true),
    ];
}

function resetPeakMemory(): void
{
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
}
