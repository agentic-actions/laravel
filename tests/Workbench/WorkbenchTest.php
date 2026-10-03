<?php

use AgenticActions\Ai\ActionTool;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Testing\ActionAssertions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\ToolNameResolver;
use Workbench\App\Ai\BlogAssistant;
use Workbench\App\Ai\Tools\CountDrafts;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The blog workbench, end to end on the real kernel: the Blade form, the token client on the api mount, the slug team,
 * and BlogAssistant on laravel/ai's fake gateway, all from one CreatePost class and its two team siblings.
 */

uses(ActionAssertions::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('the Blade form', function () {
    it('renders the form that posts to the generated route', function () {
        $this->actingAs($this->user)
            ->get('/posts/create')
            ->assertOk()
            ->assertSee('action="'.route('actions.create-post').'"', false)
            ->assertSee('name="_token"', false);
    });

    it('redirects an invalid post back with its errors and old input, but not the card number', function () {
        $title = str_repeat('x', 121);

        $this->actingAs($this->user)
            ->from('/posts/create')
            ->post(route('actions.create-post'), ['title' => $title, 'card_number' => '4242424242424242'])
            ->assertRedirect('/posts/create')
            ->assertSessionHasErrors(['title', 'body'])
            ->assertSessionHasInput('title', $title)
            ->assertSessionMissing('_old_input.card_number');

        $this->get('/posts/create')
            ->assertOk()
            ->assertSee('The title field must not be greater than 120 characters.')
            ->assertSee('The body field is required.')
            ->assertSee('value="'.$title.'"', false)
            ->assertDontSee('4242424242424242');

        expect(Post::query()->count())->toBe(0);
    });

    it('saves a draft, then shows a refusal on the title field for the same title', function () {
        $this->actingAs($this->user)
            ->from('/posts/create')
            ->followingRedirects()
            ->post(route('actions.create-post'), ['title' => 'Hi', 'body' => 'Hello'])
            ->assertOk()
            ->assertSee('Saved "Hi" as a draft.', false);

        $this->from('/posts/create')
            ->followingRedirects()
            ->post(route('actions.create-post'), ['title' => 'Hi', 'body' => 'Again'])
            ->assertOk()
            ->assertSee('You already have a post with that title.')
            ->assertSee('value="Hi"', false);

        expect(Post::query()->where('user_id', $this->user->id)->pluck('status', 'title')->all())->toBe(['Hi' => 'draft']);
    });
});

describe('the token client', function () {
    it('posts JSON to /api/actions/create-post with a default token, and gets 422 for invalid input', function () {
        $token = $this->user->createToken('client')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/actions/create-post', ['title' => 'Hi', 'body' => 'Hello'])
            ->assertOk()
            ->assertExactJson(['id' => Post::query()->value('id'), 'title' => 'Hi']);

        $this->withToken($token)
            ->postJson('/api/actions/create-post', ['body' => 'Hello'])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['title']]);

        expect(Post::query()->count())->toBe(1);
    });

    it('mounts no tenant-scoped action on the api mount', function () {
        $token = $this->user->createToken('client')->plainTextToken;

        $this->withToken($token)->postJson('/api/actions/list-team-posts')->assertNotFound();
    });
});

describe('the slug team', function () {
    beforeEach(function () {
        $this->team = Team::factory()->create(['slug' => 'acme']);
        $this->team->users()->attach($this->user);

        $this->other = Team::factory()->create(['slug' => 'other']);
    });

    it('lists the team\'s posts under its slug for a member', function () {
        $older = Post::factory()->for($this->user)->forTeam($this->team)->create(['title' => 'First']);
        $newer = Post::factory()->forTeam($this->team)->create(['title' => 'Second']);
        Post::factory()->forTeam($this->other)->create(['title' => 'Elsewhere']);
        Post::factory()->for($this->user)->create(['title' => 'No team']);

        $this->actingAs($this->user)
            ->postJson('/teams/acme/actions/list-team-posts')
            ->assertOk()
            ->assertExactJson(['posts' => [
                ['id' => $newer->id, 'title' => 'Second'],
                ['id' => $older->id, 'title' => 'First'],
            ]]);
    });

    it('reads a team the actor does not belong to, or an unknown slug, as not found', function () {
        $this->actingAs($this->user)
            ->postJson('/teams/other/actions/list-team-posts')
            ->assertNotFound()
            ->assertExactJson(['message' => trans('agentic-actions::http.not_found')]);

        $this->postJson('/teams/nobody/actions/list-team-posts')->assertNotFound();
    });

    it('runs the listing under the Read guard', function () {
        Post::factory()->for($this->user)->forTeam($this->team)->create(['status' => 'draft']);

        Post::retrieved(function (): void {
            DB::table('posts')->update(['status' => 'archived']);
        });

        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($this->user)->postJson('/teams/acme/actions/list-team-posts'))
            ->toThrow(ReadActionWrote::class);

        expect(DB::table('posts')->where('status', 'archived')->count())->toBe(0);
    });

    it('publishes the actor\'s own draft in the team, and refuses a post of another team', function () {
        $draft = Post::factory()->for($this->user)->forTeam($this->team)->create();
        $foreign = Post::factory()->for($this->user)->forTeam($this->other)->create();
        $colleague = Post::factory()->forTeam($this->team)->create();

        $this->actingAs($this->user)
            ->postJson('/teams/acme/actions/publish-post', ['post' => $draft->id])
            ->assertOk()
            ->assertExactJson(['id' => $draft->id, 'status' => 'published']);

        $this->postJson('/teams/acme/actions/publish-post', ['post' => $foreign->id])->assertNotFound();
        $this->postJson('/teams/acme/actions/publish-post', ['post' => $colleague->id])->assertForbidden();

        expect(Post::query()->where('status', 'published')->pluck('id')->all())->toBe([$draft->id]);
    });
});

