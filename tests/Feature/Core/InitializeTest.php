<?php

use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Elicitation\Form;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Mcp\McpTool;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Initialize\InitializeProbe;
use Tests\Fixtures\Initialize\InitializingDataset;
use Tests\Fixtures\Initialize\InitializingWrite;
use Tests\Fixtures\Initialize\OwnInitialize;
use Tests\Fixtures\Initialize\ShareLink;
use Tests\Fixtures\Initialize\ShowShareLink;
use Workbench\App\Models\User;

/*
 * A Read that lists tables in $initializes runs initialize() once every check has passed, right before handle(): there
 * it may add rows to those tables, while handle() stays guarded as every Read's is. Nothing that only lists, previews
 * or refuses the call runs it.
 */

beforeEach(function () {
    Trace::reset();
    InitializeProbe::reset();
    ShareLink::createTable();

    $this->user = User::factory()->create();
    $this->post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
    $this->context = ActionContext::http($this->user);
});

afterEach(fn () => ShareLink::dropTable());

it('adds the missing row on the first read, and a later read sends nothing but SELECTs', function () {
    $first = ShowShareLink::run(['post' => $this->post->id], $this->context);

    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (! str_starts_with($query->sql, 'select')) {
            $writes[] = $query->sql;
        }
    });

    $second = ShowShareLink::run(['post' => $this->post->id], $this->context);

    expect($first)->toBe($second)
        ->and($first['token'])->toBe(ShareLink::query()->sole()->token)
        ->and($writes)->toBe([]);
});

it('runs initialize() after both authorize() steps and validation, right before handle()', function () {
    ShowShareLink::run(['post' => $this->post->id], $this->context);

    expect(Trace::$calls)->toBe(['authorize', 'rules', 'authorize with input', 'initialize', 'handle']);
});

it('never runs initialize() for a call a check refuses', function (Closure $call, OutcomeKind $kind) {
    $outcome = $call($this);

    expect($outcome->kind())->toBe($kind)
        ->and(Trace::$calls)->not->toContain('initialize')
        ->and(ShareLink::query()->count())->toBe(0);
})->with([
    'no actor, refused before any input is read' => [fn ($test) => Actions::attempt(ShowShareLink::class, ['post' => $test->post->id], ActionContext::system()), OutcomeKind::Denied],
    'input validation refuses' => [fn ($test) => Actions::attempt(ShowShareLink::class, ['post' => 'the first one'], $test->context), OutcomeKind::Invalid],
    'another author\'s post, refused with the input' => [fn ($test) => Actions::attempt(ShowShareLink::class, ['post' => User::factory()->create()->posts()->create(['title' => 'Theirs', 'body' => 'x', 'status' => 'draft'])->id], $test->context), OutcomeKind::Denied],
]);

it('keeps handle() guarded: it may not add a row, even to a table $initializes names', function () {
    Exceptions::fake();
    InitializeProbe::$handling = fn () => ShareLink::query()->create(['post_id' => $this->post->id, 'token' => 'from-handle']);

    $outcome = Actions::attempt(InitializeProbe::class, [], $this->context);

    expect($outcome->exception())->toBeInstanceOf(ReadActionWrote::class)
        ->and($outcome->exception()?->getMessage())->toBe('A Read action tried to write [share_links]. The statement did not run: give the action a writing effect, or list the table in agentic-actions.reads.writable_tables.')
        ->and(InitializeProbe::$initialized)->toBe(1)
        ->and(ShareLink::query()->count())->toBe(0);
});

it('still runs initialize() with reads.guard off', function () {
    config(['agentic-actions.reads.guard' => false]);

    $output = ShowShareLink::run(['post' => $this->post->id], $this->context);

    expect($output['token'])->toBe(ShareLink::query()->sole()->token)
        ->and(Trace::$calls)->toContain('initialize');
});

it('never calls an initialize() that only a Read declaring $initializes runs', function (string $class, array $input) {
    expect(Actions::attempt($class, $input, $this->context)->kind())->toBe(OutcomeKind::Ok)
        ->and(Trace::$calls)->not->toContain('initialize');
})->with([
    'a Read\'s own method, without $initializes' => [OwnInitialize::class, []],
    'a Write\'s, with $initializes' => [InitializingWrite::class, []],
    'a dataset\'s, with $initializes' => [InitializingDataset::class, ['measures' => ['posts']]],
]);

it('adds the row for a token that may only read, never for one that may not, and keeps MCP\'s read-only hint', function () {
    $this->mountRoutes(fn () => Route::middleware(['api', 'auth:sanctum'])->post('api/share-link', ShowShareLink::class));

    $writer = $this->user->createToken('writer', ['actions:write'])->plainTextToken;
    $reader = $this->user->createToken('reader', ['actions:read'])->plainTextToken;

    $this->withToken($writer)->postJson('/api/share-link', ['post' => $this->post->id])->assertNotFound();

    expect(ShareLink::query()->count())->toBe(0);

    $this->app['auth']->forgetGuards();

    $token = $this->withToken($reader)->postJson('/api/share-link', ['post' => $this->post->id])->assertOk()->json('token');

    expect($token)->toBe(ShareLink::query()->sole()->token)
        ->and((new McpTool(ClassExposure::of(ShowShareLink::class), $this->context))->annotations())->toBe([
            'readOnlyHint' => true,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ]);
});

it('never runs initialize() while an MCP client\'s tool list is built, with or without laravel/ai', function () {
    Sanctum::actingAs($this->user, ['actions:read']);

    expect(app(Runner::class)->exposed(ClassExposure::of(ShowShareLink::class), ActionContext::mcp($this->user, null), Door::Mcp, []))->toBeTrue()
        ->and(Trace::$calls)->toBe(['authorize'])
        ->and(ShareLink::query()->count())->toBe(0);
});

describe('a model\'s call', function () {
    beforeEach(function () {
        $this->skipUnlessAi();

        $this->agent = ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1'));
    });

    it('never runs initialize() while a tool list, a preview or a form is built', function () {
        $runner = app(Runner::class);
        $entry = ClassExposure::of(ShowShareLink::class);

        expect($runner->exposed($entry, $this->agent, Door::Agent, ['default']))->toBeTrue()
            ->and($runner->preview($entry, [], $this->agent, ['default']))->toBeInstanceOf(Form::class)
            ->and($runner->preview($entry, ['post' => $this->post->id], $this->agent, ['default']))->toBeNull()
            ->and(Trace::$calls)->toContain('authorize')
            ->and(Trace::$calls)->not->toContain('initialize')
            ->and(ShareLink::query()->count())->toBe(0);
    });

    it('never runs initialize() for a form\'s answer whose claim is refused, though both authorize() steps passed', function () {
        $entry = ClassExposure::of(ShowShareLink::class);
        $form = app(Runner::class)->preview($entry, [], $this->agent, ['default']);
        $answered = ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1', $form));

        // No claim was minted for the form, so step 8 refuses its answer.
        $outcome = app(Runner::class)->run($entry, ['post' => $this->post->id], $answered, Door::Agent, ['default']);

        expect($form)->toBeInstanceOf(Form::class)
            ->and($outcome->forModel())->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and(Trace::$calls)->toContain('authorize with input')
            ->and(Trace::$calls)->not->toContain('initialize')
            ->and(ShareLink::query()->count())->toBe(0);
    });
});
