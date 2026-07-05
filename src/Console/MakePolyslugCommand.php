<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class MakePolyslugCommand extends Command
{
    /** @var string */
    protected $signature = 'make:polyslug {name : The model class name}';

    /** @var string */
    protected $description = 'Scaffold a sluggable Eloquent model wired for Polyslug.';

    public function handle(Filesystem $files): int
    {
        $name = $this->argument('name');

        if (! is_string($name) || $name === '') {
            $this->error('A model name is required.');

            return self::FAILURE;
        }

        $class = Str::studly(class_basename($name));
        $path = app_path('Models/'.$class.'.php');

        if ($files->exists($path)) {
            $this->error("Model [{$class}] already exists.");

            return self::FAILURE;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->contents($class));

        $this->info("Created [{$path}].");

        return self::SUCCESS;
    }

    private function contents(string $class): string
    {
        return implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace App\\Models;',
            '',
            'use Illuminate\\Database\\Eloquent\\Model;',
            'use Polyslug\\Attributes\\Polyslug;',
            'use Polyslug\\Concerns\\HasPolyslug;',
            'use Polyslug\\Contracts\\Sluggable;',
            '',
            "#[Polyslug(source: 'title')]",
            "final class {$class} extends Model implements Sluggable",
            '{',
            '    use HasPolyslug;',
            '}',
            '',
        ]);
    }
}
