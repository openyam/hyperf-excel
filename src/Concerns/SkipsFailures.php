<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use OpenYam\HyperfExcel\Result\Failure;

trait SkipsFailures
{
    /** @var Failure[] */
    private array $importFailures = [];

    public function onFailure(...$failures): void
    {
        array_push($this->importFailures, ...$failures);
    }

    /** @return Failure[] */
    public function failures(): array
    {
        return $this->importFailures;
    }
}
