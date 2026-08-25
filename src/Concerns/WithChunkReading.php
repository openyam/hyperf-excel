<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithChunkReading
{
    public function chunkSize(): int;
}
