<?php

use AgenticActions\ActionContext;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Catalog;
use AgenticActions\Discovery\Manifest;
use AgenticActions\Discovery\ManifestWriter;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;
use Workbench\App\Models\User;

/*
 * Production web processes, as in WebRegistryTest, reading hand-written manifests. The manifest only nominates:
 * every gate re-reads the class, so a manifest that says more than the class can only narrow what is exposed.
 */

beforeAll(function () {
    $_SERVER['APP_RUNNING_IN_CONSOLE'] = $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
    $_SERVER['APP_ROUTES_CACHE'] = $_ENV['APP_ROUTES_CACHE'] = sys_get_temp_dir().'/agentic-actions-routes-'.getmypid().'.php';
});

afterAll(function () {
    unset($_SERVER['APP_RUNNING_IN_CONSOLE'], $_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_ROUTES_CACHE'], $_ENV['APP_ROUTES_CACHE']);
});

beforeEach(function () {
    config(['cache.default' => 'array']);
    Auth::shouldUse('web');

    $this->manifest = Manifest::path($this->app);
    $this->user = User::factory()->create();
    $this->app['env'] = 'production';

    File::delete($this->manifest);
});

afterEach(function () {
    File::delete($this->manifest);

    // Teardown runs commands that ask for confirmation in production.
    $this->app['env'] = 'testing';
});

/**
 * Write a manifest by hand, as an older release would have left it, and forget the loaded registry.
 *
 * @param  array<string, array<string, mixed>>  $actions
 */
function writeStaleManifest(array $actions): void
{
    File::put(
        Manifest::path(app()),
        '<?php return '.var_export(['version' => ActionRegistry::VERSION, 'actions' => $actions, 'agents' => []], true).';',
    );

    ClassExposure::flush();
    app()->forgetInstance(ActionRegistry::class);
}

/**
 * The names of a list of entries.
 *
 * @param  list<Entry>  $entries
 * @return list<string>
 */
function staleManifestNames(array $entries): array
{
    return array_map(fn (Entry $entry): string => $entry->name, $entries);
}

describe('a stale manifest only narrows', function () {
    it('leaves a class that now declares another name out of the catalog, since no tool call could reach it', function () {
        $this->skipUnlessAi();

        writeStaleManifest([
            'create-note' => ClassExposure::of(CreateNote::class)->toManifest(),
            'old-note' => ['name' => 'old-note'] + ClassExposure::of(ListNotes::class)->toManifest(),
        ]);

        expect(staleManifestNames(app(Catalog::class)->forAgents(ActionContext::agent($this->user), ['default'])))->toBe(['create-note'])
            ->and(Actions::attempt('list-notes', [], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::NotFound)
            ->and(Actions::attempt('old-note', [], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::NotFound);
    });
});

describe('a manifest that omits an action', function () {
    it('hides it from name lookups until the next cache', function () {
        writeStaleManifest(['create-note' => ClassExposure::of(CreateNote::class)->toManifest()]);

        expect(app(ActionRegistry::class)->find('list-notes'))->toBeNull()
            ->and(Actions::attempt('list-notes', [], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::NotFound)
            // A class-string names its class, so it needs no discovery.
            ->and(Actions::attempt(ListNotes::class, [], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::Ok);

        app(ManifestWriter::class)->write();
        app()->forgetInstance(ActionRegistry::class);

        expect(app(ActionRegistry::class)->find('list-notes')?->class)->toBe(ListNotes::class)
            ->and(Actions::attempt('list-notes', [], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::Ok);
    });

    it('hides it from the catalog until the next cache', function () {
        $this->skipUnlessAi();

        writeStaleManifest(['create-note' => ClassExposure::of(CreateNote::class)->toManifest()]);

        expect(staleManifestNames(app(Catalog::class)->forAgents(ActionContext::agent($this->user), ['default'])))->toBe(['create-note']);
    });
});
