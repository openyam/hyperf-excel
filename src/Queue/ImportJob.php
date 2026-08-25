<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Queue;

use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use OpenYam\HyperfExcel\Concerns\Queueable;
use OpenYam\HyperfExcel\ExcelInterface;

final class ImportJob extends Job
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $operationId,
        public string $importClass,
        public array $payload,
        public string $path,
        public ?string $disk,
        public ?string $readerType,
    ) {}

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        /** @var Queueable&object $import */
        $import = $container->get($this->importClass);
        $statuses = $container->get(OperationStatusStoreInterface::class);
        JobLifecycle::run($container, $statuses, $import, $this->operationId, 'import', function () use ($container, $import): array {
            $import->useQueuePayload($this->payload);
            $result = $container->get(ExcelInterface::class)->import($import, $this->path, $this->disk, $this->readerType);
            return ['processed' => $result->processed, 'skipped' => $result->skipped, 'result' => null];
        });
    }
}
