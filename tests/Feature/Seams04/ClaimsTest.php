<?php

use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Cache\SessionStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ConfirmedSend;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The single-use claim: minted once per paused call, taken once at step 8 for the same action, person,
 * tenant, conversation, call, validated input and card text, spent by a decline or a repeated id, and gone after
 * approvals.ttl; and the reservation that lets one request resume a waiting turn, a cache lock only its holder ends.
 * Every case runs on the array store and on the database store, whose add() is an insertOrIgnore.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->entry = ClassExposure::of(ConfirmedDelete::class);
    $this->input = new ValidatedInput(['post' => 7]);
    $this->claims = app(ApprovalClaims::class);

    // The context a pause and its answer share: the agent's person, named for the action, with the call's ticket.
    $this->ticketed = fn (string $conversation = 'conversation-1', string $call = 'call_1', ?ActionContext $context = null): ActionContext => ($context ?? ActionContext::agent($this->user))
        ->forAction('confirmed-delete')
        ->withApproval(new ApprovalTicket($conversation, $call));

    // The card a pause shows, and step 8 rebuilds from the input that will run.
    $this->card = fn (?ActionContext $context = null, ?ValidatedInput $input = null, ?Entry $entry = null, string $title = 'Delete this post?', array $summary = [['label' => 'Post', 'value' => 'Launch notes']]): ApprovalCard => new ApprovalCard(
        $entry ?? $this->entry, $context ?? ($this->ticketed)(), $title, $summary, ApprovalClaims::fingerprint($input ?? $this->input),
    );

    $this->mint = fn (string $conversation = 'conversation-1', string $call = 'call_1', ?ApprovalCard $card = null): bool => $this->claims->mint($conversation, $call, $card ?? ($this->card)());

    $this->claim = fn (?ActionContext $context = null, ?ValidatedInput $input = null, ?Entry $entry = null, ?ApprovalCard $card = null): bool => $this->claims->claim($card ?? ($this->card)($context, $input, $entry));
});

dataset('claim stores', ['array', 'database']);

/**
 * The key a call's claim is kept under.
 */
function seams04ClaimKey(string $conversation = 'conversation-1', string $call = 'call_1'): string
{
    return ApprovalClaims::PREFIX.hash('sha256', $conversation."\n".$call);
}

it('lets a minted claim be taken once', function (string $store) {
    config(['cache.default' => $store]);

    expect(($this->mint)())->toBeTrue()
        ->and(Cache::has(seams04ClaimKey()))->toBeTrue()
        ->and(Cache::getStore())->toBeInstanceOf($store === 'database' ? DatabaseStore::class : ArrayStore::class)
        ->and(($this->claim)())->toBeTrue()
        ->and(($this->claim)())->toBeFalse();

    if ($store === 'database') {
        expect(DB::table((string) config('cache.stores.database.table', 'cache'))->where('key', 'like', '%'.ApprovalClaims::PREFIX.'%')->count())->toBe(1);
    }
})->with('claim stores');

it('forgets a won claim, so losing the marker never reopens it', function (string $store) {
    config(['cache.default' => $store]);

    ($this->mint)();
    ($this->claim)();

    expect(Cache::has(seams04ClaimKey()))->toBeFalse()
        ->and(Cache::has(seams04ClaimKey().':claimed'))->toBeTrue();

    Cache::forget(seams04ClaimKey().':claimed');

    expect(($this->claim)())->toBeFalse();
})->with('claim stores');

it('refuses a second mint of one call and spends the first, so neither binding can be claimed', function (string $store) {
    config(['cache.default' => $store]);

    $other = new ValidatedInput(['post' => 8]);

    expect(($this->mint)())->toBeTrue()
        ->and(($this->mint)('conversation-1', 'call_1', ($this->card)(input: $other)))->toBeFalse()
        ->and(($this->claim)())->toBeFalse()
        ->and(($this->claim)(input: $other))->toBeFalse()
        ->and(($this->mint)())->toBeFalse();
})->with('claim stores');

it('never mints again for a call whose claim was won or burned', function (string $store) {
    config(['cache.default' => $store]);

    ($this->mint)();
    ($this->claim)();

    $this->claims->burn('conversation-1', 'call_2');

    expect(($this->mint)())->toBeFalse()
        ->and(($this->mint)('conversation-1', 'call_2'))->toBeFalse()
        ->and(($this->claim)(($this->ticketed)('conversation-1', 'call_2')))->toBeFalse();
})->with('claim stores');

it('refuses a claim that was burned before it was taken', function (string $store) {
    config(['cache.default' => $store]);

    ($this->mint)();
    $this->claims->burn('conversation-1', 'call_1');

    expect(Cache::has(seams04ClaimKey()))->toBeFalse()
        ->and(($this->claim)())->toBeFalse();
})->with('claim stores');

