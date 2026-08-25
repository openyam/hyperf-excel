<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Queue;

use OpenYam\HyperfExcel\Concerns\Queueable;
use OpenYam\HyperfExcel\Concerns\WithQueueCallbacks;
use OpenYam\HyperfExcel\Events\QueueCompleted;
use OpenYam\HyperfExcel\Events\QueueFailed;
use OpenYam\HyperfExcel\Result\OperationStatus;
use OpenYam\HyperfExcel\Support\OperationContext;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/** @internal */
final class JobLifecycle
{
    /**
     * @param Queueable&object $owner
     * @param callable(): array{processed: int, skipped: int, result: ?string} $operation
     */
    public static function run(
        ContainerInterface $container,
        OperationStatusStoreInterface $statuses,
        object $owner,
        string $operationId,
        string $operationType,
        callable $operation,
    ): void {
        $statuses->save(new OperationStatus($operationId, $operationType, OperationStatus::RUNNING));
        OperationContext::set($operationId);
        try {
            try {
                $result = $operation();
            } catch (Throwable $e) {
                $current = $statuses->find($operationId) ?? new OperationStatus($operationId, $operationType);
                $statuses->save($current->markFailed($e->getMessage()));
                self::notifyFailure($container, $owner, $operationId, $operationType, $e);
                throw $e;
            }
            $current = $statuses->find($operationId) ?? new OperationStatus($operationId, $operationType);
            $statuses->save($current->markCompleted(
                $result['processed'],
                $result['skipped'],
                $result['result'],
            ));
            $notificationError = null;
            if ($owner instanceof WithQueueCallbacks) {
                try {
                    $owner->queueCompleted($operationId);
                } catch (Throwable $e) {
                    $notificationError = $e;
                }
            }
            try {
                $container->get(EventDispatcherInterface::class)->dispatch(new QueueCompleted($operationId, $operationType));
            } catch (Throwable $e) {
                $notificationError ??= $e;
            }
            if ($notificationError !== null) {
                throw $notificationError;
            }
        } finally {
            OperationContext::clear();
        }
    }

    /** @param Queueable&object $owner */
    private static function notifyFailure(ContainerInterface $container, object $owner, string $operationId, string $operationType, Throwable $error): void
    {
        if ($owner instanceof WithQueueCallbacks) {
            try {
                $owner->queueFailed($operationId, $error);
            } catch (Throwable) {
            }
        }
        try {
            $container->get(EventDispatcherInterface::class)->dispatch(new QueueFailed($operationId, $operationType, $error));
        } catch (Throwable) {
        }
    }
}
