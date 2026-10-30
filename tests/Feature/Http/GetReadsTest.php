<?php

namespace Tests\Feature\Http;

use AgenticActions\Effect;
use AgenticActions\Exposure\Entry;
use AgenticActions\Facades\Actions;
use AgenticActions\Http\ActionRequest;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Http\SearchNotes;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * Actions::routes(reads: 'get'): a Read's route also answers GET and HEAD, with a weak ETag, private caching and 304,
 * on a mount the app opts in; only and except keep a mount to some actions. The defaults are today's routes exactly.
 */

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'agentic-actions.discovery.classes' => [SearchNotes::class],
    ]);
    $this->refreshActions();

    $this->user = User::factory()->create();
    Post::factory()->for($this->user)->create(['title' => 'Hello']);
});

/**
 * The web mount as today, and a token mount under api/v1 whose Reads answer GET.
 */
function mountWebAndGetApi(): void
{
    test()->mountRoutes(function (): void {
        Route::middleware(['web', 'auth'])->group(fn () => Actions::routes());
        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->name('api.v1.')->group(fn () => Actions::routes(reads: 'get'));
    });
}

/**
 * The methods of a named route.
 *
 * @return list<string>
 */
function methodsOf(string $name): array
{
    return Route::getRoutes()->getByName($name)?->methods() ?? [];
}

describe('the defaults', function () {
    it('keeps every generated route POST only, so a GET to a Read is not allowed', function () {
        $this->mountRoutes(function (): void {
            Route::middleware(['web', 'auth'])->group(fn () => Actions::routes());
            Route::middleware(['api', 'auth:sanctum'])->prefix('api')->name('api.')->group(fn () => Actions::routes());
        });
        Sanctum::actingAs($this->user, ['actions:read']);

        expect(methodsOf('actions.list-notes'))->toBe(['POST'])
            ->and(methodsOf('api.actions.list-notes'))->toBe(['POST']);

        $this->getJson('/api/actions/list-notes')->assertMethodNotAllowed();
    });

    it('answers a Read over POST without an ETag or the conditional headers, on every mount', function () {
        mountWebAndGetApi();
        Sanctum::actingAs($this->user, ['actions:read']);

        $response = $this->postJson('/api/v1/actions/list-notes')->assertOk()->assertJsonPath('posts.0.title', 'Hello');

        expect($response->headers->get('ETag'))->toBeNull()
            ->and($response->getVary())->toBe(['Precognition']);

        $this->postJson('/api/v1/actions/list-notes', [], ['If-None-Match' => '*'])->assertOk();
    });

    it('refuses a reads value other than post or get', function () {
        $this->mountRoutes(fn () => Route::middleware('auth:sanctum')->group(fn () => Actions::routes(reads: 'put')));
    })->throws(InvalidArgumentException::class, "Actions::routes() takes reads: 'post' or 'get', not [put].");
});

