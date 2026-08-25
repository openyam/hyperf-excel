<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Result;

final class Failure implements \JsonSerializable
{
    /**
     * @param list<string> $errors
     * @param array<array-key, mixed> $values
     */
    public function __construct(
        public readonly int $row,
        public readonly string|int $attribute,
        public readonly array $errors,
        public readonly array $values = [],
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
