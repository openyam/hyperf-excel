<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithProperties
{
    /** @return array<string, mixed> */
    public function properties(): array;
}
