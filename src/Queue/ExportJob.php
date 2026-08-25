<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Queue;

use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use OpenYam\HyperfExcel\Concerns\Queueable;
use OpenYam\HyperfExcel\ExcelInterface;
use OpenYam\HyperfExcel\Exceptions\ExcelException;

final class ExportJob extends Job
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $operationId,
        public string $exportClass,
        public array $payload,
        public string $path,
        public ?string $disk,
        public ?string $writerType,
        public array $options = [],
    ) {}

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        /** @var Queueable&object $export */
        $export = $container->get($this->exportClass);
        $statuses = $container->get(OperationStatusStoreInterface::class);
        JobLifecycle::run($container, $statuses, $export, $this->operationId, 'export', function () use ($container, $export, $statuses): array {
            $export->useQueuePayload($this->payload);
            if (! $container->get(ExcelInterface::class)->store($export, $this->path, $this->disk, $this->writerType, $this->options)) {
                throw new ExcelException(sprintf('Unable to store queued export [%s].', $this->path));
            }
            $progress = $statuses->find($this->operationId);
            return [
                'processed' => $progress !== null ? $progress->processed : 0,
                'skipped' => $progress !== null ? $progress->skipped : 0,
                'result' => $this->path,
            ];
        });
    }
}
