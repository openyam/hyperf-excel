<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use Hyperf\Database\Model\Builder as ModelBuilder;
use Hyperf\Database\Query\Builder as QueryBuilder;

interface FromQuery
{
    /** @return ModelBuilder<\Hyperf\Database\Model\Model>|QueryBuilder */
    public function query(): ModelBuilder|QueryBuilder;
}
