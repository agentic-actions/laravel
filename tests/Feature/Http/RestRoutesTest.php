<?php

namespace Tests\Feature\Http;

use AgenticActions\Action;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Facades\Actions;
use AgenticActions\Http\ActionRequest;
use AgenticActions\Http\RestRegistrar;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Http\CreatePost;
use Tests\Fixtures\Http\DeletePost;
use Tests\Fixtures\Http\ListPosts;
use Tests\Fixtures\Http\PublishPost;
use Tests\Fixtures\Http\ShowPost;
use Tests\Fixtures\Http\UpdatePost;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Actions::rest(): the #[Rest] routes of the web-exposed actions, inside the caller's group, through the generated
 * route's pipeline: path parameters as input, the same refusals, and the REST statuses.
 */

const REST_FIXTURES = [ListPosts::class, CreatePost::class, ShowPost::class, UpdatePost::class, DeletePost::class, PublishPost::class];

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => REST_FIXTURES,
    ]);
    $this->refreshActions();

    $this->user = User::factory()->create();
    $this->post = Post::factory()->for($this->user)->create(['title' => 'Hello']);
});

/**
 * The versioned token API: api/v1, named api.v1.
 */
function mountRestApi(): void
{
    test()->mountRoutes(fn () => Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->name('api.v1.')->group(fn () => Actions::rest()));
}

/**
 * The registered routes whose name starts with a prefix, in registration order.
 *
 * @return list<\Illuminate\Routing\Route>
 */
function restRoutesNamed(string $prefix): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn ($route): bool => str_starts_with((string) $route->getName(), $prefix)));
}

describe('the routes', function () {
    it('registers each #[Rest] route with its method and path, named rest.{action}, marked generated', function () {
        mountRestApi();

        $routes = [];

        foreach (restRoutesNamed('api.v1.rest.') as $route) {
            $routes[(string) $route->getName()] = implode('|', $route->methods()).' '.$route->uri();
        }

        // In action-name order, as the registry lists them.
        expect($routes)->toBe([
            'api.v1.rest.create-post' => 'POST api/v1/posts',
            'api.v1.rest.delete-post' => 'DELETE api/v1/posts/{post}',
            'api.v1.rest.list-posts' => 'GET|HEAD api/v1/posts',
            'api.v1.rest.publish-post' => 'POST api/v1/posts/{post}/publish',
            'api.v1.rest.show-post' => 'GET|HEAD api/v1/posts/{post}',
            'api.v1.rest.show-post.2' => 'GET|HEAD api/v1/articles/{post}',
            'api.v1.rest.update-post' => 'PATCH api/v1/posts/{post}',
        ])->and(Route::getRoutes()->getByName('api.v1.rest.show-post')?->getAction(ActionRequest::GENERATED))->toBeTrue();
    });

    it('registers nothing for an action without #[Rest], nor while surfaces.web is off', function () {
        config(['agentic-actions.discovery.classes' => [...REST_FIXTURES, ListNotes::class]]);
        $this->refreshActions();
        mountRestApi();

        expect(array_map(fn ($route) => $route->getName(), restRoutesNamed('api.v1.rest.')))->not->toContain('api.v1.rest.list-notes')
            ->and(restRoutesNamed('api.v1.rest.'))->toHaveCount(7);

        Route::setRoutes(new RouteCollection);
        config(['agentic-actions.surfaces.web' => false]);
        mountRestApi();

        expect(restRoutesNamed('api.v1.rest.'))->toBe([]);
    });

    it('filters with only and except, as Actions::routes() does', function () {
        $this->mountRoutes(fn () => Route::middleware('auth:sanctum')->group(fn () => Actions::rest(
            only: fn (Entry $action): bool => $action->effect?->value === 'read',
            except: fn (Entry $action): bool => $action->name === 'show-post',
        )));

        expect(array_map(fn ($route) => $route->getName(), restRoutesNamed('rest.')))->toBe(['rest.list-posts']);
    });

    it('refuses two routes on one method and path in a mount, and a method #[Rest] does not take', function (string $class, string $message) {
        config(['agentic-actions.discovery.classes' => [$class]]);
        $this->refreshActions();

        expect(fn () => $this->mountRoutes(fn () => Route::middleware('auth:sanctum')->group(fn () => Actions::rest())))
            ->toThrow(MisconfiguredExposure::class, $message);
    })->with([
        'twice' => [RestTwice::class, RestTwice::class.": #[Rest('GET', 'posts')] is already ".RestTwice::class."'s route in this group."],
        'options' => [RestOptions::class, RestOptions::class.': #[Rest] takes GET, POST, PUT, PATCH or DELETE, not [options].'],
    ]);

    it('keeps the method, the bind map and the generated key through route:cache', function () {
        mountRestApi();

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        $cache = sys_get_temp_dir().'/agentic-actions-rest-routes-'.getmypid().'.php';
        File::put($cache, '<?php app(\'router\')->setCompiledRoutes('.var_export(Route::getRoutes()->compile(), true).');');

        try {
            require $cache;
        } finally {
            File::delete($cache);
        }

        expect(Route::getRoutes())->not->toBeInstanceOf(RouteCollection::class)
            ->and(RestRegistrar::of(Route::getRoutes()->getByName('api.v1.rest.show-post'))['bind'] ?? null)->toBe(['post' => 'post_id']);

        Sanctum::actingAs($this->user, ['*']);

        $this->getJson("/api/v1/posts/{$this->post->id}")->assertOk()->assertJsonPath('title', 'Hello');
        $this->deleteJson("/api/v1/posts/{$this->post->id}")->assertNoContent();
    });
});

