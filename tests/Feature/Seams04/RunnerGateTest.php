<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Gateway\ParentInvocation;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ConfirmedSend;
use Tests\Fixtures\Approvals\WritingAuthorize;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * The Runner's gate for Destructive and External calls a model drives: admission lets one through only on the agent
 * door, for a person, with a ticket; its steps before step 8 run inside the Read guard; step 8 runs it only on its
 * claim, once, and handle() never sees the ticket. Every other door, and every other effect, is as in 0.3.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    Trace::reset();
    ConfirmedDelete::$handled = null;
    ConfirmedDelete::$current = null;

    $this->user = User::factory()->create();
    $this->post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);

    // Every cache key the claims read, so a test can tell whether step 8 ran.
    $this->claimReads = [];
    Event::listen(RetrievingKey::class, function (RetrievingKey $event): void {
        if (str_starts_with($event->key, ApprovalClaims::PREFIX)) {
            $this->claimReads[] = $event->key;
        }
    });
});

/**
 * Run a class through one of the Runner's doors, with the approvals toolset.
 *
 * @param  class-string<Action>  $class
 * @param  array<string, mixed>  $input
 * @param  list<string>  $toolsets
 */
function seams04Run(string $class, array $input, ActionContext $context, Door $door = Door::Agent, array $toolsets = ['approvals']): Outcome
{
    return app(Runner::class)->run(ClassExposure::of($class), $input, $context, $door, $toolsets);
}

/**
 * Mint the claim a real pause would, from the card the Runner previews for this call.
 *
 * @param  class-string<Action>  $class
 * @param  array<string, mixed>  $input
 */
function seams04Mint(string $class, array $input, ActionContext $context): void
{
    $ticket = $context->approval;
    $card = app(Runner::class)->preview(ClassExposure::of($class), $input, $context, ['approvals']);

    expect($card)->not->toBeNull()
        ->and(app(ApprovalClaims::class)->mint((string) $ticket?->conversationId, (string) $ticket?->toolCallId, $card))->toBeTrue();
}

describe('admission', function () {
    it('lets a ticket that names no call through admission, and step 8 refuses it', function (ApprovalTicket $ticket) {
        Event::fake([ActionRefused::class]);

        $outcome = seams04Run(ConfirmedDelete::class, ['post' => $this->post->id], ActionContext::agent($this->user)->withApproval($ticket));

        expect($outcome->kind())->toBe(OutcomeKind::Refused)
            ->and($outcome->status())->toBe(409)
            ->and($outcome->forModel())->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and(Trace::$calls)->toBe(['ConfirmedDelete::authorize'])
            ->and(Post::query()->whereKey($this->post->id)->exists())->toBeTrue();

        Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'refused' && $event->status === 409);
    })->with([
        'the catalog\'s ticket' => [new ApprovalTicket(null, null)],
        'a call with no conversation' => [new ApprovalTicket(null, 'call_1')],
        'a call that never paused' => [new ApprovalTicket('conversation-1', 'call_1')],
    ]);

    it('never finds one on the MCP door, in-process inside a tool, or for a guest, ticket or not', function () {
        $ticket = new ApprovalTicket('conversation-1', 'call_1');
        seams04Mint(ConfirmedDelete::class, ['post' => $this->post->id], ActionContext::agent($this->user)->withApproval($ticket));
        $this->claimReads = [];
        Trace::reset();

        $mcp = seams04Run(ConfirmedDelete::class, ['post' => $this->post->id], ActionContext::mcp($this->user, null)->withApproval($ticket), Door::Mcp);
        $inProcess = ParentInvocation::within('invocation-1', 'tool-invocation-1', fn (): Outcome => seams04Run(
            ConfirmedDelete::class, ['post' => $this->post->id], ActionContext::http($this->user)->withApproval($ticket), Door::InProcess,
        ));
        $guest = seams04Run(ConfirmedDelete::class, ['post' => $this->post->id], ActionContext::agent(null)->withApproval($ticket));

        expect([$mcp->kind(), $inProcess->kind(), $guest->kind()])->toBe([OutcomeKind::NotFound, OutcomeKind::NotFound, OutcomeKind::NotFound])
            ->and(Trace::$calls)->toBe([])
            ->and($this->claimReads)->toBe([])
            ->and(Post::query()->whereKey($this->post->id)->exists())->toBeTrue();
    });
});

