<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use OpenYam\HyperfExcel\Exceptions\ExcelException;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Files\TemporaryFileStream;
use PHPUnit\Framework\TestCase;

final class TemporaryFileStreamTest extends TestCase
{
    public function testTemporaryResourcesUseOwnerOnlyPermissions(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('fileperms')) {
            self::markTestSkipped('POSIX file permissions are not available.');
        }
        $root = sys_get_temp_dir() . '/hyperf-excel-permissions-' . bin2hex(random_bytes(6));
        $file = (new TemporaryFileManager($root))->make('txt');
        $directory = dirname($file->path());

        try {
            self::assertSame(0700, fileperms($directory) & 0777);
            self::assertSame(0600, fileperms($file->path()) & 0777);
        } finally {
            $file->delete();
            @rmdir($root);
        }
    }

    public function testTemporaryFileCreationFailureDoesNotLeaveArtifacts(): void
    {
        $root = tempnam(sys_get_temp_dir(), 'hyperf-excel-invalid-root-');
        self::assertIsString($root);
        try {
            $this->expectException(ExcelException::class);
            (new TemporaryFileManager($root))->make('txt');
        } finally {
            @unlink($root);
        }
    }

    public function testDeletingTemporaryFileTwiceIsSafe(): void
    {
        $file = (new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'))->make('txt');
        $path = $file->path();

        $file->delete();
        $file->delete();

        self::assertFileDoesNotExist($path);
    }

    public function testClosingStreamRemovesTemporaryFile(): void
    {
        $file = (new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'))->make('txt');
        file_put_contents($file->path(), 'spreadsheet');
        $path = $file->path();
        $stream = new TemporaryFileStream($file);
        self::assertSame('spreadsheet', $stream->getContents());
        $stream->close();
        self::assertFileDoesNotExist($path);
    }

    public function testReadingMissingTemporaryFileFailsExplicitly(): void
    {
        $file = (new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'))->make('txt');
        $file->delete();

        $this->expectException(ExcelException::class);
        $file->contents();
    }

    public function testDetachPreservesPositionAndTransfersReadableResource(): void
    {
        $file = (new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'))->make('txt');
        file_put_contents($file->path(), 'spreadsheet');
        $path = $file->path();
        $stream = new TemporaryFileStream($file);
        self::assertSame('sprea', $stream->read(5));

        $resource = $stream->detach();

        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isSeekable());
        self::assertFileDoesNotExist($path);
        self::assertSame('dsheet', stream_get_contents($resource));
        fclose($resource);
    }
}
