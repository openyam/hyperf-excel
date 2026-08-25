<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithChunkSize
{
    public function chunkSize(): int;
}
