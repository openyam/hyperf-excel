<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use OpenYam\HyperfExcel\Concerns\Queueable;
use OpenYam\HyperfExcel\Concerns\WithQueueCallbacks;
use OpenYam\HyperfExcel\Events\QueueCompleted;
use OpenYam\HyperfExcel\Events\QueueFailed;
use OpenYam\HyperfExcel\Queue\JobLifecycle;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;
use OpenYam\HyperfExcel\Result\OperationStatus;
use OpenYam\HyperfExcel\Support\OperationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class JobLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        OperationContext::clear();
    }

    public function testCompletionNotificationFailuresKeepCompletedStatusAndAttemptEveryNotification(): void
    {
        $owner = new LifecycleOwner();
        $owner->completedError = new \RuntimeException('callback failed');
        $events = new LifecycleEvents();
        $events->completedError = new \RuntimeException('event failed');
        $statuses = new LifecycleStatuses();

        try {
            JobLifecycle::run($this->container($events), $statuses, $owner, 'operation-id', 'export', static fn(): array => [
                'processed' => 12,
                'skipped' => 2,
                'result' => 'report.xlsx',
            ]);
            self::fail('Expected the first completion notification exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('callback failed', $exception->getMessage());
        }

        $status = $statuses->find('operation-id');
        self::assertInstanceOf(OperationStatus::class, $status);
        self::assertSame(OperationStatus::COMPLETED, $status->status);
        self::assertSame(12, $status->processed);
        self::assertSame(2, $status->skipped);
        self::assertSame('report.xlsx', $status->result);
        self::assertSame(1, $owner->completedCalls);
        self::assertSame(1, $events->completedCalls);
        self::assertNull(OperationContext::id());
    }

    public function testCompletionEventFailureKeepsCompletedStatus(): void
    {
        $owner = new LifecycleOwner();
        $events = new LifecycleEvents();
        $events->completedError = new \RuntimeException('event failed');
        $statuses = new LifecycleStatuses();

        try {
            JobLifecycle::run($this->container($events), $statuses, $owner, 'operation-id', 'import', static fn(): array => [
                'processed' => 3,
                'skipped' => 0,
                'result' => null,
            ]);
            self::fail('Expected the completion event exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('event failed', $exception->getMessage());
        }

        self::assertSame(OperationStatus::COMPLETED, $statuses->find('operation-id')?->status);
        self::assertNull(OperationContext::id());
    }

    public function testFailureNotificationErrorsNeverReplaceTheOperationError(): void
    {
        $owner = new LifecycleOwner();
        $owner->failedError = new \RuntimeException('callback failed');
        $events = new LifecycleEvents();
        $events->failedError = new \RuntimeException('event failed');
        $statuses = new LifecycleStatuses();

        try {
            JobLifecycle::run($this->container($events), $statuses, $owner, 'operation-id', 'import', static function (): array {
                throw new \RuntimeException('operation failed');
            });
            self::fail('Expected the operation exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('operation failed', $exception->getMessage());
        }

        $status = $statuses->find('operation-id');
        self::assertInstanceOf(OperationStatus::class, $status);
        self::assertSame(OperationStatus::FAILED, $status->status);
        self::assertSame('operation failed', $status->error);
        self::assertSame(1, $owner->failedCalls);
        self::assertSame(1, $events->failedCalls);
        self::assertNull(OperationContext::id());
    }

    public function testExplicitStatusTransitionsClearStaleNullableFields(): void
    {
        $failed = new OperationStatus('id', 'export', OperationStatus::FAILED, 4, 1, 10, 'old-result', 'old-error');

        $running = $failed->markRunning(5, 2, null);
        self::assertSame(OperationStatus::RUNNING, $running->status);
        self::assertNull($running->total);
        self::assertNull($running->result);
        self::assertNull($running->error);

        $completed = $running->markCompleted(8, 2, 'new-result');
        self::assertSame('new-result', $completed->result);
        self::assertNull($completed->error);

        $againFailed = $completed->markFailed('new-error');
        self::assertNull($againFailed->result);
        self::assertSame('new-error', $againFailed->error);
    }

    private function container(LifecycleEvents $events): ContainerInterface
    {
        return new class ($events) implements ContainerInterface {
            public function __construct(private readonly LifecycleEvents $events) {}
            public function get(string $id): mixed
            {
                return $id === EventDispatcherInterface::class ? $this->events : throw new \RuntimeException(sprintf('Unknown service [%s].', $id));
            }
            public function has(string $id): bool
            {
                return $id === EventDispatcherInterface::class;
            }
        };
    }
}

final class LifecycleOwner implements Queueable, WithQueueCallbacks
{
    public int $completedCalls = 0;
    public int $failedCalls = 0;
    public ?\Throwable $completedError = null;
    public ?\Throwable $failedError = null;

    public function toQueuePayload(): array
    {
        return [];
    }

    public function useQueuePayload(array $payload): void {}

    public function queueCompleted(string $operationId): void
    {
        ++$this->completedCalls;
        if ($this->completedError !== null) {
            throw $this->completedError;
        }
    }

    public function queueFailed(string $operationId, \Throwable $throwable): void
    {
        ++$this->failedCalls;
        if ($this->failedError !== null) {
            throw $this->failedError;
        }
    }
}

final class LifecycleEvents implements EventDispatcherInterface
{
    public int $completedCalls = 0;
    public int $failedCalls = 0;
    public ?\Throwable $completedError = null;
    public ?\Throwable $failedError = null;

    public function dispatch(object $event): object
    {
        if ($event instanceof QueueCompleted) {
            ++$this->completedCalls;
            if ($this->completedError !== null) {
                throw $this->completedError;
            }
        }
        if ($event instanceof QueueFailed) {
            ++$this->failedCalls;
            if ($this->failedError !== null) {
                throw $this->failedError;
            }
        }
        return $event;
    }
}

final class LifecycleStatuses implements OperationStatusStoreInterface
{
    /** @var array<string, OperationStatus> */
    private array $items = [];

    public function save(OperationStatus $status): void
    {
        $this->items[$status->id] = $status;
    }

    public function find(string $operationId): ?OperationStatus
    {
        return $this->items[$operationId] ?? null;
    }
}
