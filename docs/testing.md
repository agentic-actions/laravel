# Testing

Most tests need nothing from the package. Post to the route, run the command or call `CreatePost::run()`, then assert on the response and the database, as you would for any Laravel code. The Read guard is on in tests too, so a Read action that writes fails the test that runs it.

The testing kit covers the rest. Every assertion is built on `PHPUnit\Framework\Assert`, so it works in a PHPUnit test class and in Pest. The Pest expectation below registers only when Pest is installed.

## Faking actions

`Actions::fake()` fakes every action while it is bound. Calls are recorded, and `authorize()`, `handle()`, the gates and the events never run. Use it when the code under test is the caller: a controller, a Livewire component, a job, an agent. Because it switches every gate off, it works only while the app runs its tests (`APP_ENV=testing`): it throws anywhere else, and a fake left bound outside a test is ignored.

```php
use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Refusal;
use App\Actions\CreatePost;
use App\Actions\ListTeamPosts;
use App\Actions\PublishPost;

$fake = Actions::fake([
    CreatePost::class => ['id' => 1, 'title' => 'Hello'],
    PublishPost::class => Refusal::make(__('Not ready to publish.')),
    ListTeamPosts::class => fn (array $input, ActionContext $context) => ['posts' => []],
]);
```

- An array is the output, and also what `run()` returns.
- A `Refusal` makes the call refused: `run()` throws it, a route renders it, an agent reads its sentence.
- A closure receives the input and the context. What it returns is the result, and also the output when it is an array. A `Refusal` it throws refuses the call.
- An action you did not list succeeds with no output, and `run()` returns null.

Then assert on the calls. Each assertion takes the action's class or its name:

```php
$fake->assertRan(CreatePost::class);
$fake->assertRan('create-post', fn (array $input, ActionContext $context) => $input['title'] === 'Hello');
$fake->assertNotRan(PublishPost::class);
$fake->assertRanTimes(CreatePost::class, 1);
$fake->assertNothingRan();
```

A name no action has fails the assertion, so a typo never passes `assertNotRan()`.

## Toolsets and agents

`assertToolset()` pins what one toolset holds. It compares the actions whose classes put them in the toolset now with the list you give, in any order, and names the extra and the missing ones when they differ, so widening a toolset shows up as a failing test someone has to update.

`assertAgentTools()` checks one agent's tools the way laravel/ai resolves them before a turn: its hand-written tools, sub-agents, tool-search groups, MCP tools and action tools. It fails on two tools with one name, on any tool that offers a forbidden key, and on a toolset holding more than `agents.max_tools_per_toolset` actions.

Both come with the `AgenticActions\Testing\ActionAssertions` trait, and as `Actions::assertToolset()` and `Actions::assertAgentTools()`.

### PHPUnit

```php
<?php

namespace Tests\Feature;

use AgenticActions\Facades\Actions;
use AgenticActions\Testing\ActionAssertions;
use App\Actions\CreatePost;
use App\Ai\BlogAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\TestCase;

final class BlogAssistantTest extends TestCase
{
    use ActionAssertions;
    use RefreshDatabase;

    public function test_the_default_toolset_is_reviewed(): void
    {
        $this->assertToolset('default', ['create-post']);
        $this->assertAgentTools(new BlogAssistant(User::factory()->create()));
    }

    public function test_the_assistant_drafts_a_post(): void
    {
        $user = User::factory()->create();

        BlogAssistant::fake([
            new ToolCall('call_1', 'create-post', ['title' => 'Hello', 'body' => 'My first post.']),
            'Your draft is saved.',
        ]);

        (new BlogAssistant($user))->prompt('Draft a post called Hello.');

        $this->assertDatabaseHas('posts', ['user_id' => $user->id, 'title' => 'Hello', 'status' => 'draft']);
    }

    public function test_the_form_calls_the_action(): void
    {
        $fake = Actions::fake([CreatePost::class => ['id' => 1, 'title' => 'Hello']]);

        $this->actingAs(User::factory()->create())
            ->post(route('actions.create-post'), ['title' => 'Hello', 'body' => 'My first post.'])
            ->assertRedirect();

        $fake->assertRan(CreatePost::class, fn (array $input) => $input['title'] === 'Hello');
    }

    public function test_the_exposure_snapshot_is_current(): void
    {
        $this->artisan('actions:check')->assertSuccessful();
    }
}
```

