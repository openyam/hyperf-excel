<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Command;

use Hyperf\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

final class ImportMakeCommand extends Command
{
    protected ?string $name = 'gen:excel-import';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Create a Hyperf Excel import class.')->addArgument('name', InputArgument::REQUIRED);
    }

    public function handle(): void
    {
        $input = $this->input ?? throw new \LogicException('Console input is not initialized.');
        $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $input->getArgument('name')) ?? '';
        $basePath = defined('BASE_PATH') ? (string) constant('BASE_PATH') : getcwd();
        $path = $basePath . '/app/Excel/Imports';
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
        $class = str_ends_with($name, 'Import') ? $name : $name . 'Import';
        $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Excel\\Imports;\n\nuse Hyperf\\Collection\\Collection;\nuse OpenYam\\HyperfExcel\\Concerns\\ToCollection;\nuse OpenYam\\HyperfExcel\\Importable;\n\nfinal class {$class} implements ToCollection\n{\n    use Importable;\n\n    public function collection(Collection \$rows): void\n    {\n        // Process rows.\n    }\n}\n";
        if (file_exists($path . '/' . $class . '.php')) {
            $this->error('File already exists.');
            return;
        }
        file_put_contents($path . '/' . $class . '.php', $source);
        $this->info('Created ' . $class . '.');
    }
}
