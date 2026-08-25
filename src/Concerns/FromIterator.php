<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface FromIterator
{
    /** @return iterable<array-key, mixed> */
    public function iterator(): iterable;
}
