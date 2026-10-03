<?php

namespace AgenticActions\Streaming;

use AgenticActions\ActionContext;
use AgenticActions\Ai\ConfirmingAgents;
use AgenticActions\Ai\PendingCards;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Elicitation\Form;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A chat request read as one new thing: the person's newest words, or their answers to the confirmations and forms
 * their conversation is waiting on. It reads the last message of "messages" and ignores the rest, and never turns
 * request data into history or into a tool's arguments: history and every paused call's arguments come from the
 * server's conversation store, and a form's values reach only the action.
 *
 * @api
 */
final class ChatRequest implements AgentInput
{
    /**
     * Create a chat request. Only from() builds one.
     *
     * @param  array<string, list<string>>|null  $invalid  the answers' errors, by field
     * @param  bool  $precognitive  a Precognition request: a check that never runs a turn
     */
    private function __construct(
        private readonly ?UserMessage $message,
        private readonly ?Decisions $decisions = null,
        private readonly ?string $messageId = null,
        private readonly bool $stale = false,
        private readonly ?Lock $reservation = null,
        private readonly ?array $invalid = null,
        private readonly bool $precognitive = false,
    ) {}

    /**
     * Read the last element of the body's "messages". A user message keeps its text parts, joined by new lines; a text
     * that is not UTF-8, or over agents.max_message_length, reads as empty. With the agent whose conversation the
     * server chose, an assistant message is read as answers, per tool-call id, approve or decline and an optional
     * reason and nothing else, for calls that conversation is still waiting on; only from a session, and only when the
     * conversation belongs to the request's user. Answers that match no waiting call read as stale. Answers that match,
     * and words for a conversation that waits on calls, reserve the waiting turn for this request, before any agent
     * work, until respond()'s turn ends (or the reservation lapses): answers or words read while another request holds
     * it read as stale, so an answer and new words never both go on with one turn. The claim of every waiting call it
     * does not approve, answered or not, is spent here, after the reservation. Any other body reads as empty.
     *
     * An answer to a form is its ElicitResult on the answered tool part: accept with content, decline or cancel; any
     * other answer to a form's call is a decline. An accept counts only for the form the call still waits on, rebuilt
     * now from the call's stored arguments, while its claim holds. Accepted content is trimmed to the form, then
     * checked by a dry run of the action's own pipeline on the merged input; any failure reads as invalid, before any
     * reservation, and spends nothing. A Precognition request stops after the checks and reserves nothing.
     *
     * @upstream The package builds every decision itself and never an edit.
     */
    public static function from(Request $request, ?Agent $agent = null): self
    {
        $messages = $request->input('messages');
        $last = is_array($messages) ? Arr::last($messages) : null;

        if (! is_array($last)) {
            return new self(null);
        }

        if (($last['role'] ?? null) === 'user') {
            $message = self::words($last);

            return match (true) {
                $message === null => new self(null),
                $request->isAttemptingPrecognition() => new self(null, precognitive: true),
                default => self::newTurn($message, $agent),
            };
        }

        return $agent !== null && ($last['role'] ?? null) === 'assistant' ? self::answers($request, $agent, $last) : new self(null);
    }

    /**
     * The person's newest words, or null.
     */
    public function message(): ?UserMessage
    {
        return $this->message;
    }

    /**
     * The answers as decisions: approve, or decline with the package's sentence; any waiting call left unanswered is
     * declined. Null when no answer matched a waiting call, never an empty Decisions.
     */
    public function decisions(): ?Decisions
    {
        return $this->decisions;
    }

    /**
     * The assistant message the answers continue, for new ActionsProtocol(messageId: ...); null for a new turn.
     */
    public function messageId(): ?string
    {
        return $this->messageId;
    }

    /**
     * Whether there is nothing to send to the agent: no words and no answers. A stale body is empty too.
     */
    public function isEmpty(): bool
    {
        return $this->message === null && $this->decisions === null;
    }

