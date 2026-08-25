<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithColumnFormatting
{
    /** @return array<string, string> */
    public function columnFormats(): array;
}