describe('step 8', function () {
    it('runs a call on its minted claim once, and handle() never sees the ticket', function () {
        $context = ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1'));
        seams04Mint(ConfirmedDelete::class, ['post' => $this->post->id], $context);

        $outcome = seams04Run(ConfirmedDelete::class, ['post' => $this->post->id], $context);

        expect($outcome->kind())->toBe(OutcomeKind::Ok)
            ->and($outcome->forModel())->toBe('Done.')
            ->and(Post::query()->whereKey($this->post->id)->exists())->toBeFalse()
            ->and(Trace::$calls)->toBe(['ConfirmedDelete::authorize', 'ConfirmedDelete::authorize', 'ConfirmedDelete::handle'])
            ->and(ConfirmedDelete::$handled?->approval)->toBeNull()
            ->and(ConfirmedDelete::$current?->requestId)->toBe($context->requestId)
            ->and(ConfirmedDelete::$current?->approval)->toBeNull()
            ->and($outcome->context()->approval)->toBeNull()
            ->and($this->claimReads)->not->toBe([]);
    });

    it('refuses the same ticket and input again: the claim is spent', function () {
        $context = ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1'));
        seams04Mint(ConfirmedSend::class, ['to' => 'someone@example.com'], $context);

        $first = seams04Run(ConfirmedSend::class, ['to' => 'someone@example.com'], $context);
        $second = seams04Run(ConfirmedSend::class, ['to' => 'someone@example.com'], $context);

        expect($first->kind())->toBe(OutcomeKind::Ok)
            ->and($second->kind())->toBe(OutcomeKind::Refused)
            ->and($second->forModel())->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and(Trace::$calls)->toBe(['ConfirmedSend::handle']);
    });

    it('refuses a call whose validated input differs from the card\'s', function () {
        $context = ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1'));
        seams04Mint(ConfirmedSend::class, ['to' => 'someone@example.com'], $context);

        $outcome = seams04Run(ConfirmedSend::class, ['to' => 'someone-else@example.com'], $context);

        expect($outcome->kind())->toBe(OutcomeKind::Refused)
            ->and(Trace::$calls)->toBe([])
            ->and(seams04Run(ConfirmedSend::class, ['to' => 'someone@example.com'], $context)->kind())->toBe(OutcomeKind::Ok);
    });

    it('leaves the claim untouched when authorize() refuses first', function () {
        $foreign = User::factory()->create()->posts()->create(['title' => 'Theirs', 'body' => 'x', 'status' => 'draft']);
        $context = ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1'));

        expect(seams04Run(ConfirmedDelete::class, ['post' => $foreign->id], $context)->kind())->toBe(OutcomeKind::Denied)
            ->and($this->claimReads)->toBe([])
            ->and(Post::query()->whereKey($foreign->id)->exists())->toBeTrue();
    });
});

describe('the gated guard', function () {
    it('refuses a write in authorize() before step 8, with reads.guard off', function () {
        config(['agentic-actions.reads.guard' => false]);
        Exceptions::fake();

        $outcome = seams04Run(WritingAuthorize::class, ['post' => $this->post->id], ActionContext::agent($this->user)->withApproval(new ApprovalTicket(null, null)));

        expect($outcome->kind())->toBe(OutcomeKind::Failed)
            ->and($outcome->exception())->toBeInstanceOf(ReadActionWrote::class)
            ->and(Post::query()->where('title', 'WRITTEN-BY-AUTHORIZE')->exists())->toBeFalse()
            ->and(Trace::$calls)->toBe([])
            ->and($this->claimReads)->toBe([]);

        Exceptions::assertReported(ReadActionWrote::class);
    });
});

describe('every other call', function () {
    it('runs a Write on the agent door as before, ticket or not, and reads no claim', function () {
        $outcome = seams04Run(CreateNote::class, ['title' => 'Hi', 'body' => 'x'], ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1')), toolsets: ['default']);

        expect($outcome->kind())->toBe(OutcomeKind::Ok)
            ->and(Post::query()->where('title', 'Hi')->exists())->toBeTrue()
            ->and($this->claimReads)->toBe([]);
    });

    it('runs a Destructive action on its generated web route with no claim', function () {
        $this->mountRoutes(fn () => Route::middleware('auth')->group(fn () => $this->generatedRoute(ConfirmedDelete::class)));

        $this->actingAs($this->user)
            ->postJson('/confirmed-delete', ['post' => $this->post->id])
            ->assertOk();

        expect(Post::query()->whereKey($this->post->id)->exists())->toBeFalse()
            ->and(Trace::$calls)->toBe(['ConfirmedDelete::authorize', 'ConfirmedDelete::handle'])
            ->and($this->claimReads)->toBe([]);
    });
});
