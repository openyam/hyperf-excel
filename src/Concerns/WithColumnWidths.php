<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithColumnWidths
{
    /** @return array<string, int|float> */
    public function columnWidths(): array;
}
