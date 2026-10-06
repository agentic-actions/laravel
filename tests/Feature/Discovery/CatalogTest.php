<?php

use AgenticActions\ActionContext;
use AgenticActions\Datasets\Measure;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Catalog;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Support\Packages;
use AgenticActions\Support\PackageStatus;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\Discovery\Misdeclared\PostCounts;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The agent surface opens only with laravel/ai, so every case needs it. The support toolset comes from
 * tests/Fixtures/Discovery/Toolsets, which only this file scans.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    $this->fixtures = dirname(__DIR__, 2).'/Fixtures';

    config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Toolsets']]);
    $this->refreshActions();

    Auth::shouldUse('web');

    $this->user = User::factory()->create();
    $this->staff = User::factory()->create(['email' => 'lina@staff.test']);
});

afterEach(function () {
    // Teardown runs commands that ask for confirmation in production.
    $this->app['env'] = 'testing';
    PostCounts::$measures = null;
});

/**
 * The names the catalog lists for a context and its toolsets.
 *
 * @param  list<string>  $toolsets
 * @return list<string>
 */
function discoveryCatalog(ActionContext $context, array $toolsets): array
{
    return array_map(fn (Entry $entry): string => $entry->name, app(Catalog::class)->forAgents($context, $toolsets));
}

it('lists what a toolset holds for this context, sorted by name', function () {
    expect(discoveryCatalog(ActionContext::agent($this->user), ['default']))
        ->toBe(['create-note', 'late-authorize', 'list-notes', 'team-note', 'translated-note'])
        ->and(discoveryCatalog(ActionContext::agent($this->user), ['support']))->toBe(['support-note'])
        ->and(discoveryCatalog(ActionContext::agent($this->user), ['support', 'default']))
        ->toBe(['create-note', 'late-authorize', 'list-notes', 'support-note', 'team-note', 'translated-note'])
        ->and(discoveryCatalog(ActionContext::agent($this->user), ['nobody']))->toBe([])
        ->and(discoveryCatalog(ActionContext::agent($this->user), []))->toBe([]);
});

it('treats any context as the agent surface', function () {
    expect(discoveryCatalog(ActionContext::http($this->user), ['default']))->toBe(discoveryCatalog(ActionContext::agent($this->user), ['default']));
});

it('reads what the class declares now, not what the registry nominated', function () {
    $registry = app(ActionRegistry::class);

    // laravel/ai goes away after the registry loaded: the class now skips the agent surface.
    ClassExposure::flush();
    $this->app->instance(Packages::class, new Packages(['laravel/ai' => PackageStatus::Missing]));

    expect(app(ActionRegistry::class))->toBe($registry)
        ->and($registry->on(Surface::Agent))->not->toBe([])
        ->and(discoveryCatalog(ActionContext::agent($this->user), ['default']))->toBe([]);
});

it('filters tenant-scoped actions by the context\'s tenant and its membership', function () {
    $this->useTeamTenancy();

    $team = Team::factory()->create();
    $team->users()->attach($this->user);
    $archived = Team::factory()->create(['slug' => 'archived-2025']);
    $archived->users()->attach($this->user);
    $foreign = Team::factory()->create();

    expect(discoveryCatalog(ActionContext::agent($this->user), ['default']))->toBe([])
        ->and(discoveryCatalog(ActionContext::agent($this->user, $team), ['default']))
        ->toBe(['create-note', 'late-authorize', 'list-notes', 'team-note', 'translated-note'])
        // TeamMembership refuses writes to an archived team; its Reads stay.
        ->and(discoveryCatalog(ActionContext::agent($this->user, $archived), ['default']))->toBe(['list-notes'])
        ->and(discoveryCatalog(ActionContext::agent($this->user, $foreign), ['default']))->toBe([]);
});

it('throws for an action whose advertised input holds a forbidden key in a test run', function () {
    config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Forbidden']]);
    $this->refreshActions();

    expect(fn () => discoveryCatalog(ActionContext::agent($this->user), ['default']))
        ->toThrow(MisconfiguredExposure::class, 'api_key');
});

