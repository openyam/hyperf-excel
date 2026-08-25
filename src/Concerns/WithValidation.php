<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithValidation
{
    /** @return array<string, mixed> */
    public function rules(): array;
}