    /**
     * Run the turn, or answer with one sentence, as JSON whatever Accept says: 409 (stream.stale) for a stale body and
     * for answers found stale as the turn starts; 422 (stream.empty) for an empty one. A request that reserved the
     * waiting turn keeps it until the turn ends: a streamed turn until its stream ends or fails. 422 with ask.invalid
     * and the errors by field for an invalid answer; 204 with Precognition-Success for a Precognition request that
     * passed its checks. Neither runs the turn.
     *
     * @param  Closure(self): (Responsable|Response)  $turn
     *
     * @upstream The package answers a stale confirmation with its own sentence.
     */
    public function respond(Closure $turn): Responsable|Response
    {
        if ($this->stale) {
            return self::conflict();
        }

        $precognition = $this->precognitive ? ['Precognition' => 'true'] : [];

        if ($this->invalid !== null) {
            return response()->json(['message' => (string) trans('agentic-actions::ask.invalid'), 'errors' => $this->invalid], 422, $precognition);
        }

        if ($this->precognitive) {
            return response()->noContent(204, [...$precognition, 'Precognition-Success' => 'true']);
        }

        if ($this->isEmpty()) {
            return response()->json(['message' => (string) trans('agentic-actions::stream.empty', ['max' => config('agentic-actions.agents.max_message_length', 4000)])], 422);
        }

        try {
            $response = $turn($this);
        } catch (ApprovalMismatchException) {
            $response = self::conflict();
        } catch (Throwable $exception) {
            $this->release();

            throw $exception;
        }

        // A streamed turn runs while its response is sent, and laravel/ai stores it before these callbacks run.
        if ($response instanceof StreamableAgentResponse) {
            return $response->then($this->release(...))->catch($this->release(...));
        }

        $this->release();

        return $response;
    }

    /**
     * End this request's reservation of the waiting turn, if it holds one.
     */
    private function release(): void
    {
        if ($this->reservation !== null) {
            app(ApprovalClaims::class)->release($this->reservation);
        }
    }

    /**
     * The 409 answer for a confirmation that is no longer waiting, or a turn another request holds.
     */
    private static function conflict(): JsonResponse
    {
        return response()->json(['message' => (string) trans('agentic-actions::stream.stale')], 409);
    }

    /**
     * The person's words, reserving the turn their conversation is waiting on, if any, whether a session or a token sent
     * them: new words go on past the waiting calls. Stale when another request holds the turn.
     */
    private static function newTurn(UserMessage $message, ?Agent $agent): self
    {
        $conversationId = ConfirmingAgents::conversation($agent);
        $store = $conversationId === null || ! ConfirmingAgents::storeSupports() ? null : app(ConversationStore::class);

        if ($conversationId === null || ! $store instanceof ResolvesPendingApprovals || ($waiting = self::pending($store, $conversationId)) === []) {
            return new self($message);
        }

        $reservation = app(ApprovalClaims::class)->reserve($conversationId, array_keys($waiting));

        return $reservation === null ? new self(null, stale: true) : new self($message, reservation: $reservation);
    }

