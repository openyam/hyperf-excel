<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use OpenYam\HyperfExcel\Row;

interface OnEachRow
{
    public function onRow(Row $row): void;
}
