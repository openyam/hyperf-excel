<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithHeadings
{
    /** @return array<array-key, mixed> */
    public function headings(): array;
}
