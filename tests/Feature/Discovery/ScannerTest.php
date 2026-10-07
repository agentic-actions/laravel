<?php

use AgenticActions\Discovery\Scan;
use AgenticActions\Discovery\Scanner;
use AgenticActions\Exceptions\DuplicateActionName;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Discovery\Agents\BlogWriter;
use Tests\Fixtures\Discovery\Agents\SupportDesk;
use Tests\Fixtures\Discovery\Deferring\DeferringDesk;
use Tests\Fixtures\Discovery\Deferring\SearchDesk;
use Tests\Fixtures\Discovery\PageContext\BasePageAssistant;
use Tests\Fixtures\Discovery\PageContext\PageAssistant;
use Tests\Fixtures\Discovery\PageContext\ToolsetAssistant;
use Tests\Fixtures\Discovery\PageContext\ToolsetPageAssistant;
use Tests\Fixtures\Discovery\Plain\DomainService;
use Tests\Fixtures\Discovery\Segments\CamelNamed;
use Tests\Fixtures\Discovery\Segments\KebabNamed;
use Tests\Fixtures\Misconfigured\DuplicateNameA;
use Tests\Fixtures\Misconfigured\DuplicateNameB;
use Tests\Fixtures\Misconfigured\UndeclaredEffect;

beforeEach(function () {
    $this->fixtures = dirname(__DIR__, 2).'/Fixtures';
    $this->discovery = $this->fixtures.'/Discovery';
});

describe('the class name', function () {
    it('comes from the file\'s own tokens, not its path', function () {
        $file = $this->discovery.'/Modules/Demo/app/Agentic/SavePhone.php';

        expect(Scanner::declaredClass((string) file_get_contents($file)))->toBe('Modules\\Demo\\Agentic\\SavePhone');

        // The module's own autoloading, as an app's composer.json maps Modules\Demo\ to Modules/Demo/app/.
        $autoload = function (string $class): void {
            if (str_starts_with($class, 'Modules\\Demo\\')) {
                $file = $this->discovery.'/Modules/Demo/app/'.str_replace('\\', '/', substr($class, strlen('Modules\\Demo\\'))).'.php';

                if (is_file($file)) {
                    require_once $file;
                }
            }
        };

        spl_autoload_register($autoload);

        try {
            $scan = app(Scanner::class)->scan([$this->discovery.'/Modules/*/app']);
        } finally {
            spl_autoload_unregister($autoload);
        }

        expect(array_keys($scan->actions))->toBe(['save-phone'])
            ->and($scan->actions['save-phone']->class)->toBe('Modules\\Demo\\Agentic\\SavePhone')
            ->and($scan->directories)->toBe([$this->discovery.'/Modules/Demo/app']);
    });

    it('skips ::class and new class', function (string $source, ?string $class) {
        expect(Scanner::declaredClass($source))->toBe($class);
    })->with([
        'a class constant before the class' => ["<?php\nnamespace App\\Agentic;\n\$handler = Handler::class;\nfinal class SaveNote {}", 'App\\Agentic\\SaveNote'],
        'a comment between :: and class' => ["<?php\nnamespace App;\n\$x = Handler:: /* why */ class;\nclass SaveNote {}", 'App\\SaveNote'],
        'an anonymous class first' => ["<?php\nnamespace App;\n\$helper = new class {};\nabstract class SaveNote {}", 'App\\SaveNote'],
        'an anonymous class with arguments' => ["<?php\nnamespace App;\n\$helper = new class(1) extends Base {};\nclass SaveNote {}", 'App\\SaveNote'],
        'a readonly anonymous class' => ["<?php\nnamespace App;\n\$helper = new readonly class {};\nfinal readonly class SaveNote {}", 'App\\SaveNote'],
        'an attribute naming a class' => ["<?php\nnamespace App;\n#[Uses(Other::class)]\nfinal class SaveNote {}", 'App\\SaveNote'],
        'no namespace' => ["<?php\nfinal class SaveNote {}", 'SaveNote'],
        'a braced namespace' => ["<?php\nnamespace App\\Agentic {\n    final class SaveNote {}\n}", 'App\\Agentic\\SaveNote'],
        'a braced global namespace' => ["<?php\nnamespace {\n    final class SaveNote {}\n}", 'SaveNote'],
        'an interface' => ["<?php\nnamespace App;\ninterface SavesNotes { const HANDLER = Handler::class; }", null],
        'an enum' => ["<?php\nnamespace App;\nenum Status: string { case Draft = 'draft'; }", null],
        'no PHP at all' => ['plain text', null],
    ]);
});

