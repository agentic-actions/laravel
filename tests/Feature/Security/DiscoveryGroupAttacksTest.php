<?php

use AgenticActions\ActionContext;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Catalog;
use AgenticActions\Discovery\Groups;
use AgenticActions\Exceptions\MisconfiguredDiscovery;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\Discovery\Groups\Billing\ChargeCard;
use Tests\Fixtures\Discovery\Groups\Billing\RefundCharge;
use Workbench\App\Models\User;

/*
 * A deployment that leaves an optional module out, and a config that tries to point discovery or its outputs outside
 * the app. A module that is not installed must reach no route, no agent and no MCP client, even while its classes still
 * autoload; grouping must not change what any action exposes; and a group's paths never leave the base path.
 */

beforeEach(function () {
    // Every group fixture offers agents, an exposure error without laravel/ai.
    $this->skipUnlessAi();

    $this->fixtures = dirname(__DIR__, 2).'/Fixtures/Discovery/Groups';
    $this->root = 'agentic-group-attacks-'.getmypid();
    $this->outside = sys_get_temp_dir().'/agentic-group-attacks-outside-'.getmypid();

    File::copyDirectory($this->fixtures.'/Core', base_path($this->root.'/app'));
    File::copyDirectory($this->fixtures.'/Billing', base_path($this->root.'/modules/Billing/src'));

    config(['agentic-actions.discovery.paths' => ['app'], 'agentic-actions.discovery.classes' => [], 'agentic-actions.discovery.groups' => [
        'core' => [
            'paths' => [$this->root.'/app'],
            'snapshot' => $this->root.'/actions.exposure.json',
            'typescript' => $this->root.'/actions.ts',
        ],
        'billing' => [
            'paths' => [$this->root.'/modules/Billing/src'],
            'snapshot' => $this->root.'/modules/Billing/actions.exposure.json',
            'typescript' => $this->root.'/modules/Billing/actions.ts',
            'optional' => true,
        ],
    ]]);

    $this->refreshActions();

    Auth::shouldUse('web');

    $this->user = User::factory()->create();
    $this->billing = User::factory()->create(['email' => 'billing@example.test']);
});

afterEach(function () {
    if (! isset($this->root)) {
        return;
    }

    File::deleteDirectory(base_path($this->root));
    File::deleteDirectory($this->outside);
    ChargeCard::$registers = true;
});

/**
 * Register Actions::routes() from scratch, as the next boot would.
 */
function mountAttackRoutes(): void
{
    Route::setRoutes(new RouteCollection);

    test()->mountRoutes(fn () => Route::middleware('web')->group(fn () => Actions::routes()));
}

/**
 * Everything each door offers this person: routes, agent tools for the default and billing toolsets, and MCP tools.
 *
 * @return array{routes: list<string>, agents: list<string>, mcp: list<string>}
 */
function attackSurface(User $user): array
{
    mountAttackRoutes();

    // Each call starts from the same signed-in state, so two calls compare like for like.
    Auth::forgetGuards();
    Auth::shouldUse('web');
    Auth::login($user);

    $names = fn (array $entries): array => array_map(fn (Entry $entry): string => $entry->name, $entries);
    $routes = array_values(array_filter(
        array_map(fn ($route): ?string => $route->getName(), Route::getRoutes()->getRoutes()),
        fn (?string $name): bool => $name !== null && str_starts_with($name, 'actions.'),
    ));

    sort($routes);

    return [
        'routes' => $routes,
        'agents' => $names(app(Catalog::class)->forAgents(ActionContext::agent($user), ['default', 'billing'])),
        // MCP counts only the abilities a token names.
        'mcp' => $names((function () use ($user): array {
            Sanctum::actingAs($user, ['actions:read', 'actions:write', 'actions:destructive', 'actions:external']);

            return app(Catalog::class)->forMcp(ActionContext::mcp($user, null));
        })()),
    ];
}

