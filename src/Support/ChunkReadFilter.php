<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Support;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final class ChunkReadFilter implements IReadFilter
{
    public function __construct(
        private readonly string $sheet,
        private readonly int $startRow,
        private readonly int $endRow,
        private readonly ?int $headingRow = null,
        private readonly ?IReadFilter $inner = null,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($worksheetName !== '' && $worksheetName !== $this->sheet) {
            return false;
        }
        if ($row !== $this->headingRow && ($row < $this->startRow || $row > $this->endRow)) {
            return false;
        }
        return $this->inner?->readCell($columnAddress, $row, $worksheetName) ?? true;
    }
}
