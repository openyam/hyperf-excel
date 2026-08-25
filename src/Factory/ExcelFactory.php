<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Factory;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Filesystem\FilesystemFactory;
use Hyperf\HttpServer\Contract\ResponseInterface;
use OpenYam\HyperfExcel\Excel;
use OpenYam\HyperfExcel\Files\FileResolver;
use OpenYam\HyperfExcel\Files\TemporaryFileManager;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;
use OpenYam\HyperfExcel\Queue\QueueDispatcher;
use OpenYam\HyperfExcel\Reader;
use OpenYam\HyperfExcel\Support\EventBus;
use OpenYam\HyperfExcel\Writer;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ExcelFactory
{
    public function __invoke(ContainerInterface $container): Excel
    {
        $config = $container->get(ConfigInterface::class)->get('excel', []);
        $fallback = (defined('BASE_PATH') ? BASE_PATH : sys_get_temp_dir()) . '/runtime/container/hyperf-excel';
        $temporaryFiles = new TemporaryFileManager((string) ($config['temporary_path'] ?? $fallback));
        $files = new FileResolver($temporaryFiles, $container->get(FilesystemFactory::class), $config['security'] ?? []);
        $events = new EventBus($container->get(EventDispatcherInterface::class));
        $statuses = $container->get(OperationStatusStoreInterface::class);
        $writer = new Writer($temporaryFiles, $events, $container, $config, $statuses);
        $reader = new Reader($files, $events, $container, $config, $statuses);
        $queue = new QueueDispatcher($container->get(DriverFactory::class), $config['queue'] ?? [], $statuses);
        return new Excel($writer, $reader, $files, $container->get(ResponseInterface::class), $queue, $statuses);
    }
}