describe('a module that is not installed', function () {
    beforeEach(function () {
        // The module's folder is gone, but its classes still autoload and a stale config still lists them.
        File::deleteDirectory(base_path($this->root.'/modules'));
        config(['agentic-actions.discovery.groups.billing.classes' => [ChargeCard::class, RefundCharge::class]]);

        $this->refreshActions();

        expect(class_exists(ChargeCard::class))->toBeTrue();
    });

    it('nominates none of its actions or agents', function () {
        expect(array_keys(app(ActionRegistry::class)->all()))->toBe(['rename-team'])
            ->and(app(ActionRegistry::class)->find('charge-card'))->toBeNull()
            ->and(app(ActionRegistry::class)->find(ChargeCard::class))->toBeNull()
            ->and(app(ActionRegistry::class)->agents())->toBe([]);
    });

    it('adds no route, and its old URL answers 404', function () {
        mountAttackRoutes();

        expect(Route::has('actions.charge-card'))->toBeFalse()
            ->and(Route::has('actions.refund-charge'))->toBeFalse()
            ->and(Route::has('actions.rename-team'))->toBeTrue();

        $this->actingAs($this->billing)->postJson('/actions/charge-card', ['cents' => 100])->assertNotFound();
    });

    it('offers no agent tool and no MCP tool, by listing or by name', function () {
        $this->skipUnlessAi();

        expect(attackSurface($this->billing)['agents'])->toBe(['rename-team'])
            ->and(attackSurface($this->billing)['mcp'])->toBe(['rename-team'])
            ->and(app(Catalog::class)->mcpTool(ActionContext::mcp($this->billing, null), 'charge-card'))->toBeNull()
            ->and(app(Catalog::class)->mcpTool(ActionContext::mcp($this->billing, null), 'rename-team'))->not->toBeNull();

        $outcome = Actions::call(['billing'], 'charge-card', ['cents' => 100], ActionContext::agent($this->billing));

        expect($outcome->kind())->toBe(OutcomeKind::NotFound)
            ->and($outcome->ok())->toBeFalse();
    });

    it('is not found by name in process either', function () {
        expect(Actions::attempt('charge-card', ['cents' => 100], ActionContext::http($this->billing))->kind())->toBe(OutcomeKind::NotFound);
    });

    it('writes and checks none of its files', function () {
        mountAttackRoutes();

        $this->artisan('actions:check --update')->run();
        $this->artisan('actions:typescript')->assertExitCode(0);

        expect(File::exists(base_path($this->root.'/modules')))->toBeFalse()
            ->and(File::get(base_path($this->root.'/actions.ts')))->not->toContain('chargeCard')
            ->and(File::get(base_path($this->root.'/actions.exposure.json')))->not->toContain('charge-card');
    });
});

describe('an installed module', function () {
    it('reaches exactly the doors it reaches without groups', function () {
        $this->skipUnlessAi();

        $grouped = [attackSurface($this->user), attackSurface($this->billing)];

        config([
            'agentic-actions.discovery.groups' => [],
            'agentic-actions.discovery.paths' => [$this->fixtures.'/Core', $this->fixtures.'/Billing'],
        ]);
        $this->refreshActions();

        // authorize() keeps charge-card from everyone but the billing contact, and refund-charge, Destructive, is an
        // MCP tool for no one, grouped or not.
        expect([attackSurface($this->user), attackSurface($this->billing)])->toBe($grouped)
            ->and($grouped[0]['agents'])->toBe(['rename-team'])
            ->and($grouped[0]['mcp'])->toBe(['rename-team'])
            ->and($grouped[1]['agents'])->toBe(['charge-card', 'rename-team'])
            ->and($grouped[1]['mcp'])->toBe(['charge-card', 'rename-team'])
            ->and($grouped[1]['routes'])->toContain('actions.charge-card', 'actions.refund-charge', 'actions.rename-team');
    });

    it('keeps each action\'s live exposure: the registry entry is the class\'s own', function () {
        foreach (app(ActionRegistry::class)->all() as $entry) {
            expect($entry->toSnapshot())->toBe(ClassExposure::of($entry->class)->toSnapshot());
        }
    });

    it('still honours the surface switches', function (string $switch) {
        $this->skipUnlessAi();

        config(["agentic-actions.surfaces.{$switch}" => false]);

        $surface = attackSurface($this->billing);

        expect($surface[$switch === 'web' ? 'routes' : $switch])->toBe([]);
    })->with(['agents', 'mcp']);

    it('still honours shouldRegister()', function () {
        $this->skipUnlessAi();

        ChargeCard::$registers = false;

        $surface = attackSurface($this->billing);

        expect($surface['agents'])->not->toContain('charge-card')
            ->and($surface['mcp'])->not->toContain('charge-card')
            ->and(Actions::attempt('charge-card', ['cents' => 100], ActionContext::http($this->billing))->kind())->toBe(OutcomeKind::NotFound);
    });

    it('still honours authorize()', function () {
        expect(Actions::attempt('charge-card', ['cents' => 100], ActionContext::http($this->user))->ok())->toBeFalse()
            ->and(Actions::attempt('charge-card', ['cents' => 100], ActionContext::http($this->billing))->result())->toBe(100);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        mountAttackRoutes();

        $this->actingAs($this->user)->postJson('/actions/charge-card', ['cents' => 100])->assertForbidden();
    });

    it('still waits for a person before a Destructive call from an agent', function () {
        $this->skipUnlessAi();

        $outcome = Actions::call(['billing'], 'refund-charge', ['charge' => 'ch_1'], ActionContext::agent($this->billing));

        expect($outcome->ok())->toBeFalse()
            ->and($outcome->kind())->not->toBe(OutcomeKind::Ok);
    });

    it('writes no configuration into its files', function () {
        config(['app.key' => 'base64:'.base64_encode('CANARY-APP-KEY-CANARY-APP-KEY-32')]);

        mountAttackRoutes();

        $this->artisan('actions:check --update')->run();
        $this->artisan('actions:typescript')->assertExitCode(0);

        foreach (['/actions.ts', '/actions.exposure.json', '/modules/Billing/actions.ts', '/modules/Billing/actions.exposure.json'] as $file) {
            expect(File::get(base_path($this->root.$file)))
                ->not->toContain(base_path())
                ->not->toContain('billing@example.test')
                ->not->toContain(config('app.key'))
                ->not->toContain($this->root);
        }
    });
});

