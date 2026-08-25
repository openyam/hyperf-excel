<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Result;

final class ImportResult
{
    /**
     * @param list<Failure> $failures
     * @param array<string, int> $sheets
     */
    public function __construct(
        public readonly int $processed = 0,
        public readonly int $skipped = 0,
        public readonly array $failures = [],
        public readonly array $sheets = [],
    ) {}

    public function successful(): bool
    {
        return $this->failures === [];
    }
}
