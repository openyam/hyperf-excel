<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests\Fixtures;

use OpenYam\HyperfExcel\Concerns\SkipsFailures;
use OpenYam\HyperfExcel\Concerns\SkipsOnFailure;
use OpenYam\HyperfExcel\Importable;

final class ImportableFixture implements SkipsOnFailure
{
    use Importable;
    use SkipsFailures;
}