The second test runs the real action through laravel/ai's own fake gateway: the model's tool call goes through the agent door, the whole pipeline and the database.

### Pest

```php
use AgenticActions\Testing\ActionAssertions;
use App\Ai\BlogAssistant;
use App\Models\User;

uses(ActionAssertions::class);

it('keeps the default toolset reviewed', function () {
    $this->assertToolset('default', ['create-post']);
    $this->assertAgentTools(new BlogAssistant(User::factory()->create()));
});

it('offers the assistant the create-post tool', function () {
    expect((new BlogAssistant(User::factory()->create()))->tools())->toContainActionTool('create-post');
});

it('keeps the exposure snapshot current', function () {
    $this->artisan('actions:check')->assertSuccessful();
});
```

`toContainActionTool()` passes when the iterable holds an action tool with that name, and otherwise fails naming the action tools it does hold.

## Confirmations

A confirmation spans two requests: the turn that pauses, and the answer that resumes it. Script the model on the provider instead of faking the agent: laravel/ai resumes approvals against the provider's gateway, so an agent-level fake such as `BlogAssistant::fake()` tests the pause only. laravel/ai's `FakeTextGateway`, installed with `Ai::textProvider()->useTextGateway()`, answers each step in turn. Turn off title generation, which asks the same provider. Post a turn and assert that nothing ran and that the stream holds a `data-approval` part, post the answer as the same user and assert that the action ran, then post it again and assert 409:

```php
use App\Models\Post;
use App\Models\User;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Responses\Data\ToolCall;

it('deletes a post only after the author confirms', function () {
    config(['ai.conversations.generate_title' => false]);

    $user = User::factory()->create();
    $post = Post::factory()->for($user)->create();

    Ai::textProvider()->useTextGateway(new FakeTextGateway([
        new ToolCall('call_1', 'delete-post', ['post' => $post->id]),
        'Deleted.',
    ]));

    $pause = $this->actingAs($user)->postJson('/assistant', ['messages' => [
        ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete my launch notes.']]],
    ]]);

    expect($pause->streamedContent())->toContain('"type":"data-approval"');
    $this->assertModelExists($post);

    $answer = ['messages' => [
        ['id' => 'm2', 'role' => 'assistant', 'parts' => [[
            'type' => 'tool-delete-post',
            'toolCallId' => 'call_1',
            'state' => 'approval-responded',
            'approval' => ['id' => 'call_1', 'approved' => true],
        ]]],
    ]];

    $this->postJson('/assistant', $answer)->streamedContent();
    $this->assertModelMissing($post);

    $this->postJson('/assistant', $answer)->assertStatus(409);
});
```

A streamed turn runs only when the test reads it, so call `streamedContent()` on every turn you post.

## Asking the person

A form for missing fields ([asking the person](asking.md)) spans the same two requests, so script the model on the provider the same way. Have it call the action without a field, assert that nothing ran and that the stream holds a `data-elicitation` part, then post the person's answer: the tool part approved, with MCP's `ElicitResult` beside it under `elicitation`. Assert that the action ran once with the person's value, and that the value appears nowhere in the stored conversation, which is what the model reads on its next step and on every later turn:

