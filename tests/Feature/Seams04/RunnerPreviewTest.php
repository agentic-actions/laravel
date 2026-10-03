<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Elicitation\Form;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionFailed;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Runner;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ConfirmedSend;
use Tests\Fixtures\Approvals\DeleteByTitle;
use Tests\Fixtures\Approvals\NineRows;
use Tests\Fixtures\Approvals\UnsummarizedDelete;
use Tests\Fixtures\Approvals\WritingSummary;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * Runner::preview(): the card a person confirms, built on the server after admission and both authorize steps, from
 * the validated input that will run, inside the Read guard. It never runs handle(), fires no event and takes no claim;
 * any refusal gives no card, and a step that throws gives none and is reported.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    Trace::reset();

    $this->user = User::factory()->create();
    $this->post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
    $this->context = ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1'));
});

/**
 * Preview a call of a class for the approvals toolset.
 *
 * @param  class-string<Action>  $class
 * @param  array<string, mixed>  $arguments
 */
function seams04Preview(string $class, array $arguments, ActionContext $context): ApprovalCard|Form|null
{
    return app(Runner::class)->preview(ClassExposure::of($class), $arguments, $context, ['approvals']);
}

/**
 * Build a card for ConfirmedDelete's entry from an action whose summary is $rows.
 *
 * @param  array<string, mixed>  $rows
 */
function seams04Card(array $rows, ?string $reason = null): ApprovalCard
{
    $action = new class($rows, $reason) extends Action
    {
        /**
         * @param  array<string, mixed>  $rows
         */
        public function __construct(private readonly array $rows, private readonly ?string $reason) {}

        public function approvalReason(ActionContext $context): ?string
        {
            return $this->reason;
        }

        public function approvalSummary(ActionContext $context, ValidatedInput $input): array
        {
            return $this->rows;
        }
    };

    return ApprovalCard::build(ClassExposure::of(ConfirmedDelete::class), $action, ActionContext::agent(User::factory()->create()), new ValidatedInput(['post' => 1]));
}

