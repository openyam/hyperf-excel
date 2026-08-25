<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Support;

use Hyperf\Context\Context;

final class OperationContext
{
    private const KEY = 'hyperf-excel.operation-id';

    public static function set(string $operationId): void
    {
        Context::set(self::KEY, $operationId);
    }

    public static function id(): ?string
    {
        $id = Context::get(self::KEY);
        return is_string($id) ? $id : null;
    }

    public static function clear(): void
    {
        Context::destroy(self::KEY);
    }
}
