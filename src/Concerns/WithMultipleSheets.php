<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithMultipleSheets
{
    /** @return array<int|string, object> */
    public function sheets(): array;
}
