<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Result;

final class Progress
{
    public function __construct(
        public readonly string $operation,
        public readonly ?string $operationId,
        public readonly ?string $sheet,
        public readonly int $processed,
        public readonly int $skipped,
        public readonly ?int $total,
    ) {}
}
