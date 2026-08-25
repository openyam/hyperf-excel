<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithEvents
{
    /** @return array<class-string, callable(object): void> */
    public function registerEvents(): array;
}
