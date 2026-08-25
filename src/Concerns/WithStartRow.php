<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithStartRow
{
    public function startRow(): int;
}
