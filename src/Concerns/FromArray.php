<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface FromArray
{
    /** @return array<array-key, mixed> */
    public function array(): array;
}
