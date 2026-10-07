<?php

namespace Tests\Feature\Security;

use AgenticActions\ActionContext;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Facades\Actions;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\OutcomeKind;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Initialize\AddsLinkInHandle;
use Tests\Fixtures\Initialize\InitializeProbe;
use Tests\Fixtures\Initialize\ShareLink;
use Tests\Fixtures\Initialize\ShowShareLink;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A Read's initialize() may only add rows to the tables its $initializes names: statements a careless or hostile
 * initialize() might send, run end to end through the pipeline on a real connection. Each one is refused before it
 * runs and named, and the rows it aimed at stay as they were.
 */

beforeEach(function () {
    InitializeProbe::reset();
    ShareLink::createTable();

    $this->user = User::factory()->create();
    $this->post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
    $this->other = $this->user->posts()->create(['title' => 'Roadmap', 'body' => 'y', 'status' => 'draft']);
    $this->context = ActionContext::http($this->user);

    ShareLink::query()->create(['post_id' => $this->post->id, 'token' => 'original']);
});

afterEach(fn () => ShareLink::dropTable());

/**
 * Run InitializeProbe with these statements in its initialize() and return the outcome.
 */
function initializing(Closure $statements): Outcome
{
    InitializeProbe::$initializing = $statements;

    return Actions::attempt(InitializeProbe::class, [], test()->context);
}

const CHANGES_ROWS = "A Read action's initialize() tried to change rows of [share_links]. The statement did not run: initialize() only adds rows, and an update, delete, replace or upsert belongs in a Write action.";

it('refuses every statement but one that adds rows to a table $initializes names, and says why', function (Closure $statements, string $message) {
    Exceptions::fake();

    $outcome = initializing($statements);

    expect($outcome->kind())->toBe(OutcomeKind::Failed)
        ->and($outcome->exception())->toBeInstanceOf(ReadActionWrote::class)
        ->and($outcome->exception()?->getMessage())->toBe($message)
        ->and(InitializeProbe::$initialized)->toBe(0)
        ->and(ShareLink::query()->pluck('token', 'post_id')->all())->toBe([$this->post->id => 'original'])
        ->and(Post::query()->count())->toBe(2);
})->with([
    'updateOrCreate() on a row that exists' => [fn () => ShareLink::query()->updateOrCreate(['post_id' => test()->post->id], ['token' => 'rotated']), CHANGES_ROWS],
    'an upsert' => [fn () => DB::table('share_links')->upsert([['post_id' => test()->post->id, 'token' => 'upserted']], ['post_id'], ['token']), CHANGES_ROWS],
    'SQLite\'s insert or replace' => [fn () => DB::insert('insert or replace into share_links (post_id, token) values (?, ?)', [test()->post->id, 'replaced']), CHANGES_ROWS],
    'SQLite\'s insert or rollback, which rolls back the open transaction' => [fn () => DB::insert('insert or rollback into share_links (post_id, token) values (?, ?)', [test()->post->id, 'rolled-back']), CHANGES_ROWS],
    'a delete' => [fn () => ShareLink::query()->delete(), CHANGES_ROWS],
    'a row added to another table' => [fn (ActionContext $context) => $context->actor(User::class)->posts()->create(['title' => 'Inside', 'body' => 'x', 'status' => 'draft']), "A Read action's initialize() tried to write [posts]. The statement did not run: initialize() only adds rows to the tables its \$initializes lists."],
    'a Write run inside it' => [fn (ActionContext $context) => CreateNote::run(['title' => 'Inside', 'body' => 'x'], $context), "A Read action's initialize() tried to write [posts]. The statement did not run: initialize() only adds rows to the tables its \$initializes lists."],
    'a raw rollback' => [fn () => DB::statement('rollback'), "A Read action's initialize() tried to write [a statement]. The statement did not run: initialize() only adds rows to the tables its \$initializes lists."],
    'a Read run inside it, whose handle() adds a row to the same table' => [fn (ActionContext $context) => AddsLinkInHandle::run([], $context), 'A Read action tried to write [share_links]. The statement did not run: give the action a writing effect, or list the table in agentic-actions.reads.writable_tables.'],
    'a Write queued from it' => [fn (ActionContext $context) => CreateNote::dispatch(['title' => 'Inside', 'body' => 'x'], $context), 'A Read action tried to queue ['.CreateNote::class.'], which is not a Read. Nothing was queued: give the calling action a writing effect.'],
]);

it('runs what only adds rows to a table $initializes names, or only reads', function (Closure $statements, array $tokens) {
    $outcome = initializing($statements);

    expect($outcome->kind())->toBe(OutcomeKind::Ok)
        ->and(InitializeProbe::$initialized)->toBe(1)
        ->and(ShareLink::query()->orderBy('post_id')->pluck('token')->all())->toBe($tokens);
})->with([
    'firstOrCreate() for a post with no link' => [fn () => ShareLink::query()->firstOrCreate(['post_id' => test()->other->id], ['token' => 'added']), ['original', 'added']],
    'firstOrCreate() for a post with one' => [fn () => ShareLink::query()->firstOrCreate(['post_id' => test()->post->id], ['token' => 'ignored']), ['original']],
    'insertOrIgnore() of a row that exists' => [fn () => DB::table('share_links')->insertOrIgnore(['post_id' => test()->post->id, 'token' => 'ignored']), ['original']],
    'an insert that reads another table' => [fn () => DB::table('share_links')->insertUsing(['post_id', 'token'], DB::table('posts')->select('id', 'title')->where('id', test()->other->id)), ['original', 'Roadmap']],
    'a Read run inside it that only reads' => [fn (ActionContext $context) => ListNotes::run([], $context), ['original']],
]);

it('gives two first reads at once one row and one answer, through the key on the post', function () {
    $second = null;

    // The second read runs once the first one's initialize() has found no link, before it adds its own.
    DB::listen(function (QueryExecuted $query) use (&$second): void {
        if ($second === null && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'share_links')) {
            $second = [];
            $second = ShowShareLink::run(['post' => $this->other->id], $this->context);
        }
    });

    $first = ShowShareLink::run(['post' => $this->other->id], $this->context);

    expect($first)->toBe($second)
        ->and(ShareLink::query()->where('post_id', $this->other->id)->count())->toBe(1);
})->group('database');