describe('the pipeline', function () {
    it('serves the collection, a create, a show, an update, a delete and a verb, with REST statuses', function () {
        mountRestApi();
        Sanctum::actingAs($this->user, ['*']);

        $this->getJson('/api/v1/posts?status=draft')->assertOk()->assertExactJson(['data' => [['id' => $this->post->id, 'title' => 'Hello']]]);

        $created = $this->postJson('/api/v1/posts', ['title' => 'New', 'body' => 'x'])->assertCreated();
        $id = $created->json('id');

        expect($created->headers->get('Location'))->toBe(url("/api/v1/posts/{$id}"))
            ->and($created->json())->toBe(['id' => $id, 'title' => 'New']);

        $this->getJson("/api/v1/posts/{$id}")->assertOk()->assertExactJson(['id' => $id, 'title' => 'New', 'status' => 'draft']);
        $this->getJson("/api/v1/articles/{$id}")->assertOk()->assertJsonPath('title', 'New');
        $this->patchJson("/api/v1/posts/{$id}", ['title' => 'Renamed'])->assertOk()->assertExactJson(['id' => $id, 'title' => 'Renamed']);
        $this->postJson("/api/v1/posts/{$id}/publish")->assertOk()->assertExactJson(['status' => 'published']);
        $this->postJson("/api/v1/posts/{$id}/publish")->assertStatus(409)->assertJsonPath('message', 'The post is already published.');

        $deleted = $this->deleteJson("/api/v1/posts/{$id}")->assertNoContent();

        expect($deleted->getContent())->toBe('')
            ->and(Post::query()->find($id))->toBeNull();
    });

    it('answers a Precognition request with a draft\'s validation only, as the generated route does', function () {
        mountRestApi();
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson('/api/v1/posts', ['title' => str_repeat('x', 41), 'body' => 'x'], ['Precognition' => 'true'])
            ->assertUnprocessable()->assertJsonValidationErrors(['title']);
        $this->postJson('/api/v1/posts', ['title' => 'Draft', 'body' => 'x'], ['Precognition' => 'true'])
            ->assertNoContent()->assertHeader('Precognition', 'true');

        expect(Post::query()->where('title', 'Draft')->exists())->toBeFalse();
    });

    it('answers the generated route\'s refusals: 401, 404 for a missing row, 422 for invalid input, 405 for another method', function () {
        mountRestApi();

        $this->getJson('/api/v1/posts')->assertUnauthorized();

        Sanctum::actingAs($this->user, ['*']);

        $this->getJson('/api/v1/posts/999999')->assertNotFound();
        $this->postJson('/api/v1/posts', ['title' => str_repeat('x', 41)])->assertUnprocessable()->assertJsonValidationErrors(['title', 'body']);
        $this->getJson('/api/v1/posts?status=archived')->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->putJson("/api/v1/posts/{$this->post->id}", ['title' => 'x'])->assertMethodNotAllowed();
    });

    it('fires the same events as the generated route, once per call', function () {
        mountRestApi();
        Sanctum::actingAs($this->user, ['*']);
        Event::fake([ActionCompleted::class]);

        $this->patchJson("/api/v1/posts/{$this->post->id}", ['title' => 'Renamed'])->assertOk();

        Event::assertDispatchedTimes(ActionCompleted::class, 1);
    });

    it('gives a Read over GET its ETag and 304', function () {
        mountRestApi();
        Sanctum::actingAs($this->user, ['actions:read']);

        $etag = (string) $this->getJson("/api/v1/posts/{$this->post->id}")->assertOk()->headers->get('ETag');

        expect($etag)->toStartWith('W/');

        $this->getJson("/api/v1/posts/{$this->post->id}", ['If-None-Match' => $etag])->assertStatus(304);
    });
});

