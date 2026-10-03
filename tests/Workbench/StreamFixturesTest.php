<?php

use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\ChatRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Streaming\ErrorGateway;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\Parts;
use Tests\Workbench\CrashingGateway;
use Workbench\App\Ai\BlogAssistant;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The stream fixtures js/tests/fixtures/streams/*.sse, which wire.test.mjs validates against ai 7's own chunk schema,
 * come from real workbench turns. Each one is rebuilt here, its ULIDs and UUIDs numbered in order of first appearance,
 * and compared with the committed file. UPDATE_STREAM_FIXTURES=1 writes the files instead; nothing else does.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config(['ai.conversations.generate_title' => false]);

    Exceptions::fake();

    $this->user = User::factory()->create();

    // One turn through the workbench's endpoint, with the page the preset sends.
    $this->endpoint = fn (string $words): string => $this->actingAs($this->user)
        ->json('POST', '/assistant', [
            'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => $words]]]],
            'page' => ['url' => '/posts/create', 'component' => 'Posts/Create'],
        ], ['Accept' => 'application/json, text/event-stream'])
        ->assertOk()
        ->streamedContent();

    // A turn of TeamAssistant through the team's route that pauses on the card of a delete; with $confirm, the member's
    // Confirm, continuing the paused message, is the turn returned.
    $this->confirmation = function (bool $confirm): string {
        $team = Team::factory()->create(['slug' => 'acme']);
        $team->users()->attach($this->user);
        $post = Post::factory()->for($this->user)->forTeam($team)->create(['title' => 'Launch notes', 'status' => 'draft']);

        (new ScriptedGateway([['delete-post', ['post' => $post->id]]], 'Deleted it.', 'I will ask you first.'))->install();

        $send = fn (array $message): string => $this->actingAs($this->user)
            ->json('POST', '/teams/acme/assistant', ['messages' => [$message]], ['Accept' => 'application/json, text/event-stream'])
            ->assertOk()
            ->streamedContent();

        $paused = $send(['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete my launch notes.']]]);

        if (! $confirm) {
            return $paused;
        }

        app()->forgetScopedInstances();

        return $send([
            'id' => Parts::of($paused)[0]['messageId'],
            'role' => 'assistant',
            'parts' => [['type' => 'tool-delete-post', 'toolCallId' => 'call_1', 'state' => 'approval-responded', 'approval' => ['id' => 'call_1', 'approved' => true]]],
        ]);
    };

    // A turn of TeamAssistant through the team's route that pauses on the form of a draft missing its body and status;
    // with $answer, the member's submitted form, continuing the paused message, is the turn returned.
    $this->elicitation = function (bool $answer): string {
        $team = Team::factory()->create(['slug' => 'acme']);
        $team->users()->attach($this->user);

        (new ScriptedGateway([['draft-team-post', ['title' => 'Launch notes']]], 'Saved your draft.', 'I can draft it once you add a few details.'))->install();

        $send = fn (array $message): string => $this->actingAs($this->user)
            ->json('POST', '/teams/acme/assistant', ['messages' => [$message]], ['Accept' => 'application/json, text/event-stream'])
            ->assertOk()
            ->streamedContent();

        $paused = $send(['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Draft a team post called Launch notes.']]]);

        if (! $answer) {
            return $paused;
        }

        app()->forgetScopedInstances();

        return $send([
            'id' => Parts::of($paused)[0]['messageId'],
            'role' => 'assistant',
            'parts' => [[
                'type' => 'tool-draft-team-post',
                'toolCallId' => 'call_1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call_1', 'approved' => true],
                'elicitation' => ['action' => 'accept', 'content' => ['title' => 'Launch notes', 'body' => 'What shipped this week.', 'status' => 'draft']],
            ]],
        ]);
    };
});

afterEach(function () {
    ignore_user_abort(false);
});

/**
 * The body with every ULID and UUID replaced by id-1, id-2, … in order of first appearance.
 */
function numberedIds(string $body): string
{
    $ids = [];

    return (string) preg_replace_callback(
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\b[0-9a-hjkmnp-tv-z]{26}\b/i',
        function (array $match) use (&$ids): string {
            return $ids[strtolower($match[0])] ??= 'id-'.(count($ids) + 1);
        },
        $body,
    );
}

