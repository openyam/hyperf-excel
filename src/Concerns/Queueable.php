<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface Queueable
{
    /** @return array<string, mixed> */
    public function toQueuePayload(): array;
    /** @param array<string, mixed> $payload */
    public function useQueuePayload(array $payload): void;
}