describe('path parameters', function () {
    it('fills the input key the bind map names, and the key named like the parameter', function () {
        mountRestApi();
        Sanctum::actingAs($this->user, ['*']);

        $this->getJson("/api/v1/posts/{$this->post->id}")->assertOk()->assertJsonPath('id', $this->post->id);
        $this->patchJson("/api/v1/posts/{$this->post->id}", ['title' => 'Renamed'])->assertOk();

        expect($this->post->refresh()->title)->toBe('Renamed');
    });

    it('refuses the same key in the query or the body with 422 on that key, and runs nothing', function () {
        mountRestApi();
        Sanctum::actingAs($this->user, ['*']);
        $other = Post::factory()->for($this->user)->create(['title' => 'Other']);

        $this->getJson("/api/v1/posts/{$this->post->id}?post_id={$other->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['post_id' => trans('agentic-actions::http.path_parameter')]);

        $this->patchJson("/api/v1/posts/{$this->post->id}", ['post' => $other->id, 'title' => 'Hijacked'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['post']);

        $this->deleteJson("/api/v1/posts/{$this->post->id}", ['post' => $this->post->id])->assertUnprocessable();

        expect(Post::query()->orderBy('id')->pluck('title')->all())->toBe(['Hello', 'Other']);
    });

    it('validates a path parameter as input: a non-numeric id is 422, never a query', function () {
        mountRestApi();
        Sanctum::actingAs($this->user, ['*']);

        $this->getJson('/api/v1/posts/abc')->assertUnprocessable()->assertJsonValidationErrors(['post_id']);
    });
});

describe('tenants', function () {
    beforeEach(function () {
        $this->useTeamTenancy();
        config(['agentic-actions.discovery.classes' => REST_FIXTURES]);
        $this->refreshActions();

        $this->acme = Team::factory()->create(['slug' => 'acme']);
        $this->other = Team::factory()->create(['slug' => 'other']);
        $this->acme->users()->attach($this->user);

        $this->mine = Post::factory()->for($this->user)->create(['title' => 'Mine', 'team_id' => $this->acme->id]);
        $this->theirs = Post::factory()->for(User::factory())->create(['title' => 'Theirs', 'team_id' => $this->other->id]);

        $this->mountRoutes(fn () => Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1/teams/{team}')->name('api.v1.teams.')
            ->group(fn () => Actions::rest(tenant: true)));
    });

    it('finds a row through the tenant scope: another tenant\'s id is 404, and so is a team the actor is not in', function () {
        Sanctum::actingAs($this->user, ['*']);

        $this->getJson("/api/v1/teams/acme/posts/{$this->mine->id}")->assertOk()->assertJsonPath('title', 'Mine');
        $this->getJson("/api/v1/teams/acme/posts/{$this->theirs->id}")->assertNotFound();
        $this->patchJson("/api/v1/teams/acme/posts/{$this->theirs->id}", ['title' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/teams/acme/posts/{$this->theirs->id}")->assertNotFound();
        $this->getJson("/api/v1/teams/other/posts/{$this->theirs->id}")->assertNotFound();

        expect($this->theirs->refresh()->title)->toBe('Theirs');
    });

    it('fills the Location of a create from the group\'s tenant parameter and the output', function () {
        Sanctum::actingAs($this->user, ['*']);

        $created = $this->postJson('/api/v1/teams/acme/posts', ['title' => 'In acme', 'body' => 'x'])->assertCreated();

        expect($created->headers->get('Location'))->toBe(url('/api/v1/teams/acme/posts/'.$created->json('id')))
            ->and(Post::query()->find($created->json('id'))?->team_id)->toBe($this->acme->id);
    });

    it('keeps a token bound to one tenant to that tenant', function () {
        $this->other->users()->attach($this->user);
        $token = $this->user->createToken('other', ['actions:read', 'tenant:'.$this->other->getKey()])->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/teams/acme/posts/{$this->mine->id}")->assertNotFound();
        $this->withToken($token)->getJson("/api/v1/teams/other/posts/{$this->theirs->id}")->assertOk();
    });
});

describe('token abilities', function () {
    it('lets a read token GET and nothing else, a write token write but not delete, as on the generated route', function () {
        mountRestApi();
        $read = $this->user->createToken('read', ['actions:read'])->plainTextToken;
        $write = $this->user->createToken('write', ['actions:write'])->plainTextToken;
        $destructive = $this->user->createToken('destructive', ['actions:destructive'])->plainTextToken;

        $this->withToken($read)->getJson('/api/v1/posts')->assertOk();
        $this->withToken($read)->getJson("/api/v1/posts/{$this->post->id}")->assertOk();
        $this->withToken($read)->postJson('/api/v1/posts', ['title' => 'x', 'body' => 'x'])->assertNotFound();
        $this->withToken($read)->patchJson("/api/v1/posts/{$this->post->id}", ['title' => 'x'])->assertNotFound();
        $this->withToken($read)->deleteJson("/api/v1/posts/{$this->post->id}")->assertNotFound();

        $this->app['auth']->forgetGuards();

        $this->withToken($write)->getJson('/api/v1/posts')->assertNotFound();
        $this->withToken($write)->patchJson("/api/v1/posts/{$this->post->id}", ['title' => 'Renamed'])->assertOk();
        $this->withToken($write)->deleteJson("/api/v1/posts/{$this->post->id}")->assertNotFound();

        $this->app['auth']->forgetGuards();

        $this->withToken($destructive)->deleteJson("/api/v1/posts/{$this->post->id}")->assertNoContent();

        expect(Post::query()->count())->toBe(0);
    });
});

describe('restRoutes()', function () {
    it('describes each route for an OpenAPI document: method, path, parameters, input without them, output, status', function () {
        mountRestApi();

        $rows = collect(Actions::restRoutes())->keyBy('name');

        expect($rows->keys()->all())->toBe([
            'api.v1.rest.create-post', 'api.v1.rest.delete-post', 'api.v1.rest.list-posts', 'api.v1.rest.publish-post',
            'api.v1.rest.show-post', 'api.v1.rest.show-post.2', 'api.v1.rest.update-post',
        ]);

        $show = $rows['api.v1.rest.show-post'];

        expect($show)->toMatchArray([
            'method' => 'GET',
            'path' => '/api/v1/posts/{post}',
            'action' => 'show-post',
            'class' => ShowPost::class,
            'description' => 'Show one post.',
            'effect' => 'read',
            'ability' => 'actions:read',
            'input_in' => 'query',
            'status' => 200,
        ])->and($show['parameters'])->toBe([['name' => 'post', 'input' => 'post_id', 'required' => true, 'schema' => ['type' => 'integer']]])
            ->and(array_keys($show['input']['properties'] ?? []))->toBe([])
            ->and(array_keys($show['output']['properties']))->toBe(['id', 'title', 'status']);

        expect($rows['api.v1.rest.create-post'])->toMatchArray(['method' => 'POST', 'input_in' => 'body', 'status' => 201, 'ability' => 'actions:write'])
            ->and(array_keys($rows['api.v1.rest.create-post']['input']['properties']))->toBe(['title', 'body'])
            ->and($rows['api.v1.rest.update-post']['input']['required'] ?? [])->toBe(['title'])
            ->and($rows['api.v1.rest.delete-post'])->toMatchArray(['method' => 'DELETE', 'status' => 204, 'effect' => 'destructive']);
    });
});

/**
 * Two routes on one method and path.
 */
#[Expose(web: true)]
#[\AgenticActions\Attributes\Rest('GET', 'posts')]
#[\AgenticActions\Attributes\Rest('GET', '/posts/')]
final class RestTwice extends Action
{
    protected ?Effect $effect = Effect::Read;
}

/**
 * A method #[Rest] does not take.
 */
#[Expose(web: true)]
#[\AgenticActions\Attributes\Rest('options', 'posts')]
final class RestOptions extends Action
{
    protected ?Effect $effect = Effect::Read;
}
