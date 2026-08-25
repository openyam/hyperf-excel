<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Import;

use Hyperf\DbConnection\Db;
use OpenYam\HyperfExcel\Concerns\SkipsOnFailure;
use OpenYam\HyperfExcel\Exceptions\ValidationException;

/** @internal */
final class ChunkConsumer
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly SheetProcessor $sheets, private readonly array $config = []) {}

    /**
     * @param array<int, array<array-key, mixed>> $rows
     * @return array{processed: int, skipped: int, failures: list<\OpenYam\HyperfExcel\Result\Failure>}
     */
    public function consume(object $import, array $rows, bool $transactional): array
    {
        $consume = function () use ($import, $rows): array {
            [$valid, $invalid, $failures] = $this->sheets->validateRows($import, $rows);
            if ($failures !== []) {
                if (! $import instanceof SkipsOnFailure) {
                    throw new ValidationException($failures);
                }
                $import->onFailure(...$failures);
            }
            [$processed, $executionSkipped] = $this->sheets->consumeRows($import, $valid);
            return ['processed' => $processed, 'skipped' => $invalid + $executionSkipped, 'failures' => $failures];
        };

        if ($transactional && ($this->config['transaction']['handler'] ?? 'db') === 'db') {
            return Db::connection($this->config['transaction']['connection'] ?? null)->transaction($consume);
        }

        return $consume();
    }
}
