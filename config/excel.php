<?php

declare(strict_types=1);

use OpenYam\HyperfExcel\Excel;

return [
    'temporary_path' => BASE_PATH . '/runtime/container/hyperf-excel',
    'chunk_size' => 1000,
    'read_batch_size' => 1000,
    'transaction' => [
        'handler' => 'db',
        'connection' => null,
    ],
    'queue' => [
        'driver' => 'default',
        'delay' => 0,
        'max_attempts' => 0,
        'status_store' => \OpenYam\HyperfExcel\Queue\NullOperationStatusStore::class,
    ],
    'csv' => [
        'delimiter' => ',',
        'enclosure' => '"',
        'escape_character' => '\\',
        'input_encoding' => 'UTF-8',
        'use_bom' => false,
        'include_separator_line' => false,
        'excel_compatibility' => false,
    ],
    'pdf' => [
        'driver' => Excel::DOMPDF,
    ],
    'security' => [
        'allowed_stream_wrappers' => ['file'],
        'allowed_reader_types' => [Excel::XLSX, Excel::XLS, Excel::CSV, Excel::TSV, Excel::ODS, Excel::HTML, Excel::SLK, Excel::GNUMERIC],
        'max_upload_size' => 50 * 1024 * 1024,
        'max_uncompressed_size' => 512 * 1024 * 1024,
        'max_compression_ratio' => 100,
        'max_archive_entries' => 10000,
        'formula_injection_protection' => true,
        'formula_injection_prefix' => "'",
    ],
];
