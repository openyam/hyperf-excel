<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithSheetSelection
{
    /** @return list<int|string> */
    public function sheets(): array;
}
