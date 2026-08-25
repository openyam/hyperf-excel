<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface SkipsUnknownSheets
{
    public function onUnknownSheet(string $sheetName): void;
}
