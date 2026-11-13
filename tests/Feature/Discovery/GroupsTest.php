<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Group;
use AgenticActions\Discovery\Groups;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Exceptions\DuplicateActionName;
use AgenticActions\Exceptions\MisconfiguredDiscovery;
use AgenticActions\Facades\Actions;
use AgenticActions\TypeScript\Emitter;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Discovery\Groups\Billing\BillingDesk;
use Tests\Fixtures\Discovery\Groups\Billing\ChargeCard;
use Tests\Fixtures\Discovery\Groups\Clash\RenameTeamAgain;
use Tests\Fixtures\Discovery\Groups\Core\RenameTeam;

/*
 * discovery.groups: a core group and an optional billing module, each with its own snapshot and TypeScript file. The
 * fixtures are copied under the base path, since a group's paths stay inside it; the classes autoload from
 * tests/Fixtures/Discovery/Groups as before.
 */

beforeEach(function () {
    // Every group fixture offers agents, an exposure error without laravel/ai.
    $this->skipUnlessAi();

    $this->fixtures = dirname(__DIR__, 2).'/Fixtures/Discovery/Groups';
    $this->root = 'agentic-groups-'.getmypid();

    File::copyDirectory($this->fixtures.'/Core', base_path($this->root.'/app'));
    File::copyDirectory($this->fixtures.'/Billing', base_path($this->root.'/modules/Billing/src'));

    config(['agentic-actions.typescript.path' => $this->root.'/legacy.ts', 'agentic-actions.discovery.paths' => ['app'], 'agentic-actions.discovery.classes' => [], 'agentic-actions.discovery.groups' => [
        'core' => [
            'paths' => [$this->root.'/app'],
            'snapshot' => $this->root.'/actions.exposure.json',
            'typescript' => $this->root.'/resources/js/actions.ts',
        ],
        'billing' => [
            'paths' => [$this->root.'/modules/Billing/src'],
            'snapshot' => $this->root.'/modules/Billing/actions.exposure.json',
            'typescript' => $this->root.'/modules/Billing/resources/js/actions.ts',
            'optional' => true,
        ],
    ]]);

    $this->refreshActions();
});

afterEach(function () {
    if (! isset($this->root)) {
        return;
    }

    File::deleteDirectory(base_path($this->root));
    File::delete(Snapshot::path());
});

/**
 * Remove the billing module from disk, as a deployment without it would be.
 */
function uninstallBilling(): void
{
    File::deleteDirectory(base_path(test()->root.'/modules/Billing/src'));

    test()->refreshActions();
}

/**
 * Register Actions::routes() from scratch, as the next boot would.
 */
function mountGroupRoutes(): void
{
    Route::setRoutes(new RouteCollection);

    test()->mountRoutes(fn () => Route::middleware('web')->prefix('actions')->name('actions.')->group(fn () => Actions::routes()));
}

/**
 * The findings of one row.
 *
 * @return list<string>
 */
function groupFindings(string $row): array
{
    return array_values(array_map(
        fn (Finding $finding): string => $finding->message,
        array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => $finding->row === $row),
    ));
}

describe('without discovery.groups', function () {
    beforeEach(function () {
        config([
            'agentic-actions.discovery.groups' => [],
            'agentic-actions.discovery.paths' => [$this->fixtures.'/Core', $this->fixtures.'/Billing'],
            'agentic-actions.typescript.path' => $this->root.'/legacy/actions.ts',
        ]);

        $this->refreshActions();
    });

    it('has one implicit group read from the top-level keys', function () {
        expect(Groups::grouped())->toBeFalse()
            ->and(Groups::configured())->toEqual([new Group(
                'default',
                [$this->fixtures.'/Core', $this->fixtures.'/Billing'],
                [],
                $this->root.'/legacy/actions.ts',
                Snapshot::path(),
                implicit: true,
            )]);
    });

    it('writes the snapshot and the TypeScript file byte for byte as before groups existed', function () {
        mountGroupRoutes();

        $this->artisan('actions:check --update')->run();
        $this->artisan('actions:typescript')->assertExitCode(0);

        // Both files were written by 0.9.0-beta.9, before discovery groups, from these fixtures and routes.
        expect(File::get(Snapshot::path()))->toBe(File::get($this->fixtures.'/before-groups/actions.exposure.json'))
            ->and(File::get(base_path($this->root.'/legacy/actions.ts')))->toBe(File::get($this->fixtures.'/before-groups/actions.ts'));
    });

    it('fails the one committed snapshot, naming what went, when a discovery root is removed', function () {
        config(['agentic-actions.snapshot' => $this->root.'/actions.exposure.json']);

        $this->artisan('actions:check --update')->run();

        config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Core']]);
        $this->refreshActions();

        expect(groupFindings('Snapshot'))->toBe([
            "{$this->root}/actions.exposure.json is stale: review the change, then run php artisan actions:check --update. Removed: charge-card, refund-charge. Agents changed.",
        ]);
    });

    it('refuses an unknown --group, and accepts the implicit one', function () {
        $this->artisan('actions:check --group=billing')
            ->expectsOutputToContain('There is no discovery group [billing].')
            ->assertExitCode(1);

        $this->artisan('actions:typescript --group=billing')->assertExitCode(1);

        $this->artisan('actions:typescript --group=default')->assertExitCode(0);
    });
});