describe("reads: 'get'", function () {
    it('registers GET, HEAD and POST for each Read, and POST only for everything else', function () {
        mountWebAndGetApi();

        expect(methodsOf('api.v1.actions.list-notes'))->toBe(['GET', 'POST', 'HEAD'])
            ->and(methodsOf('api.v1.actions.search-notes'))->toBe(['GET', 'POST', 'HEAD'])
            ->and(methodsOf('api.v1.actions.create-note'))->toBe(['POST'])
            ->and(methodsOf('api.v1.actions.delete-note'))->toBe(['POST'])
            ->and(methodsOf('actions.list-notes'))->toBe(['POST'])
            ->and(Route::getRoutes()->getByName('api.v1.actions.list-notes')?->getAction(ActionRequest::GENERATED))->toBeTrue();
    });

    it('answers GET on a Read with its output, the input read from the query string and coerced', function () {
        mountWebAndGetApi();
        Post::factory()->for($this->user)->create(['title' => 'Other', 'status' => 'published']);
        Sanctum::actingAs($this->user, ['actions:read']);

        $this->getJson('/api/v1/actions/list-notes?page=1')->assertOk()->assertJsonPath('posts.0.title', 'Hello');

        $this->getJson('/api/v1/actions/search-notes?limit=5&drafts=0&ids[0]='.Post::query()->max('id'))
            ->assertOk()
            ->assertExactJson([
                'posts' => [['id' => Post::query()->max('id'), 'title' => 'Other']],
                'input' => ['limit' => 5, 'drafts' => false, 'ids' => [Post::query()->max('id')]],
            ]);
    });

    it('validates a GET\'s query string as it validates a body', function () {
        mountWebAndGetApi();
        Sanctum::actingAs($this->user, ['actions:read']);

        $this->getJson('/api/v1/actions/search-notes?limit=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);
    });

    it('reads an empty query value as null: ConvertEmptyStringsToNull, as on a form', function () {
        mountWebAndGetApi();
        Sanctum::actingAs($this->user, ['actions:read']);

        $this->getJson('/api/v1/actions/search-notes?title=')->assertOk()->assertJsonPath('input', ['title' => null]);
    });

    it('answers GET on a Write with 405, so a link can never run one', function () {
        mountWebAndGetApi();
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/actions/create-note?title=Hi&body=x')->assertMethodNotAllowed();
        $this->get('/api/v1/actions/delete-note?note=1')->assertMethodNotAllowed();

        expect(Post::query()->count())->toBe(1);
    });

    it('still refuses a guest, and a token without the Read ability, before any input', function () {
        mountWebAndGetApi();

        $this->getJson('/api/v1/actions/list-notes')->assertUnauthorized();

        Sanctum::actingAs($this->user, ['actions:write']);

        $this->getJson('/api/v1/actions/list-notes')->assertNotFound()->assertExactJson(['message' => trans('agentic-actions::http.not_found')]);
    });
});

describe('conditional answers', function () {
    it('sends a weak ETag, private no-cache and Vary, then 304 without a body for If-None-Match', function () {
        mountWebAndGetApi();
        Sanctum::actingAs($this->user, ['actions:read']);

        $first = $this->getJson('/api/v1/actions/list-notes')->assertOk();
        $etag = (string) $first->headers->get('ETag');

        expect($etag)->toMatch('/^W\/"[0-9a-f]{32}"$/')
            ->and($first->headers->getCacheControlDirective('private'))->toBeTrue()
            ->and($first->headers->getCacheControlDirective('no-cache'))->toBeTrue()
            ->and($first->headers->hasCacheControlDirective('public'))->toBeFalse()
            ->and($first->getVary())->toBe(['Authorization', 'Accept-Language', 'Precognition']);

        $again = $this->getJson('/api/v1/actions/list-notes', ['If-None-Match' => $etag])->assertStatus(304);

        expect($again->getContent())->toBe('')
            ->and($again->headers->get('ETag'))->toBe($etag);

        // Another note changes the body, so the same tag no longer matches.
        Post::factory()->for($this->user)->create(['title' => 'Second']);

        $changed = $this->getJson('/api/v1/actions/list-notes', ['If-None-Match' => $etag])->assertOk()->assertJsonCount(2, 'posts');

        expect($changed->headers->get('ETag'))->not->toBe($etag);
    });

    it('answers HEAD like GET without the body, ETag and 304 included', function () {
        mountWebAndGetApi();
        Sanctum::actingAs($this->user, ['actions:read']);

        $get = $this->getJson('/api/v1/actions/list-notes');
        $head = $this->call('HEAD', '/api/v1/actions/list-notes', server: ['HTTP_ACCEPT' => 'application/json'])->assertOk();

        expect($head->getContent())->toBe('')
            ->and($head->headers->get('ETag'))->toBe($get->headers->get('ETag'));

        $this->call('HEAD', '/api/v1/actions/list-notes', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_IF_NONE_MATCH' => (string) $get->headers->get('ETag')])
            ->assertStatus(304);
    });

    it('lets the caller keep an answer for routes.max_age seconds, still private', function () {
        config(['agentic-actions.routes.max_age' => 60]);
        mountWebAndGetApi();
        Sanctum::actingAs($this->user, ['actions:read']);

        $response = $this->getJson('/api/v1/actions/list-notes')->assertOk();

        expect($response->headers->getCacheControlDirective('max-age'))->toBe('60')
            ->and($response->headers->getCacheControlDirective('private'))->toBeTrue()
            ->and($response->headers->hasCacheControlDirective('no-cache'))->toBeFalse();
    });

    it('builds the ETag only after every check: a caller the checks refuse gets their refusal, never 304', function () {
        mountWebAndGetApi();
        Sanctum::actingAs($this->user, ['actions:read']);

        $etag = (string) $this->getJson('/api/v1/actions/list-notes')->headers->get('ETag');

        app('auth')->forgetGuards();
        Sanctum::actingAs($this->user, ['actions:write']);

        $this->getJson('/api/v1/actions/list-notes', ['If-None-Match' => $etag])->assertNotFound();
        $this->getJson('/api/v1/actions/search-notes?limit=500', ['If-None-Match' => '*'])->assertNotFound();

        app('auth')->forgetGuards();
        Sanctum::actingAs($this->user, ['actions:read']);

        $this->getJson('/api/v1/actions/search-notes?limit=500', ['If-None-Match' => '*'])->assertUnprocessable();
    });

    it('gives two people their own tags: the body is theirs, and Vary keeps a shared cache from mixing them', function () {
        mountWebAndGetApi();
        $other = User::factory()->create();
        Post::factory()->for($other)->create(['title' => 'Theirs']);

        Sanctum::actingAs($this->user, ['actions:read']);
        $mine = (string) $this->getJson('/api/v1/actions/list-notes')->headers->get('ETag');

        app('auth')->forgetGuards();
        Sanctum::actingAs($other, ['actions:read']);

        $this->getJson('/api/v1/actions/list-notes', ['If-None-Match' => $mine])->assertOk()->assertJsonPath('posts.0.title', 'Theirs');
    });
});

describe('only and except', function () {
    it('keeps a mount to the actions only returns true for, and drops those except returns true for', function () {
        $this->mountRoutes(function (): void {
            Route::middleware(['web', 'auth'])->group(fn () => Actions::routes());
            Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->name('api.v1.')->group(fn () => Actions::routes(
                reads: 'get',
                only: fn (Entry $action): bool => $action->effect === Effect::Read,
                except: fn (Entry $action): bool => $action->name === 'search-notes',
            ));
        });

        $api = array_values(array_filter(
            array_map(fn ($route) => $route->getName(), Route::getRoutes()->getRoutes()),
            fn (?string $name): bool => $name !== null && str_starts_with($name, 'api.v1.actions.') && ! str_contains($name, '._'),
        ));

        expect($api)->toBe(['api.v1.actions.list-notes'])
            ->and(Route::getRoutes()->getByName('actions.create-note'))->not->toBeNull()
            ->and(Route::getRoutes()->getByName('actions.search-notes'))->not->toBeNull();

        Sanctum::actingAs($this->user, ['*']);

        $this->postJson('/api/v1/actions/create-note', ['title' => 'Hi', 'body' => 'x'])->assertNotFound();
        $this->getJson('/api/v1/actions/list-notes')->assertOk();
    });

    it('treats anything but true from only as false, and anything but true from except as keep', function () {
        $this->mountRoutes(fn () => Route::middleware('auth:sanctum')->prefix('a')->name('a.')->group(fn () => Actions::routes(
            only: fn (Entry $action): mixed => $action->name === 'list-notes' ? true : 1,
            except: fn (Entry $action): mixed => 'yes',
        )));

        expect(Route::getRoutes()->getByName('a.actions.list-notes'))->not->toBeNull()
            ->and(Route::getRoutes()->getByName('a.actions.create-note'))->toBeNull();
    });
});

describe('two mounts and the route cache', function () {
    /**
     * Compile the routes and load them back exactly as route:cache writes and a cached app reads them.
     */
    function cacheRoutes(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        $cache = sys_get_temp_dir().'/agentic-actions-get-routes-'.getmypid().'.php';

        File::put($cache, '<?php app(\'router\')->setCompiledRoutes('.var_export(Route::getRoutes()->compile(), true).');');

        try {
            require $cache;
        } finally {
            File::delete($cache);
        }
    }

    it('compiles a web mount and a name-prefixed GET mount, and serves GET, ETag and 304 from the cache', function () {
        mountWebAndGetApi();
        cacheRoutes();

        expect(Route::getRoutes())->not->toBeInstanceOf(RouteCollection::class)
            ->and(methodsOf('api.v1.actions.list-notes'))->toBe(['GET', 'POST', 'HEAD'])
            ->and(methodsOf('actions.list-notes'))->toBe(['POST']);

        Sanctum::actingAs($this->user, ['actions:read']);

        $etag = (string) $this->getJson('/api/v1/actions/list-notes')->assertOk()->headers->get('ETag');

        $this->getJson('/api/v1/actions/list-notes', ['If-None-Match' => $etag])->assertStatus(304);
        $this->getJson('/api/v1/actions/create-note')->assertMethodNotAllowed();
    });

    it('cannot compile two mounts that share a name prefix, which actions:check reports', function () {
        $this->mountRoutes(function (): void {
            Route::middleware(['web', 'auth'])->group(fn () => Actions::routes());
            Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(fn () => Actions::routes(reads: 'get'));
        });

        expect(fn () => cacheRoutes())->toThrow(LogicException::class, 'Another route has already been assigned name');
    });
});

it('runs the Read through the same pipeline on GET as on POST: the class still decides', function () {
    mountWebAndGetApi();
    Sanctum::actingAs($this->user, ['actions:read']);

    config(['agentic-actions.surfaces.web' => false]);

    $this->getJson('/api/v1/actions/list-notes')->assertNotFound();
    $this->postJson('/api/v1/actions/list-notes')->assertNotFound();
});

it('keeps a hand-written GET route to a Read working, now with the conditional answer', function () {
    $this->mountRoutes(fn () => Route::middleware(['api', 'auth:sanctum'])->get('api/v1/notes', ListNotes::class)->name('api.v1.notes'));
    Sanctum::actingAs($this->user, ['actions:read']);

    $etag = (string) $this->getJson('/api/v1/notes?x=1')->assertOk()->assertJsonPath('posts.0.title', 'Hello')->headers->get('ETag');

    expect($etag)->not->toBe('');

    $this->getJson('/api/v1/notes', ['If-None-Match' => $etag])->assertStatus(304);
});
