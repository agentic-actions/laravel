<?php

use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Manifest;
use AgenticActions\Exceptions\MisconfiguredDiscovery;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

use function Orchestra\Testbench\workbench_path;

/*
 * A misconfigured discovery.groups with the workbench's real route files, which call Actions::routes() at boot. Outside
 * a test run, a console process records the error instead of throwing, so artisan keeps working and actions:check can
 * print its Discovery row; the registry nominates nothing, so no route opens; and actions:cache refuses to write a
 * manifest, so the deploy stops.
 */

beforeEach(function () {
    $this->manifest = Manifest::path($this->app);

    File::delete($this->manifest);

    config(['agentic-actions.discovery.groups' => [
        'core' => ['paths' => ['../x'], 'snapshot' => 'actions.exposure.json'],
    ]]);

    // A console process that is not a test run, as php artisan on a server is.
    $this->app['env'] = 'production';

    $this->refreshActions();
});

afterEach(function () {
    File::delete($this->manifest);

    $this->app['env'] = 'testing';
});

it('boots the real route files, mounting no action route', function () {
    Route::setRoutes(new RouteCollection);

    Route::middleware('api')->group(workbench_path(['routes', 'api.php']));
    Route::middleware('web')->group(workbench_path(['routes', 'web.php']));

    $names = array_filter(array_map(fn ($route): ?string => $route->getName(), Route::getRoutes()->getRoutes()));

    // The change feed and the tables' refresh route are the package's own, and serve no action of their own.
    expect(app(ActionRegistry::class)->all())->toBe([])
        ->and(array_values(array_filter($names, fn (string $name): bool => str_contains($name, 'actions.') && ! str_contains($name, 'actions._'))))->toBe([])
        ->and($names)->toContain('actions._views');
});

it('keeps artisan working, and actions:check prints the Discovery row and fails', function () {
    $this->artisan('about')->assertSuccessful();

    $this->artisan('actions:check')
        ->expectsOutputToContain('[Discovery] discovery.groups.core: paths [../x] steps out with ".."')
        ->assertFailed();

    $this->artisan('actions:typescript --check')
        ->expectsOutputToContain('discovery.groups.core: paths [../x] steps out')
        ->assertFailed();
});

it('refuses to write the manifest', function () {
    expect(fn () => $this->artisan('actions:cache')->run())->toThrow(MisconfiguredDiscovery::class, 'steps out');

    expect($this->manifest)->not->toBeFile();
});
