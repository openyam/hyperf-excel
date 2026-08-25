<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\AsyncQueue\JobInterface;
use OpenYam\HyperfExcel\Concerns\Queueable;
use OpenYam\HyperfExcel\Concerns\ShouldQueue;
use OpenYam\HyperfExcel\Queue\ExportJob;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;
use OpenYam\HyperfExcel\Queue\QueueDispatcher;
use OpenYam\HyperfExcel\Result\OperationStatus;
use PHPUnit\Framework\TestCase;

final class QueueDispatcherTest extends TestCase
{
    public function testItQueuesOnlySerializableBusinessPayload(): void
    {
        $driver = $this->createStub(DriverInterface::class);
        $driver->method('push')->willReturnCallback(static function (JobInterface $job, int $delay): bool {
            self::assertInstanceOf(ExportJob::class, $job);
            self::assertSame(['tenant_id' => 42], $job->payload);
            self::assertSame(5, $delay);
            self::assertSame(3, $job->getMaxAttempts());
            return true;
        });
        $factory = $this->createStub(DriverFactory::class);
        $factory->method('get')->willReturn($driver);
        $statuses = new class implements OperationStatusStoreInterface {
            /** @var array<string, OperationStatus> */
            public array $items = [];
            public function save(OperationStatus $status): void
            {
                $this->items[$status->id] = $status;
            }
            public function find(string $operationId): ?OperationStatus
            {
                return $this->items[$operationId] ?? null;
            }
        };
        $dispatcher = new QueueDispatcher($factory, [], $statuses);
        $export = new class implements ShouldQueue, Queueable {
            public function toQueuePayload(): array
            {
                return ['tenant_id' => 42];
            }
            public function useQueuePayload(array $payload): void {}
        };

        $operation = $dispatcher->export($export, 'reports/users.xlsx', 's3', null, ['queue' => 'reports', 'delay' => 5, 'max_attempts' => 3]);

        self::assertTrue($operation->accepted);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $operation->id);
        self::assertSame(OperationStatus::PENDING, $statuses->find($operation->id)?->status);
    }

    public function testItRecordsRejectedDispatchAsFailed(): void
    {
        $driver = $this->createStub(DriverInterface::class);
        $driver->method('push')->willReturn(false);
        $factory = $this->createStub(DriverFactory::class);
        $factory->method('get')->willReturn($driver);
        $statuses = new class implements OperationStatusStoreInterface {
            /** @var array<string, OperationStatus> */
            public array $items = [];
            public function save(OperationStatus $status): void
            {
                $this->items[$status->id] = $status;
            }
            public function find(string $operationId): ?OperationStatus
            {
                return $this->items[$operationId] ?? null;
            }
        };
        $export = new class implements ShouldQueue, Queueable {
            public function toQueuePayload(): array
            {
                return [];
            }
            public function useQueuePayload(array $payload): void {}
        };

        $operation = (new QueueDispatcher($factory, [], $statuses))->export($export, 'failed.xlsx', null, null, []);

        self::assertFalse($operation->accepted);
        self::assertSame(OperationStatus::FAILED, $statuses->find($operation->id)?->status);
    }

    public function testItRecordsDriverExceptionsAsFailedAndRethrows(): void
    {
        $driver = $this->createStub(DriverInterface::class);
        $driver->method('push')->willThrowException(new \RuntimeException('queue unavailable'));
        $factory = $this->createStub(DriverFactory::class);
        $factory->method('get')->willReturn($driver);
        $statuses = new class implements OperationStatusStoreInterface {
            /** @var array<string, OperationStatus> */
            public array $items = [];
            public function save(OperationStatus $status): void
            {
                $this->items[$status->id] = $status;
            }
            public function find(string $operationId): ?OperationStatus
            {
                return $this->items[$operationId] ?? null;
            }
        };
        $export = new class implements ShouldQueue, Queueable {
            public function toQueuePayload(): array
            {
                return [];
            }
            public function useQueuePayload(array $payload): void {}
        };

        try {
            (new QueueDispatcher($factory, [], $statuses))->export($export, 'failed.xlsx', null, null, []);
            self::fail('Expected queue driver exception was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('queue unavailable', $exception->getMessage());
        }

        self::assertCount(1, $statuses->items);
        $status = array_values($statuses->items)[0];
        self::assertSame(OperationStatus::FAILED, $status->status);
        self::assertSame('queue unavailable', $status->error);
    }
}
