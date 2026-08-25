<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel;

final class Row
{
    /** @param array<array-key, mixed> $values */
    public function __construct(private readonly array $values, private readonly int $index) {}

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
    public function getIndex(): int
    {
        return $this->index;
    }
    public function get(string|int $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}
