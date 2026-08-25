<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Events;

use Throwable;

final class QueueFailed
{
    public function __construct(public readonly string $operationId, public readonly string $operation, public readonly Throwable $exception) {}
}