it('refuses a claim for another person, tenant, conversation, call, action or validated input, and leaves the claim untouched', function (string $store) {
    config(['cache.default' => $store]);

    ($this->mint)();

    $other = User::factory()->create();
    $team = Team::factory()->create();

    expect(($this->claim)(($this->ticketed)(context: ActionContext::agent($other))))->toBeFalse()
        ->and(($this->claim)(($this->ticketed)(context: ActionContext::agent($this->user, $team))))->toBeFalse()
        ->and(($this->claim)(($this->ticketed)('conversation-2')))->toBeFalse()
        ->and(($this->claim)(($this->ticketed)('conversation-1', 'call_2')))->toBeFalse()
        ->and(($this->claim)(entry: ClassExposure::of(ConfirmedSend::class)))->toBeFalse()
        ->and(($this->claim)(input: new ValidatedInput(['post' => 8])))->toBeFalse()
        ->and(($this->claim)(input: new ValidatedInput(['post' => '7'])))->toBeFalse()
        ->and(($this->claim)(input: new ValidatedInput(['post' => 7, 'note' => 'x'])))->toBeFalse()
        ->and(($this->claim)())->toBeTrue();
})->with('claim stores');

it('refuses a claim whose card reads otherwise than the one the person was shown, and leaves the claim untouched', function (string $store) {
    config(['cache.default' => $store]);

    ($this->mint)();

    expect(($this->claim)(card: ($this->card)(title: 'Delete this draft?')))->toBeFalse()
        ->and(($this->claim)(card: ($this->card)(summary: [['label' => 'Post', 'value' => 'Payroll plan']])))->toBeFalse()
        ->and(($this->claim)(card: ($this->card)(summary: [['label' => 'Title', 'value' => 'Launch notes']])))->toBeFalse()
        ->and(($this->claim)(card: ($this->card)(summary: [['label' => 'Post', 'value' => 'Launch notes'], ['label' => 'Status', 'value' => 'draft']])))->toBeFalse()
        ->and(($this->claim)(card: ($this->card)(summary: [])))->toBeFalse()
        ->and($this->claims->holds('conversation-1', 'call_1', ($this->card)()))->toBeTrue()
        ->and(($this->claim)())->toBeTrue()
        ->and($this->claims->holds('conversation-1', 'call_1', ($this->card)()))->toBeFalse();
})->with('claim stores');

it('binds a claim to the tenant it was minted in', function (string $store) {
    config(['cache.default' => $store]);

    $team = Team::factory()->create();
    $inTeam = ($this->ticketed)(context: ActionContext::agent($this->user, $team));

    ($this->mint)('conversation-1', 'call_1', ($this->card)($inTeam));

    expect(($this->claim)())->toBeFalse()
        ->and(($this->claim)(($this->ticketed)(context: ActionContext::agent($this->user, Team::factory()->create()))))->toBeFalse()
        ->and(($this->claim)($inTeam))->toBeTrue();
})->with('claim stores');

it('lets a claim lapse after approvals.ttl, and never renews it', function (string $store) {
    config(['cache.default' => $store, 'agentic-actions.approvals.ttl' => 60]);

    ($this->mint)();
    $this->travel(61)->seconds();

    expect(($this->claim)())->toBeFalse()
        ->and(Cache::has(seams04ClaimKey()))->toBeFalse();
})->with('claim stores');

it('keeps a claim until approvals.ttl', function (string $store) {
    config(['cache.default' => $store, 'agentic-actions.approvals.ttl' => 60]);

    ($this->mint)();
    $this->travel(59)->seconds();

    expect(($this->claim)())->toBeTrue();
})->with('claim stores');

it('reads an approvals.ttl below 1 as 1', function () {
    config(['agentic-actions.approvals.ttl' => 0]);

    expect(($this->mint)())->toBeTrue()
        ->and(($this->claim)())->toBeTrue();
});

it('refuses a context with no ticket, or a ticket that names no call', function (?ApprovalTicket $ticket) {
    ($this->mint)();

    $context = ActionContext::agent($this->user)->forAction('confirmed-delete')->withApproval($ticket);

    expect(($this->claim)($context))->toBeFalse()
        ->and(($this->claim)())->toBeTrue();
})->with([
    'no ticket' => [null],
    'the catalog\'s ticket' => [new ApprovalTicket(null, null)],
    'no conversation' => [new ApprovalTicket(null, 'call_1')],
    'no call' => [new ApprovalTicket('conversation-1', null)],
    'blank ids' => [new ApprovalTicket('', ' ')],
]);

