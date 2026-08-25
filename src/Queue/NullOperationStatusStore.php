<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Queue;

use OpenYam\HyperfExcel\Result\OperationStatus;

final class NullOperationStatusStore implements OperationStatusStoreInterface
{
    public function save(OperationStatus $status): void {}

    public function find(string $operationId): ?OperationStatus
    {
        return null;
    }
}
