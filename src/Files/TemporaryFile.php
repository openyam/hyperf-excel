<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Files;

use OpenYam\HyperfExcel\Exceptions\ExcelException;

final class TemporaryFile
{
    private bool $deleted = false;

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }
    public function contents(): string
    {
        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new ExcelException(sprintf('Unable to read temporary spreadsheet [%s].', $this->path));
        }
        return $contents;
    }

    public function delete(): void
    {
        if (! $this->deleted && is_file($this->path)) {
            @unlink($this->path);
        }
        $this->deleted = true;
        $directory = dirname($this->path);
        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }

    public function __destruct()
    {
        $this->delete();
    }
}
