<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

use OpenYam\HyperfExcel\Result\Progress;

interface WithProgress
{
    public function onProgress(Progress $progress): void;
}
