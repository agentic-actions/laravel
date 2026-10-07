<?php

use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Manifest;
use AgenticActions\Discovery\ManifestWriter;
use AgenticActions\Discovery\Scanner;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exceptions\StaleActionManifest;
use AgenticActions\Exposure\Entry;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Discovery\Deferring\DeferringDesk;
use Tests\Fixtures\Discovery\Deferring\SearchDesk;
use Tests\Fixtures\Misconfigured\UndeclaredEffect;

/*
 * Each application in this file boots as a web process: runningInConsole() reads APP_RUNNING_IN_CONSOLE and
 * memoizes the answer at its first call, so the variable is set before any test's application exists. The route
 * cache path points at a scratch file, so a test that creates one never touches the Testbench skeleton.
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

    // The MCP mount asks the registry at boot, which spends the hourly report before a test fakes the handler.
    Cache::forget('agentic-actions:manifest-missing');

    $this->manifest = Manifest::path($this->app);
    $this->routes = $this->app->getCachedRoutesPath();

    File::delete([$this->manifest, $this->routes]);
});

afterEach(function () {
    File::delete([$this->manifest, $this->routes]);

    // Teardown runs commands that ask for confirmation in production.
    $this->app['env'] = 'testing';
});

/**
 * Write the manifest for the configured paths, then point discovery at none: from here on a scan finds nothing, so a
 * registry that holds any action read it from the manifest.
 *
 * @return array<string, Entry> the actions the manifest holds
 */
function writeManifestThenScanNothing(): array
{
    $actions = app(Scanner::class)->scan(config('agentic-actions.discovery.paths'))->actions;

    app(ManifestWriter::class)->write();
    config(['agentic-actions.discovery.paths' => []]);

    return $actions;
}

it('boots as a web process', function () {
    expect($this->app->runningInConsole())->toBeFalse()
        ->and($this->routes)->toStartWith(sys_get_temp_dir());
});

it('reads a fresh manifest in production', function () {
    $manifest = writeManifestThenScanNothing();
    $this->app['env'] = 'production';

    expect(ActionRegistry::load($this->app)->all())->toEqual($manifest)->toHaveKey('create-note');
});

it('reads the agents from a fresh manifest in production, with both lists of one that defers toolsets', function () {
    config(['agentic-actions.discovery.paths' => [dirname(__DIR__, 2).'/Fixtures/Discovery/Agents', dirname(__DIR__, 2).'/Fixtures/Discovery/Deferring']]);
    $scanned = Snapshot::build(app(Scanner::class)->scan(config('agentic-actions.discovery.paths')))['agents'];
    writeManifestThenScanNothing();
    $this->app['env'] = 'production';

    expect(Snapshot::build(ActionRegistry::load($this->app))['agents'])->toBe($scanned)
        ->and($scanned)->toHaveKeys([DeferringDesk::class, SearchDesk::class]);
});

it('reads a manifest written in the same second as the route cache', function () {
    writeManifestThenScanNothing();
    File::put($this->routes, '<?php return [];');
    touch($this->routes, $now = time());
    touch($this->manifest, $now);
    $this->app['env'] = 'production';

    expect(ActionRegistry::load($this->app)->all())->toHaveKey('create-note');
});

it('ignores a manifest older than the route cache', function () {
    Exceptions::fake();
    app(ManifestWriter::class)->write();
    File::put($this->routes, '<?php return [];');
    touch($this->manifest, time() - 60);
    touch($this->routes, time());
    $this->app['env'] = 'production';

    ActionRegistry::load($this->app);

    Exceptions::assertReported(StaleActionManifest::class);
});

it('ignores a manifest of another version, or one that is not a manifest', function (string $contents) {
    File::put($this->manifest, $contents);
    $this->app['env'] = 'production';

    // Each manifest nominates nothing, so create-note comes from the scan.
    expect(ActionRegistry::load($this->app)->all())->toHaveKey('create-note');
})->with([
    'another version' => '<?php return '.var_export(['version' => ActionRegistry::VERSION + 1, 'actions' => [], 'agents' => []], true).';',
    'version 1' => '<?php return '.var_export(['version' => 1, 'actions' => [], 'agents' => []], true).';',
    'version 2' => '<?php return '.var_export(['version' => 2, 'actions' => [], 'agents' => []], true).';',
    'version 3' => '<?php return '.var_export(['version' => 3, 'actions' => [], 'agents' => []], true).';',
    'version 4' => '<?php return '.var_export(['version' => 4, 'actions' => [], 'agents' => []], true).';',
    'no actions' => '<?php return '.var_export(['version' => ActionRegistry::VERSION], true).';',
    'not an array' => '<?php return true;',
    'not PHP that parses' => '<?php return [',
]);

it('reports a missing manifest once an hour', function () {
    Exceptions::fake();
    $this->app['env'] = 'production';

    ActionRegistry::load($this->app);

    expect(ActionRegistry::load($this->app)->all())->toHaveKey('create-note');

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (StaleActionManifest $exception): bool => $exception->getMessage() === 'The actions manifest is missing or older than the route cache, so this process scanned for actions: run php artisan optimize, or actions:cache, on deploy.');
});

it('scans on every local boot, beside a fresh manifest, and throws on scan errors', function () {
    writeManifestThenScanNothing();
    $this->app['env'] = 'local';

    expect(ActionRegistry::load($this->app)->all())->toBe([]);

    config(['agentic-actions.discovery.classes' => [UndeclaredEffect::class]]);

    expect(fn () => ActionRegistry::load($this->app))
        ->toThrow(MisconfiguredExposure::class, UndeclaredEffect::class.': http: effect undeclared');
});

it('reports scan errors in production once an hour, and keeps the refused surfaces closed', function () {
    Exceptions::fake();
    config(['agentic-actions.discovery.classes' => [UndeclaredEffect::class]]);
    $this->app['env'] = 'production';

    $registry = ActionRegistry::load($this->app);
    ActionRegistry::load($this->app);

    expect($registry->find(UndeclaredEffect::class)?->allows(Surface::Http))->toBeFalse()
        ->and($registry->find('create-note')?->allows(Surface::Http))->toBeTrue();

    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(fn (MisconfiguredExposure $exception): bool => str_contains($exception->getMessage(), UndeclaredEffect::class.': http: effect undeclared'));
    Exceptions::assertReported(StaleActionManifest::class);
});
