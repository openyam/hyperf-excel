<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Import;

use Hyperf\Collection\Collection;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenYam\HyperfExcel\Concerns\OnEachRow;
use OpenYam\HyperfExcel\Concerns\SkipsEmptyRows;
use OpenYam\HyperfExcel\Concerns\SkipsOnError;
use OpenYam\HyperfExcel\Concerns\ToArray;
use OpenYam\HyperfExcel\Concerns\ToCollection;
use OpenYam\HyperfExcel\Concerns\ToModel;
use OpenYam\HyperfExcel\Concerns\WithBatchInserts;
use OpenYam\HyperfExcel\Concerns\WithCalculatedFormulas;
use OpenYam\HyperfExcel\Concerns\WithColumnLimit;
use OpenYam\HyperfExcel\Concerns\WithHeadingFormatter;
use OpenYam\HyperfExcel\Concerns\WithHeadingRow;
use OpenYam\HyperfExcel\Concerns\WithLimit;
use OpenYam\HyperfExcel\Concerns\WithStartRow;
use OpenYam\HyperfExcel\Concerns\WithUpsertColumns;
use OpenYam\HyperfExcel\Concerns\WithUpserts;
use OpenYam\HyperfExcel\Concerns\WithValidation;
use OpenYam\HyperfExcel\Exceptions\InvalidConcernException;
use OpenYam\HyperfExcel\Result\Failure;
use OpenYam\HyperfExcel\Row;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Container\ContainerInterface;

/** @internal */
final class SheetProcessor
{
    public function __construct(private readonly ContainerInterface $container) {}

    /** @return array<int, array<array-key, mixed>> */
    public function extractRows(Worksheet $sheet, object $import, ?int $rangeStart = null, ?int $rangeEnd = null, ?int $maximum = null): array
    {
        $rows = [];
        foreach ($this->rowBatches($sheet, $import, $rangeStart, $rangeEnd, $maximum) as $batch) {
            $rows += $batch;
        }
        return $rows;
    }

    /** @return \Generator<int, array<int, array<array-key, mixed>>> */
    public function rowBatches(
        Worksheet $sheet,
        object $import,
        ?int $rangeStart = null,
        ?int $rangeEnd = null,
        ?int $maximum = null,
        int $batchSize = 1000,
    ): \Generator {
        $calculate = $import instanceof WithCalculatedFormulas;
        $endColumn = $import instanceof WithColumnLimit ? $import->endColumn() : $sheet->getHighestColumn();
        $highestRow = $rangeEnd ?? $sheet->getHighestDataRow();
        $start = $rangeStart ?? ($import instanceof WithStartRow ? max(1, $import->startRow()) : 1);
        $headings = null;
        if ($import instanceof WithHeadingRow) {
            $headingRow = max(1, $import->headingRow());
            $headings = $sheet->rangeToArray('A' . $headingRow . ':' . $endColumn . $headingRow, null, $calculate, false)[0] ?? [];
            $headings = $this->formatHeadings($headings, $import);
            $start = max($start, $headingRow + 1);
        }
        $limit = $maximum ?? ($import instanceof WithLimit ? max(0, $import->limit()) : PHP_INT_MAX);
        $batchSize = max(1, $batchSize);
        $accepted = 0;
        for ($blockStart = $start; $blockStart <= $highestRow && $accepted < $limit; $blockStart += $batchSize) {
            $blockEnd = min($highestRow, $blockStart + $batchSize - 1);
            $valuesByRow = $sheet->rangeToArray('A' . $blockStart . ':' . $endColumn . $blockEnd, null, $calculate, false);
            $batch = [];
            foreach ($valuesByRow as $offset => $values) {
                if ($accepted >= $limit) {
                    break;
                }
                $index = $blockStart + $offset;
                if ($headings !== null) {
                    $values = $this->combineHeadings($headings, $values);
                }
                if ($import instanceof SkipsEmptyRows && $this->isEmptyRow($values, $import)) {
                    continue;
                }
                $batch[$index] = $values;
                ++$accepted;
            }
            if ($batch !== []) {
                yield $batch;
            }
        }
    }

    /**
     * @param array<int, array<array-key, mixed>> $rows
     * @return array{array<int, array<array-key, mixed>>, int, list<Failure>}
     */
    public function validateRows(object $import, array $rows): array
    {
        if (! $import instanceof WithValidation) {
            return [$rows, 0, []];
        }
        $valid = [];
        $failures = [];
        $factory = $this->container->get(ValidatorFactoryInterface::class);
        foreach ($rows as $index => $row) {
            $prepared = method_exists($import, 'prepareForValidation') ? $import->prepareForValidation($row, $index) : $row;
            $validator = $factory->make($prepared, $import->rules(), method_exists($import, 'customValidationMessages') ? $import->customValidationMessages() : [], method_exists($import, 'customValidationAttributes') ? $import->customValidationAttributes() : []);
            if ($validator->fails()) {
                foreach ($validator->errors()->toArray() as $attribute => $errors) {
                    $failures[] = new Failure((int) $index, $attribute, $errors, $row);
                }
            } else {
                $valid[$index] = $prepared;
            }
        }
        return [$valid, count($rows) - count($valid), $failures];
    }

