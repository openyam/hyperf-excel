<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use Hyperf\Collection\Collection;

interface ToCollection
{
    /** @param Collection<array-key, mixed> $rows */
    public function collection(Collection $rows): void;
}
