<?php

namespace Tests\Feature\Security;

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Manifest;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Contracts\Tool;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\PlainNote;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A production web process reads the manifest, and the manifest only nominates. A row that points a known name at
 * another class, or at something that is not an action, can hide an action but never open one.
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

    $this->user = User::factory()->create();
    $this->app['env'] = 'production';

    File::delete(Manifest::path($this->app));
});

afterEach(function () {
    File::delete(Manifest::path($this->app));

    // Teardown runs commands that ask for confirmation in production.
    $this->app['env'] = 'testing';
});

/**
 * Write a manifest whose rows all say "web and agents, default toolset", whatever the classes declare.
 *
 * @param  array<string, string>  $classes  name => class
 */
function writeTamperedManifest(array $classes): void
{
    $actions = [];

    foreach ($classes as $name => $class) {
        $actions[$name] = [...ClassExposure::of(CreateNote::class)->toManifest(), 'class' => $class, 'name' => $name];
    }

    File::put(Manifest::path(app()), '<?php return '.var_export(['version' => ActionRegistry::VERSION, 'actions' => $actions, 'agents' => []], true).';');

    ClassExposure::flush();
    app()->forgetInstance(ActionRegistry::class);
}

it('refuses a known name the manifest points at a class that now declares another name, on every door', function () {
    writeTamperedManifest(['create-note' => PlainNote::class]);

    // The registry reads the tampered row, not a scan, which would find CreateNote under that name.
    expect(collect(app(ActionRegistry::class)->on(Surface::Http))->pluck('class', 'name')->all())->toBe(['create-note' => PlainNote::class]);

    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    expect(Route::has('actions.create-note'))->toBeFalse()
        ->and(Actions::attempt('create-note', ['title' => 'Hi'], ActionContext::agent($this->user))->kind())->toBe(OutcomeKind::NotFound);

    if (interface_exists(Tool::class)) {
        expect(Actions::tools(ActionContext::agent($this->user), ['default']))->toBe([])
            ->and(Actions::call(['default'], 'create-note', ['title' => 'Hi'], ActionContext::agent($this->user))->kind())->toBe(OutcomeKind::NotFound);
    }

    expect(Post::query()->count())->toBe(0);
});

it('opens neither the web nor an agent door for a class the manifest nominates under its own name, when it exposes nothing', function () {
    writeTamperedManifest(['create-note' => CreateNote::class, 'plain-note' => PlainNote::class]);

    // The row and the class agree on the name, so only the class's own #[Expose], read live, can refuse it.
    expect(collect(app(ActionRegistry::class)->on(Surface::Http))->pluck('name')->all())->toBe(['create-note', 'plain-note']);

    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    expect(collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getControllerClass())->filter()->all())
        ->toContain(CreateNote::class)
        ->not->toContain(PlainNote::class);

    if (interface_exists(Tool::class)) {
        expect(array_map(fn (ActionTool $tool): string => $tool->name(), Actions::tools(ActionContext::agent($this->user), ['default'])))->toBe(['create-note'])
            ->and(Actions::call(['default'], 'plain-note', ['title' => 'Hi'], ActionContext::agent($this->user))->kind())->toBe(OutcomeKind::NotFound);
    }

    expect(Post::query()->count())->toBe(0);
});

it('never runs a class the manifest names that is not an action', function () {
    writeTamperedManifest(['user-model' => User::class, 'no-class' => 'Tests\\Fixtures\\Actions\\NothingHere']);

    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    expect(collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getControllerClass())->filter()->all())->not->toContain(User::class)
        ->and(Actions::attempt('user-model', [], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::NotFound)
        ->and(Actions::attempt('no-class', [], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::NotFound);

    if (interface_exists(Tool::class)) {
        expect(Actions::tools(ActionContext::agent($this->user), ['default']))->toBe([]);
    }
});
