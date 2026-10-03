<?php

namespace AgenticActions\Ai;

use AgenticActions\ActionContext;
use AgenticActions\ActionsManager;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Contracts\DescribesActivity;
use AgenticActions\Elicitation\Form;
use AgenticActions\Exposure\Entry;
use AgenticActions\Outcome;
use AgenticActions\Runner;
use AgenticActions\Schema\AdvertisedSchema;
use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\Activity;
use AgenticActions\Streaming\AgenticView;
use AgenticActions\Views\RefreshView;
use AgenticActions\Views\Table;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Traits\Localizable;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;
use Throwable;

/**
 * One discovered action as a laravel/ai tool, built per turn for one actor. It never throws: every outcome becomes an
 * authored sentence. The action alone decides whether a call waits for a person: a Destructive or External call always
 * waits for a confirmation, a call to a Read or Write action with $askForMissing waits for the person's answer when
 * it leaves fields out, and any other call never waits.
 *
 * @internal
 */
final class ActionTool implements Approvable, DescribesActivity, Tool
{
    use Localizable;

    /**
     * Create the tool.
     *
     * @param  list<string>  $toolsets  the calling agent's toolsets
     * @param  Agent|null  $agent  the agent the tool was built for; set only for a Destructive or External action, an
     *                             asking one or one that shows a table, on an agent that ConfirmingAgents::supports().
     *                             Its conversation is read when a call runs.
     */
    public function __construct(
        private readonly Entry $entry,
        private readonly ActionContext $context,
        private readonly array $toolsets,
        private readonly ?Agent $agent = null,
    ) {}

    /**
     * The action's name, which laravel/ai uses as the tool name.
     */
    public function name(): string
    {
        return $this->entry->name;
    }

    /**
     * The action's description; for a tool that can ask (built with the agent), model.asks after it, so the model calls
     * the tool with what it knows instead of inventing the rest or asking in words.
     */
    public function description(): string
    {
        return $this->entry->asks && $this->agent !== null
            ? $this->entry->description.' '.(string) trans('agentic-actions::model.asks', [], $this->context->locale)
            : $this->entry->description;
    }

    /**
     * agentSchema() or schema(), minus the context's fixed keys.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return app(AdvertisedSchema::class)->types($this->entry->action(), $schema, $this->context);
    }

    /**
     * The action's own label, or the package's for its effect, in the context's locale, from the instance the
     * container resolves.
     */
    public function activityLabel(bool $finished): string
    {
        return $this->withLocale($this->context->locale, fn (): string => $this->entry->action()->activityLabel($this->context, $finished)
            ?? (string) trans('agentic-actions::activity.'.($this->entry->effect->value ?? 'write').($finished ? '_done' : '_running')));
    }

    /**
     * A Destructive or External tool always asks, with its action's own sentence, so the reason given here is ignored.
     *
     * @throws LogicException for a Read or Write tool, which never pauses
     */
    public function requireApproval(?string $reason = null): static
    {
        if (! $this->gated()) {
            throw new LogicException("[{$this->entry->name}] is {$this->effect()} action: it never waits for a person; only Destructive and External actions ask for a confirmation.");
        }

        return $this;
    }

    /**
     * A Read or Write tool never asks, so this changes nothing for one.
     *
     * @throws LogicException for a Destructive or External tool: an agent cannot switch its confirmation off
     */
    public function withoutApproval(): static
    {
        if ($this->gated()) {
            throw new LogicException("[{$this->entry->name}] is {$this->effect()} action: a person confirms each call, and an agent cannot switch that off.");
        }

        return $this;
    }

    /**
     * Asked at the pause and again while a resume is checked: previews what the call waits on, a card or a form, and
     * remembers it; never takes or mints a claim. Null for a call that runs at once or is refused (the model then hears
     * the real answer), a tool that cannot pause, and a call without an id.
     *
     * @upstream The package mints a claim only when a run really pauses.
     */
    public function shouldRequestApproval(Request $request): ?Approval
    {
        $toolCallId = $request->toolCallId();

        if ((! $this->gated() && ! $this->entry->asks) || $this->agent === null || $toolCallId === null || blank($toolCallId)) {
            return null;
        }

        $paused = $this->card($toolCallId, $request->all());

        if ($paused === null) {
            // It runs at once, and its ticket names no call.
            app(PendingCards::class)->forget($toolCallId);

            return null;
        }

        app(PendingCards::class)->put($toolCallId, $paused, $this->agent);

        // Authored and input-free; the protocol never forwards it.
        return Approval::required($paused instanceof Form ? $paused->message : $paused->title);
    }

