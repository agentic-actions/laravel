<?php

use AgenticActions\Discovery\Scanner;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Discovery\MissingTraits\GrandparentTraitAgent;
use Tests\Fixtures\Discovery\MissingTraits\LoopingTraitAgent;
use Tests\Fixtures\Discovery\MissingTraits\NestedTraitAgent;
use Tests\Fixtures\Discovery\MissingTraits\OrphanAgent;
use Tests\Fixtures\Discovery\MissingTraits\ParentTraitAgent;
use Tests\Fixtures\Discovery\MissingTraits\SoundAgent;
use Tests\Fixtures\Discovery\MissingTraits\Support\LoopsOne;
use Tests\Fixtures\Discovery\MissingTraits\Support\PromptingAgent;
use Tests\Fixtures\Discovery\MissingTraits\Support\Remembers;

/*
 * An agent class keeps laravel/ai's Promptable trait after the package is gone (composer install --no-dev, or the
 * "no laravel/ai" CI cell with the workbench's BlogAssistant). Before PHP 8.5, PHP stops outright when it loads a
 * class whose trait no autoloader can find, and the same goes for a trait used by a parent at any depth or by another
 * trait. So the scanner walks the class's parents and traits from their files' tokens and skips such a class with a
 * warning, before it loads anything. The first cases write their classes into a scratch directory that only Composer's
 * loader knows about; the ancestry cases use tests/Fixtures/Discovery/MissingTraits, which no other test names,
 * because loading one of those classes would stop the suite.
 */

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/agentic-actions-traits-'.getmypid();
    $this->loader = array_values(ClassLoader::getRegisteredLoaders())[0];

    File::deleteDirectory($this->scratch);
    File::ensureDirectoryExists($this->scratch.'/Scanned');
    File::ensureDirectoryExists($this->scratch.'/Listed');

    $this->loader->setPsr4('Tests\\Scratch\\Traits\\', [$this->scratch.'/Scanned']);
    $this->loader->setPsr4('Tests\\Scratch\\ListedTraits\\', [$this->scratch.'/Listed']);
});

afterEach(function () {
    $this->loader->setPsr4('Tests\\Scratch\\Traits\\', []);
    $this->loader->setPsr4('Tests\\Scratch\\ListedTraits\\', []);

    File::deleteDirectory($this->scratch);
});

/**
 * Write one class into the scratch directory.
 */
function scratchClass(string $path, string $source): void
{
    File::put(test()->scratch.'/'.$path, "<?php\n\n".$source);
}

it('skips a class whose trait is gone, naming the trait, and loads nothing of it', function () {
    scratchClass('Scanned/GoneAgent.php', <<<'PHP'
        namespace Tests\Scratch\Traits;

        use AgenticActions\Attributes\UseToolset;
        use Gone\Package\Promptable;

        #[UseToolset]
        final class GoneAgent
        {
            use Promptable;
        }
        PHP);

    scratchClass('Scanned/GroupAgent.php', <<<'PHP'
        namespace Tests\Scratch\Traits;

        use AgenticActions\Attributes\UseToolset;
        use Gone\{Package\Talks as Chatty, Other};

        #[UseToolset('support')]
        final class GroupAgent
        {
            use Other\Kept, Chatty {
                Chatty::say as speak;
            }
        }
        PHP);

    scratchClass('Scanned/RelativeAgent.php', <<<'PHP'
        namespace Tests\Scratch\Traits;

        use AgenticActions\Attributes\UseToolset;

        #[UseToolset]
        final class RelativeAgent
        {
            use namespace\Missing\Remembers;
        }
        PHP);

    scratchClass('Scanned/QualifiedAgent.php', <<<'PHP'
        namespace Tests\Scratch\Traits;

        use AgenticActions\Attributes\UseToolset;

        #[UseToolset]
        final class QualifiedAgent
        {
            use \Gone\Package\Remembers;
        }
        PHP);

    scratchClass('Scanned/KeptNote.php', <<<'PHP'
        namespace Tests\Scratch\Traits;

        use AgenticActions\Action;
        use AgenticActions\ActionContext;
        use AgenticActions\Effect;
        use Illuminate\Support\Traits\Conditionable;

        use function Illuminate\Support\enum_value;

        final class KeptNote extends Action
        {
            use Conditionable;

            protected ?Effect $effect = Effect::Read;

            public function authorize(ActionContext $context): bool
            {
                $label = "note {$context->locale}";

                return (function () use ($label): bool {
                    return $label !== '';
                })();
            }

            public function handle(): array
            {
                return ['effect' => enum_value($this->effect)];
            }
        }
        PHP);

    $scan = app(Scanner::class)->scan([$this->scratch.'/Scanned']);

    expect($scan->warnings)->toBe([
        'Tests\Scratch\Traits\GoneAgent could not be loaded: Trait "Gone\Package\Promptable" not found',
        'Tests\Scratch\Traits\GroupAgent could not be loaded: Trait "Gone\Other\Kept" not found',
        'Tests\Scratch\Traits\QualifiedAgent could not be loaded: Trait "Gone\Package\Remembers" not found',
        'Tests\Scratch\Traits\RelativeAgent could not be loaded: Trait "Tests\Scratch\Traits\Missing\Remembers" not found',
    ])
        ->and(array_keys($scan->actions))->toBe(['kept-note'])
        ->and($scan->agents)->toBe([])
        ->and(class_exists('Tests\Scratch\Traits\GoneAgent', false))->toBeFalse();
});

