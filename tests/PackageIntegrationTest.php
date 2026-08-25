<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Tests;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Filesystem\FilesystemFactory;
use Hyperf\HttpServer\Contract\ResponseInterface;
use OpenYam\HyperfExcel\Command\ExportMakeCommand;
use OpenYam\HyperfExcel\Command\ImportMakeCommand;
use OpenYam\HyperfExcel\ConfigProvider;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Factory\ExcelFactory;
use OpenYam\HyperfExcel\Factory\OperationStatusStoreFactory;
use OpenYam\HyperfExcel\Queue\NullOperationStatusStore;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;
use OpenYam\HyperfExcel\Result\OperationStatus;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class PackageIntegrationTest extends TestCase
{
    public function testConfigProviderAndFactoriesBuildPackageServices(): void
    {
        $provider = (new ConfigProvider())();
        self::assertSame(ExcelFactory::class, $provider['dependencies'][\OpenYam\HyperfExcel\ExcelInterface::class]);
        self::assertSame([ExportMakeCommand::class, ImportMakeCommand::class], $provider['commands']);

        $config = $this->createStub(ConfigInterface::class);
        $config->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => match ($key) {
            'excel' => ['temporary_path' => sys_get_temp_dir() . '/hyperf-excel-tests', 'transaction' => ['handler' => 'null']],
            'excel.queue.status_store' => NullOperationStatusStore::class,
            default => $default,
        });
        $statuses = new NullOperationStatusStore();
        $services = [
            ConfigInterface::class => $config,
            FilesystemFactory::class => $this->createStub(FilesystemFactory::class),
            EventDispatcherInterface::class => new class implements EventDispatcherInterface {
                public function dispatch(object $event): object
                {
                    return $event;
                }
            },
            OperationStatusStoreInterface::class => $statuses,
            DriverFactory::class => $this->createStub(DriverFactory::class),
            ResponseInterface::class => $this->createStub(ResponseInterface::class),
        ];
        $container = new class ($services) implements ContainerInterface {
            public function __construct(private readonly array $services) {}
            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new \RuntimeException('Unknown service.');
            }
            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };

        self::assertInstanceOf(Excel::class, (new ExcelFactory())($container));
        self::assertInstanceOf(NullOperationStatusStore::class, (new OperationStatusStoreFactory())($container));
        $statuses->save(new OperationStatus('id', 'import'));
        self::assertNull($statuses->find('id'));
    }

    public function testStatusStoreFactoryResolvesConfiguredImplementation(): void
    {
        $configured = new class implements OperationStatusStoreInterface {
            public function save(OperationStatus $status): void {}
            public function find(string $operationId): ?OperationStatus
            {
                return null;
            }
        };
        $config = $this->createStub(ConfigInterface::class);
        $config->method('get')->willReturn($configured::class);
        $container = new class ($config, $configured) implements ContainerInterface {
            public function __construct(private readonly ConfigInterface $config, private readonly OperationStatusStoreInterface $status) {}
            public function get(string $id): mixed
            {
                return $id === ConfigInterface::class ? $this->config : $this->status;
            }
            public function has(string $id): bool
            {
                return true;
            }
        };

        self::assertSame($configured, (new OperationStatusStoreFactory())($container));
    }

    public function testGeneratorCommandsCreateExpectedClasses(): void
    {
        $root = sys_get_temp_dir() . '/hyperf-excel-command-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $previous = getcwd();
        self::assertIsString($previous);
        chdir($root);
        try {
            $this->runCommand(new ExportMakeCommand(), 'gen:excel-export', 'Users');
            $this->runCommand(new ImportMakeCommand(), 'gen:excel-import', 'Users');
            self::assertFileExists($root . '/app/Excel/Exports/UsersExport.php');
            self::assertFileExists($root . '/app/Excel/Imports/UsersImport.php');
            self::assertStringContainsString('final class UsersExport', (string) file_get_contents($root . '/app/Excel/Exports/UsersExport.php'));
            self::assertStringContainsString('final class UsersImport', (string) file_get_contents($root . '/app/Excel/Imports/UsersImport.php'));
        } finally {
            chdir($previous);
            @unlink($root . '/app/Excel/Exports/UsersExport.php');
            @unlink($root . '/app/Excel/Imports/UsersImport.php');
            @rmdir($root . '/app/Excel/Exports');
            @rmdir($root . '/app/Excel/Imports');
            @rmdir($root . '/app/Excel');
            @rmdir($root . '/app');
            @rmdir($root);
        }
    }

    private function runCommand(\Symfony\Component\Console\Command\Command $command, string $name, string $class): void
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->add($command);
        self::assertSame(0, $application->run(new ArrayInput(['command' => $name, 'name' => $class]), new BufferedOutput()));
    }
}
