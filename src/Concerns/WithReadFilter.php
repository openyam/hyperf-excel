<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

interface WithReadFilter
{
    public function readFilter(): IReadFilter;
}
