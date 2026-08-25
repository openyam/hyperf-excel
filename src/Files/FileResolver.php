<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Files;

use Hyperf\Filesystem\FilesystemFactory;
use OpenYam\HyperfExcel\Exceptions\ExcelException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

final class FileResolver
{
    /** @param array<string, mixed> $security */
    public function __construct(
        private readonly TemporaryFileManager $temporaryFiles,
        private readonly FilesystemFactory $filesystems,
        private readonly array $security = [],
    ) {}

    public function localize(string|UploadedFileInterface|StreamInterface $file, ?string $disk = null): ResolvedFile
    {
        if ($file instanceof UploadedFileInterface) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new ExcelException(sprintf('Spreadsheet upload failed with error code [%d].', $file->getError()));
            }
            $name = $file->getClientFilename() ?: 'upload.xlsx';
            return $this->copyStream($file->getStream(), $name);
        }
        if ($file instanceof StreamInterface) {
            return $this->copyStream($file, 'stream.xlsx');
        }
        if ($disk !== null) {
            $stream = $this->filesystems->get($disk)->readStream($file);
            if (! is_resource($stream)) {
                throw new ExcelException(sprintf('Unable to read [%s] from disk [%s].', $file, $disk));
            }
            try {
                return $this->copyResource($stream, $file);
            } finally {
                fclose($stream);
            }
        }
        $this->assertSafeLocalPath($file);
        if (! is_file($file) || ! is_readable($file)) {
            throw new ExcelException(sprintf('Spreadsheet [%s] is not readable.', $file));
        }
        $max = (int) ($this->security['max_upload_size'] ?? 0);
        $size = filesize($file);
        if ($max > 0) {
            if ($size === false) {
                throw new ExcelException('Unable to determine spreadsheet size.');
            }
            if ($size > $max) {
                throw new ExcelException('Spreadsheet exceeds the configured maximum size.');
            }
        }
        return new ResolvedFile(realpath($file) ?: $file, null, $file);
    }

    /** @param array<string, mixed> $options */
    public function store(TemporaryFile $file, string $path, ?string $disk, array $options = []): bool
    {
        if ($disk === null) {
            $this->assertSafeLocalPath($path);
            $directory = dirname($path);
            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                return false;
            }
            $temporary = @tempnam($directory, '.hyperf-excel-');
            if ($temporary === false) {
                return false;
            }
            try {
                if (! @copy($file->path(), $temporary)) {
                    return false;
                }
                $permissions = is_file($path) ? (fileperms($path) & 0777) : (0666 & ~umask());
                if (! @chmod($temporary, $permissions)) {
                    return false;
                }
                return @rename($temporary, $path);
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }
        $stream = fopen($file->path(), 'rb');
        if (! is_resource($stream)) {
            throw new ExcelException(sprintf('Unable to open temporary spreadsheet [%s].', $file->path()));
        }
        try {
            $this->filesystems->get($disk)->writeStream($path, $stream, $options);
            return true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function copyStream(StreamInterface $stream, string $name): ResolvedFile
    {
        $temp = $this->temporaryFiles->make(pathinfo($name, PATHINFO_EXTENSION) ?: 'tmp');
        $target = fopen($temp->path(), 'wb');
        if (! is_resource($target)) {
            $temp->delete();
            throw new ExcelException('Unable to create a temporary spreadsheet stream.');
        }
        $written = 0;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        try {
            while (! $this->streamEof($stream)) {
                $chunk = $stream->read(1024 * 1024);
                if ($chunk === '' && ! $this->streamEof($stream)) {
                    throw new ExcelException('Spreadsheet stream stopped making progress.');
                }
                $written += strlen($chunk);
                $this->assertSize($written);
                $this->writeResource($target, $chunk);
            }
        } catch (\Throwable $e) {
            fclose($target);
            $temp->delete();
            throw $e;
        }
        fclose($target);
        return new ResolvedFile($temp->path(), $temp, $name);
    }

    /** @param resource $stream */
    private function copyResource($stream, string $name): ResolvedFile
    {
        $temp = $this->temporaryFiles->make(pathinfo($name, PATHINFO_EXTENSION) ?: 'tmp');
        $target = fopen($temp->path(), 'wb');
        if (! is_resource($target)) {
            $temp->delete();
            throw new ExcelException('Unable to create a temporary spreadsheet stream.');
        }
        $written = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new ExcelException(sprintf('Unable to read [%s].', $name));
                }
                if ($chunk === '' && ! feof($stream)) {
                    throw new ExcelException(sprintf('Spreadsheet stream [%s] stopped making progress.', $name));
                }
                $written += strlen($chunk);
                $this->assertSize($written);
                $this->writeResource($target, $chunk);
            }
            fclose($target);
            return new ResolvedFile($temp->path(), $temp, $name);
        } catch (\Throwable $e) {
            fclose($target);
            $temp->delete();
            throw $e;
        }
    }

    private function assertSize(int $size): void
    {
        $max = (int) ($this->security['max_upload_size'] ?? 0);
        if ($max > 0 && $size > $max) {
            throw new ExcelException('Spreadsheet exceeds the configured maximum size.');
        }
    }

    /** @phpstan-impure */
    private function streamEof(StreamInterface $stream): bool
    {
        return $stream->eof();
    }

    /** @param resource $target */
    private function writeResource($target, string $contents): void
    {
        $length = strlen($contents);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($target, substr($contents, $written));
            if ($count === false || $count === 0) {
                throw new ExcelException('Unable to write the complete temporary spreadsheet.');
            }
            $written += $count;
        }
    }

    private function assertSafeLocalPath(string $path): void
    {
        $scheme = parse_url($path, PHP_URL_SCHEME);
        $allowed = $this->security['allowed_stream_wrappers'] ?? ['file'];
        if ($scheme !== null && ! in_array(strtolower((string) $scheme), $allowed, true)) {
            throw new ExcelException(sprintf('Stream wrapper [%s] is not allowed.', $scheme));
        }
    }
}
