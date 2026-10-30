<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Facades\Actions;
use Illuminate\Routing\RouteCollection;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;

/*
 * The Routes row on what Actions::routes() mounts: a GET Read in a group with a session, and two mounts under one
 * name. Each test starts from an empty route collection.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [CreateNote::class, ListNotes::class]]);
    $this->refreshActions();

    Route::setRoutes(new RouteCollection);
});

/**
 * The Routes row's findings, as [level, message].
 *
 * @return list<array{0: string, 1: string}>
 */
function routesRow(): array
{
    return array_values(array_map(
        fn (Finding $finding): array => [$finding->level, $finding->message],
        array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => $finding->row === 'Routes'),
    ));
}

describe("reads: 'get' and sessions", function () {
    it('fails a GET Read in the web group, which starts a session', function () {
        $this->mountRoutes(fn () => Route::middleware(['web', 'auth'])->group(fn () => Actions::routes(reads: 'get')));

        expect(routesRow())->toBe([
            ['fail', "The generated route [actions.list-notes] answers GET, and its group starts a session: a GET skips the CSRF check, and a link on another site sends the session cookie. Mount reads: 'get' only in a token group, such as Route::middleware('auth:sanctum') in routes/api.php."],
        ]);
    });

    it('fails a GET Read whose auth middleware names a session guard, by default or by name', function () {
        $this->mountRoutes(function (): void {
            Route::middleware('auth')->prefix('a')->name('a.')->group(fn () => Actions::routes(reads: 'get'));
            Route::middleware('auth:web')->prefix('b')->name('b.')->group(fn () => Actions::routes(reads: 'get'));
            Route::middleware([StartSession::class, 'auth:sanctum'])->prefix('c')->name('c.')->group(fn () => Actions::routes(reads: 'get'));
        });

        expect(array_map(fn (array $finding): string => str($finding[1])->before(': a GET')->toString(), routesRow()))->toBe([
            'The generated route [a.actions.list-notes] answers GET, and it authenticates with the session guard [web]',
            'The generated route [b.actions.list-notes] answers GET, and it authenticates with the session guard [web]',
            'The generated route [c.actions.list-notes] answers GET, and its group starts a session',
        ]);
    });

    it('passes a GET Read on a token group, and every POST-only mount whatever its middleware', function () {
        $this->mountRoutes(function (): void {
            Route::middleware(['web', 'auth'])->group(fn () => Actions::routes());
            Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->name('api.v1.')->group(fn () => Actions::routes(reads: 'get'));
        });

        expect(routesRow())->toBe([]);
    });
});

describe('shared names', function () {
    it('fails two mounts under one name prefix, naming each route and the fix', function () {
        $this->mountRoutes(function (): void {
            Route::middleware(['web', 'auth'])->group(fn () => Actions::routes());
            Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(fn () => Actions::routes());
        });

        $findings = routesRow();

        expect($findings)->toContain(
            ['fail', "The routes [POST actions/create-note, POST api/v1/actions/create-note] share the name [actions.create-note], which route:cache refuses. Give each Actions::routes() group its own name prefix, such as ->name('api.v1.')."],
            ['fail', "The routes [POST actions/list-notes, POST api/v1/actions/list-notes] share the name [actions.list-notes], which route:cache refuses. Give each Actions::routes() group its own name prefix, such as ->name('api.v1.')."],
            ['fail', "The routes [POST actions/_changes, POST api/v1/actions/_changes] share the name [actions._changes], which route:cache refuses. Give each Actions::routes() group its own name prefix, such as ->name('api.v1.')."],
            ['fail', "The routes [POST actions/_views/{view}, POST api/v1/actions/_views/{view}] share the name [actions._views], which route:cache refuses. Give each Actions::routes() group its own name prefix, such as ->name('api.v1.')."],
        )->and($findings)->toHaveCount(4);
    });

    it('fails a route of the app\'s own that takes a generated route\'s name, and leaves the app\'s own pairs alone', function () {
        $this->mountRoutes(function (): void {
            Route::middleware('auth')->group(fn () => Actions::routes());
            Route::post('notes', fn () => 'x')->name('actions.list-notes');
            Route::get('a', fn () => 'a')->name('mine');
            Route::get('b', fn () => 'b')->name('mine');
        });

        expect(routesRow())->toBe([
            ['fail', "The routes [POST actions/list-notes, POST notes] share the name [actions.list-notes], which route:cache refuses. Give each Actions::routes() group its own name prefix, such as ->name('api.v1.')."],
        ]);
    });
});
