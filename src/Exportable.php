<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel;

use Hyperf\Context\ApplicationContext;

trait Exportable
{
    /** @param array<string, string|string[]> $headers */
    public function download(string $fileName, ?string $writerType = null, array $headers = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->excel()->download($this, $fileName, $writerType, $headers);
    }
    /** @param array<string, mixed> $options */
    public function store(string $filePath, ?string $disk = null, ?string $writerType = null, array $options = []): bool
    {
        return $this->excel()->store($this, $filePath, $disk, $writerType, $options);
    }
    /** @param array<string, mixed> $options */
    public function queue(string $filePath, ?string $disk = null, ?string $writerType = null, array $options = []): Result\QueuedOperation
    {
        return $this->excel()->queue($this, $filePath, $disk, $writerType, $options);
    }
    public function raw(string $writerType): string
    {
        return $this->excel()->raw($this, $writerType);
    }
    private function excel(): ExcelInterface
    {
        return ApplicationContext::getContainer()->get(ExcelInterface::class);
    }
}
