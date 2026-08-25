<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Factory;

use Hyperf\Contract\ConfigInterface;
use OpenYam\HyperfExcel\Queue\NullOperationStatusStore;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;
use Psr\Container\ContainerInterface;

final class OperationStatusStoreFactory
{
    public function __invoke(ContainerInterface $container): OperationStatusStoreInterface
    {
        $class = $container->get(ConfigInterface::class)->get('excel.queue.status_store');
        if (is_string($class) && $class !== '' && $class !== NullOperationStatusStore::class) {
            return $container->get($class);
        }
        return new NullOperationStatusStore();
    }
}