```php
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Responses\Data\ToolCall;

it('drafts a post with the body the person typed, which the model never reads', function () {
    config(['ai.conversations.generate_title' => false]);

    $user = User::factory()->create();

    Ai::textProvider()->useTextGateway(new FakeTextGateway([
        new ToolCall('call_1', 'draft-post', ['title' => 'Launch notes']),
        'Drafted.',
    ]));

    $pause = $this->actingAs($user)->postJson('/assistant', ['messages' => [
        ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Draft a post called Launch notes.']]],
    ]]);

    expect($pause->streamedContent())->toContain('"type":"data-elicitation"');
    expect(Post::count())->toBe(0);

    $answer = ['messages' => [
        ['id' => 'm2', 'role' => 'assistant', 'parts' => [[
            'type' => 'tool-draft-post',
            'toolCallId' => 'call_1',
            'state' => 'approval-responded',
            'approval' => ['id' => 'call_1', 'approved' => true],
            'elicitation' => ['action' => 'accept', 'content' => [
                'title' => 'Launch notes',
                'body' => 'What shipped this week.',
                'status' => 'draft',
            ]],
        ]]],
    ]];

    $this->withHeader('Precognition', 'true')->postJson('/assistant', $answer)->assertNoContent();
    expect(Post::count())->toBe(0);

    $this->withoutHeader('Precognition')->postJson('/assistant', $answer)->streamedContent();

    expect(Post::sole()->body)->toBe('What shipped this week.')
        ->and(DB::table('agent_conversation_messages')->get()->toJson())->not->toContain('What shipped this week.');

    $this->postJson('/assistant', $answer)->assertStatus(409);
});
```

The Precognition request checks the answer against the action's rules and runs nothing: an invalid answer gets 422 with the errors by field, and a valid one 204. A decline is `['action' => 'decline']` with `approved` false, and runs nothing.

## Misconfigured actions fail the suite

When the test suite discovers an action whose `#[Expose]` names a surface the rules refuse (`mcp: true` on a Destructive action, agents without a description), the registry throws `MisconfiguredExposure` the first time it loads, so the first test that touches an action fails with the class and the reason. `php artisan actions:list` shows the same reasons without throwing.

## Queued runs

`CreatePost::dispatch($input, $context)` pushes an `AgenticActions\Queue\RunAction` job, so `Queue::fake()` works as for any job:

```php
use AgenticActions\Queue\RunAction;
use Illuminate\Support\Facades\Queue;

Queue::fake();

// ... the code under test calls ImportPosts::dispatch($input, $context) ...

Queue::assertPushed(RunAction::class, fn (RunAction $job): bool => $job->action === ImportPosts::class
    && $job->input['url'] === 'https://example.com/feed.xml'
    && $job->grants === ['actions:read', 'actions:write']);
```

The job's public properties are what the worker runs with: `action`, `input`, `fixed`, `actor`, `tenant`, `locale`, `grants` (null when the caller had no token limits, such as a session), `origin` (the caller's surface) and `idempotencyKey`. `Actions::fake()` answers a queued run in the worker as it answers a direct one.

`Queue::fake()` serializes nothing, so it cannot show what survives the queue: the actor and tenant restored from the database, the encrypted payload, or an input that fails to serialize. A test of those runs a real connection: `sync`, or `database` followed by `$this->artisan('queue:work', ['--once' => true])`.

## MCP in tests

Send a real token to the MCP URL, as [MCP](mcp.md#testing) shows. As with any token test, call `$this->app['auth']->forgetGuards()` before sending a second token in the same test.

## Tokens in tests

`Sanctum::actingAs($user, ['*'])` and `Sanctum::actingAs($user, ['actions:read'])` work with the default token reader, exactly as real tokens with those abilities do. What they cannot carry is a tenant binding: a token double answers only whether it has an ability, so it can never be bound to `tenant:{key}`. To test a tenant-bound token, create a real one and send it as a bearer header:

```php
$token = $user->createToken('test', ['actions:external', 'tenant:'.$team->getKey()])->plainTextToken;

$this->withToken($token)
    ->postJson("/api/teams/{$team->slug}/actions/publish-post", ['post' => $post->id])
    ->assertOk();
```

A session in a test (`$this->actingAs($user)`) has full access, as it does in the app.

## The Read guard and faked events

The Read guard attaches to a database connection when Laravel dispatches `Illuminate\Database\Events\ConnectionEstablished`, the first time your code asks for that connection. `Event::fake()` with no list of events swallows that event, so a connection a test first uses after faking events is not guarded in that test. `RefreshDatabase` and `DatabaseTransactions` use the default connection before the test body runs, so this concerns your other connections, or the default one in a test with neither trait. Use the connection before faking events, fake only the events you assert on (`Event::fake([PostPublished::class])`), or keep the connection event real with `Event::fakeExcept([ConnectionEstablished::class])`. Your application never fakes events, so production is unaffected.
