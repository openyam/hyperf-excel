<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel;

use OpenYam\HyperfExcel\Command\ExportMakeCommand;
use OpenYam\HyperfExcel\Command\ImportMakeCommand;
use OpenYam\HyperfExcel\Factory\ExcelFactory;
use OpenYam\HyperfExcel\Factory\OperationStatusStoreFactory;
use OpenYam\HyperfExcel\Queue\OperationStatusStoreInterface;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ExcelInterface::class => ExcelFactory::class,
                Excel::class => ExcelFactory::class,
                OperationStatusStoreInterface::class => OperationStatusStoreFactory::class,
            ],
            'commands' => [
                ExportMakeCommand::class,
                ImportMakeCommand::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file for openyam/hyperf-excel.',
                    'source' => __DIR__ . '/../config/excel.php',
                    'destination' => (defined('BASE_PATH') ? (string) constant('BASE_PATH') : getcwd()) . '/config/autoload/excel.php',
                ],
            ],
        ];
    }
}
