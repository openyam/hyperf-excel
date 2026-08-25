<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

interface WithDrawings
{
    /** @return object|array<array-key, object> */
    public function drawings(): object|array;
}
