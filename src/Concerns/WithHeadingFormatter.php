<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithHeadingFormatter
{
    /** @return 'slug'|'none'|callable */
    public function headingFormatter(): string|callable;
}
