<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use Throwable;

interface WithQueueCallbacks
{
    public function queueCompleted(string $operationId): void;
    public function queueFailed(string $operationId, Throwable $throwable): void;
}
