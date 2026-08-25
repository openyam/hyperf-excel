<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\Filesystem\FilesystemFactory;
use Hyperf\HttpMessage\Stream\SwooleStream;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Exceptions\ExcelException;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Reader;
use OpenYam\HyperfExcel\Security\ArchiveGuard;
use OpenYam\HyperfExcel\Support\EventBus;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\UploadedFileInterface;

final class SecurityTest extends TestCase
{
    public function testItRejectsUnsafeStreamWrappers(): void
    {
        $resolver = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        $this->expectException(ExcelException::class);
        $resolver->localize('phar:///tmp/attacker.xlsx');
    }

    public function testItLimitsStreamInputsWhileCopying(): void
    {
        $resolver = new FileResolver(
            new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'),
            $this->createStub(FilesystemFactory::class),
            ['max_upload_size' => 3],
        );
        $this->expectException(ExcelException::class);
        $resolver->localize(new SwooleStream('oversized'));
    }

    public function testItLimitsLocalFileInputsBeforeReading(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-oversized-');
        file_put_contents($path, 'oversized');
        $resolver = new FileResolver(
            new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'),
            $this->createStub(FilesystemFactory::class),
            ['max_upload_size' => 3],
        );

        try {
            $this->expectException(ExcelException::class);
            $this->expectExceptionMessage('maximum size');
            $resolver->localize($path);
        } finally {
            @unlink($path);
        }
    }

    public function testItRejectsFailedUploadsBeforeOpeningTheirStream(): void
    {
        $upload = $this->createStub(UploadedFileInterface::class);
        $upload->method('getError')->willReturn(UPLOAD_ERR_INI_SIZE);
        $resolver = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));

        $this->expectException(ExcelException::class);
        $this->expectExceptionMessage('error code [1]');
        $resolver->localize($upload);
    }

    public function testItRejectsArchivePathTraversal(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-unsafe-') . '.xlsx';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('../evil.xml', 'payload');
        $zip->close();

        $this->expectException(ExcelException::class);
        $this->expectExceptionMessage('unsafe path');
        try {
            $this->reader()->read(new \stdClass(), $path);
        } finally {
            @unlink($path);
        }
    }

    public function testItLimitsArchiveEntryCount(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-entries-') . '.xlsx';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('first.xml', 'first');
        $zip->addFromString('second.xml', 'second');
        $zip->close();

        try {
            $guard = new ArchiveGuard(['max_archive_entries' => 1]);
            $this->expectException(ExcelException::class);
            $this->expectExceptionMessage('too many entries');
            $guard->assertSafe($path, Excel::XLSX);
        } finally {
            @unlink($path);
        }
    }

    public function testArchiveEntryLimitCanBeDisabled(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-entries-disabled-') . '.xlsx';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('first.xml', 'first');
        $zip->addFromString('second.xml', 'second');
        $zip->close();

        try {
            (new ArchiveGuard(['max_archive_entries' => 0]))->assertSafe($path, Excel::XLSX);
            self::assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function testItLimitsArchiveUncompressedSize(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-size-') . '.xlsx';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('payload.xml', 'oversized');
        $zip->close();

        try {
            $this->expectException(ExcelException::class);
            $this->expectExceptionMessage('uncompressed size');
            (new ArchiveGuard(['max_uncompressed_size' => 3]))->assertSafe($path, Excel::XLSX);
        } finally {
            @unlink($path);
        }
    }

    public function testItLimitsArchiveCompressionRatio(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-ratio-') . '.xlsx';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('payload.xml', str_repeat('a', 10000));
        $zip->close();

        try {
            $this->expectException(ExcelException::class);
            $this->expectExceptionMessage('compression ratio');
            (new ArchiveGuard(['max_compression_ratio' => 2]))->assertSafe($path, Excel::XLSX);
        } finally {
            @unlink($path);
        }
    }

    private function reader(): Reader
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        return new Reader($files, new EventBus($dispatcher), $this->createStub(ContainerInterface::class), [
            'transaction' => ['handler' => 'null'],
            'security' => [
                'allowed_reader_types' => [],
                'max_uncompressed_size' => 1024 * 1024,
                'max_compression_ratio' => 100,
            ],
        ]);
    }
}