it('refuses and reports when the store throws', function () {
    Exceptions::fake();

    ($this->mint)();

    Cache::shouldReceive('get')->andThrow(new RuntimeException('The store is down.'));

    expect(($this->claim)())->toBeFalse();

    Exceptions::assertReported(RuntimeException::class);
});

it('reserves a waiting turn for one request, whatever the order of its calls, until it is released or lapses with approvals.ttl', function (string $store) {
    config(['cache.default' => $store, 'agentic-actions.approvals.ttl' => 300]);

    $reserve = fn (string $conversation = 'conversation-1', array $calls = ['call_1', 'call_2']): ?Lock => $this->claims->reserve($conversation, $calls);
    $held = $reserve();

    expect($held)->not->toBeNull()
        ->and(Cache::lock(ApprovalClaims::TURN_PREFIX.hash('sha256', "conversation-1\ncall_1\ncall_2"), 1)->get())->toBeFalse()
        ->and($reserve(calls: ['call_2', 'call_1']))->toBeNull()
        ->and($reserve(calls: ['call_1']))->not->toBeNull()
        ->and($reserve('conversation-2'))->not->toBeNull();

    $this->claims->release($held);

    // A request that died holding it: the turn waits until the reservation lapses.
    expect($reserve())->not->toBeNull();

    $this->travel(299)->seconds();

    expect($reserve())->toBeNull();

    $this->travel(2)->seconds();

    expect($reserve())->not->toBeNull();
})->with('claim stores');

it('lets only the request holding a reservation end it, so one whose reservation lapsed never frees a later one', function (string $store) {
    config(['cache.default' => $store, 'agentic-actions.approvals.ttl' => 60]);

    $reserve = fn (): ?Lock => $this->claims->reserve('conversation-1', ['call_1']);

    // A reserves, and outlives its reservation; B then reserves the same turn, and C is turned away.
    $a = $reserve();
    $this->travel(61)->seconds();
    $b = $reserve();

    expect($a)->not->toBeNull()
        ->and($b)->not->toBeNull()
        ->and($reserve())->toBeNull();

    // A ends late: B still holds the turn.
    $this->claims->release($a);

    expect($reserve())->toBeNull();

    // B ends: the turn is free.
    $this->claims->release($b);

    expect($reserve())->not->toBeNull();
})->with('claim stores');

it('reads a turn as reserved, and reports, when the store throws', function () {
    Exceptions::fake();

    Cache::shouldReceive('lock')->andThrow(new RuntimeException('The store is down.'));

    expect($this->claims->reserve('conversation-1', ['call_1']))->toBeNull();

    Exceptions::assertReported(RuntimeException::class);
});

it('reads every turn as reserved, and reports, on a store that cannot lock', function () {
    Exceptions::fake();

    Cache::extend('lockless', fn (): Repository => Cache::repository(new SessionStore(app('session')->driver('array'))));
    config(['cache.default' => 'lockless', 'cache.stores.lockless' => ['driver' => 'lockless']]);

    expect($this->claims->reserve('conversation-1', ['call_1']))->toBeNull()
        ->and($this->claims->reserve('conversation-2', ['call_9']))->toBeNull();

    Exceptions::assertReported(fn (Error $exception): bool => str_contains($exception->getMessage(), 'lock'));
});

it('has no card, so no claim, for validated input that cannot be encoded', function () {
    ($this->mint)();

    expect(fn () => ($this->card)(input: new ValidatedInput(['post' => "\xB1\x31"])))->toThrow(JsonException::class)
        ->and(($this->claim)())->toBeTrue();
});

it('fingerprints the validated input: key order ignored at every depth, list order kept, text as is', function () {
    $fingerprint = fn (array $input): string => ApprovalClaims::fingerprint(new ValidatedInput($input));

    expect($fingerprint(['a' => 1, 'b' => ['y' => 1, 'x' => [2, 3]]]))->toBe($fingerprint(['b' => ['x' => [2, 3], 'y' => 1], 'a' => 1]))
        ->and($fingerprint(['list' => [1, 2]]))->not->toBe($fingerprint(['list' => [2, 1]]))
        ->and($fingerprint(['n' => 1]))->not->toBe($fingerprint(['n' => 1.0]))
        ->and($fingerprint(['n' => 1]))->not->toBe($fingerprint(['n' => '1']))
        ->and($fingerprint(['title' => 'منشور الإطلاق']))->toBe(hash('sha256', '{"title":"منشور الإطلاق"}'))
        ->and($fingerprint(['title' => 'منشور الإطلاق']))->toBe($fingerprint(['title' => 'منشور الإطلاق']));
});
