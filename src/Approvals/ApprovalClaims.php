<?php

namespace AgenticActions\Approvals;

use AgenticActions\Elicitation\Form;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ValidatedInput;
use JsonException;
use Throwable;

/**
 * The single-use claim that lets one confirmed or answered call run once. Kept in the app's default cache store; no
 * table.
 *
 * A card's claim binds the action, the person, the tenant, the conversation, the call, the validated input and the card
 * the person was shown: a call whose card would read differently when it runs is refused, so the person confirms again.
 * A form's claim binds "form", the action, the person, the tenant, the conversation, the call and the model's arguments,
 * never the form's text, so neither kind can ever take the other's claim.
 * Single use holds on a store whose add() is atomic and shared by every server: database, redis, memcached and
 * dynamodb. The claim's marker has the claim's TTL and is always written later, so it outlives it; a won or burned
 * claim is forgotten, so a store that evicts the marker early never reopens it.
 *
 * Before any agent work, the one request that resumes a waiting turn, or goes on past it with new words, also reserves
 * it (reserve()), with a cache lock only that request can end, so an answer or words sent while it runs never go on with
 * the turn a second time, and the conversation keeps the result of the call that ran.
 *
 * @internal
 */
final class ApprovalClaims
{
    /**
     * The prefix of every claim key; a change of binding changes it.
     */
    public const PREFIX = 'agentic-actions:approval:v1:';

    /**
     * The prefix of every reservation of a waiting turn.
     */
    public const TURN_PREFIX = 'agentic-actions:approval-turn:v1:';

    /**
     * Reserve the turn waiting on these calls for the one request that resumes it: a lock with an owner of its own, or
     * null when another request holds it. release() ends it when the resume ends; a request that never ends it (a
     * worker that died) leaves it to lapse after approvals.ttl seconds, when every claim the turn waited on has lapsed
     * too. A deploy keeps its longest turn under that, so a confirmed call's result is stored first: a resume stores its
     * answered calls before the model continues, and from then on the turn no longer waits on them. Never throws: a
     * failure is reported and reads as held.
     *
     * @param  list<string>  $toolCallIds
     */
    public function reserve(string $conversationId, array $toolCallIds): ?Lock
    {
        sort($toolCallIds, SORT_STRING);

        try {
            $lock = Cache::lock(self::TURN_PREFIX.hash('sha256', implode("\n", [$conversationId, ...$toolCallIds])), $this->ttl());

            return $lock->get() ? $lock : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * End a reservation reserve() returned, only while its request still owns it, so a request whose reservation lapsed
     * never ends another's. Never throws: a failure is reported, and the reservation lapses.
     */
    public function release(Lock $reservation): void
    {
        try {
            $reservation->release();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * At a real pause only. False when the call's claim was already spent, or when the call already has one: that
     * claim is then spent too, so a repeated tool-call id in one conversation fails closed and an earlier call's claim
     * can never serve a later call.
     */
    public function mint(string $conversationId, string $toolCallId, ApprovalCard|Form $call): bool
    {
        $key = $this->key($conversationId, $toolCallId);

        if (Cache::has($key.':claimed')) {
            return false;
        }

        if (Cache::add($key, $this->binding($conversationId, $toolCallId, $call), $this->ttl())) {
            return true;
        }

        $this->burn($conversationId, $toolCallId);

        return false;
    }

    /**
     * Runner step 8, after both authorize steps and before handle(), with the card rebuilt from the input that will
     * run. True once: for the call the ticket names, the person and tenant it was minted for, the validated input the
     * card described, and a card that reads as the one the person was shown. Never throws.
     */
    public function claim(ApprovalCard $card): bool
    {
        $ticket = $card->context->approval;

        return $ticket !== null && $ticket->names() && $this->take($ticket->conversationId, $ticket->toolCallId, $card);
    }

    /**
     * Step 8: true once for this call, while holds() says its claim waits for this card or form: the marker's atomic
     * add(), then the claim forgotten. Never throws: a failure is reported and reads as false.
     */
    public function take(string $conversationId, string $toolCallId, ApprovalCard|Form $call): bool
    {
        if (! $this->holds($conversationId, $toolCallId, $call)) {
            return false;
        }

        try {
            $key = $this->key($conversationId, $toolCallId);

            // add() is atomic on the database, redis, memcached and dynamodb stores.
            if (! Cache::add($key.':claimed', true, $this->ttl())) {
                return false;
            }

            // Once won, losing the marker can never reopen the claim.
            Cache::forget($key);

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Whether the call's claim still waits, minted for this card (its action, person, tenant, validated input and text)
     * or this form (its action, person, tenant and the model's arguments). It takes nothing. Never throws: a failure is
     * reported and reads as false.
     */
    public function holds(string $conversationId, string $toolCallId, ApprovalCard|Form $call): bool
    {
        try {
            $minted = Cache::get($this->key($conversationId, $toolCallId));

            return is_string($minted) && hash_equals($minted, $this->binding($conversationId, $toolCallId, $call));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Spend a call's claim without running it: a declined call, or a key minted twice. Writes the marker, then forgets
     * the claim. Never throws: a failure is reported.
     */
    public function burn(string $conversationId, string $toolCallId): void
    {
        try {
            $key = $this->key($conversationId, $toolCallId);

            Cache::put($key.':claimed', true, $this->ttl());
            Cache::forget($key);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * SHA-256 of the validated input as JSON, keys sorted at every depth, lists in their order; for a form, of a model's
     * arguments as an array.
     *
     * @param  ValidatedInput|array<array-key, mixed>  $input
     *
     * @throws JsonException when the input cannot be encoded
     */
    public static function fingerprint(ValidatedInput|array $input): string
    {
        $input = $input instanceof ValidatedInput ? $input->all() : $input;

        return hash('sha256', json_encode(self::sorted($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The value with every map's keys sorted, at every depth; a list keeps its order. A number JSON cannot hold
     * (infinity, NaN) becomes a one-key map, so the hash never throws; validation refuses such a call anyway.
     */
    private static function sorted(mixed $value): mixed
    {
        if (is_float($value) && ! is_finite($value)) {
            return ["\0" => is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')];
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }

    /**
     * SHA-256 of what the card or form binds for this call.
     */
    private function binding(string $conversationId, string $toolCallId, ApprovalCard|Form $call): string
    {
        return hash('sha256', $call->binding($conversationId, $toolCallId));
    }

    /**
     * PREFIX + sha256(conversation id + "\n" + tool-call id).
     */
    private function key(string $conversationId, string $toolCallId): string
    {
        return self::PREFIX.hash('sha256', $conversationId."\n".$toolCallId);
    }

    /**
     * approvals.ttl, at least 1.
     */
    private function ttl(): int
    {
        $ttl = config('agentic-actions.approvals.ttl', 1800);

        return max(1, is_numeric($ttl) ? (int) $ttl : 1800);
    }
}
