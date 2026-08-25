<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use Hyperf\Collection\Collection;

interface FromCollection
{
    /** @return Collection<array-key, mixed> */
    public function collection(): Collection;
}
