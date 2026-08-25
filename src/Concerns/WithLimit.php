<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithLimit
{
    public function limit(): int;
}