describe('BlogAssistant', function () {
    it('creates a post through laravel/ai\'s fake gateway', function () {
        $this->skipUnlessAi();

        BlogAssistant::fake([new ToolCall('call_1', 'create-post', ['title' => 'Hi', 'body' => 'Hello']), 'Drafted.']);

        $response = (new BlogAssistant($this->user))->prompt('Draft a post called Hi.');

        expect($response->text)->toBe('Drafted.')
            ->and($response->toolResults->first()->result)->toBe('Done.')
            ->and(Post::query()->sole()->only(['user_id', 'title', 'status']))->toBe(['user_id' => $this->user->id, 'title' => 'Hi', 'status' => 'draft']);
    });

    it('puts both team-free and team actions in the default toolset, and gives the assistant only the team-free ones beside CountDrafts', function () {
        $this->skipUnlessAi();

        $this->assertToolset('default', ['create-post', 'import-posts', 'list-team-posts', 'post-stats', 'posts']);
        $this->assertAgentTools(new BlogAssistant($this->user));

        $tools = [...(new BlogAssistant($this->user))->tools()];

        expect(array_map(fn (Tool $tool): string => ToolNameResolver::resolve($tool), $tools))->toBe(['create-post', 'post-stats', 'posts', 'CountDrafts'])
            ->and(array_slice($tools, 0, 3))->each->toBeInstanceOf(ActionTool::class)
            ->and($tools[3])->toBeInstanceOf(CountDrafts::class);
    });
});

describe('MCP and the change feed', function () {
    it('mounts both MCP paths and a feed route in each group, all in route:list', function () {
        expect(route('agentic-actions.mcp', absolute: false))->toBe('/mcp/actions')
            ->and(route('agentic-actions.mcp.tenant', ['team' => 'acme'], false))->toBe('/mcp/t/acme')
            ->and(route('actions._changes', absolute: false))->toBe('/actions/_changes')
            ->and(route('teams.actions._changes', ['team' => 'acme'], false))->toBe('/teams/acme/actions/_changes');

        $this->artisan('route:list', ['--path' => 'mcp'])
            ->expectsOutputToContain('agentic-actions.mcp.tenant')
            ->assertSuccessful();

        $this->artisan('route:list', ['--path' => '_changes'])
            ->expectsOutputToContain('teams.actions._changes')
            ->assertSuccessful();
    });

    it('shows where each MCP action is served in actions:list', function () {
        Artisan::call('actions:list');
        $output = Artisan::output();

        expect($output)
            ->toContain('  create-post ')
            ->toContain("  mcp        open  POST mcp/actions\n")
            ->toContain("  mcp        open  POST mcp/t/{team}\n")
            ->toContain("  agents     toolsets: team\n  mcp        skipped: not declared\n");
    });
});

describe('the committed files', function () {
    it('passes actions:check against the committed snapshot, warning only that SQLite gives the dataset no time limit', function () {
        $this->artisan('actions:check')
            ->expectsOutputToContain('[Tables & datasets] The datasets [posts] run on the connection [testing], which has no statement time limit (SQLite sets none)')
            ->expectsOutputToContain('Every check passed, with 1 warning.')
            ->assertSuccessful();
    });

    it('keeps the committed TypeScript file current', function () {
        $path = config('agentic-actions.typescript.path');

        if (Artisan::call('actions:typescript', ['--check' => true]) !== 0) {
            Artisan::call('actions:typescript');

            $this->fail("{$path} was stale and has been rewritten: review the change and commit it.");
        }

        expect(File::get($path))
            ->toContain("from '@agentic-actions/client';")
            ->toContain("url: '/actions/create-post'")
            ->toContain("url: uri('/teams/{team}/actions/list-team-posts', params)")
            ->not->toContain('/api/actions/');
    });
});
