<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Effect;
use AgenticActions\Facades\Actions;
use Illuminate\Routing\RouteCollection;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Checks\RestReadOnPost;
use Tests\Fixtures\Checks\RestWrongMethods;
use Tests\Fixtures\Http\CreatePost;
use Tests\Fixtures\Http\DeletePost;
use Tests\Fixtures\Http\ListPosts;
use Tests\Fixtures\Http\PublishPost;
use Tests\Fixtures\Http\ShowPost;
use Tests\Fixtures\Http\UpdatePost;

/*
 * The Routes row on what Actions::routes() mounts: a GET Read in a group with a session, and two mounts under one
 * name; and the REST row on each #[Rest] route. Each test starts from an empty route collection.
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

describe('REST', function () {
    /**
     * The REST row's messages for these classes.
     *
     * @param  list<class-string>  $classes
     * @return list<string>
     */
    function restRow(array $classes): array
    {
        config(['agentic-actions.discovery.classes' => $classes]);
        test()->refreshActions();

        return array_values(array_map(
            fn (Finding $finding): string => $finding->level.' '.$finding->message,
            array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => $finding->row === 'REST'),
        ));
    }

    it('fails each method its effect may not use, and every parameter, bind, created and location that cannot work', function () {
        $wrong = RestWrongMethods::class;

        expect(restRow([RestWrongMethods::class, RestReadOnPost::class]))->toBe([
            'fail '.RestReadOnPost::class.': #[Rest] needs the web open, and it is closed (no #[Expose]). Add #[Expose(web: true)].',
            'fail '.RestReadOnPost::class.": #[Rest('POST', 'posts')] is not for a Read: use GET.",
            "fail {$wrong}: #[Rest('GET', 'posts/{post}')] is for a Read, and the action is write: use POST, PUT or PATCH.",
            "fail {$wrong}: #[Rest('DELETE', 'posts/{post}/draft')] is for a Destructive action, and the action is write.",
            "fail {$wrong}: #[Rest('PATCH', 'posts/{post}/{section}')] fills the input key [section] from {section}, which schema() does not declare.",
            "fail {$wrong}: #[Rest('PATCH', 'posts/{post}/{section}')] binds {chapter}, which its path does not hold.",
            "fail {$wrong}: #[Rest('PATCH', 'posts/{post}/{section}')] sets created, which only a POST answers.",
            "fail {$wrong}: #[Rest('POST', 'posts')]'s location names {slug}, which neither outputSchema() nor the path holds.",
            "fail {$wrong}: #[Rest('PUT', 'posts/{post}/title')] sets a location without created: true, so no answer carries it.",
        ]);
    });

    it('fails two web-open actions on one method and path', function () {
        expect(restRow([ListPosts::class, RestAccountPosts::class]))->toBe([
            'fail '.RestAccountPosts::class.": #[Rest('GET', 'posts')] takes the method and path of ".ListPosts::class."'s route, so one mount cannot serve both.",
        ]);
    });

    it('passes the REST fixtures, whose routes the feature tests serve', function () {
        expect(restRow([
            ListPosts::class, CreatePost::class, ShowPost::class,
            UpdatePost::class, DeletePost::class, PublishPost::class,
        ]))->toBe([]);
    });

    it('lets a tenant-scoped and an account-level action share a method and path, which two mounts serve', function () {
        $this->useTeamTenancy();

        expect(restRow([ListPosts::class, RestAccountPosts::class]))->toBe([]);
    });
});

/**
 * GET posts, outside any tenant.
 */
#[Expose(web: true)]
#[AgenticActions\Attributes\Rest('GET', 'posts')]
final class RestAccountPosts extends Action
{
    protected string $description = 'List my posts.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nothing.
     *
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [];
    }
}
