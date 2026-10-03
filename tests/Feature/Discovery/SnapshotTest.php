<?php

use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Scanner;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Facades\Actions;
use AgenticActions\Support\PackageStatus;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Actions\CreateNote;

beforeEach(function () {
    $this->snapshot = Snapshot::path();

    File::delete($this->snapshot);
});

afterEach(function () {
    File::delete($this->snapshot);
});

describe('encode()', function () {
    it('sorts keys at every depth, keeps lists in order, and pretty-prints with one trailing newline', function () {
        $text = Snapshot::encode([
            'version' => 1,
            'agents' => ['App\\Ai\\Support' => ['support', 'default']],
            'actions' => [
                'save-note' => ['web' => true, 'class' => 'App\\Actions\\SaveNote', 'agents' => ['support/قسم', 'default']],
                'archive-note' => ['web' => false, 'class' => 'App\\Actions\\ArchiveNote', 'agents' => []],
            ],
        ]);

        expect($text)->toBe(implode("\n", [
            '{',
            '    "actions": {',
            '        "archive-note": {',
            '            "agents": [],',
            '            "class": "App\\\\Actions\\\\ArchiveNote",',
            '            "web": false',
            '        },',
            '        "save-note": {',
            '            "agents": [',
            '                "support/قسم",',
            '                "default"',
            '            ],',
            '            "class": "App\\\\Actions\\\\SaveNote",',
            '            "web": true',
            '        }',
            '    },',
            '    "agents": {',
            '        "App\\\\Ai\\\\Support": [',
            '            "support",',
            '            "default"',
            '        ]',
            '    },',
            '    "version": 1',
            '}',
        ])."\n");
    });

    it('encodes an empty map as {}', function () {
        expect(Snapshot::encode(['version' => 1, 'actions' => [], 'agents' => []]))
            ->toBe("{\n    \"actions\": {},\n    \"agents\": {},\n    \"version\": 1\n}\n")
            ->and(Snapshot::encode([]))->toBe("{}\n");
    });

    it('gives the same text for a snapshot read back from disk', function () {
        Snapshot::write($snapshot = Snapshot::build(app(ActionRegistry::class)));

        expect(Snapshot::encode((array) Snapshot::read()))->toBe(Snapshot::encode($snapshot));

        Snapshot::write($empty = ['version' => 1, 'actions' => [], 'agents' => []]);

        expect(Snapshot::read())->toBe(['actions' => [], 'agents' => [], 'version' => 1])
            ->and(Snapshot::encode((array) Snapshot::read()))->toBe(Snapshot::encode($empty));
    });
});

describe('the file', function () {
    it('resolves the configured path against the base path unless it is absolute', function () {
        config(['agentic-actions.snapshot' => 'actions.exposure.json']);

        expect(Snapshot::path())->toBe(base_path('actions.exposure.json'));

        config(['agentic-actions.snapshot' => '/srv/app/actions.exposure.json']);

        expect(Snapshot::path())->toBe('/srv/app/actions.exposure.json');
    });

    it('writes through a temporary file and rename()', function () {
        File::put($this->snapshot, 'an older snapshot');
        $inode = fileinode($this->snapshot);
        $snapshot = Snapshot::build(app(ActionRegistry::class));

        Snapshot::write($snapshot);
        clearstatcache();

        expect(fileinode($this->snapshot))->not->toBe($inode)
            ->and(File::get($this->snapshot))->toBe(Snapshot::encode($snapshot))
            ->and(glob($this->snapshot.'?*'))->toBe([])
            ->and(fileperms($this->snapshot) & 0111)->toBe(0);
    });

    it('creates the directory the configured path names', function () {
        $directory = sys_get_temp_dir().'/agentic-actions-snapshot-'.getmypid();
        config(['agentic-actions.snapshot' => $directory.'/exposure/actions.exposure.json']);

        try {
            Snapshot::write(['version' => 1, 'actions' => [], 'agents' => []]);

            expect(File::get($directory.'/exposure/actions.exposure.json'))->toBe(Snapshot::encode(['version' => 1, 'actions' => [], 'agents' => []]));
        } finally {
            File::deleteDirectory($directory);
        }
    });

    it('reads null for a missing or unreadable snapshot', function () {
        expect(Snapshot::read())->toBeNull();

        File::put($this->snapshot, '{"actions": ');

        expect(Snapshot::read())->toBeNull();

        File::put($this->snapshot, '"a string"');

        expect(Snapshot::read())->toBeNull();
    });

    it('is written by nothing but an explicit write()', function () {
        app(ActionRegistry::class);
        Actions::exposure();

        expect($this->snapshot)->not->toBeFile();
    });
});

describe('build()', function () {
    it('gives each action its snapshot row, and the agents by class', function () {
        config(['agentic-actions.discovery.paths' => [dirname(__DIR__, 2).'/Fixtures/Actions', dirname(__DIR__, 2).'/Fixtures/Discovery/Agents']]);
        $this->refreshActions();

        $snapshot = Snapshot::build(app(ActionRegistry::class));

        expect($snapshot['version'])->toBe(1)
            ->and($snapshot['actions']['create-note'])->toBe(app(ActionRegistry::class)->find('create-note')?->toSnapshot())
            ->and($snapshot['actions']['plain-note'])->toMatchArray(['web' => false, 'agents' => [], 'mcp' => false, 'effect' => 'write'])
            ->and($snapshot['agents'])->toBe(app(ActionRegistry::class)->agents());
    });

    it('is what Actions::exposure() returns, for the registry and for a fresh scan alike', function () {
        $scan = app(Scanner::class)->scan(config('agentic-actions.discovery.paths'), config('agentic-actions.discovery.classes'));

        expect(Actions::exposure())->toBe(Snapshot::build(app(ActionRegistry::class)))
            ->and(Actions::exposure())->toBe(Snapshot::build($scan));
    });

    it('records the widening when laravel/ai arrives, so it becomes a reviewed diff', function () {
        $this->skipUnlessAi();
        $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

        $before = Actions::exposure();

        $this->usePackages(['laravel/ai' => PackageStatus::Installed]);

        $after = Actions::exposure();

        expect($before['actions']['create-note']['agents'])->toBe([])
            ->and($before['actions']['create-note']['web'])->toBeTrue()
            ->and($after['actions']['create-note']['agents'])->toBe(['default'])
            ->and($after['actions']['create-note']['class'])->toBe(CreateNote::class)
            ->and(Snapshot::encode($after))->not->toBe(Snapshot::encode($before));
    });
});
