<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Export;

use OpenYam\HyperfExcel\Concerns\FromArray;
use OpenYam\HyperfExcel\Concerns\FromCollection;
use OpenYam\HyperfExcel\Concerns\FromIterator;
use OpenYam\HyperfExcel\Concerns\FromQuery;
use OpenYam\HyperfExcel\Exceptions\InvalidConcernException;

/** @internal */
final class RowSource
{
    public function each(object $export, callable $consumer): void
    {
        if ($export instanceof FromArray) {
            foreach ($export->array() as $row) {
                $consumer($row);
            }
            return;
        }
        if ($export instanceof FromCollection) {
            foreach ($export->collection() as $row) {
                $consumer($row);
            }
            return;
        }
        if ($export instanceof FromIterator) {
            foreach ($export->iterator() as $row) {
                $consumer($row);
            }
            return;
        }
        if ($export instanceof FromQuery) {
            $query = $export->query();
            foreach ($query->cursor() as $row) {
                $consumer($row);
            }
            return;
        }
        throw new InvalidConcernException('Export must implement FromArray, FromCollection, FromIterator, FromQuery or FromView.');
    }

    /** @return array<array-key, mixed> */
    public function normalize(mixed $row): array
    {
        if (is_array($row)) {
            return $row;
        }
        if ($row instanceof \JsonSerializable) {
            return (array) $row->jsonSerialize();
        }
        if (is_object($row) && method_exists($row, 'toArray')) {
            return $row->toArray();
        }
        if (is_object($row)) {
            return get_object_vars($row);
        }
        throw new InvalidConcernException('Export rows must be arrays or objects.');
    }
}
