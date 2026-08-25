<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Events;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BeforeSheet
{
    public function __construct(public readonly object $export, public readonly Worksheet $sheet) {}
}
