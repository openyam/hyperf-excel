<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel;

use Hyperf\Context\ApplicationContext;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

trait Importable
{
    public function import(string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): Result\ImportResult
    {
        return $this->excel()->import($this, $file, $disk, $readerType);
    }
    /** @param array<string, mixed> $options */
    public function queueImport(string $filePath, ?string $disk = null, ?string $readerType = null, array $options = []): Result\QueuedOperation
    {
        return $this->excel()->queueImport($this, $filePath, $disk, $readerType, $options);
    }
    /** @return list<array<int, array<array-key, mixed>>> */
    public function toArray(string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): array
    {
        return $this->excel()->toArray($this, $file, $disk, $readerType);
    }
    /** @return \Hyperf\Collection\Collection<int, \Hyperf\Collection\Collection<int, array<array-key, mixed>>> */
    public function toCollection(string|UploadedFileInterface|StreamInterface $file, ?string $disk = null, ?string $readerType = null): \Hyperf\Collection\Collection
    {
        return $this->excel()->toCollection($this, $file, $disk, $readerType);
    }
    private function excel(): ExcelInterface
    {
        return ApplicationContext::getContainer()->get(ExcelInterface::class);
    }
}