describe('a module that joins a core toolset', function () {
    beforeEach(function () {
        File::copyDirectory($this->fixtures.'/Support', base_path($this->root.'/modules/Support/src'));
        config(['agentic-actions.discovery.groups.support' => [
            'paths' => [$this->root.'/modules/Support/src'],
            'snapshot' => $this->root.'/modules/Support/actions.exposure.json',
            'optional' => true,
        ]]);

        $this->refreshActions();
    });

    it('widens the core agent while installed, recorded in the module\'s snapshot, not the core\'s', function () {
        $this->skipUnlessAi();

        $agents = fn (): array => array_map(fn (Entry $entry): string => $entry->name, app(Catalog::class)->forAgents(ActionContext::agent($this->user), ['default']));

        $this->artisan('actions:check --update')->run();
        $core = File::get(base_path($this->root.'/actions.exposure.json'));

        expect($agents())->toBe(['open-ticket', 'rename-team'])
            ->and($core)->not->toContain('open-ticket')
            ->and(json_decode(File::get(base_path($this->root.'/modules/Support/actions.exposure.json')), true)['actions']['open-ticket']['agents'])->toBe(['default']);

        File::deleteDirectory(base_path($this->root.'/modules/Support'));
        $this->refreshActions();

        expect($agents())->toBe(['rename-team']);

        $this->artisan('actions:check --update')->run();

        expect(File::get(base_path($this->root.'/actions.exposure.json')))->toBe($core);
    });
});

describe('a path outside the app', function () {
    it('is refused before anything is scanned or written', function (string $key, Closure $value) {
        File::ensureDirectoryExists($this->outside);
        File::copyDirectory($this->fixtures.'/Billing', $this->outside.'/src');

        config(["agentic-actions.discovery.groups.billing.{$key}" => $value($this)]);

        expect(fn () => Groups::configured())->toThrow(MisconfiguredDiscovery::class);

        // The registry fails closed: no surface opens on a guess.
        $this->refreshActions();

        expect(fn () => app(ActionRegistry::class))->toThrow(MisconfiguredDiscovery::class);

        $this->artisan('actions:check --update')
            ->expectsOutputToContain('[Discovery] discovery.groups.billing:')
            ->assertExitCode(1);

        $this->artisan('actions:typescript')->assertExitCode(1);

        expect(File::files($this->outside))->toBe([])
            ->and(File::exists(base_path($this->root.'/actions.exposure.json')))->toBeFalse();
    })->with([
        'a relative root that steps out' => ['paths', fn ($test) => ['../'.basename($test->outside).'/src']],
        'a root with ".." inside it' => ['paths', fn ($test) => [$test->root.'/modules/../../../outside']],
        'a Windows-style ".." root' => ['paths', fn ($test) => [$test->root.'\\..\\..\\outside']],
        'an absolute root outside the base path' => ['paths', fn ($test) => [$test->outside.'/src']],
        'the filesystem root' => ['paths', fn () => ['/']],
        'a glob that steps out' => ['paths', fn () => ['../*']],
        'a snapshot that steps out' => ['snapshot', fn ($test) => '../'.basename($test->outside).'/actions.exposure.json'],
        'an absolute snapshot outside the base path' => ['snapshot', fn ($test) => $test->outside.'/actions.exposure.json'],
        'a TypeScript file that steps out' => ['typescript', fn () => '../../actions.ts'],
        'an absolute TypeScript file outside the base path' => ['typescript', fn ($test) => $test->outside.'/actions.ts'],
        'a null byte' => ['snapshot', fn ($test) => $test->root."/a.json\0.ts"],
    ]);

    it('refuses a root that is a symbolic link out of the app, alone or through a glob', function (string $path) {
        File::copyDirectory($this->fixtures.'/Billing', $this->outside.'/src');
        File::deleteDirectory(base_path($this->root.'/modules/Billing/src'));
        symlink($this->outside.'/src', base_path($this->root.'/modules/Billing/src'));

        config(['agentic-actions.discovery.groups.billing.paths' => [str_replace('{root}', $this->root, $path)]]);
        $this->refreshActions();

        expect(fn () => Groups::configured())->toThrow(MisconfiguredDiscovery::class, 'outside the base path');

        expect(fn () => app(ActionRegistry::class))->toThrow(MisconfiguredDiscovery::class);
    })->with(['{root}/modules/Billing/src', '{root}/modules/*/src']);

    it('accepts an absolute path inside the base path', function () {
        config(['agentic-actions.discovery.groups.billing.paths' => [base_path($this->root.'/modules/Billing/src')]]);
        $this->refreshActions();

        expect(array_keys(app(ActionRegistry::class)->all()))->toBe(['charge-card', 'refund-charge', 'rename-team']);
    });
});
