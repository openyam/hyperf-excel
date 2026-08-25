<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use Throwable;

trait SkipsErrors
{
    /** @var Throwable[] */
    private array $importErrors = [];

    public function onError(Throwable $error): void
    {
        $this->importErrors[] = $error;
    }

    /** @return Throwable[] */
    public function errors(): array
    {
        return $this->importErrors;
    }
}
