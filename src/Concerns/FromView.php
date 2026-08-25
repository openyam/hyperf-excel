<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface FromView
{
    public function view(): string;
    /** @return array<array-key, mixed> */
    public function viewData(): array;
}
