<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Files;

use OpenYam\HyperfExcel\Exceptions\ExcelException;

final class TemporaryFileManager
{
    public function __construct(private readonly string $root) {}

    public function make(string $extension = 'tmp'): TemporaryFile
    {
        $directory = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12));
        if (! @mkdir($directory, 0700, true)) {
            throw new ExcelException(sprintf('Unable to create temporary directory [%s].', $directory));
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'spreadsheet.' . strtolower($extension);
        $stream = @fopen($path, 'x+b');
        if (! is_resource($stream)) {
            @rmdir($directory);
            throw new ExcelException(sprintf('Unable to create temporary spreadsheet [%s].', $path));
        }
        try {
            if (! @chmod($path, 0600)) {
                throw new ExcelException(sprintf('Unable to secure temporary spreadsheet [%s].', $path));
            }
        } catch (\Throwable $exception) {
            fclose($stream);
            @unlink($path);
            @rmdir($directory);
            throw $exception;
        }
        fclose($stream);
        return new TemporaryFile($path);
    }
}
