<?php

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Ai\PendingCards;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Runner;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ConfirmedSend;
use Tests\Fixtures\Approvals\ConfirmingAgent;
use Tests\Fixtures\Approvals\WritingSummary;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * ActionTool as laravel/ai's Approvable: its effect alone decides whether a call waits for a person, an agent cannot
 * switch that off, the pause previews the card and remembers it, and a call is named on a ticket only after this
 * request asked for its confirmation.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [...config('agentic-actions.discovery.paths'), dirname(__DIR__, 2).'/Fixtures/Approvals'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    Trace::reset();
    ConfirmedDelete::$handled = null;
    Exceptions::fake();

    $this->user = User::factory()->create();
    $this->post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
    $this->agent = (new ConfirmingAgent($this->user))->continue('conversation-1', as: $this->user);

    // A tool as ToolFactory builds it: the agent only when the action can pause.
    $this->tool = fn (string $class, bool $pausing = true, ?ActionContext $context = null): ActionTool => new ActionTool(
        ClassExposure::of($class),
        $context ?? ActionContext::agent($this->user),
        ['approvals', 'default'],
        $pausing ? $this->agent : null,
    );

    $this->claimKey = fn (string $call): string => ApprovalClaims::PREFIX.hash('sha256', "conversation-1\n".$call);

    // Mint the claim a real pause would for this call, from the card the Runner previews for it.
    $this->mint = function (string $call, array $arguments): void {
        $tool = ($this->tool)(ConfirmedDelete::class);
        $context = ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', $call));
        $card = app(Runner::class)->preview($tool->entry(), $arguments, $context, ['approvals']);

        expect(app(ApprovalClaims::class)->mint('conversation-1', $call, $card))->toBeTrue();
    };
});