it('matches the committed stream fixture', function (string $name, Closure $turn) {
    $body = numberedIds($turn->call($this));
    $path = dirname(__DIR__, 2)."/js/tests/fixtures/streams/{$name}.sse";

    Parts::of($body);   // every frame is a data line

    if (getenv('UPDATE_STREAM_FIXTURES') === '1') {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $body);
    }

    expect(File::exists($path))->toBeTrue("{$path} is missing: run UPDATE_STREAM_FIXTURES=1 vendor/bin/pest tests/Workbench/StreamFixturesTest.php and review it.")
        ->and($body)->toBe(File::get($path), "{$path} is stale: run UPDATE_STREAM_FIXTURES=1 vendor/bin/pest tests/Workbench/StreamFixturesTest.php and review the change.");
})->with([
    // 6.4's one-step shape: a write and a hand-written tool in one provider step, then the reply.
    'success' => ['success', function (): string {
        (new OneStepGateway([
            new ToolCall('call_1', 'create-post', ['title' => 'Tides', 'body' => 'The sea comes and goes.']),
            new ToolCall('call_2', 'CountDrafts', []),
        ], 'I saved the draft. You have one.'))->fake(BlogAssistant::class);

        return ($this->endpoint)('Draft a post called Tides, then count my drafts.');
    }],
    'refused' => ['refused', function (): string {
        Post::factory()->for($this->user)->create(['title' => 'Tides']);

        (new OneStepGateway([new ToolCall('call_1', 'create-post', ['title' => 'Tides', 'body' => 'Again.'])], 'You already have that post.'))
            ->fake(BlogAssistant::class);

        return ($this->endpoint)('Draft a post called Tides.');
    }],
    'provider-error' => ['provider-error', function (): string {
        (new ErrorGateway([new ToolCall('call_1', 'create-post', ['title' => 'Tides', 'body' => 'The sea comes and goes.'])]))
            ->fake(BlogAssistant::class);

        return ($this->endpoint)('Draft a post called Tides.');
    }],
    'exception' => ['exception', function (): string {
        (new CrashingGateway([new ToolCall('call_1', 'create-post', ['title' => 'Tides', 'body' => 'The sea comes and goes.'])]))
            ->fake(BlogAssistant::class);

        return ($this->endpoint)('Draft a post called Tides.');
    }],
    // The docs' host part, on the same one-step turn: sent after the rows close, before finish.
    'host-parts' => ['host-parts', function (): string {
        (new OneStepGateway([new ToolCall('call_1', 'create-post', ['title' => 'Tides', 'body' => 'The sea comes and goes.'])], 'Saved.'))
            ->fake(BlogAssistant::class);

        $this->actingAs($this->user);

        $request = Request::create('/assistant', 'POST', ['messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Draft a post called Tides.']]]]]);

        $protocol = new ActionsProtocol(after: fn (StreamableAgentResponse $response, bool $failed): array => [
            ['type' => 'data-blog-stats', 'data' => ['drafts' => $this->user->posts()->where('status', 'draft')->count(), 'failed' => $failed]],
        ]);

        return Parts::body((new BlogAssistant($this->user))->continueLastConversation($this->user)->stream(ChatRequest::from($request)), $protocol);
    }],
    // 7.1's pause: the words, the input-less tool part, its approval request and the card.
    'approval-pause' => ['approval-pause', fn (): string => ($this->confirmation)(false)],
    // 7.3's resume: the same message, the confirmed call's rows, its settled part, then the model's reply.
    'approval-resume' => ['approval-resume', fn (): string => ($this->confirmation)(true)],
    // 0.5's 7.1 pause: the words, the input-less tool part, its approval request and the form.
    'elicitation-pause' => ['elicitation-pause', fn (): string => ($this->elicitation)(false)],
    // 7.4's resume: the same message, the answered call's rows, its settled part, then the model's reply.
    'elicitation-resume' => ['elicitation-resume', fn (): string => ($this->elicitation)(true)],
    // 0.9's tables: a table action's and a dataset's, each a data-view part right after its done row, then the words.
    'views' => ['views', function (): string {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
        Post::factory()->for($this->user)->forTeam(Team::factory()->create(['name' => 'Blue']))->create(['title' => 'Launch notes', 'body' => 'one two three', 'status' => 'published', 'created_at' => '2026-09-10 10:00:00']);
        Post::factory()->for($this->user)->create(['title' => 'Roadmap', 'body' => 'one', 'status' => 'draft', 'created_at' => '2026-09-12 10:00:00']);

        (new OneStepGateway([
            new ToolCall('call_1', 'post-stats', []),
            new ToolCall('call_2', 'posts', ['measures' => ['posts', 'published'], 'by' => ['team'], 'since' => '-1m']),
        ], 'Launch notes is your longest post.'))->fake(BlogAssistant::class);

        return ($this->endpoint)('How are my posts doing?');
    }],
]);
