<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Result;

final class ReaderContext
{
    /** @param array<int, array<string, mixed>> $worksheets */
    public function __construct(
        public readonly string $readerType,
        public readonly array $worksheets,
        public readonly bool $chunked,
    ) {}
}
