<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithUpsertColumns
{
    /** @return list<string> */
    public function upsertColumns(): array;
}
