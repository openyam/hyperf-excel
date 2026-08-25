<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Files;

final class ResolvedFile
{
    public function __construct(public readonly string $path, private readonly ?TemporaryFile $temporary, public readonly string $originalName) {}

    public function delete(): void
    {
        $this->temporary?->delete();
    }
    public function __destruct()
    {
        $this->delete();
    }
}