    /**
     * An assistant message read as answers for the calls the agent's conversation is still waiting on, stale when none
     * matches or another request has reserved the turn. Only a waiting id becomes a decision, the first answer for it
     * winning; the claim of every waiting call not approved is spent, so a decline can never later become a run. A
     * form's call is answered by its ElicitResult only, a card's call never by one; an accept whose answer the action's
     * own rules refuse makes the whole request invalid, and a Precognition request stops once every answer is checked.
     *
     * @upstream The package builds the id-keyed decision map itself, the wildcard included, so every decision keeps its id.
     *
     * @param  array<array-key, mixed>  $message
     */
    private static function answers(Request $request, Agent $agent, array $message): self
    {
        $conversationId = ConfirmingAgents::conversation($agent);
        $waiting = $conversationId === null ? [] : self::waiting($request, $agent, $conversationId);
        $tools = $waiting === [] ? [] : ConfirmingAgents::tools($agent);
        $decisions = $approved = $accepted = [];
        $errors = null;
        $line = fn (string $key, array $replace = []): string => (string) trans('agentic-actions::model.'.$key, $replace);

        foreach (self::read($message) as [$id, $approve, $reason, $answer]) {
            $pending = $waiting[$id] ?? null;
            $tool = $pending === null ? null : ($tools[$pending->tool] ?? null);
            $asks = $tool !== null && $tool->entry()->asks;

            // Each kind answers only what it was shown: a form's result never answers a card's call.
            if ($pending === null || isset($decisions[$id]) || ($answer !== null && ! $asks)) {
                continue;
            }

            if (! $asks) {
                $approved = $approve ? [...$approved, $id] : $approved;

                // The person's reason reaches the model as one quoted string inside the package's sentence.
                $decisions[$id] = $approve ? Decision::approve() : Decision::reject($reason === null
                    ? $line('declined')
                    : $line('declined_because', ['reason' => json_encode($reason, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]));
            } elseif ($answer === null || $answer['action'] === 'cancel') {
                // A bare approval never runs the model's arguments: the person answered no form.
                $decisions[$id] = Decision::reject($line($answer === null ? 'declined' : 'form_cancelled'));
            } elseif ($answer['action'] === 'decline') {
                $form = $tool->card($id, $pending->arguments);
                $decisions[$id] = Decision::reject($form instanceof Form ? $line('form_declined', ['fields' => implode(', ', $form->fields)]) : $line('declined'));
            } elseif (($form = $tool->card($id, $pending->arguments)) instanceof Form && app(ApprovalClaims::class)->holds((string) $conversationId, $id, $form)) {
                $values = $form->content($answer['content']);

                // The dry run: the action's own pipeline on the merged input, which previews a form while it still refuses.
                if (($dry = $tool->card($id, $form->merge($pending->arguments, $values))) instanceof Form) {
                    $errors = array_merge_recursive($errors ?? [], $dry->errors);
                } else {
                    $decisions[$id] = Decision::approve();
                    $approved[] = $id;
                    $accepted[$id] = [$form, $values];
                }
            }
        }

        $precognitive = $request->isAttemptingPrecognition();

        // An answer the action's rules refuse, or a check, reserves and spends nothing.
        if ($errors !== null || ($precognitive && $decisions !== [])) {
            return new self(null, invalid: $errors, precognitive: $precognitive);
        }

        // One request resumes a waiting turn: the same answers sent again while it runs change and resume nothing.
        if ($conversationId === null || $decisions === [] || ($reservation = app(ApprovalClaims::class)->reserve($conversationId, array_keys($waiting))) === null) {
            return new self(null, stale: true);
        }

        foreach (array_diff(array_keys($waiting), $approved) as $id) {
            app(ApprovalClaims::class)->burn($conversationId, (string) $id);
        }

        // The resume this request runs takes each verified form and its values; step 8 takes the form's claim.
        foreach ($accepted as $id => [$form, $values]) {
            app(PendingCards::class)->answer((string) $id, $form, $values);
        }

        // A waiting call the body did not answer is declined, in the same id-keyed map.
        $decisions['*'] = Decision::reject($line('declined'));
        $id = $message['id'] ?? null;

        return new self(null, Decisions::from($decisions), is_string($id) && $id !== '' && strlen($id) <= 255 ? $id : null, reservation: $reservation);
    }

    /**
     * The calls the conversation is still waiting on, by id, when the agent can pause and the request is a session of
     * the person the conversation belongs to; none otherwise.
     *
     * @return array<string, PendingApproval>
     */
    private static function waiting(Request $request, Agent $agent, string $conversationId): array
    {
        if (! ConfirmingAgents::supports($agent)) {
            return [];
        }

        $context = ActionContext::fromRequest($request);
        $user = $context->actor;
        $store = app(ConversationStore::class);

        if ($user === null || $context->guard === null
            || app(ReadsTokenGrants::class)->grants($user, Auth::guard($context->guard)) !== null   // sessions only; a token never answers
            || ! $store instanceof ResolvesPendingApprovals || ! $store instanceof VerifiesConversationOwnership
            || ! $store->conversationBelongsTo($conversationId, Conversation::participantType($user), Conversation::participantKey($user))) {
            return [];
        }

        return self::pending($store, $conversationId);
    }

    /**
     * The calls the conversation is waiting on, by id, as the store reads them.
     *
     * @return array<string, PendingApproval>
     */
    private static function pending(ResolvesPendingApprovals $store, string $conversationId): array
    {
        return array_column($store->pendingApprovalsFor($conversationId), null, 'id');
    }

    /**
     * The answers in a message, in order: for each tool part in approval-responded, its approval id, a JSON boolean
     * approved, an optional reason and its form answer (elicitation()). Nothing else of a part is read, so an input,
     * an output or a string never approves.
     *
     * @param  array<array-key, mixed>  $message
     * @return list<array{0: string, 1: bool, 2: ?string, 3: array{action: string, content: array<array-key, mixed>}|null}>
     */
    private static function read(array $message): array
    {
        $answers = [];

        foreach (is_array($message['parts'] ?? null) ? $message['parts'] : [] as $part) {
            if (! is_array($part) || ! is_string($type = $part['type'] ?? null) || ($part['state'] ?? null) !== 'approval-responded'
                || (! str_starts_with($type, 'tool-') && $type !== 'dynamic-tool') || ! is_array($approval = $part['approval'] ?? null)) {
                continue;
            }

            $id = $approval['id'] ?? null;
            $approve = $approval['approved'] ?? null;
            $reason = $approval['reason'] ?? null;

            // The first part per call is its answer: a later one never replaces it, nor rebuilds its form again.
            if (is_string($id) && $id !== '' && $id !== '*' && strlen($id) <= 255 && is_bool($approve)) {
                $answers[$id] ??= [$id, $approve, is_string($reason) ? self::text($reason) : null, self::elicitation($part['elicitation'] ?? null, $approve)];
            }
        }

        return array_values($answers);
    }

    /**
     * A part's elicitation read as MCP's ElicitResult: an action of accept, decline or cancel that agrees with approved
     * (true exactly for accept), and for accept its content, a JSON object (absent reads as none); null otherwise.
     *
     * @return array{action: string, content: array<array-key, mixed>}|null
     */
    private static function elicitation(mixed $result, bool $approve): ?array
    {
        $action = is_array($result) ? ($result['action'] ?? null) : null;
        $content = $action === 'accept' ? ($result['content'] ?? []) : [];

        return in_array($action, ['accept', 'decline', 'cancel'], true) && ($action === 'accept') === $approve
            && is_array($content) && ($content === [] || ! array_is_list($content))
            ? ['action' => $action, 'content' => $content]
            : null;
    }

    /**
     * A user message's text parts, joined, or null when empty, not UTF-8 (a form post can carry any bytes), or too
     * long.
     *
     * @param  array<array-key, mixed>  $message
     */
    private static function words(array $message): ?UserMessage
    {
        $texts = [];

        foreach (is_array($message['parts'] ?? null) ? $message['parts'] : [] as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }

        $text = self::text(implode("\n", $texts));

        return $text === null ? null : new UserMessage($text);
    }

    /**
     * The person's words trimmed, or null when empty, not UTF-8, or over agents.max_message_length.
     */
    private static function text(string $text): ?string
    {
        $text = trim($text);

        return $text === '' || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > (int) config('agentic-actions.agents.max_message_length', 4000)
            ? null
            : $text;
    }
}
