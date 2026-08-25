<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\Context\ApplicationContext;
use OpenYam\HyperfExcel\Concerns\Queueable;
use OpenYam\HyperfExcel\Concerns\WithQueueCallbacks;
use OpenYam\HyperfExcel\Events\QueueCompleted;
use OpenYam\HyperfExcel\ExcelInterface;
use OpenYam\HyperfExcel\Queue\ExportJob;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;
use OpenYam\HyperfExcel\Result\OperationStatus;
use OpenYam\HyperfExcel\Support\OperationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class QueueJobTest extends TestCase
{
    public function testSuccessfulExportCompletesLifecycleAndDispatchesCallbackAndEvent(): void
    {
        $export = new class implements Queueable, WithQueueCallbacks {
            public ?string $completedId = null;
            public function toQueuePayload(): array
            {
                return [];
            }
            public function useQueuePayload(array $payload): void {}
            public function queueCompleted(string $operationId): void
            {
                $this->completedId = $operationId;
            }
            public function queueFailed(string $operationId, \Throwable $throwable): void {}
        };
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
        $events = new class implements EventDispatcherInterface {
            public array $events = [];
            public function dispatch(object $event): object
            {
                $this->events[] = $event;
                return $event;
            }
        };
        $excel = $this->createStub(ExcelInterface::class);
        $excel->method('store')->willReturnCallback(static function () use ($statuses): bool {
            $current = $statuses->find('operation-id');
            self::assertInstanceOf(OperationStatus::class, $current);
            $statuses->save($current->with(OperationStatus::RUNNING, 7));
            return true;
        });
        $services = [
            $export::class => $export,
            OperationStatusStoreInterface::class => $statuses,
            EventDispatcherInterface::class => $events,
            ExcelInterface::class => $excel,
        ];
        $container = new class ($services) implements ContainerInterface {
            public function __construct(private readonly array $services) {}
            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new \RuntimeException(sprintf('Unknown service [%s].', $id));
            }
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
        $previous = ApplicationContext::hasContainer() ? ApplicationContext::getContainer() : null;
        ApplicationContext::setContainer($container);

        try {
            (new ExportJob('operation-id', $export::class, [], 'report.xlsx', null, null))->handle();
        } finally {
            OperationContext::clear();
            if ($previous !== null) {
                ApplicationContext::setContainer($previous);
            } else {
                $property = new \ReflectionProperty(ApplicationContext::class, 'container');
                $property->setValue(null, null);
            }
        }

        $status = $statuses->find('operation-id');
        self::assertInstanceOf(OperationStatus::class, $status);
        self::assertSame(OperationStatus::COMPLETED, $status->status);
        self::assertSame(7, $status->processed);
        self::assertSame('report.xlsx', $status->result);
        self::assertSame('operation-id', $export->completedId);
        self::assertInstanceOf(QueueCompleted::class, $events->events[0]);
        self::assertNull(OperationContext::id());
    }

    public function testPayloadHydrationFailureMarksJobFailedAndClearsContext(): void
    {
        $export = new class implements Queueable {
            public function toQueuePayload(): array
            {
                return [];
            }
            public function useQueuePayload(array $payload): void
            {
                throw new \RuntimeException('invalid payload');
            }
        };
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
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $services = [
            $export::class => $export,
            OperationStatusStoreInterface::class => $statuses,
            EventDispatcherInterface::class => $events,
        ];
        $container = new class ($services) implements ContainerInterface {
            public function __construct(private readonly array $services) {}
            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new \RuntimeException(sprintf('Unknown service [%s].', $id));
            }
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
        $previous = ApplicationContext::hasContainer() ? ApplicationContext::getContainer() : null;
        ApplicationContext::setContainer($container);

        try {
            $job = new ExportJob('operation-id', $export::class, [], 'report.xlsx', null, null);
            $job->handle();
            self::fail('Expected payload hydration exception was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('invalid payload', $exception->getMessage());
            self::assertNull(OperationContext::id());
        } finally {
            OperationContext::clear();
            if ($previous !== null) {
                ApplicationContext::setContainer($previous);
            } else {
                $property = new \ReflectionProperty(ApplicationContext::class, 'container');
                $property->setValue(null, null);
            }
        }

        $status = $statuses->find('operation-id');
        self::assertInstanceOf(OperationStatus::class, $status);
        self::assertSame(OperationStatus::FAILED, $status->status);
        self::assertSame('invalid payload', $status->error);
    }
}