describe('an allowed call', function () {
    it('builds the card from approvalReason() and approvalSummary(), and runs nothing', function () {
        Event::fake([ActionCompleted::class, ActionRefused::class, ActionFailed::class]);

        $card = seams04Preview(ConfirmedDelete::class, ['post' => $this->post->id], $this->context);

        expect($card)->toBeInstanceOf(ApprovalCard::class)
            ->and($card->entry->name)->toBe('confirmed-delete')
            ->and($card->title)->toBe('Delete this post? This cannot be undone.')
            ->and($card->summary)->toBe([['label' => 'Post', 'value' => 'Launch notes'], ['label' => 'Status', 'value' => 'draft']])
            ->and($card->fingerprint)->toBe(ApprovalClaims::fingerprint(new ValidatedInput(['post' => $this->post->id])))
            ->and($card->context->actor)->toBe($this->user)
            ->and($card->context->action)->toBe('confirmed-delete')
            ->and(Trace::$calls)->toBe(['ConfirmedDelete::authorize'])
            ->and(Post::query()->whereKey($this->post->id)->exists())->toBeTrue();

        Event::assertNothingDispatched();
    });

    it('reads the package\'s line for the effect, in the context\'s locale, when approvalReason() is null', function () {
        $arabic = ActionContext::agent($this->user, locale: 'ar')->withApproval(new ApprovalTicket(null, null));

        $destructive = seams04Preview(UnsummarizedDelete::class, ['post' => $this->post->id], $arabic);
        $external = seams04Preview(ConfirmedSend::class, ['to' => 'someone@example.com'], $this->context);

        expect($destructive->title)->toBe(trans('agentic-actions::approval.destructive', [], 'ar'))
            ->and($destructive->title)->not->toBe(trans('agentic-actions::approval.destructive', [], 'en'))
            ->and($destructive->summary)->toBe([])
            ->and($external->title)->toBe(trans('agentic-actions::approval.external', [], 'en'))
            ->and(app()->getLocale())->toBe('en');
    });

    it('shows exactly the input value its summary shows, and no other argument', function () {
        $card = seams04Preview(ConfirmedSend::class, ['to' => 'someone@example.com', 'note' => 'CANARY-NOTE'], $this->context);

        $wire = json_encode([$card->part('call_1'), $card->refusedRow('call_1')], JSON_UNESCAPED_UNICODE);

        expect($card->summary)->toBe([['label' => 'To', 'value' => 'someone@example.com']])
            ->and($wire)->not->toContain('CANARY-NOTE')
            ->and($card->fingerprint)->toBe(ApprovalClaims::fingerprint(new ValidatedInput(['to' => 'someone@example.com', 'note' => 'CANARY-NOTE'])));
    });

    it('prunes an argument the agent was never offered, so the fingerprint is the same without it', function () {
        $card = seams04Preview(ConfirmedDelete::class, ['post' => $this->post->id, 'CANARY-KEY' => 'CANARY-VALUE'], $this->context);

        expect($card->fingerprint)->toBe(seams04Preview(ConfirmedDelete::class, ['post' => $this->post->id], $this->context)->fingerprint)
            ->and(json_encode($card->part('call_1')))->not->toContain('CANARY');
    });

    it('translates agentSchema() input before the card is built', function () {
        $card = seams04Preview(DeleteByTitle::class, ['title' => 'Launch notes'], $this->context);

        expect($card->summary)->toBe([['label' => 'Post', 'value' => 'Launch notes']])
            ->and($card->fingerprint)->toBe(ApprovalClaims::fingerprint(new ValidatedInput(['post' => $this->post->id])));
    });

    it('drops null values, clips long ones to 200 characters and turns control characters into spaces', function () {
        $this->post->update(['title' => "  Launch\nnotes\t".str_repeat('x', 300), 'excerpt' => null]);

        $card = seams04Preview(ConfirmedDelete::class, ['post' => $this->post->id], $this->context);
        $value = $card->summary[0]['value'];

        expect(array_column($card->summary, 'label'))->toBe(['Post', 'Status'])
            ->and(mb_strlen($value))->toBe(ApprovalCard::MAX_TEXT)
            ->and($value)->toStartWith('Launch notes x');
    });

    it('builds the part and the refused row from the card, in the context\'s locale', function () {
        $card = seams04Preview(ConfirmedDelete::class, ['post' => $this->post->id], ActionContext::agent($this->user, locale: 'ar')->withApproval(new ApprovalTicket(null, null)));

        expect($card->part('call_7'))->toBe([
            'type' => 'data-approval',
            'id' => 'approval:call_7',
            'data' => [
                'action' => 'confirmed-delete',
                'effect' => 'destructive',
                'label' => trans('agentic-actions::approval.waiting', [], 'ar'),
                'title' => 'Delete this post? This cannot be undone.',
                'summary' => [['label' => 'Post', 'value' => 'Launch notes'], ['label' => 'Status', 'value' => 'draft']],
                'confirm' => trans('agentic-actions::approval.confirm', [], 'ar'),
                'decline' => trans('agentic-actions::approval.decline', [], 'ar'),
            ],
        ])->and($card->refusedRow('call_7'))->toBe([
            'type' => 'data-action',
            'id' => 'a:call_7',
            'data' => [
                'action' => 'confirmed-delete',
                'label' => 'Delete this post? This cannot be undone.',
                'status' => 'refused',
                'effect' => 'destructive',
                'note' => trans('agentic-actions::activity.refused', [], 'ar'),
            ],
        ]);
    });
});

