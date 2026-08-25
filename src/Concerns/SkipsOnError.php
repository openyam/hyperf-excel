<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use Throwable;

interface SkipsOnError
{
    public function onError(Throwable $error): void;
}
