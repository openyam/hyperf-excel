<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithMapping
{
    /** @return array<array-key, mixed> */
    public function map(mixed $row): array;
}
