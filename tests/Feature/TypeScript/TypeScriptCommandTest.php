<?php

use AgenticActions\TypeScript\Emitter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Feature\TypeScript\Fixtures\CreatePost;

beforeEach(function () {
    $this->relative = 'agentic-typescript-'.getmypid();
    $this->absolute = sys_get_temp_dir().'/agentic-typescript-'.getmypid();

    $this->mountRoutes(fn () => Route::middleware('web')->prefix('actions')->name('actions.')
        ->group(fn () => $this->generatedRoute(CreatePost::class)));
});

afterEach(function () {
    File::deleteDirectory(base_path($this->relative));
    File::deleteDirectory($this->absolute);
});

it('writes the configured path, relative to the base path', function () {
    config(['agentic-actions.typescript.path' => $this->relative.'/agentic/actions.ts']);

    $this->artisan('actions:typescript')
        ->expectsOutputToContain("Wrote {$this->relative}/agentic/actions.ts.")
        ->assertExitCode(0);

    expect(File::get(base_path($this->relative.'/agentic/actions.ts')))->toBe(app(Emitter::class)->render());
});

it('writes an absolute path, creating its directories', function () {
    config(['agentic-actions.typescript.path' => $this->absolute.'/deep/er/actions.ts']);

    $this->artisan('actions:typescript')
        ->expectsOutputToContain("Wrote {$this->absolute}/deep/er/actions.ts.")
        ->assertExitCode(0);

    expect(File::get($this->absolute.'/deep/er/actions.ts'))
        ->toContain("url: '/actions/create-post'")
        ->toContain("import { action, type ActionDefinition } from '@agentic-actions/client';");
});

it('passes --check when the file is current', function () {
    config(['agentic-actions.typescript.path' => $this->absolute.'/actions.ts']);

    $this->artisan('actions:typescript')->assertExitCode(0);

    $this->artisan('actions:typescript --check')->assertExitCode(0);
});

it('fails --check when the file is stale or missing, and leaves it as it was', function (?string $contents) {
    config(['agentic-actions.typescript.path' => $this->relative.'/actions.ts']);

    if ($contents !== null) {
        File::ensureDirectoryExists(base_path($this->relative));
        File::put(base_path($this->relative.'/actions.ts'), $contents);
    }

    $this->artisan('actions:typescript --check')
        ->expectsOutputToContain("{$this->relative}/actions.ts is stale: run php artisan actions:typescript")
        ->assertExitCode(1);

    expect(File::exists(base_path($this->relative.'/actions.ts')) ? File::get(base_path($this->relative.'/actions.ts')) : null)->toBe($contents);
})->with(['a stale file' => "// old\n", 'a missing file' => null]);

it('refuses while routes are cached', function () {
    config(['agentic-actions.typescript.path' => $this->absolute.'/actions.ts']);

    // The application memoizes its answer under this key, so a test can give it without a route cache file.
    $this->app->instance('routes.cached', true);

    expect($this->app->routesAreCached())->toBeTrue();

    $this->artisan('actions:typescript')
        ->expectsOutputToContain('Routes are cached, so new actions are invisible. Run php artisan route:clear first.')
        ->assertExitCode(1);

    $this->artisan('actions:typescript --check')->assertExitCode(1);

    expect(File::exists($this->absolute.'/actions.ts'))->toBeFalse();
});
