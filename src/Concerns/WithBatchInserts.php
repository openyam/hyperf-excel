<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithBatchInserts
{
    public function batchSize(): int;
}
