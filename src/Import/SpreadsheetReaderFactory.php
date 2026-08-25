<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Import;

use OpenYam\HyperfExcel\Concerns\WithReadFilter;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Exceptions\InvalidConcernException;
use OpenYam\HyperfExcel\Support\DelimitedSettings;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

/** @internal */
final class SpreadsheetReaderFactory
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function create(string $type, object $import): BaseReader
    {
        $reader = IOFactory::createReader($type === Excel::TSV ? Excel::CSV : $type);
        if (! $reader instanceof BaseReader) {
            throw new InvalidConcernException(sprintf('Reader [%s] does not support worksheet metadata.', $type));
        }
        $reader->setReadEmptyCells(false);
        $reader->setIgnoreRowsWithNoCells(true);
        if ($reader instanceof Csv) {
            $settings = DelimitedSettings::resolve($this->config, $import, $type);
            $reader->setDelimiter($settings->delimiter);
            $reader->setEnclosure($settings->enclosure);
            $reader->setEscapeCharacter($settings->escapeCharacter);
            $reader->setInputEncoding($settings->inputEncoding);
        }
        if ($import instanceof WithReadFilter) {
            $reader->setReadFilter($import->readFilter());
        }
        return $reader;
    }
}