describe('no card', function () {
    it('gives none for a call the pipeline would refuse, and reports nothing', function (Closure $arguments, Closure $context) {
        Exceptions::fake();

        expect(seams04Preview(ConfirmedDelete::class, $arguments->call($this), $context->call($this)))->toBeNull()
            ->and(Trace::$calls)->not->toContain('ConfirmedDelete::handle');

        Exceptions::assertNothingReported();
    })->with([
        'another author\'s post (denied)' => [fn (): array => ['post' => User::factory()->create()->posts()->create(['title' => 'Theirs', 'body' => 'x', 'status' => 'draft'])->id], fn (): ActionContext => $this->context],
        'no such post (not found)' => [fn (): array => ['post' => 999999], fn (): ActionContext => $this->context],
        'invalid input' => [fn (): array => ['post' => 'seven'], fn (): ActionContext => $this->context],
        'missing input' => [fn (): array => [], fn (): ActionContext => $this->context],
        'no ticket' => [fn (): array => ['post' => $this->post->id], fn (): ActionContext => ActionContext::agent($this->user)],
        'a guest' => [fn (): array => ['post' => $this->post->id], fn (): ActionContext => ActionContext::agent(null)->withApproval(new ApprovalTicket(null, null))],
    ]);

    it('gives none for another toolset', function () {
        expect(app(Runner::class)->preview(ClassExposure::of(ConfirmedDelete::class), ['post' => $this->post->id], $this->context, ['default']))->toBeNull();
    });

    it('gives none, and reports, when approvalSummary() writes, whatever reads.guard says', function (bool $guard) {
        config(['agentic-actions.reads.guard' => $guard]);
        Exceptions::fake();

        expect(seams04Preview(WritingSummary::class, ['post' => $this->post->id], $this->context))->toBeNull()
            ->and(Post::query()->where('title', 'WRITTEN-BY-SUMMARY')->exists())->toBeFalse();

        Exceptions::assertReported(ReadActionWrote::class);
    })->with(['reads.guard on' => [true], 'reads.guard off' => [false]]);

    it('gives none, and reports, when approvalSummary() returns more rows than a card shows', function () {
        Exceptions::fake();

        expect(seams04Preview(NineRows::class, [], $this->context))->toBeNull();

        Exceptions::assertReported(fn (LogicException $exception): bool => str_contains($exception->getMessage(), 'at most 8'));
    });
});

describe('ApprovalCard::build()', function () {
    it('casts numbers to text and keeps the rows in order', function () {
        expect(seams04Card(['Count' => 3, 'Share' => 0.5, 'Name' => 'x'])->summary)->toBe([
            ['label' => 'Count', 'value' => '3'],
            ['label' => 'Share', 'value' => '0.5'],
            ['label' => 'Name', 'value' => 'x'],
        ]);
    });

    it('keeps text valid UTF-8', function () {
        expect(seams04Card(['Row' => "a\xB1b"])->summary)->toBe([['label' => 'Row', 'value' => 'a?b']])
            ->and(json_encode(seams04Card(['Row' => "a\xB1b"])->part('call_1')))->toBeString();
    });

    it('cleans the title as it cleans the rows', function () {
        expect(seams04Card([], "  Go\u{0007} ahead?\n")->title)->toBe('Go  ahead?')
            ->and(seams04Card([], str_repeat('é', 300))->title)->toBe(str_repeat('é', ApprovalCard::MAX_TEXT));
    });

    it('keeps eight rows, and refuses a ninth even when a row is null', function () {
        $eight = array_combine(array_map(fn (int $row): string => "Row {$row}", range(1, 8)), range(1, 8));

        expect(seams04Card($eight)->summary)->toHaveCount(8)
            ->and(fn () => seams04Card([...$eight, 'Row 9' => null]))->toThrow(LogicException::class);
    });

    it('refuses a value that is not a string or a number', function (mixed $value) {
        expect(fn () => seams04Card(['Row' => $value]))->toThrow(LogicException::class, 'strings and numbers only');
    })->with([
        'an array' => [['a']],
        'an object' => [new stdClass],
        'a boolean' => [true],
    ]);
});
