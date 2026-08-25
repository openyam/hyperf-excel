<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Support\FormatDetector;
use PHPUnit\Framework\TestCase;

final class FormatDetectorTest extends TestCase
{
    public function testItDetectsFormatsCaseInsensitively(): void
    {
        self::assertSame(Excel::XLSX, FormatDetector::detect('/tmp/report.XLSX'));
    }
    public function testExplicitFormatWins(): void
    {
        self::assertSame(Excel::CSV, FormatDetector::detect('no-extension', Excel::CSV));
    }
}
