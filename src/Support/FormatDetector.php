<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Support;

use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Exceptions\ExcelException;

final class FormatDetector
{
    private const FORMATS = [
        'xlsx' => Excel::XLSX, 'xlsm' => Excel::XLSX, 'xltx' => Excel::XLSX,
        'xls' => Excel::XLS, 'csv' => Excel::CSV, 'tsv' => Excel::TSV,
        'ods' => Excel::ODS, 'html' => Excel::HTML, 'htm' => Excel::HTML,
        'slk' => Excel::SLK, 'gnumeric' => Excel::GNUMERIC,
        'pdf' => Excel::DOMPDF,
    ];

    public static function detect(string $name, ?string $explicit = null): string
    {
        if ($explicit !== null) {
            return $explicit;
        }
        $extension = strtolower(pathinfo(parse_url($name, PHP_URL_PATH) ?: $name, PATHINFO_EXTENSION));
        return self::FORMATS[$extension] ?? throw new ExcelException(sprintf('Unable to detect spreadsheet type from [%s].', $name));
    }
}
