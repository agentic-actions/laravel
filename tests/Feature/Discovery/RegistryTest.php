<?php

use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Manifest;
use AgenticActions\Exceptions\DuplicateActionName;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Support\PackageStatus;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Discovery\Agents\BlogWriter;
use Tests\Fixtures\Discovery\Agents\SupportDesk;
use Tests\Fixtures\Misconfigured\DuplicateNameA;
use Tests\Fixtures\Misconfigured\DuplicateNameB;
use Tests\Fixtures\Misconfigured\UndeclaredEffect;

/*
 * A PHPUnit or Pest run is a console process, so every case here scans. Each case sets the environment itself and
 * loads the registry directly; WebRegistryTest covers web processes.
 */

beforeEach(function () {
    $this->fixtures = dirname(__DIR__, 2).'/Fixtures';
    $this->manifest = Manifest::path($this->app);

    File::delete($this->manifest);
});

afterEach(function () {
    File::delete($this->manifest);

    // Teardown runs commands that ask for confirmation in production.
    $this->app['env'] = 'testing';
});

/**
 * The names of a list of entries.
 *
 * @param  list<Entry>  $entries
 * @return list<string>
 */
function registryEntryNames(array $entries): array
{
    return array_map(fn (Entry $entry): string => $entry->name, $entries);
}

it('scans in a test run and in any console process, even beside a fresh manifest', function (string $environment) {
    File::put($this->manifest, '<?php return '.var_export(['version' => ActionRegistry::VERSION, 'actions' => [], 'agents' => []], true).';');

    $this->app['env'] = $environment;

    $registry = ActionRegistry::load($this->app);

    // The manifest nominates nothing, so every candidate came from the scan.
    expect($registry->all())->toHaveKey('create-note');
})->with(['testing', 'production', 'local']);

it('is the container\'s singleton, and refreshActions() forgets it', function () {
    $registry = app(ActionRegistry::class);

    expect(app(ActionRegistry::class))->toBe($registry);

    $this->refreshActions();

    expect(app(ActionRegistry::class))->not->toBe($registry);
});

it('finds a candidate by name or by class', function () {
    $registry = ActionRegistry::load($this->app);

    expect($registry->find('create-note')?->class)->toBe(CreateNote::class)
        ->and($registry->find(CreateNote::class)?->name)->toBe('create-note')
        ->and($registry->find('\\'.CreateNote::class)?->name)->toBe('create-note')
        ->and($registry->find('nothing'))->toBeNull()
        ->and($registry->find('App\\Actions\\Nothing'))->toBeNull();
});

it('lists the candidates each surface nominates', function () {
    $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

    $registry = ActionRegistry::load($this->app);
    $web = registryEntryNames($registry->on(Surface::Http));
    $mcp = registryEntryNames($registry->on(Surface::Mcp));

    // MCP does not depend on laravel/ai: a bare Read or Write with a description is nominated without it.
    expect(registryEntryNames($registry->on(Surface::Console)))->toBe(array_keys($registry->all()))
        ->and($web)->toContain('create-note', 'delete-note', 'publish-note')
        ->and($web)->not->toContain('plain-note')
        ->and($web)->not->toContain('sync-action-items')
        ->and($registry->on(Surface::Agent))->toBe([])
        ->and($mcp)->toContain('create-note', 'list-notes')
        ->and($mcp)->not->toContain('delete-note')
        ->and($mcp)->not->toContain('publish-note')
        ->and($mcp)->not->toContain('plain-note')
        ->and($mcp)->not->toContain('sync-action-items');
});

it('nominates agent candidates once laravel/ai is installed', function () {
    $this->skipUnlessAi();

    $agents = registryEntryNames(ActionRegistry::load($this->app)->on(Surface::Agent));

    expect($agents)->toContain('create-note', 'list-notes')
        ->and($agents)->not->toContain('delete-note')
        ->and($agents)->not->toContain('publish-note')
        ->and($agents)->not->toContain('plain-note');
});

it('records the #[UseToolset] agents it scanned', function () {
    config(['agentic-actions.discovery.paths' => [$this->fixtures.'/Actions', $this->fixtures.'/Discovery/Agents']]);

    expect(ActionRegistry::load($this->app)->agents())->toBe([BlogWriter::class => ['default'], SupportDesk::class => ['support']]);
});

it('throws scan errors in a test run', function () {
    config(['agentic-actions.discovery.classes' => [UndeclaredEffect::class]]);

    expect(fn () => ActionRegistry::load($this->app))
        ->toThrow(MisconfiguredExposure::class, UndeclaredEffect::class.': http: effect undeclared');
});

it('loads past scan errors in any other console process, reporting nothing, and keeps the refused surfaces closed', function (string $environment) {
    Exceptions::fake();
    config(['agentic-actions.discovery.classes' => [UndeclaredEffect::class]]);
    $this->app['env'] = $environment;

    $registry = ActionRegistry::load($this->app);

    expect($registry->find(UndeclaredEffect::class)?->surfaces)->toBe([Surface::Console])
        ->and($registry->find('create-note'))->not->toBeNull();

    Exceptions::assertNothingReported();
})->with(['production', 'local']);

it('lets a duplicate name stop the load in every context', function (string $environment) {
    config(['agentic-actions.discovery.classes' => [DuplicateNameA::class, DuplicateNameB::class]]);
    $this->app['env'] = $environment;

    expect(fn () => ActionRegistry::load($this->app))->toThrow(DuplicateActionName::class);
})->with(['testing', 'production']);
