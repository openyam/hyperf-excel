<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Events;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

final class BeforeExport
{
    public function __construct(public readonly object $export, public readonly Spreadsheet $spreadsheet) {}
}
