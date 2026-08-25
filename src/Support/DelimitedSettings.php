<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Support;

use OpenYam\HyperfExcel\Concerns\WithCustomCsvSettings;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Exceptions\ExcelException;

/** @internal */
final class DelimitedSettings
{
    private function __construct(
        public readonly string $delimiter,
        public readonly string $enclosure,
        public readonly string $escapeCharacter,
        public readonly string $inputEncoding,
        public readonly bool $useBom,
        public readonly bool $includeSeparatorLine,
        public readonly bool $excelCompatibility,
    ) {}

    /** @param array<string, mixed> $config */
    public static function resolve(array $config, object $owner, string $type): self
    {
        $settings = array_merge($config['csv'] ?? [], $owner instanceof WithCustomCsvSettings ? $owner->getCsvSettings() : []);
        $delimiter = $type === Excel::TSV ? "\t" : (string) ($settings['delimiter'] ?? ',');
        $enclosure = (string) ($settings['enclosure'] ?? '"');
        $escape = (string) ($settings['escape_character'] ?? '\\');
        $encoding = (string) ($settings['input_encoding'] ?? 'UTF-8');

        self::assertSingleByte($delimiter, 'delimiter');
        self::assertSingleByte($enclosure, 'enclosure');
        if ($escape !== '') {
            self::assertSingleByte($escape, 'escape_character');
        }
        self::assertEncoding($encoding);

        return new self(
            $delimiter,
            $enclosure,
            $escape,
            $encoding,
            (bool) ($settings['use_bom'] ?? false),
            (bool) ($settings['include_separator_line'] ?? false),
            (bool) ($settings['excel_compatibility'] ?? false),
        );
    }

    public function outputDelimiter(): string
    {
        return $this->excelCompatibility ? ';' : $this->delimiter;
    }

    public function outputEnclosure(): string
    {
        return $this->excelCompatibility ? '"' : $this->enclosure;
    }

    public function outputLineEnding(): string
    {
        return $this->excelCompatibility ? "\r\n" : "\n";
    }

    public function shouldUseBom(): bool
    {
        return $this->useBom || $this->excelCompatibility;
    }

    public function shouldIncludeSeparatorLine(string $type): bool
    {
        return $type === Excel::CSV && ($this->includeSeparatorLine || $this->excelCompatibility);
    }

    private static function assertSingleByte(string $value, string $name): void
    {
        if (strlen($value) !== 1) {
            throw new ExcelException(sprintf('CSV setting [%s] must be a single-byte character.', $name));
        }
    }

    private static function assertEncoding(string $encoding): void
    {
        try {
            if ($encoding !== '' && mb_check_encoding('', $encoding)) {
                return;
            }
        } catch (\ValueError) {
        }

        throw new ExcelException(sprintf('CSV input encoding [%s] is not supported.', $encoding));
    }
}
