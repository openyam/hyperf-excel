<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Events;

final class QueueCompleted
{
    public function __construct(public readonly string $operationId, public readonly string $operation) {}
}
