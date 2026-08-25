<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use Hyperf\Database\Model\Model;

interface ToModel
{
    /** @param array<array-key, mixed> $row */
    public function model(array $row): ?Model;
}
