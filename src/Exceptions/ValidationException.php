<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Exceptions;

use OpenYam\HyperfExcel\Result\Failure;

final class ValidationException extends ExcelException
{
    /** @param Failure[] $failures */
    public function __construct(public readonly array $failures)
    {
        parent::__construct('The spreadsheet contains invalid rows.');
    }
}
