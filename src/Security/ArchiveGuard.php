<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Security;

use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Exceptions\ExcelException;

/** @internal */
final class ArchiveGuard
{
    /** @param array<string, mixed> $security */
    public function __construct(private readonly array $security = []) {}

    public function assertSafe(string $path, string $type): void
    {
        if (! in_array($type, [Excel::XLSX, Excel::ODS], true) || ! class_exists(\ZipArchive::class)) {
            return;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ExcelException('Unable to open spreadsheet archive.');
        }
        $total = 0;
        $maxTotal = (int) ($this->security['max_uncompressed_size'] ?? 0);
        $maxRatio = (float) ($this->security['max_compression_ratio'] ?? 0);
        $maxEntries = (int) ($this->security['max_archive_entries'] ?? 10000);
        try {
            if ($maxEntries > 0 && $zip->numFiles > $maxEntries) {
                throw new ExcelException('Spreadsheet archive contains too many entries.');
            }
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    throw new ExcelException('Unable to inspect spreadsheet archive entry.');
                }
                $name = $stat['name'];
                $size = $stat['size'];
                $compressed = $stat['comp_size'];
                if ($size < 0 || $compressed < 0) {
                    throw new ExcelException('Spreadsheet archive contains invalid entry metadata.');
                }
                $this->assertSafePath($name);
                if ($size > PHP_INT_MAX - $total) {
                    throw new ExcelException('Spreadsheet archive size exceeds the supported range.');
                }
                $total += $size;
                if ($maxTotal > 0 && $total > $maxTotal) {
                    throw new ExcelException('Spreadsheet archive exceeds the configured uncompressed size.');
                }
                if ($maxRatio > 0 && $size > 0 && ($compressed === 0 || $size / $compressed > $maxRatio)) {
                    throw new ExcelException('Spreadsheet archive exceeds the configured compression ratio.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function assertSafePath(string $name): void
    {
        if (str_contains($name, "\0")) {
            throw new ExcelException('Spreadsheet archive contains an unsafe path.');
        }
        $normalized = str_replace('\\', '/', $name);
        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            throw new ExcelException('Spreadsheet archive contains an unsafe path.');
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new ExcelException('Spreadsheet archive contains an unsafe path.');
            }
        }
    }
}
