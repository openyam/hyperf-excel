<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Model;
use Hyperf\Filesystem\FilesystemFactory;
use Mockery;
use Mockery\MockInterface;
use OpenYam\HyperfExcel\Concerns\ToModel;
use OpenYam\HyperfExcel\Concerns\WithBatchInserts;
use OpenYam\HyperfExcel\Concerns\WithUpsertColumns;
use OpenYam\HyperfExcel\Concerns\WithUpserts;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Reader;
use OpenYam\HyperfExcel\Support\EventBus;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ModelImportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testBatchImportsInsertCollectedModelAttributes(): void
    {
        $builder = $this->builder();
        $builder->shouldReceive('insert')->once()->with([['value' => 'Alice'], ['value' => 'Bob']])->andReturn(true);
        $models = [$this->model(['value' => 'Alice'], $builder), $this->model(['value' => 'Bob'], $builder)];
        $import = new class ($models) implements ToModel, WithBatchInserts {
            public function __construct(private array $models) {}
            public function batchSize(): int
            {
                return 10;
            }
            public function model(array $row): ?Model
            {
                return array_shift($this->models);
            }
        };

        $result = $this->import($import, "Alice\nBob\n");

        self::assertSame(2, $result->processed);
    }

    public function testUpsertImportsUseUniqueAndUpdateColumns(): void
    {
        $builder = $this->builder();
        $builder->shouldReceive('upsert')->once()->with([['email' => 'a@example.com'], ['email' => 'b@example.com']], 'email', ['email'])->andReturn(2);
        $models = [$this->model(['email' => 'a@example.com'], $builder), $this->model(['email' => 'b@example.com'], $builder)];
        $import = new class ($models) implements ToModel, WithUpserts, WithUpsertColumns {
            public function __construct(private array $models) {}
            public function model(array $row): ?Model
            {
                return array_shift($this->models);
            }
            public function uniqueBy(): string
            {
                return 'email';
            }
            public function upsertColumns(): array
            {
                return ['email'];
            }
        };

        $result = $this->import($import, "a@example.com\nb@example.com\n");

        self::assertSame(2, $result->processed);
    }

    /** @param Builder<Model>&MockInterface $builder */
    private function model(array $attributes, Builder&MockInterface $builder): Model
    {
        $model = Mockery::mock(Model::class);
        $model->shouldReceive('getAttributes')->andReturn($attributes);
        $model->shouldReceive('newQuery')->andReturn($builder);
        if (! $model instanceof Model) {
            throw new \LogicException('Unable to create model mock.');
        }
        return $model;
    }

    /** @return Builder<Model>&MockInterface */
    private function builder(): Builder&MockInterface
    {
        $builder = Mockery::mock(Builder::class);
        if (! $builder instanceof Builder) {
            throw new \LogicException('Unable to create builder mock.');
        }
        return $builder;
    }

    private function import(object $import, string $csv): \OpenYam\HyperfExcel\Result\ImportResult
    {
        $path = tempnam(sys_get_temp_dir(), 'excel-model-') . '.csv';
        file_put_contents($path, $csv);
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $files = new FileResolver(new TemporaryFileManager(sys_get_temp_dir() . '/hyperf-excel-tests'), $this->createStub(FilesystemFactory::class));
        $reader = new Reader($files, new EventBus($events), $this->createStub(ContainerInterface::class), ['transaction' => ['handler' => 'null']]);
        try {
            return $reader->read($import, $path);
        } finally {
            @unlink($path);
        }
    }
}
