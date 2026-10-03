<?php

namespace Tests\Feature;

use AgenticActions\Facades\Actions;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The fresh-app path inside a new Laravel application: the Blade form, the token client, an unknown guard, and
 * actions:check, in a plain PHPUnit class.
 */
class FreshAppPathTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Item 7: the form keeps @error and old(), and never flashes the key bootstrap/app.php added to dontFlash.
     */
    public function test_a_blade_form_keeps_old_input_and_errors_but_not_dont_flash_keys(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/posts/create')
            ->followingRedirects()
            ->post(route('actions.create-post'), ['title' => 'Hi', 'card_number' => '4242424242424242'])
            ->assertOk()
            ->assertSee('The body field is required.')
            ->assertSee('value="Hi"', false)
            ->assertDontSee('4242424242424242');

        $this->from('/posts/create')
            ->post(route('actions.create-post'), ['title' => 'Hi', 'card_number' => '4242424242424242'])
            ->assertRedirect('/posts/create')
            ->assertSessionHasErrors(['body'])
            ->assertSessionHasInput('title', 'Hi')
            ->assertSessionMissing('_old_input.card_number');

        $this->assertSame(0, Post::query()->count());
    }

    /**
     * Item 7: a success flashes the output and redirects back; the same title again is a refusal on the title field.
     */
    public function test_a_blade_form_saves_a_draft_then_refuses_the_same_title(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/posts/create')
            ->post(route('actions.create-post'), ['title' => 'Hi', 'body' => 'Hello'])
            ->assertStatus(303)
            ->assertRedirect('/posts/create')
            ->assertSessionHas('action.name', 'create-post');

        $this->from('/posts/create')
            ->post(route('actions.create-post'), ['title' => 'Hi', 'body' => 'Again'])
            ->assertRedirect('/posts/create')
            ->assertSessionHasErrors(['title' => 'You already have a post with that title.']);

        $this->assertSame(['Hi'], $user->posts()->pluck('title')->all());
    }

    /**
     * Item 8: a default token (['*']) posts JSON to the api mount and gets 200; an invalid post gets 422.
     */
    public function test_a_default_sanctum_token_posts_json(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('fresh-app')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/actions/create-post', ['title' => 'Hi', 'body' => 'Hello'])
            ->assertOk()
            ->assertExactJson(['id' => Post::query()->value('id'), 'title' => 'Hi']);

        $this->withToken($token)
            ->postJson('/api/actions/create-post', ['title' => 'Again'])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['body']]);
    }

    /**
     * Item 8: a token from a guard the reader does not know gets 404, and actions:list says why.
     */
    public function test_an_unknown_guard_reads_as_not_found_and_list_explains_it(): void
    {
        $user = User::factory()->create();

        Auth::viaRequest('api-key', fn (Request $request): ?User => $request->header('X-Api-Key') === 'secret' ? $user : null);
        config(['auth.guards.api-key' => ['driver' => 'api-key']]);

        Route::middleware('auth:api-key')->prefix('keyed')->name('keyed.')->group(fn () => Actions::routes());
        Route::getRoutes()->refreshNameLookups();

        $this->postJson('/keyed/actions/create-post', ['title' => 'Hi', 'body' => 'Hello'], ['X-Api-Key' => 'secret'])
            ->assertNotFound()
            ->assertJsonPath('message', trans('agentic-actions::http.not_found'));

        $this->assertSame(0, Post::query()->count());

        $this->artisan('actions:list')
            ->expectsOutputToContain('api-key  unknown to the token reader: every action reads as not found for it.')
            ->assertSuccessful();
    }

    /**
     * Item 13: actions:check passes inside a PHPUnit suite.
     */
    public function test_actions_check_passes(): void
    {
        $this->artisan('actions:check')
            ->expectsOutputToContain('Every check passed')
            ->assertSuccessful();
    }
}
