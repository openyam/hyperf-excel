<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithCustomCsvSettings
{
    /** @return array<string, mixed> */
    public function getCsvSettings(): array;
}