it('drops an action whose advertised input holds a forbidden key in production, and reports it', function () {
    Exceptions::fake();
    config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Forbidden']]);
    $this->refreshActions();
    $this->app['env'] = 'production';

    expect(app(ActionRegistry::class)->find('leaky-note')?->allows(Surface::Agent))->toBeTrue()
        ->and(discoveryCatalog(ActionContext::agent($this->user), ['default']))
        ->toBe(['create-note', 'late-authorize', 'list-notes', 'team-note', 'translated-note']);

    Exceptions::assertReported(fn (MisconfiguredExposure $exception): bool => str_contains($exception->getMessage(), 'api_key'));
});

it('throws for a dataset whose declaration throws in a test run and locally', function (string $environment) {
    config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Misdeclared']]);
    $this->refreshActions();
    PostCounts::$measures = fn (): array => [Measure::count('posts', 'Posts')->where('title', 'like', '%a%')];
    $this->app['env'] = $environment;

    expect(fn () => discoveryCatalog(ActionContext::agent($this->user), ['default']))
        ->toThrow(MisconfiguredExposure::class, PostCounts::class.': agents cannot be offered it: The measure [posts] compares with [like]: use one of = != <> < <= > >=. The tool is left out.');
})->with(['a test run' => 'testing', 'locally' => 'local']);

it('leaves out only a dataset whose declaration throws in production, for agents and MCP, and reports it once, with the declaration\'s own exception attached', function (Closure $measures, string $error, string $cause) {
    Exceptions::fake();
    config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Misdeclared']]);
    $this->refreshActions();
    PostCounts::$measures = $measures;
    $this->app['env'] = 'production';

    // MCP counts only the abilities a token names.
    Sanctum::actingAs($this->user, ['actions:read', 'actions:write']);
    $working = ['create-note', 'late-authorize', 'list-notes', 'team-note', 'translated-note'];
    $nominated = app(ActionRegistry::class)->find('post-counts');

    expect($nominated?->toolsets)->toBe(['default'])
        ->and($nominated?->allows(Surface::Mcp))->toBeTrue()
        ->and(discoveryCatalog(ActionContext::agent($this->user), ['default']))->toBe($working)
        ->and(array_map(fn (Entry $entry): string => $entry->name, app(Catalog::class)->forMcp(ActionContext::mcp($this->user, null))))->toBe($working);

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (MisconfiguredExposure $exception): bool => $exception->getMessage() === PostCounts::class.": agents cannot be offered it: {$error} The tool is left out."
        && $exception->getPrevious() instanceof $cause);
})->with([
    'an operator where() refuses' => [fn (): array => [Measure::count('posts', 'Posts')->where('title', 'like', '%a%')], 'The measure [posts] compares with [like]: use one of = != <> < <= > >=.', InvalidArgumentException::class],
    'a named value, which PHP refuses' => [fn (): array => [Measure::count('posts', 'Posts')->where('title', value: 'Launch')], Measure::class.'::where(): Argument #2 ($operator) not passed.', ArgumentCountError::class],
]);

it('runs the token check', function () {
    Sanctum::actingAs($this->user, ['actions:read']);

    expect(discoveryCatalog(ActionContext::agent($this->user), ['default']))->toBe(['list-notes']);

    Sanctum::actingAs($this->user, ['actions:read', 'actions:write']);

    expect(discoveryCatalog(ActionContext::agent($this->user), ['default']))
        ->toBe(['create-note', 'late-authorize', 'list-notes', 'team-note', 'translated-note']);
});

it('offers a guest only the actions that allow guests', function () {
    expect(discoveryCatalog(ActionContext::agent(null), ['default']))->toBe([])
        ->and(discoveryCatalog(ActionContext::agent(null), ['support']))->toBe(['support-note']);
});

it('runs shouldRegister() and the input-free authorize()', function () {
    expect(discoveryCatalog(ActionContext::agent($this->user), ['default']))->not->toContain('hidden-note')
        ->and(discoveryCatalog(ActionContext::agent($this->user), ['support']))->not->toContain('staff-note')
        ->and(discoveryCatalog(ActionContext::agent($this->staff), ['support']))->toBe(['staff-note', 'support-note']);
});

it('is empty while surfaces.agents is off', function () {
    config(['agentic-actions.surfaces.agents' => false]);

    expect(app(Catalog::class)->forAgents(ActionContext::agent($this->user), ['default', 'support']))->toBe([]);
});