describe('the registry', function () {
    it('holds every installed group\'s actions', function () {
        expect(array_keys(app(ActionRegistry::class)->all()))->toBe(['charge-card', 'refund-charge', 'rename-team'])
            ->and(array_keys(app(ActionRegistry::class)->agents()))->toBe([BillingDesk::class]);
    });

    it('holds only the core group\'s once the module is gone', function () {
        uninstallBilling();

        expect(array_keys(app(ActionRegistry::class)->all()))->toBe(['rename-team'])
            ->and(app(ActionRegistry::class)->agents())->toBe([])
            ->and(array_map(fn (Group $group): string => $group->name, Groups::installed()))->toBe(['core']);
    });
});

describe('the snapshots', function () {
    it('writes each group\'s snapshot from its own scan', function () {
        $this->artisan('actions:check --update')->run();

        $core = Snapshot::read(base_path($this->root.'/actions.exposure.json'));
        $billing = Snapshot::read(base_path($this->root.'/modules/Billing/actions.exposure.json'));

        expect(array_keys($core['actions']))->toBe(['rename-team'])
            ->and($core['agents'])->toBe([])
            ->and(array_keys($billing['actions']))->toBe(['charge-card', 'refund-charge'])
            ->and(array_keys($billing['agents']))->toBe([BillingDesk::class])
            ->and(File::exists(Snapshot::path()))->toBeFalse();
    });

    it('records each action exactly as one snapshot of every path would', function () {
        $this->artisan('actions:check --update')->run();

        $grouped = array_merge(
            Snapshot::read(base_path($this->root.'/actions.exposure.json'))['actions'],
            Snapshot::read(base_path($this->root.'/modules/Billing/actions.exposure.json'))['actions'],
        );

        ksort($grouped);

        expect($grouped)->toBe(json_decode(File::get($this->fixtures.'/before-groups/actions.exposure.json'), true)['actions']);
    });

    it('passes with the module installed and without it, leaving the core snapshot as it was', function () {
        $this->artisan('actions:check --update')->run();
        $core = File::get(base_path($this->root.'/actions.exposure.json'));

        expect(groupFindings('Snapshot'))->toBe([]);

        uninstallBilling();

        expect(groupFindings('Snapshot'))->toBe([])
            ->and(groupFindings('Discovery'))->toBe([]);

        $this->artisan('actions:check --update')
            ->expectsOutputToContain('Skipped the billing group: none of its paths exists.')
            ->assertExitCode(0);

        expect(File::get(base_path($this->root.'/actions.exposure.json')))->toBe($core);
    });

    it('fails only the group whose snapshot is stale or missing, naming its file', function () {
        $this->artisan('actions:check --update')->run();

        File::put(base_path($this->root.'/modules/Billing/actions.exposure.json'), "{}\n");

        expect(groupFindings('Snapshot'))->toBe([
            "{$this->root}/modules/Billing/actions.exposure.json is stale: review the change, then run php artisan actions:check --update. Added: charge-card, refund-charge. Agents changed.",
        ]);

        File::delete(base_path($this->root.'/actions.exposure.json'));

        expect(groupFindings('Snapshot'))->toContain("{$this->root}/actions.exposure.json is missing: run php artisan actions:check --update");

        $this->artisan('actions:check')->assertExitCode(1);
    });

    it('updates and compares only the groups --group names', function () {
        $this->artisan('actions:check --update --group=core')->run();

        expect(File::exists(base_path($this->root.'/actions.exposure.json')))->toBeTrue()
            ->and(File::exists(base_path($this->root.'/modules/Billing/actions.exposure.json')))->toBeFalse();

        $this->artisan('actions:check --group=core')
            ->doesntExpectOutputToContain('[Snapshot]')
            ->run();

        $this->artisan('actions:check --group=nope')
            ->expectsOutputToContain('There is no discovery group [nope].')
            ->assertExitCode(1);
    });
});