describe('the gate an agent cannot switch off', function () {
    it('throws when an agent switches a Destructive or External tool\'s confirmation off', function (string $class, string $message) {
        expect(fn () => ($this->tool)($class)->withoutApproval())->toThrow(LogicException::class, $message)
            ->and(fn () => ($this->tool)($class, pausing: false)->withoutApproval())->toThrow(LogicException::class, $message);
    })->with([
        'destructive' => [ConfirmedDelete::class, '[confirmed-delete] is a destructive action: a person confirms each call, and an agent cannot switch that off.'],
        'external' => [ConfirmedSend::class, '[confirmed-send] is an external action: a person confirms each call, and an agent cannot switch that off.'],
    ]);

    it('changes nothing on a Read or Write tool that is told to go without', function (string $class) {
        $tool = ($this->tool)($class, pausing: false);

        expect($tool->withoutApproval())->toBe($tool);
    })->with([CreateNote::class, ListNotes::class]);

    it('throws when a Read or Write tool is told to ask', function (string $class, string $message) {
        expect(fn () => ($this->tool)($class, pausing: false)->requireApproval('Please ask.'))->toThrow(LogicException::class, $message);
    })->with([
        'write' => [CreateNote::class, '[create-note] is a write action: it never waits for a person; only Destructive and External actions ask for a confirmation.'],
        'read' => [ListNotes::class, '[list-notes] is a read action: it never waits for a person; only Destructive and External actions ask for a confirmation.'],
    ]);

    it('keeps asking with the action\'s own sentence when a Destructive tool is told to ask with another reason', function () {
        $tool = ($this->tool)(ConfirmedDelete::class);

        expect($tool->requireApproval('CANARY-REASON'))->toBe($tool)
            ->and($tool->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_1'))?->reason)
            ->toBe('Delete this post? This cannot be undone.');
    });
});

describe('the pause', function () {
    it('asks with the card\'s title and remembers the card for the call', function (string $class, array $arguments, string $title) {
        $approval = ($this->tool)($class)->shouldRequestApproval(new Request($arguments, 'call_1'));

        expect($approval)->toBeInstanceOf(Approval::class)
            ->and($approval->reason)->toBe($title)
            ->and(app(PendingCards::class)->has('call_1'))->toBeTrue()
            ->and(Trace::$calls)->not->toContain('ConfirmedDelete::handle')
            ->and(Cache::has(($this->claimKey)('call_1')))->toBeFalse();
    })->with([
        'the authored sentence' => [ConfirmedDelete::class, fn (): array => ['post' => $this->post->id], 'Delete this post? This cannot be undone.'],
        'the package\'s line for the effect' => [ConfirmedSend::class, ['to' => 'ada@example.com'], 'This reaches people or systems outside the app. Go ahead?'],
    ]);

    it('never asks for a call without an id, for a Write tool or for a tool built without an agent', function (string $class, bool $pausing, ?string $callId) {
        expect(($this->tool)($class, $pausing)->shouldRequestApproval(new Request(['post' => $this->post->id, 'title' => 'x'], $callId)))->toBeNull()
            ->and(app(PendingCards::class)->has('call_1'))->toBeFalse();
    })->with([
        'a null id' => [ConfirmedDelete::class, true, null],
        'a blank id' => [ConfirmedDelete::class, true, ''],
        'a spaces-only id' => [ConfirmedDelete::class, true, '  '],
        'a Write tool' => [CreateNote::class, false, 'call_1'],
        'a Write tool handed an agent' => [CreateNote::class, true, 'call_1'],
        'a tool built without an agent' => [ConfirmedDelete::class, false, 'call_1'],
    ]);

    it('never asks for a call the pipeline would refuse, and forgets the call\'s card', function () {
        $someoneElses = User::factory()->create()->posts()->create(['title' => 'Theirs', 'body' => 'x', 'status' => 'draft']);
        $tool = ($this->tool)(ConfirmedDelete::class);

        expect($tool->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_1')))->toBeInstanceOf(Approval::class)
            ->and($tool->shouldRequestApproval(new Request(['post' => $someoneElses->id], 'call_1')))->toBeNull()
            ->and(app(PendingCards::class)->has('call_1'))->toBeFalse()
            ->and($tool->shouldRequestApproval(new Request(['post' => 999_999], 'call_2')))->toBeNull();
    });
});

describe('the ticket handle() hands the door', function () {
    beforeEach(function () {
        // Every claim key step 8 reads.
        $this->claimReads = [];
        Event::listen(RetrievingKey::class, function (RetrievingKey $event): void {
            if (str_starts_with($event->key, ApprovalClaims::PREFIX)) {
                $this->claimReads[] = $event->key;
            }
        });
    });

    it('names the agent\'s conversation and the call once this request asked for it, and the claim runs it once', function () {
        ($this->mint)('call_1', ['post' => $this->post->id]);
        $tool = ($this->tool)(ConfirmedDelete::class);
        $this->claimReads = [];

        $tool->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_1'));
        $answer = $tool->handle(new Request(['post' => $this->post->id], 'call_1'));

        expect($this->claimReads)->toContain(($this->claimKey)('call_1'))
            ->and(Post::query()->whereKey($this->post->id)->exists())->toBeFalse()
            ->and(Trace::$calls)->toContain('ConfirmedDelete::handle')
            ->and(ConfirmedDelete::$handled?->approval)->toBeNull()
            ->and($answer)->not->toBe(trans('agentic-actions::model.not_confirmed'));
    });

    it('names no call when this request never asked for it, so a claim for its id runs nothing', function () {
        ($this->mint)('call_1', ['post' => $this->post->id]);
        $this->claimReads = [];

        $answer = ($this->tool)(ConfirmedDelete::class)->handle(new Request(['post' => $this->post->id], 'call_1'));

        expect($answer)->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and($this->claimReads)->toBe([])
            ->and(Post::query()->whereKey($this->post->id)->exists())->toBeTrue()
            ->and(Trace::$calls)->not->toContain('ConfirmedDelete::handle')
            ->and(Cache::has(($this->claimKey)('call_1')))->toBeTrue();
    });

    it('names no call once its preview gave no card, so it runs at once and is refused at step 8', function () {
        $tool = ($this->tool)(WritingSummary::class);

        expect($tool->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_1')))->toBeNull();

        $answer = $tool->handle(new Request(['post' => $this->post->id], 'call_1'));

        expect($answer)->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and($this->claimReads)->toBe([])
            ->and(Trace::$calls)->toBe([])
            ->and(Post::query()->where('title', 'WRITTEN-BY-SUMMARY')->exists())->toBeFalse();
    });

    it('names no call that a previous request asked for', function () {
        $tool = ($this->tool)(ConfirmedDelete::class);
        $tool->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_1'));
        ($this->mint)('call_1', ['post' => $this->post->id]);

        app()->forgetScopedInstances();
        $this->claimReads = [];

        expect($tool->handle(new Request(['post' => $this->post->id], 'call_1')))->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and($this->claimReads)->toBe([])
            ->and(Post::query()->whereKey($this->post->id)->exists())->toBeTrue();
    });

    it('gives a tool built without an agent no ticket at all, so the door never finds the action', function () {
        Event::fake([ActionRefused::class]);

        $answer = ($this->tool)(ConfirmedDelete::class, pausing: false)->handle(new Request(['post' => $this->post->id], 'call_1'));

        expect($answer)->toBe(trans('agentic-actions::model.not_found'))
            ->and(Trace::$calls)->toBe([])
            ->and($this->claimReads)->toBe([])
            ->and(Post::query()->whereKey($this->post->id)->exists())->toBeTrue();

        Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->action === 'confirmed-delete');
    });
});

describe('card()', function () {
    it('rebuilds the card of a waiting call from its arguments, now', function () {
        $card = ($this->tool)(ConfirmedDelete::class)->card('call_1', ['post' => $this->post->id, 'note' => 'CANARY-ARG']);

        expect($card?->title)->toBe('Delete this post? This cannot be undone.')
            ->and($card?->summary)->toBe([['label' => 'Post', 'value' => 'Launch notes'], ['label' => 'Status', 'value' => 'draft']])
            ->and(json_encode($card?->part('call_1'), JSON_THROW_ON_ERROR))->not->toContain('CANARY-ARG')
            ->and(app(PendingCards::class)->has('call_1'))->toBeFalse();
    });

    it('gives no card for a tool that cannot pause or a call the pipeline would refuse now', function () {
        $this->post->delete();

        expect(($this->tool)(ConfirmedDelete::class, pausing: false)->card('call_1', ['post' => 1]))->toBeNull()
            ->and(($this->tool)(CreateNote::class)->card('call_1', ['title' => 'x']))->toBeNull()
            ->and(($this->tool)(ConfirmedDelete::class)->card('call_1', ['post' => $this->post->id]))->toBeNull();
    });
});

it('labels its row with the package\'s lines for a Destructive or External action, in the context\'s locale', function () {
    $delete = ($this->tool)(ConfirmedDelete::class);
    $send = ($this->tool)(ConfirmedSend::class);
    $arabic = ($this->tool)(ConfirmedSend::class, context: ActionContext::agent($this->user, null, 'ar'));

    expect([$delete->activityLabel(false), $delete->activityLabel(true)])->toBe(['Removing…', 'Removed'])
        ->and([$send->activityLabel(false), $send->activityLabel(true)])->toBe(['Sending…', 'Sent'])
        ->and([$arabic->activityLabel(false), $arabic->activityLabel(true)])->toBe(['جارٍ الإرسال…', 'تم الإرسال'])
        ->and(($this->tool)(CreateNote::class, pausing: false)->activityLabel(true))->toBe(trans('agentic-actions::activity.write_done'))
        ->and(($this->tool)(ListNotes::class, pausing: false)->activityLabel(false))->toBe(trans('agentic-actions::activity.read_running'));
});
