<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Events;

use Throwable;

final class ImportFailed
{
    public function __construct(public readonly object $import, public readonly Throwable $exception) {}
}