describe('the TypeScript files', function () {
    beforeEach(fn () => mountGroupRoutes());

    it('writes each group\'s actions into its own file, each importing only the npm client', function () {
        $this->artisan('actions:typescript')
            ->expectsOutputToContain("Wrote {$this->root}/resources/js/actions.ts.")
            ->expectsOutputToContain("Wrote {$this->root}/modules/Billing/resources/js/actions.ts.")
            ->assertExitCode(0);

        $core = File::get(base_path($this->root.'/resources/js/actions.ts'));
        $billing = File::get(base_path($this->root.'/modules/Billing/resources/js/actions.ts'));

        expect($core)->toContain('export const renameTeam')
            ->not->toContain('chargeCard')->not->toContain('refundCharge')->not->toContain('Billing')
            ->and($billing)->toContain('export const chargeCard')->toContain('export const refundCharge')
            ->not->toContain('renameTeam')
            ->and(File::exists(base_path($this->root.'/legacy.ts')))->toBeFalse();

        foreach ([$core, $billing] as $file) {
            preg_match_all("/from '([^']+)'/", $file, $imports);

            expect($imports[1])->toBe(['@agentic-actions/client']);
        }

        expect($core)->toBe(app(Emitter::class)->render([RenameTeam::class]));
    });

    it('keeps the core file byte for byte, and checks it, once the module is gone', function () {
        $this->artisan('actions:typescript')->assertExitCode(0);
        $core = File::get(base_path($this->root.'/resources/js/actions.ts'));

        uninstallBilling();
        File::deleteDirectory(base_path($this->root.'/modules'));
        mountGroupRoutes();

        $this->artisan('actions:typescript --check')
            ->expectsOutputToContain("{$this->root}/resources/js/actions.ts is current.")
            ->expectsOutputToContain('Skipped the billing group: none of its paths exists.')
            ->assertExitCode(0);

        $this->artisan('actions:typescript')->assertExitCode(0);

        expect(File::get(base_path($this->root.'/resources/js/actions.ts')))->toBe($core)
            ->and(File::exists(base_path($this->root.'/modules')))->toBeFalse()
            ->and(Route::has('actions.charge-card'))->toBeFalse();
    });

    it('fails --check for the stale group only', function () {
        $this->artisan('actions:typescript')->assertExitCode(0);

        File::put(base_path($this->root.'/modules/Billing/resources/js/actions.ts'), "// old\n");

        $this->artisan('actions:typescript --check')
            ->expectsOutputToContain("{$this->root}/resources/js/actions.ts is current.")
            ->expectsOutputToContain("{$this->root}/modules/Billing/resources/js/actions.ts is stale: run php artisan actions:typescript")
            ->assertExitCode(1);

        $this->artisan('actions:typescript --check --group=core')->assertExitCode(0);
    });

    it('writes no file for a group without typescript', function () {
        config(['agentic-actions.discovery.groups.billing.typescript' => null]);

        $this->artisan('actions:typescript')->assertExitCode(0);

        expect(File::exists(base_path($this->root.'/modules/Billing/resources/js/actions.ts')))->toBeFalse()
            ->and(File::get(base_path($this->root.'/resources/js/actions.ts')))->not->toContain('chargeCard');
    });

    it('warns about a route whose action no group finds, and exports it nowhere', function () {
        $this->mountRoutes(fn () => Route::middleware('web')->post('notes', CreateNote::class)->name('notes.create'));

        $this->artisan('actions:typescript')
            ->expectsOutputToContain('No discovery group finds '.CreateNote::class.', so no file exports its route.')
            ->assertExitCode(0);

        expect(File::get(base_path($this->root.'/resources/js/actions.ts')))->not->toContain('createNote');
    });
});

