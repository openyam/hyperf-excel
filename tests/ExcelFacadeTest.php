<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Filesystem\FilesystemFactory;
use Hyperf\HttpServer\Contract\ResponseInterface;
use League\Flysystem\Filesystem;
use OpenYam\HyperfExcel\Concerns\FromArray;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Queue\QueueDispatcher;
use OpenYam\HyperfExcel\Reader;
use OpenYam\HyperfExcel\Support\EventBus;
use OpenYam\HyperfExcel\Writer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ExcelFacadeTest extends TestCase
{
    public function testRawAndLocalStoreUseTheSameExportPipeline(): void
    {
        $excel = $this->excel();
        $export = new class implements FromArray {
            public function array(): array
            {
                return [[1, 'Alice']];
            }
        };
        $path = tempnam(sys_get_temp_dir(), 'excel-store-') . '.csv';

        try {
            $raw = $excel->raw($export, Excel::CSV);
            self::assertTrue($excel->store($export, $path));
            self::assertSame($raw, file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testLocalStoreAtomicallyReplacesExistingFile(): void
    {
        $directory = sys_get_temp_dir() . '/hyperf-excel-atomic-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $path = $directory . '/report.csv';
        file_put_contents($path, 'old contents');
        $export = new class implements FromArray {
            public function array(): array
            {
                return [['new contents']];
            }
        };

        try {
            self::assertTrue($this->excel()->store($export, $path));
            self::assertSame("\"new contents\"\n", file_get_contents($path));
            self::assertSame([], glob($directory . '/.hyperf-excel-*'));
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testFailedAtomicStorePreservesExistingFile(): void
    {
        $directory = sys_get_temp_dir() . '/hyperf-excel-atomic-failure-' . bin2hex(random_bytes(6));
        $path = $directory . '/report.csv';
        self::assertTrue(mkdir($path, 0755, true));
        file_put_contents($path . '/marker', 'old contents');
        $export = new class implements FromArray {
            public function array(): array
            {
                return [['new contents']];
            }
        };

        try {
            self::assertFalse($this->excel()->store($export, $path, writerType: Excel::CSV));
            self::assertSame('old contents', file_get_contents($path . '/marker'));
            self::assertSame([], glob($directory . '/.hyperf-excel-*'));
        } finally {
            @unlink($path . '/marker');
            @rmdir($path);
            @rmdir($directory);
        }
    }

    public function testToCollectionWrapsEveryWorksheet(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-collection-') . '.csv';
        file_put_contents($path, "Alice,a@example.com\nBob,b@example.com\n");

        try {
            $sheets = $this->excel()->toCollection(new \stdClass(), $path);
            self::assertCount(1, $sheets);
            self::assertSame('Alice', $sheets[0][1][0]);
            self::assertSame('Bob', $sheets[0][2][0]);
        } finally {
            @unlink($path);
        }
    }

    public function testStoreAndImportUseConfiguredFilesystemDisk(): void
    {
        $contents = null;
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('writeStream')->willReturnCallback(static function (string $path, $stream) use (&$contents): void {
            self::assertSame('reports/data.csv', $path);
            $contents = stream_get_contents($stream);
        });
        $filesystem->method('readStream')->willReturnCallback(static function (string $path) use (&$contents) {
            self::assertSame('reports/data.csv', $path);
            $stream = fopen('php://temp', 'w+b');
            self::assertIsResource($stream);
            fwrite($stream, (string) $contents);
            rewind($stream);
            return $stream;
        });
        $factory = $this->createStub(FilesystemFactory::class);
        $factory->method('get')->willReturn($filesystem);
        $excel = $this->excel($factory);
        $export = new class implements FromArray {
            public function array(): array
            {
                return [['remote']];
            }
        };

        self::assertTrue($excel->store($export, 'reports/data.csv', 'remote'));
        self::assertSame('remote', $excel->toArray(new \stdClass(), 'reports/data.csv', 'remote')[0][1][0]);
    }

    public function testDownloadReturnsTemporaryFileStreamWithHeaders(): void
    {
        $headers = [];
        $body = null;
        $downloadResponse = $this->createStub(\Psr\Http\Message\ResponseInterface::class);
        $downloadResponse->method('withHeader')->willReturnCallback(static function ($name, $value) use (&$headers, $downloadResponse) {
            $headers[(string) $name] = $value;
            return $downloadResponse;
        });
        $downloadResponse->method('withBody')->willReturnCallback(static function ($stream) use (&$body, $downloadResponse) {
            $body = $stream;
            return $downloadResponse;
        });
        $response = $this->createStub(ResponseInterface::class);
        $response->method('download')->willReturnCallback(static function (string $path, string $name) use ($downloadResponse): \Psr\Http\Message\ResponseInterface {
            self::assertFileExists($path);
            self::assertSame('report.csv', $name);
            return $downloadResponse;
        });
        $export = new class implements FromArray {
            public function array(): array
            {
                return [['download']];
            }
        };

        $result = $this->excel(response: $response)->download($export, 'report.csv');

        self::assertSame($downloadResponse, $result);
        self::assertSame('text/csv; charset=UTF-8', $headers['Content-Type']);
        self::assertNotNull($body);
        self::assertStringContainsString('download', $body->getContents());
        $body->close();
    }

    public function testDownloadFailureDeletesGeneratedTemporaryFile(): void
    {
        $generatedPath = null;
        $response = $this->createStub(ResponseInterface::class);
        $response->method('download')->willReturnCallback(static function (string $path) use (&$generatedPath): never {
            $generatedPath = $path;
            throw new \RuntimeException('response failed');
        });
        $export = new class implements FromArray {
            public function array(): array
            {
                return [['download']];
            }
        };

        try {
            $this->excel(response: $response)->download($export, 'report.csv');
            self::fail('Expected response creation to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('response failed', $exception->getMessage());
        }

        self::assertNotNull($generatedPath);
        self::assertFileDoesNotExist($generatedPath);
    }

    public function testSecondarySpreadsheetFormatsRoundTrip(): void
    {
        $excel = $this->excel();
        $export = new class implements FromArray {
            public function array(): array
            {
                return [['roundtrip', 42]];
            }
        };

        foreach ([Excel::TSV, Excel::ODS, Excel::XLS, Excel::HTML] as $type) {
            $path = tempnam(sys_get_temp_dir(), 'excel-format-');
            self::assertIsString($path);
            try {
                file_put_contents($path, $excel->raw($export, $type));
                $sheets = $excel->toArray(new \stdClass(), $path, readerType: $type);
                self::assertSame('roundtrip', $sheets[0][1][0], $type);
                self::assertEquals(42, $sheets[0][1][1], $type);
            } finally {
                @unlink($path);
            }
        }
    }

    private function excel(?FilesystemFactory $filesystemFactory = null, ?ResponseInterface $response = null): Excel
    {
        $temporaryFiles = new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests');
        $files = new FileResolver($temporaryFiles, $filesystemFactory ?? $this->createStub(FilesystemFactory::class));
        $events = new EventBus(new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        });
        $container = $this->createStub(ContainerInterface::class);
        return new Excel(
            new Writer($temporaryFiles, $events, $container),
            new Reader($files, $events, $container, ['transaction' => ['handler' => 'null']]),
            $files,
            $response ?? $this->createStub(ResponseInterface::class),
            new QueueDispatcher($this->createStub(DriverFactory::class)),
        );
    }
}