describe('what it reads', function () {
    it('never autoloads a file that does not name the package', function () {
        $requested = [];
        $spy = function (string $class) use (&$requested): void {
            $requested[] = $class;
        };

        spl_autoload_register($spy, prepend: true);

        try {
            $scan = app(Scanner::class)->scan([$this->discovery.'/Plain']);
        } finally {
            spl_autoload_unregister($spy);
        }

        expect($requested)->not->toContain(DomainService::class)
            ->and(class_exists(DomainService::class, false))->toBeFalse()
            ->and($scan->actions)->toBe([])
            ->and($scan->directories)->toBe([$this->discovery.'/Plain']);
    });

    it('accepts absolute paths, relative paths and globs, and skips missing paths', function () {
        $scan = app(Scanner::class)->scan([
            $this->discovery.'/Modules/*/app',
            $this->discovery.'/Missing',
            $this->discovery.'/Nothing/*/app',
            'app',
            'missing-dir',
        ]);

        expect($scan->directories)->toBe([$this->discovery.'/Modules/Demo/app', base_path('app')]);
    });

    it('works with listed classes alone, and with no directory at all', function () {
        $listed = app(Scanner::class)->scan([], [CreateNote::class]);
        $missing = app(Scanner::class)->scan(['missing-dir']);
        $nothing = app(Scanner::class)->scan([]);

        expect(array_keys($listed->actions))->toBe(['create-note'])
            ->and($listed->directories)->toBe([])
            ->and($missing->actions)->toBe([])
            ->and($missing->directories)->toBe([])
            ->and($nothing)->toEqual(new Scan([], [], []));
    });

    it('counts a listed class once, with or without its leading backslash', function () {
        $scan = app(Scanner::class)->scan([$this->fixtures.'/Actions'], ['\\'.CreateNote::class, CreateNote::class]);

        expect($scan->actions['create-note']->class)->toBe(CreateNote::class);
    });

    it('skips abstract classes and names nothing loads', function () {
        $scan = app(Scanner::class)->scan([$this->discovery.'/Bases'], ['App\\Actions\\Gone']);

        expect($scan->actions)->toBe([])
            ->and($scan->warnings)->toBe([]);
    });

    it('skips a class that cannot load, with a warning, and scans the rest', function () {
        $scan = app(Scanner::class)->scan([$this->discovery.'/Broken'], [CreateNote::class]);

        expect($scan->warnings)->toHaveCount(1)
            ->and($scan->warnings[0])->toStartWith('Tests\\Fixtures\\Discovery\\Broken\\Unloadable could not be loaded: ')
            ->and($scan->warnings[0])->toContain('MissingContract')
            ->and($scan->warnings[0])->not->toContain("\n")
            ->and(array_keys($scan->actions))->toBe(['create-note']);
    });
});

describe('what it finds', function () {
    it('records #[UseToolset] agents with their toolsets, sorted by class', function () {
        $found = app(Scanner::class)->scan([$this->discovery.'/Agents']);
        $listed = app(Scanner::class)->scan([], [SupportDesk::class, BlogWriter::class]);

        expect($found->agents)->toBe([BlogWriter::class => ['default'], SupportDesk::class => ['support']])
            ->and($found->actions)->toBe([])
            ->and(array_keys($listed->agents))->toBe([BlogWriter::class, SupportDesk::class]);
    });

    it('records #[DeferToolset] agents with their deferred toolsets, and one that loads none among the agents', function () {
        $scan = app(Scanner::class)->scan([$this->discovery.'/Deferring'], [BlogWriter::class]);

        expect($scan->agents)->toBe([BlogWriter::class => ['default'], DeferringDesk::class => ['default'], SearchDesk::class => []])
            ->and($scan->deferred)->toBe([DeferringDesk::class => ['support'], SearchDesk::class => ['support']]);
    });

    it('throws DuplicateActionName for two actions with one name', function () {
        expect(fn () => app(Scanner::class)->scan([], [DuplicateNameB::class, DuplicateNameA::class]))
            ->toThrow(DuplicateActionName::class, 'Actions '.DuplicateNameA::class.' and '.DuplicateNameB::class.' share the name or route segment [same].');
    });

    it('throws DuplicateActionName for two names with one route segment', function () {
        expect(fn () => app(Scanner::class)->scan([$this->discovery.'/Segments']))
            ->toThrow(DuplicateActionName::class, 'Actions '.CamelNamed::class.' and '.KebabNamed::class.' share the name or route segment [save-note].');
    });

    it('records exposure errors as "{class}: {error}" instead of throwing', function () {
        $scan = app(Scanner::class)->scan([], [UndeclaredEffect::class, CreateNote::class]);

        expect($scan->errors())->toBe([
            UndeclaredEffect::class.': http: effect undeclared',
            UndeclaredEffect::class.': agent: effect undeclared',
            UndeclaredEffect::class.': mcp: effect undeclared',
        ]);
    });
});

describe('page context', function () {
    beforeEach(function () {
        $this->pageContext = $this->discovery.'/PageContext';
    });

    it('records every class carrying #[WithPageContext], with or without #[UseToolset], sorted', function () {
        $scan = app(Scanner::class)->scan([$this->pageContext]);

        expect($scan->pageContext)->toBe([PageAssistant::class, ToolsetPageAssistant::class])
            ->and($scan->agents)->toBe([
                ToolsetAssistant::class => ['notes'],
                ToolsetPageAssistant::class => ['notes'],
            ]);
    });

    it('never records an abstract class', function () {
        $scan = app(Scanner::class)->scan([], [BasePageAssistant::class, PageAssistant::class]);

        expect($scan->pageContext)->toBe([PageAssistant::class]);
    });

    it('sorts listed classes', function () {
        $scan = app(Scanner::class)->scan([], [ToolsetPageAssistant::class, PageAssistant::class]);

        expect($scan->pageContext)->toBe([PageAssistant::class, ToolsetPageAssistant::class]);
    });
});