describe('misconfigured groups', function () {
    it('refuses a class two groups both find', function () {
        config(['agentic-actions.discovery.groups.core.paths' => [$this->root.'/app', $this->root.'/modules/Billing/src']]);

        expect(groupFindings('Discovery'))->toBe([
            'discovery.groups.billing: '.BillingDesk::class.' is also found by discovery.groups.core. A class belongs to one group: narrow the paths so they do not overlap.',
        ]);

        // The registry refuses it too, so a request never serves a class whose owner is unclear.
        expect(fn () => mountGroupRoutes())->toThrow(MisconfiguredDiscovery::class, 'is also found by discovery.groups.core');

        Route::setRoutes(new RouteCollection);

        $this->artisan('actions:typescript')
            ->expectsOutputToContain('is also found by discovery.groups.core')
            ->assertExitCode(1);
    });

    it('refuses an output two groups share', function (string $key) {
        config(["agentic-actions.discovery.groups.billing.{$key}" => config("agentic-actions.discovery.groups.core.{$key}")]);

        expect(fn () => Groups::configured())->toThrow(
            MisconfiguredDiscovery::class,
            "discovery.groups.billing: {$key} [".base_path(config("agentic-actions.discovery.groups.core.{$key}")).'] is already written by discovery.groups.core.',
        );
    })->with(['snapshot', 'typescript']);

    it('refuses a group without paths or snapshot, with an unknown key, or under a bad name', function (array $groups, string $message) {
        config(['agentic-actions.discovery.groups' => $groups]);

        expect(fn () => Groups::configured())->toThrow(MisconfiguredDiscovery::class, $message);

        $this->artisan('actions:check')
            ->expectsOutputToContain('[Discovery] '.$message)
            ->assertExitCode(1);
    })->with([
        'no paths' => [['core' => ['snapshot' => 'a.json']], 'discovery.groups.core: paths is a non-empty list'],
        'no snapshot' => [['core' => ['paths' => ['app']]], 'discovery.groups.core: snapshot is missing.'],
        'a typo' => [['core' => ['paths' => ['app'], 'snapshot' => 'a.json', 'path' => 'x']], 'discovery.groups.core: unknown key [path].'],
        'a list' => [[['paths' => ['app'], 'snapshot' => 'a.json']], 'discovery.groups: [0] is not a group name.'],
        'a bad name' => [['co re' => ['paths' => ['app'], 'snapshot' => 'a.json']], 'discovery.groups: [co re] is not a group name.'],
        'a pattern output' => [['core' => ['paths' => ['app'], 'snapshot' => '*.json']], 'discovery.groups.core: snapshot [*.json] is a pattern.'],
        'optional not a bool' => [['core' => ['paths' => ['app'], 'snapshot' => 'a.json', 'optional' => 'yes']], 'discovery.groups.core: optional is true or false.'],
        'a snapshot that is not JSON' => [['core' => ['paths' => ['app'], 'snapshot' => 'app/Models/User.php']], 'discovery.groups.core: snapshot [app/Models/User.php] is not a .json file.'],
        'a TypeScript file that is not .ts' => [['core' => ['paths' => ['app'], 'snapshot' => 'a.json', 'typescript' => 'routes/web.php']], 'discovery.groups.core: typescript [routes/web.php] is not a .ts file.'],
    ]);

    it('refuses two outputs that are one file on disk, however they are spelled', function (string $before, string $after) {
        config(['agentic-actions.discovery.groups.billing.snapshot' => $before.$this->root.$after]);

        expect(fn () => Groups::configured())->toThrow(MisconfiguredDiscovery::class, 'is already written by discovery.groups.core.snapshot');
    })->with([
        './' => ['./', '/actions.exposure.json'],
        '//' => ['', '//actions.exposure.json'],
        '/./' => ['', '/./actions.exposure.json'],
        'case' => ['', '/ACTIONS.exposure.json'],
    ]);

    it('refuses a class two groups both list', function () {
        config(['agentic-actions.discovery.groups.billing.classes' => [RenameTeam::class]]);

        expect(groupFindings('Discovery'))->toBe([
            'discovery.groups.billing: '.RenameTeam::class.' is also found by discovery.groups.core. A class belongs to one group: narrow the paths so they do not overlap.',
        ]);
    });

    it('refuses one action name in two groups', function () {
        File::copyDirectory($this->fixtures.'/Clash', base_path($this->root.'/modules/Clash/src'));
        config(['agentic-actions.discovery.groups.clash' => [
            'paths' => [$this->root.'/modules/Clash/src'],
            'snapshot' => $this->root.'/modules/Clash/actions.exposure.json',
        ]]);
        $this->refreshActions();

        expect(groupFindings('Names'))->toHaveCount(1)
            ->and(groupFindings('Names')[0])->toContain(RenameTeam::class)->toContain(RenameTeamAgain::class);

        expect(fn () => app(ActionRegistry::class))->toThrow(DuplicateActionName::class);
    });

    it('refuses an output whose directory is a symbolic link out of the app', function (string $key, string $file) {
        $outside = sys_get_temp_dir().'/agentic-groups-outside-'.getmypid();
        File::ensureDirectoryExists($outside);
        symlink($outside, base_path($this->root.'/link'));

        try {
            config(["agentic-actions.discovery.groups.billing.{$key}" => $this->root.'/link/nested/'.$file]);

            expect(fn () => Groups::configured())->toThrow(MisconfiguredDiscovery::class, "discovery.groups.billing: {$key} [{$this->root}/link/nested/{$file}] resolves to [");

            $this->artisan('actions:check --update')->run();
            $this->artisan('actions:typescript')->assertExitCode(1);

            expect(File::allFiles($outside))->toBe([]);
        } finally {
            File::deleteDirectory($outside);
        }
    })->with([['snapshot', 'a.json'], ['typescript', 'a.ts']]);
});

