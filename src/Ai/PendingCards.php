<?php

namespace AgenticActions\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Elicitation\Form;
use AgenticActions\Streaming\ActionsProtocol;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Throwable;

/**
 * The cards and forms previewed in this request, by tool-call id, until laravel/ai announces a real pause; then each
 * package call's claim is minted once and its card or form sent to the open stream. It also carries the person's
 * answer to a form from ChatRequest to the resume the same request runs. Scoped: one instance per request or job.
 *
 * @internal
 */
final class PendingCards
{
    /**
     * The most calls of one pause a person is asked to confirm; the rest get the refused row and no claim, so no turn
     * floods the cache store or the person with cards.
     */
    public const MAX_CARDS = 8;

    /**
     * The cards and forms previewed so far, with the agent each was previewed for.
     *
     * @var array<string, array{card: ApprovalCard|Form, agent: Agent}>
     */
    private array $cards = [];

    /**
     * The verified form and the person's accepted values, by tool-call id.
     *
     * @var array<string, array{form: Form, values: array<string, mixed>}>
     */
    private array $answers = [];

    /**
     * Remember the card or form previewed for a call of this agent; a later preview of the same call replaces it.
     */
    public function put(string $toolCallId, ApprovalCard|Form $card, Agent $agent): void
    {
        $this->cards[$toolCallId] = ['card' => $card, 'agent' => $agent];
    }

    /**
     * Hold the form ChatRequest verified for a call (rebuilt from the stored arguments, its claim holding) and the
     * person's accepted values, for the resume this request runs. ChatRequest only.
     *
     * @param  array<string, mixed>  $values
     */
    public function answer(string $toolCallId, Form $form, array $values): void
    {
        $this->answers[$toolCallId] = ['form' => $form, 'values' => $values];
    }

    /**
     * The verified form and the person's values for the call, once; null when this request read no answer for it.
     * Independent of what the resume's own preview gives: forget() leaves answers alone.
     *
     * @return array{form: Form, values: array<string, mixed>}|null
     */
    public function answered(string $toolCallId): ?array
    {
        $answer = $this->answers[$toolCallId] ?? null;

        unset($this->answers[$toolCallId]);

        return $answer;
    }

    /**
     * Forget a call whose preview gave no card or form.
     */
    public function forget(string $toolCallId): void
    {
        unset($this->cards[$toolCallId]);
    }

    /**
     * Whether this request or job previewed a card or form for the call: only then does ActionTool::handle() name it.
     */
    public function has(string $toolCallId): bool
    {
        return isset($this->cards[$toolCallId]);
    }

    /**
     * ToolApprovalRequested fires only when a run really pauses, after the turn is stored, and never while a resume
     * is checked. For each paused call this request previewed for the same agent instance and tool: mint its claim
     * when the conversation is known, its participant is the person the tools act for, and fewer than MAX_CARDS
     * calls of this pause have one, then send the card or form, or the refused row when nothing was minted. Never
     * throws: a failure is reported.
     *
     * @upstream The package mints each claim once, when laravel/ai reports a pause.
     */
    public function requested(ToolApprovalRequested $event): void
    {
        $asked = 0;

        foreach ($event->pendingApprovals as $approval) {
            $held = $this->cards[$approval->id] ?? null;

            if ($held === null || $held['agent'] !== $event->agent || $approval->tool !== $held['card']->entry->name) {
                // Not this agent's package call: never a card or a claim for it.
                continue;
            }

            unset($this->cards[$approval->id]);
            $card = $held['card'];
            $minted = false;

            try {
                $minted = $asked < self::MAX_CARDS
                    && $event->conversationId !== null
                    && self::isActor($event->conversationUser, $card->context)
                    && app(ApprovalClaims::class)->mint($event->conversationId, $approval->id, $card);
            } catch (Throwable $exception) {
                self::reportSafely($exception);
            }

            $asked += $minted ? 1 : 0;

            try {
                ActionsProtocol::current($event->invocationId)?->relay($minted ? $card->part($approval->id) : $card->refusedRow($approval->id));
            } catch (Throwable $exception) {
                self::reportSafely($exception);
            }
        }
    }

    /**
     * Whether the conversation's participant is the context's actor, compared as actorKey() spells it.
     */
    private static function isActor(?object $participant, ActionContext $context): bool
    {
        if (! $participant instanceof Authenticatable || $context->actor === null) {
            return false;
        }

        $type = $participant instanceof Model ? $participant->getMorphClass() : $participant::class;

        return $type.':'.$participant->getAuthIdentifier() === $context->actorKey();
    }

    /**
     * Report an exception. A reporter that throws is ignored: the pause is already stored, and the stream goes on.
     */
    private static function reportSafely(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Nothing more can be done for it here.
        }
    }
}