    /**
     * Run the action through the agent door and report its outcome for the row. The door gets a ticket when the tool
     * can pause; the ticket names this call only when this request or job asked shouldRequestApproval() for it and got
     * an Approval, or read the person's answer to its form, so any other call is refused at step 8. When this request
     * read that answer (PendingCards::answered()), the action runs with the person's values over the model's arguments,
     * on a ticket that carries the form the answer request verified, whatever this request's own preview gives now,
     * and the model reads which fields they filled, never their values; arguments that are not the ones that form was
     * built from run nothing. Never throws: every throwable becomes an authored sentence, on every path.
     *
     * @upstream The package names a call on a ticket only when this request asked for its confirmation.
     * @upstream The package runs a form's answer from the request it was read in, never from the conversation store.
     */
    public function handle(Request $request): string
    {
        try {
            $toolCallId = $request->toolCallId();
            $known = $toolCallId !== null && ! blank($toolCallId);
            $answer = $known ? app(PendingCards::class)->answered($toolCallId) : null;

            if ($answer !== null && ApprovalClaims::fingerprint($request->all()) !== $answer['form']->fingerprint) {
                Activity::record($request, ok: false);

                return (string) trans('agentic-actions::model.not_confirmed', [], $this->context->locale);
            }

            $named = $answer !== null || ($known && app(PendingCards::class)->has($toolCallId));
            $arguments = $answer === null ? $request->all() : $answer['form']->merge($request->all(), $answer['values']);

            $outcome = app(ActionsManager::class)
                ->call($this->toolsets, $this->entry->name, $arguments, $this->ticketed($named ? $toolCallId : null, $answer['form'] ?? null), $toolCallId);

            $view = $this->view($request, $outcome);

            Activity::outcome($request, $outcome, $view);

            $sentence = $outcome->forModel($view !== null);

            return $answer === null ? $sentence : $answer['form']->filled($answer['values']).' '.$sentence;
        } catch (Throwable $exception) {
            Activity::record($request, ok: false, crashed: true);

            return $this->failed($exception);
        }
    }

    /**
     * What a call of this tool waits on, rebuilt now from its arguments: a card, or for an asking action a form. Null
     * when this tool cannot pause, or the call would run at once or be refused now.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function card(string $toolCallId, array $arguments): ApprovalCard|Form|null
    {
        if ((! $this->gated() && ! $this->entry->asks) || $this->agent === null) {
            return null;
        }

        return app(Runner::class)->preview($this->entry, $arguments, $this->ticketed($toolCallId), $this->toolsets);
    }

    /**
     * The entry this tool was built from.
     */
    public function entry(): Entry
    {
        return $this->entry;
    }

    /**
     * The actor, tenant and locale this tool acts for.
     */
    public function context(): ActionContext
    {
        return $this->context;
    }

    /**
     * Whether the effect needs a person's confirmation.
     */
    private function gated(): bool
    {
        return $this->entry->effect?->isModelSafe() === false;
    }

    /**
     * The effect as the exceptions name it, with its article: "a destructive", "an external".
     */
    private function effect(): string
    {
        $effect = $this->entry->effect->value ?? 'undeclared';

        return (in_array($effect[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ').$effect;
    }

    /**
     * The context carrying a ticket for this call, and the answered form, when the tool can pause: a Destructive,
     * External or asking tool built with its agent. A form always rides a ticket, so it runs only on its claim; any
     * other call, such as a table's, carries none.
     */
    private function ticketed(?string $toolCallId, ?Form $form = null): ActionContext
    {
        return $form === null && ($this->agent === null || (! $this->gated() && ! $this->entry->asks))
            ? $this->context
            : $this->context->withApproval(new ApprovalTicket(ConfirmingAgents::conversation($this->agent), $toolCallId, $form));
    }

    /**
     * The data-view part of the table this call shows, once its snapshot is kept; null unless the call succeeded for an
     * action that shows a table, its row is open in a streamed turn that has shown fewer than ten, and it has a
     * tool-call id. The snapshot needs the tool built with its agent, an actor and a conversation id, and gives the part
     * its ref and time. A failure here is reported and shows no table.
     *
     * @return array<string, mixed>|null
     */
    private function view(Request $request, Outcome $outcome): ?array
    {
        $protocol = ActionsProtocol::current();
        $invocation = $request->toolInvocationId();
        $toolCallId = $request->toolCallId();

        if (! $outcome->ok() || ! $outcome->entry()->shows() || $protocol === null || $invocation === null
            || ! $protocol->open($invocation) || $protocol->full() || $toolCallId === null || blank($toolCallId)) {
            return null;
        }

        return rescue(function () use ($protocol, $outcome, $toolCallId): array {
            $conversation = $this->agent === null ? null : (ConfirmingAgents::conversation($this->agent) ?? $protocol->conversationId());
            $view = $conversation === null ? null : AgenticView::record($outcome, $conversation, $toolCallId);

            $ref = $view !== null && RefreshView::allowed($outcome->entry(), $outcome->fixed()) ? $view->id : null;

            return Table::part($outcome->entry(), $outcome->output() ?? [], $outcome->context(), $toolCallId, ($view->created_at ?? now())->format(DATE_ATOM), $ref);
        });
    }

    /**
     * Report a throwable once and answer with the failure sentence, even when reporting or translating fails too.
     */
    private function failed(Throwable $exception): string
    {
        try {
            report($exception);
        } catch (Throwable) {
            // A reporter that throws must not reach the model loop.
        }

        try {
            $line = trans('agentic-actions::model.failed', [], $this->context->locale);
        } catch (Throwable) {
            $line = null;
        }

        return is_string($line) ? $line : 'agentic-actions::model.failed';
    }
}