    /**
     * @param array<int, array<array-key, mixed>> $rows
     * @return array{int, int} Processed and skipped row counts.
     */
    public function consumeRows(object $import, array $rows): array
    {
        if ($import instanceof ToArray) {
            $import->array(array_values($rows));
            return [count($rows), 0];
        }
        if ($import instanceof ToCollection) {
            $import->collection(new Collection(array_values($rows)));
            return [count($rows), 0];
        }
        if ($import instanceof OnEachRow) {
            $errors = 0;
            foreach ($rows as $index => $row) {
                try {
                    if (method_exists($import, 'rememberRowNumber')) {
                        $import->rememberRowNumber((int) $index);
                    }
                    $import->onRow(new Row($row, (int) $index));
                } catch (\Throwable $e) {
                    if (! $import instanceof SkipsOnError) {
                        throw $e;
                    }
                    $import->onError($e);
                    ++$errors;
                }
            }
            return [count($rows) - $errors, $errors];
        }
        if ($import instanceof ToModel) {
            $count = $this->consumeModels($import, $rows);
            return [$count, count($rows) - $count];
        }
        throw new InvalidConcernException('Import must implement ToArray, ToCollection, OnEachRow or ToModel.');
    }

    /** @param array<int, array<array-key, mixed>> $rows */
    private function consumeModels(ToModel $import, array $rows): int
    {
        $models = [];
        foreach ($rows as $index => $row) {
            try {
                if (method_exists($import, 'rememberRowNumber')) {
                    $import->rememberRowNumber((int) $index);
                }
                if ($model = $import->model($row)) {
                    $models[] = $model;
                }
            } catch (\Throwable $e) {
                if (! $import instanceof SkipsOnError) {
                    throw $e;
                }
                $import->onError($e);
            }
        }
        if ($models === []) {
            return 0;
        }
        if ($import instanceof WithUpserts) {
            $values = array_map(static fn($model) => $model->getAttributes(), $models);
            $columns = $import instanceof WithUpsertColumns ? $import->upsertColumns() : null;
            $models[0]->newQuery()->upsert($values, $import->uniqueBy(), $columns);
            return count($models);
        }
        if ($import instanceof WithBatchInserts) {
            foreach (array_chunk($models, max(1, $import->batchSize())) as $batch) {
                $batch[0]->newQuery()->insert(array_map(static fn($model) => $model->getAttributes(), $batch));
            }
            return count($models);
        }
        foreach ($models as $model) {
            $model->save();
        }
        return count($models);
    }

    /**
     * @param array<array-key, mixed> $headings
     * @return list<string>
     */
    public function formatHeadings(array $headings, object $import): array
    {
        $formatter = $import instanceof WithHeadingFormatter ? $import->headingFormatter() : 'slug';
        $seen = [];
        $formatted = [];
        foreach (array_values($headings) as $index => $heading) {
            $key = is_callable($formatter)
                ? (string) $formatter($heading)
                : ($formatter === 'none' ? (string) $heading : trim((string) preg_replace('/[^\pL\pN]+/u', '_', mb_strtolower(trim((string) $heading))), '_'));
            $key = $key === '' ? 'column_' . ($index + 1) : $key;
            $count = ($seen[$key] ?? 0) + 1;
            $seen[$key] = $count;
            $formatted[] = $count === 1 ? $key : $key . '_' . $count;
        }
        return $formatted;
    }

    /**
     * @param list<string> $headings
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    public function combineHeadings(array &$headings, array $values): array
    {
        while ($values !== [] && end($values) === null) {
            array_pop($values);
        }
        while (count($headings) < count($values)) {
            $position = count($headings) + 1;
            $candidate = 'column_' . $position;
            $suffix = 1;
            while (in_array($candidate, $headings, true)) {
                $candidate = 'column_' . $position . '_' . ++$suffix;
            }
            $headings[] = $candidate;
        }

        return array_combine(array_slice($headings, 0, count($values)), $values);
    }

    /** @param array<array-key, mixed> $row */
    public function isEmptyRow(array $row, object $import): bool
    {
        if (method_exists($import, 'isEmptyWhen')) {
            return (bool) $import->isEmptyWhen($row);
        }
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }
        return true;
    }
}