describe('required and optional groups', function () {
    it('fails both checks while a required group\'s path is mistyped, instead of passing on nothing', function () {
        config(['agentic-actions.discovery.groups.core.paths' => [$this->root.'/apq']]);
        $this->refreshActions();
        mountGroupRoutes();

        expect(groupFindings('Discovery'))->toBe([
            "discovery.groups.core: none of its paths exists ({$this->root}/apq), so none of its actions is found or checked. Fix the path, or set 'optional' => true on a group a deployment may leave out.",
        ]);

        $this->artisan('actions:check')->assertExitCode(1);
        $this->artisan('actions:check --update')
            ->expectsOutputToContain("Did not write the core group's snapshot: none of its paths exists (see the Discovery row).")
            ->assertExitCode(1);
        $this->artisan('actions:typescript --check')
            ->expectsOutputToContain("The core group is not installed: none of its paths exists ({$this->root}/apq).")
            ->assertExitCode(1);
        $this->artisan('actions:typescript')->assertExitCode(1);
    });

    it('fails when no group is installed at all', function () {
        config(['agentic-actions.discovery.groups.core.optional' => true]);
        File::deleteDirectory(base_path($this->root.'/app'));
        uninstallBilling();
        mountGroupRoutes();

        expect(groupFindings('Discovery'))->toBe([
            'No discovery group is installed: none of the paths of any group in discovery.groups exists, so nothing is checked.',
        ]);

        $this->artisan('actions:check')->assertExitCode(1);
        $this->artisan('actions:typescript --check')
            ->expectsOutputToContain('No discovery group is installed')
            ->assertExitCode(1);
    });

    it('fails a --group that names a group not installed here', function () {
        mountGroupRoutes();
        $this->artisan('actions:check --update')->run();
        $this->artisan('actions:typescript')->assertExitCode(0);

        uninstallBilling();
        mountGroupRoutes();

        $this->artisan('actions:check --group=billing')
            ->expectsOutputToContain('[Discovery] discovery.groups.billing is not installed here: none of its paths exists, so --group=billing checks nothing.')
            ->assertExitCode(1);
        $this->artisan('actions:typescript --check --group=billing')
            ->expectsOutputToContain('The billing group is not installed')
            ->assertExitCode(1);

        // Without --group, an optional group that is missing passes.
        $this->artisan('actions:check')->assertExitCode(0);
        $this->artisan('actions:typescript --check')->assertExitCode(0);
    });

    it('warns while discovery.paths or discovery.classes lists what groups make it ignore', function (array $config) {
        config($config);

        expect(groupFindings('Discovery'))->toBe([
            'discovery.groups is set, so discovery.paths and discovery.classes are ignored, with what they list. Move each path and class into a group, then remove both keys or set them back to their defaults.',
        ]);
    })->with([
        'paths' => [['agentic-actions.discovery.paths' => ['app', 'modules']]],
        'classes' => [['agentic-actions.discovery.classes' => [CreateNote::class]]],
    ]);
});

describe('TypeScript names across groups', function () {
    it('refuses an export one group\'s file takes that another group\'s file takes too', function () {
        mountGroupRoutes();
        $this->mountRoutes(fn () => Route::middleware('web')->post('billing/rename', ChargeCard::class)->name('rename-team'));

        expect(fn () => $this->artisan('actions:typescript')->run())
            ->toThrow(LogicException::class, 'The action [rename-team] is exported to TypeScript as [renameTeam], a name the route [rename-team] already takes.');

        expect(File::exists(base_path($this->root.'/resources/js/actions.ts')))->toBeFalse()
            ->and(File::exists(base_path($this->root.'/modules/Billing/resources/js/actions.ts')))->toBeFalse();
    });
});
