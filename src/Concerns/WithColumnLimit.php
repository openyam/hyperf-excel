<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithColumnLimit
{
    public function endColumn(): string;
}
