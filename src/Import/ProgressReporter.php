<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Import;

use OpenYam\HyperfExcel\Concerns\WithProgress;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;
use OpenYam\HyperfExcel\Result\Progress;
use OpenYam\HyperfExcel\Support\OperationContext;

/** @internal */
final class ProgressReporter
{
    public function __construct(private readonly ?OperationStatusStoreInterface $statuses = null) {}

    public function report(object $owner, string $operation, ?string $sheet, int $processed, int $skipped, ?int $total): void
    {
        $operationId = OperationContext::id();
        if ($owner instanceof WithProgress) {
            $owner->onProgress(new Progress($operation, $operationId, $sheet, $processed, $skipped, $total));
        }
        if ($operationId !== null && $this->statuses !== null && ($status = $this->statuses->find($operationId)) !== null) {
            $this->statuses->save($status->markRunning($processed, $skipped, $total));
        }
    }
}
