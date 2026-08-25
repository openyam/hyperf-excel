<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

interface WithStyles
{
    /** @return array<string|int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array;
}
