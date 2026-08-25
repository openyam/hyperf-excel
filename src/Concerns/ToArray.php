<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface ToArray
{
    /** @param list<array<array-key, mixed>> $rows */
    public function array(array $rows): void;
}
