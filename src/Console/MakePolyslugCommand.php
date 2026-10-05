<?php

declare(strict_types=1);

namespace Polyslug\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use LogicException;

final class MakePolyslugCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:polyslug';

    /** @var string */
    protected $description = 'Scaffold a sluggable Eloquent model wired for Polyslug.';

    /** @var string */
    protected $type = 'Model';

    /**
     * @phpstan-ignore method.childReturnType (the console casts what handle() returns to the exit code, and the false GeneratorCommand documents would exit 0)
     */
    public function handle(): int
    {
        if ($this->getNameInput() === '') {
            $this->components->error('A model name is required.');

            return self::FAILURE;
        }

        // GeneratorCommand answers a reserved name or an existing model with `false`, and the
        // console casts what handle() returns to the exit code, so a refusal would exit 0.
        return parent::handle() === false ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The class to scaffold, with every namespace segment studly-cased: `admin/landing-page`
     * becomes `Admin\LandingPage`, written to `app/Models/Admin/LandingPage.php`.
     *
     * Empty when there is no class name to scaffold: no name, a name that is not a string (a
     * programmatic call can pass 42), or a separator with nothing after it.
     */
    protected function getNameInput(): string
    {
        if (! is_string($this->argument('name'))) {
            return '';
        }

        $segments = explode('\\', ltrim(str_replace('/', '\\', parent::getNameInput()), '\\'));

        if (in_array('', $segments, true)) {
            return '';
        }

        return implode('\\', array_map(Str::studly(...), $segments));
    }

    /**
     * Whether PHP reserves the name or the class it names.
     *
     * GeneratorCommand compares the whole input with PHP's reserved words, and a name with a
     * namespace is never one of them: `admin/list` is `Admin\List`, while the class it writes,
     * `List`, does not parse. A reserved namespace segment is no problem, because a qualified
     * name is a single token to PHP, so the class part is the one that has to be checked.
     *
     * @param  string  $name
     */
    protected function isReservedName($name): bool
    {
        return parent::isReservedName($name) || parent::isReservedName(class_basename($name));
    }

    /**
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Models';
    }

    /**
     * There is no stub file to point at. The package ships PHP only, so the model is written by
     * buildClass() below, which is the one method of GeneratorCommand that reads a stub.
     */
    protected function getStub(): never
    {
        throw new LogicException('make:polyslug writes its model in buildClass() and has no stub file.');
    }

    /**
     * @param  string  $name  the qualified class, such as App\Models\Admin\LandingPage
     */
    protected function buildClass($name): string
    {
        return implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace '.$this->getNamespace($name).';',
            '',
            'use Illuminate\\Database\\Eloquent\\Model;',
            'use Polyslug\\Attributes\\Polyslug;',
            'use Polyslug\\Concerns\\HasPolyslug;',
            'use Polyslug\\Contracts\\Sluggable;',
            '',
            "#[Polyslug(source: 'title')]",
            'final class '.class_basename($name).' extends Model implements Sluggable',
            '{',
            '    use HasPolyslug;',
            '}',
            '',
        ]);
    }
}
