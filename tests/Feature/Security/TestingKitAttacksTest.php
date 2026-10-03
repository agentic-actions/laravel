<?php

namespace Tests\Feature\Security;

use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\HiddenNote;
use Tests\Fixtures\Actions\Trace;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * Actions::fake() switches every gate off, so it answers only while the app runs its tests. A fake bound anywhere
 * else, by mistake or on purpose, is ignored, and a new one is refused.
 */

beforeEach(function () {
    Trace::reset();

    $this->user = User::factory()->create();
});

afterEach(function () {
    // Teardown runs commands that ask for confirmation in production.
    $this->app['env'] = 'testing';
});

it('runs every gate and handle() while a fake bound during tests is left behind outside them', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => Actions::routes()));

    Actions::fake([CreateNote::class => ['id' => 99, 'title' => 'Faked']]);

    $this->app['env'] = 'production';

    $hidden = Actions::attempt(HiddenNote::class, [], ActionContext::http($this->user));
    $created = Actions::attempt(CreateNote::class, ['title' => 'Real', 'body' => 'x'], ActionContext::http($this->user));

    $this->actingAs($this->user)->postJson('/actions/hidden-note')->assertNotFound();

    expect($hidden->kind())->toBe(OutcomeKind::NotFound)
        ->and($created->output())->toBe(['id' => Post::query()->sole()->getKey(), 'title' => 'Real'])
        ->and(Trace::$calls)->toContain('shouldRegister');
});

it('refuses to bind a fake outside tests', function () {
    $this->app['env'] = 'production';

    expect(fn () => Actions::fake())->toThrow(LogicException::class, 'Actions::fake() only works while the app runs its tests');
});
