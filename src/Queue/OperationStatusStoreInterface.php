<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Queue;

use OpenYam\HyperfExcel\Result\OperationStatus;

interface OperationStatusStoreInterface
{
    public function save(OperationStatus $status): void;

    public function find(string $operationId): ?OperationStatus;
}
