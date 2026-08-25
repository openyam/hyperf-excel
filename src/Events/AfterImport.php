<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Events;

use OpenYam\HyperfExcel\Result\ImportResult;

final class AfterImport
{
    public function __construct(public readonly object $import, public readonly ImportResult $result) {}
}
