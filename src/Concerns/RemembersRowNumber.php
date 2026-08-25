<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Concerns;

trait RemembersRowNumber
{
    private int $rowNumber = 0;

    public function rememberRowNumber(int $rowNumber): void
    {
        $this->rowNumber = $rowNumber;
    }

    public function getRowNumber(): int
    {
        return $this->rowNumber;
    }
}
