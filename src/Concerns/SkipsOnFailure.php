<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use OpenYam\HyperfExcel\Result\Failure;

interface SkipsOnFailure
{
    /** @param Failure ...$failures */
    public function onFailure(...$failures): void;
}
