<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Result;

final class QueuedOperation
{
    public function __construct(public readonly string $id, public readonly bool $accepted) {}
}
