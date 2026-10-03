<?php

namespace AgenticActions\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Writes a new action that is discovered and exposed nowhere: no #[Expose], no effect, and an authorize() that
 * denies, so nothing reaches it until its author decides.
 *
 * @internal
 */
#[AsCommand(name: 'make:agentic-action')]
final class MakeActionCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:agentic-action
        {name : The name of the action}
        {--force : Create the class even if the action already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new agentic action class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Action';

    /**
     * The stub: the app's published copy when there is one, else the package's.
     */
    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/agentic-action.stub');

        return is_file($published) ? $published : __DIR__.'/../../stubs/agentic-action.stub';
    }

    /**
     * The default namespace: {rootNamespace}\Actions.
     *
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Actions';
    }
}