it('skips a class listed in discovery.classes whose trait is gone', function () {
    scratchClass('Listed/ListedAgent.php', <<<'PHP'
        namespace Tests\Scratch\ListedTraits;

        use AgenticActions\Attributes\UseToolset;
        use Gone\Package\Promptable as Prompts;

        #[UseToolset]
        final class ListedAgent
        {
            use Prompts;
        }
        PHP);

    $scan = app(Scanner::class)->scan([], ['\Tests\Scratch\ListedTraits\ListedAgent']);

    expect($scan->warnings)->toBe([
        'Tests\Scratch\ListedTraits\ListedAgent could not be loaded: Trait "Gone\Package\Promptable" not found',
    ])->and($scan->agents)->toBe([]);
});

it('still records an agent whose traits all exist', function () {
    scratchClass('Scanned/PresentAgent.php', <<<'PHP'
        namespace Tests\Scratch\Traits;

        use AgenticActions\Attributes\UseToolset;
        use Illuminate\Support\Traits\{Conditionable, Macroable as Macros};

        #[UseToolset('support')]
        final class PresentAgent
        {
            use Conditionable, Macros;
        }
        PHP);

    $scan = app(Scanner::class)->scan([$this->scratch.'/Scanned']);

    expect($scan->warnings)->toBe([])
        ->and($scan->agents)->toBe(['Tests\Scratch\Traits\PresentAgent' => ['support']]);
});

it('skips a class whose parent, grandparent or trait uses a trait that is gone, and loads none of them', function () {
    $scan = app(Scanner::class)->scan([dirname(__DIR__, 2).'/Fixtures/Discovery/MissingTraits']);

    expect($scan->warnings)->toBe([
        GrandparentTraitAgent::class.' could not be loaded: Trait "Gone\Package\Promptable" not found',
        LoopingTraitAgent::class.' could not be loaded: Trait "'.LoopsOne::class.'" not found',
        NestedTraitAgent::class.' could not be loaded: Trait "Gone\Package\Conversational" not found',
        OrphanAgent::class.' could not be loaded: Class "Gone\Package\Agent" not found',
        ParentTraitAgent::class.' could not be loaded: Trait "Gone\Package\Promptable" not found',
    ])
        ->and($scan->agents)->toBe([SoundAgent::class => ['support']])
        ->and(class_exists(PromptingAgent::class, false))->toBeFalse()
        ->and(trait_exists(Remembers::class, false))->toBeFalse()
        ->and(trait_exists(LoopsOne::class, false))->toBeFalse();
});

it('walks the parents and traits of a class listed in discovery.classes', function () {
    $scan = app(Scanner::class)->scan([], [ParentTraitAgent::class, '\\'.NestedTraitAgent::class]);

    expect($scan->warnings)->toBe([
        NestedTraitAgent::class.' could not be loaded: Trait "Gone\Package\Conversational" not found',
        ParentTraitAgent::class.' could not be loaded: Trait "Gone\Package\Promptable" not found',
    ])->and($scan->agents)->toBe([]);
});

it('guards a class that would stop PHP, or throw, when loaded', function (string $class, string $message) {
    $code = 'require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
        .' try { class_exists('.var_export($class, true).'); echo "loaded"; }'
        .' catch (Throwable $e) { echo "caught: ".$e->getMessage(); }';

    // Deprecations are off, so a dependency's notice (the lowest symfony/translation on PHP 8.4) cannot change the output.
    exec(escapeshellarg(PHP_BINARY).' -d display_errors=stderr -d error_reporting='.(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED)
        .' -r '.escapeshellarg($code).' 2>&1', $output, $status);

    $output = implode("\n", $output);

    // Before PHP 8.5 a missing trait is a fatal error; a missing parent class always throws.
    if (PHP_VERSION_ID < 80500 && str_starts_with($message, 'Trait')) {
        expect($status)->toBe(255)
            ->and($output)->toContain('Fatal error')->toContain($message)->not->toContain('caught')->not->toContain('loaded');
    } else {
        expect($status)->toBe(0)->and($output)->toBe("caught: {$message}");
    }
})->with([
    "a parent's trait" => [ParentTraitAgent::class, 'Trait "Gone\Package\Promptable" not found'],
    "a grandparent's trait" => [GrandparentTraitAgent::class, 'Trait "Gone\Package\Promptable" not found'],
    "a trait's trait" => [NestedTraitAgent::class, 'Trait "Gone\Package\Conversational" not found'],
    'a trait that uses itself' => [LoopingTraitAgent::class, 'Trait "'.LoopsOne::class.'" not found'],
    'a parent class' => [OrphanAgent::class, 'Class "Gone\Package\Agent" not found'],
]);
