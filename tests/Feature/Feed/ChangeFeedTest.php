<?php

use AgenticActions\ActionContext;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Effect;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Feed\ChangeFeed;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Feature\Feed\Fixtures\FeedCountPosts;
use Tests\Feature\Feed\Fixtures\FeedDraftPost;
use Tests\Feature\Feed\Fixtures\FeedTeamPost;
use Tests\Feature\Feed\Fixtures\FeedThrowingStore;
use Tests\Fixtures\Actions\CreateNote;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The change feed's server half: what a completed write records and where, and what a poll reads back.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.classes' => [FeedDraftPost::class, FeedTeamPost::class, FeedCountPosts::class]]);
    $this->refreshActions();
    $this->freezeTime();

    $this->user = User::factory()->create();
});

/**
 * The server clock in milliseconds, as the feed reads it.
 */
function feedNow(): int
{
    return (int) now()->getTimestampMs();
}

/**
 * The actor bucket of a person.
 */
function feedActorBucket(User $user): string
{
    return 'actor:'.$user->getMorphClass().':'.$user->getKey();
}

/**
 * The last write time a bucket keeps for a key, or null.
 */
function feedEntry(string $bucket, string $key): mixed
{
    return Cache::get(ChangeFeed::PREFIX."{$bucket}:{$key}");
}

/**
 * An ActionCompleted as the Runner fires it.
 */
function feedCompleted(?Effect $effect, User $actor, ?int $tenantId = null): ActionCompleted
{
    return new ActionCompleted('feed-draft-post', FeedDraftPost::class, Surface::Http, $effect, false, $actor->getMorphClass(), $actor->getKey(), $tenantId, 'request', 1.0);
}

describe('recording', function () {
    it('records a write without a tenant in the actor\'s bucket', function () {
        FeedDraftPost::run(['title' => 'Hi'], ActionContext::http($this->user));

        expect(feedEntry(feedActorBucket($this->user), 'posts'))->toBe(feedNow())
            ->and(feedEntry(feedActorBucket($this->user), '*'))->toBeNull();
    });

    it('records a tenant write in the tenant\'s bucket only, so every member hears of it', function () {
        $this->useTeamTenancy();
        $team = Team::factory()->create();
        $team->users()->attach($this->user);

        FeedTeamPost::run(['title' => 'Hi'], ActionContext::http($this->user, $team));

        expect(feedEntry('tenant:'.$team->getKey(), 'posts'))->toBe(feedNow())
            ->and(feedEntry(feedActorBucket($this->user), 'posts'))->toBeNull();
    });

    it('records nothing for a Read, or for an event without an effect', function () {
        FeedCountPosts::run([], ActionContext::http($this->user));
        app(ChangeFeed::class)->handle(feedCompleted(null, $this->user));

        expect(feedEntry(feedActorBucket($this->user), 'posts'))->toBeNull()
            ->and(feedEntry(feedActorBucket($this->user), '*'))->toBeNull();
    });

    it('records nothing for a call with neither an actor nor a tenant', function () {
        config(['cache.default' => 'database']);

        app(ChangeFeed::class)->handle(new ActionCompleted('feed-draft-post', FeedDraftPost::class, Surface::System, Effect::Write, false, null, null, null, 'request', 1.0));

        expect(DB::table('cache')->count())->toBe(0);
    });

    it('records "*" for a write that declares no touches', function () {
        CreateNote::run(['title' => 'Hi', 'body' => 'x'], ActionContext::http($this->user));

        expect(feedEntry(feedActorBucket($this->user), '*'))->toBe(feedNow());
    });

    it('records nothing with feed.enabled off', function () {
        config(['agentic-actions.feed.enabled' => false]);

        FeedDraftPost::run(['title' => 'Hi'], ActionContext::http($this->user));

        expect(Post::query()->count())->toBe(1)
            ->and(feedEntry(feedActorBucket($this->user), 'posts'))->toBeNull();
    });

    it('records nothing for a write rolled back inside DB::transaction()', function () {
        try {
            DB::transaction(function () {
                FeedDraftPost::run(['title' => 'Hi'], ActionContext::http($this->user));

                throw new RuntimeException('Rolled back.');
            });
        } catch (RuntimeException) {
            //
        }

        expect(Post::query()->count())->toBe(0)
            ->and(feedEntry(feedActorBucket($this->user), 'posts'))->toBeNull();
    });

    it('reports a store that throws, and the caller still gets its result', function () {
        Exceptions::fake();
        Cache::extend('feed-throwing', fn () => Cache::repository(new FeedThrowingStore));
        config(['cache.stores.feed-throwing' => ['driver' => 'feed-throwing'], 'cache.default' => 'feed-throwing']);

        $id = FeedDraftPost::run(['title' => 'Hi'], ActionContext::http($this->user));

        expect($id)->toBe(Post::query()->value('id'));

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The cache store is down.');
    });

    it('keeps one entry per key, holding the later write\'s time', function () {
        FeedDraftPost::run(['title' => 'One'], ActionContext::http($this->user));
        $first = feedNow();

        $this->travel(5)->seconds();
        FeedDraftPost::run(['title' => 'Two'], ActionContext::http($this->user));

        expect(feedEntry(feedActorBucket($this->user), 'posts'))->toBe($first + 5000);
    });

    it('keeps an entry for feed.window seconds', function () {
        config(['agentic-actions.feed.window' => 60]);

        FeedDraftPost::run(['title' => 'Hi'], ActionContext::http($this->user));
        $this->travel(59)->seconds();

        expect(feedEntry(feedActorBucket($this->user), 'posts'))->not->toBeNull();

        $this->travel(2)->seconds();

        expect(feedEntry(feedActorBucket($this->user), 'posts'))->toBeNull();
    });
});

