<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Queue;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use OpenYam\HyperfExcel\Concerns\Queueable;
use OpenYam\HyperfExcel\Concerns\ShouldQueue;
use OpenYam\HyperfExcel\Exceptions\InvalidConcernException;
use OpenYam\HyperfExcel\Result\OperationStatus;
use OpenYam\HyperfExcel\Result\QueuedOperation;

final class QueueDispatcher
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly DriverFactory $drivers, private readonly array $config = [], private readonly ?OperationStatusStoreInterface $statuses = null) {}

    /** @param array<string, mixed> $options */
    public function export(object $export, string $path, ?string $disk, ?string $writerType, array $options): QueuedOperation
    {
        $export = $this->queueable($export);
        $id = bin2hex(random_bytes(16));
        $storeOptions = array_diff_key($options, array_flip(['queue', 'delay', 'max_attempts']));
        $job = new ExportJob($id, $export::class, $export->toQueuePayload(), $path, $disk, $writerType, $storeOptions);
        $this->statuses?->save(new OperationStatus($id, 'export'));
        try {
            $accepted = $this->push($job, $options);
        } catch (\Throwable $e) {
            $this->statuses?->save(new OperationStatus($id, 'export', OperationStatus::FAILED, error: $e->getMessage()));
            throw $e;
        }
        if (! $accepted) {
            $this->statuses?->save(new OperationStatus($id, 'export', OperationStatus::FAILED, error: 'Queue dispatch was rejected.'));
        }
        return new QueuedOperation($id, $accepted);
    }

    /** @param array<string, mixed> $options */
    public function import(object $import, string $path, ?string $disk, ?string $readerType, array $options): QueuedOperation
    {
        $import = $this->queueable($import);
        $id = bin2hex(random_bytes(16));
        $job = new ImportJob($id, $import::class, $import->toQueuePayload(), $path, $disk, $readerType);
        $this->statuses?->save(new OperationStatus($id, 'import'));
        try {
            $accepted = $this->push($job, $options);
        } catch (\Throwable $e) {
            $this->statuses?->save(new OperationStatus($id, 'import', OperationStatus::FAILED, error: $e->getMessage()));
            throw $e;
        }
        if (! $accepted) {
            $this->statuses?->save(new OperationStatus($id, 'import', OperationStatus::FAILED, error: 'Queue dispatch was rejected.'));
        }
        return new QueuedOperation($id, $accepted);
    }

    /** @param array<string, mixed> $options */
    private function push(ExportJob|ImportJob $job, array $options): bool
    {
        $driver = (string) ($options['queue'] ?? $this->config['driver'] ?? 'default');
        $delay = (int) ($options['delay'] ?? $this->config['delay'] ?? 0);
        $job->setMaxAttempts(max(0, (int) ($options['max_attempts'] ?? $this->config['max_attempts'] ?? 0)));
        return $this->drivers->get($driver)->push($job, $delay);
    }

    private function queueable(object $operation): Queueable&ShouldQueue
    {
        if (! $operation instanceof ShouldQueue || ! $operation instanceof Queueable) {
            throw new InvalidConcernException('Queued imports and exports must implement ShouldQueue and Queueable.');
        }
        return $operation;
    }
}
