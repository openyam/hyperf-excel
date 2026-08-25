<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Result;

final class OperationStatus implements \JsonSerializable
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $id,
        public readonly string $operation,
        public readonly string $status = self::PENDING,
        public readonly int $processed = 0,
        public readonly int $skipped = 0,
        public readonly ?int $total = null,
        public readonly ?string $result = null,
        public readonly ?string $error = null,
    ) {}

    public function with(string $status, ?int $processed = null, ?int $skipped = null, ?int $total = null, ?string $result = null, ?string $error = null): self
    {
        return new self($this->id, $this->operation, $status, $processed ?? $this->processed, $skipped ?? $this->skipped, $total ?? $this->total, $result ?? $this->result, $error ?? $this->error);
    }

    public function markRunning(int $processed, int $skipped = 0, ?int $total = null): self
    {
        return new self($this->id, $this->operation, self::RUNNING, $processed, $skipped, $total);
    }

    public function markCompleted(int $processed, int $skipped, ?string $result = null): self
    {
        return new self($this->id, $this->operation, self::COMPLETED, $processed, $skipped, $this->total, $result);
    }

    public function markFailed(string $error): self
    {
        return new self($this->id, $this->operation, self::FAILED, $this->processed, $this->skipped, $this->total, error: $error);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
