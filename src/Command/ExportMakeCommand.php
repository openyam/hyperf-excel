<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Command;

use Hyperf\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

final class ExportMakeCommand extends Command
{
    protected ?string $name = 'gen:excel-export';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Create a Hyperf Excel export class.')->addArgument('name', InputArgument::REQUIRED);
    }

    public function handle(): void
    {
        $input = $this->input ?? throw new \LogicException('Console input is not initialized.');
        $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $input->getArgument('name')) ?? '';
        $this->generate($name, 'Exports', 'FromCollection', 'collection', 'new Collection()');
    }

    private function generate(string $name, string $directory, string $concern, string $method, string $body): void
    {
        $basePath = defined('BASE_PATH') ? (string) constant('BASE_PATH') : getcwd();
        $path = $basePath . '/app/Excel/' . $directory;
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
        $class = str_ends_with($name, 'Export') ? $name : $name . 'Export';
        $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Excel\\{$directory};\n\nuse Hyperf\\Collection\\Collection;\nuse OpenYam\\HyperfExcel\\Concerns\\{$concern};\nuse OpenYam\\HyperfExcel\\Exportable;\n\nfinal class {$class} implements {$concern}\n{\n    use Exportable;\n\n    public function {$method}(): Collection\n    {\n        return {$body};\n    }\n}\n";
        if (file_exists($path . '/' . $class . '.php')) {
            $this->error('File already exists.');
            return;
        }
        file_put_contents($path . '/' . $class . '.php', $source);
        $this->info('Created ' . $class . '.');
    }
}
