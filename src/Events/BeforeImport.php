<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Events;

use OpenYam\HyperfExcel\Result\ReaderContext;

final class BeforeImport
{
    public function __construct(public readonly object $import, public readonly ReaderContext $context) {}
}