describe('reading', function () {
    it('answers a first poll, and a cursor from the future, with a cursor 2 s behind the clock and nothing', function () {
        $feed = app(ChangeFeed::class);
        $context = ActionContext::http($this->user);
        $now = feedNow();

        FeedDraftPost::run(['title' => 'Hi'], $context);

        expect($feed->since($context, null))->toBe(['now' => $now - 2000, 'touches' => []])
            ->and($feed->since($context, $now + 1))->toBe(['now' => $now - 2000, 'touches' => []]);
    });

    it('reloads everything once for a cursor older than the window', function () {
        $feed = app(ChangeFeed::class);
        $context = ActionContext::http($this->user);
        $now = feedNow();

        expect($feed->since($context, $now - 600_000 - 1))->toBe(['now' => $now - 2000, 'touches' => ['*']])
            ->and($feed->since($context, $now - 600_000))->toBe(['now' => $now - 2000, 'touches' => []]);
    });

    it('reads the keys written at or after the cursor, once the page echoes it', function () {
        $feed = app(ChangeFeed::class);
        $context = ActionContext::http($this->user);

        $opened = $feed->since($context, null);

        $this->travel(1)->seconds();
        FeedDraftPost::run(['title' => 'Hi'], $context);
        $this->travel(14)->seconds();

        $next = $feed->since($context, $opened['now']);

        expect($next['touches'])->toBe(['posts']);

        $this->travel(15)->seconds();

        expect($feed->since($context, $next['now'])['touches'])->toBe([]);
    });

    it('still reads a write another server recorded with a clock up to 2 s behind', function () {
        $feed = app(ChangeFeed::class);
        $context = ActionContext::http($this->user);
        $cursor = $feed->since($context, null)['now'];

        // Written after the poll, but stamped 1.5 s earlier by a server whose clock lags.
        Cache::put(ChangeFeed::PREFIX.feedActorBucket($this->user).':posts', feedNow() - 1500, 600);

        expect($feed->since($context, $cursor)['touches'])->toBe(['posts'])
            ->and($feed->since($context, feedNow() - 1499)['touches'])->toBe([]);
    });

    it('reads a time the store hands back as a numeric string, as redis does', function () {
        $feed = app(ChangeFeed::class);
        $context = ActionContext::http($this->user);
        $cursor = feedNow() - 1000;

        Cache::put(ChangeFeed::PREFIX.feedActorBucket($this->user).':posts', (string) feedNow(), 600);

        expect($feed->since($context, $cursor)['touches'])->toBe(['posts']);
    });

    it('answers "*" alone when "*" is among the keys', function () {
        $feed = app(ChangeFeed::class);
        $context = ActionContext::http($this->user);
        $cursor = feedNow() - 1000;

        FeedDraftPost::run(['title' => 'Hi'], $context);
        CreateNote::run(['title' => 'Hi', 'body' => 'x'], $context);

        expect($feed->since($context, $cursor)['touches'])->toBe(['*']);
    });

    it('never reads a key no discovered action declares', function () {
        $feed = app(ChangeFeed::class);
        $context = ActionContext::http($this->user);
        $cursor = feedNow() - 1000;

        Cache::put(ChangeFeed::PREFIX.feedActorBucket($this->user).':secrets', feedNow(), 600);

        expect($feed->since($context, $cursor)['touches'])->toBe([]);
    });

    it('reads the actor\'s bucket, and the tenant\'s on a tenant context, never another tenant\'s', function () {
        $this->useTeamTenancy();
        $acme = Team::factory()->create();
        $other = Team::factory()->create();
        $acme->users()->attach($this->user);
        $other->users()->attach($this->user);

        $feed = app(ChangeFeed::class);
        $cursor = feedNow() - 1000;

        FeedTeamPost::run(['title' => 'Hi'], ActionContext::http($this->user, $acme));

        expect($feed->since(ActionContext::http($this->user, $acme), $cursor)['touches'])->toBe(['posts'])
            ->and($feed->since(ActionContext::http($this->user, $other), $cursor)['touches'])->toBe([])
            ->and($feed->since(ActionContext::http($this->user), $cursor)['touches'])->toBe([]);

        FeedDraftPost::run(['title' => 'Mine'], ActionContext::http($this->user));

        expect($feed->since(ActionContext::http($this->user, $other), $cursor)['touches'])->toBe(['posts'])
            ->and($feed->since(ActionContext::http($this->user), $cursor)['touches'])->toBe(['posts']);
    });

    it('records a write in one query and reads a poll in one on the database store', function () {
        config(['cache.default' => 'database']);
        app(ActionRegistry::class)->all();

        $feed = app(ChangeFeed::class);
        $context = ActionContext::http($this->user);
        $cursor = feedNow() - 1000;

        DB::enableQueryLog();
        DB::flushQueryLog();

        $feed->handle(feedCompleted(Effect::Write, $this->user));

        expect(DB::getQueryLog())->toHaveCount(1);

        DB::flushQueryLog();
        $answer = $feed->since($context, $cursor);

        expect(DB::getQueryLog())->toHaveCount(1)
            ->and($answer['touches'])->toBe(['posts']);
    });
});
