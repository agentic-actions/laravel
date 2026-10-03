<?php

namespace AgenticActions\Mcp;

use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Effect;
use AgenticActions\Elicitation\Form;
use AgenticActions\Exposure\Entry;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use AgenticActions\Schema\AdvertisedSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * One listed action as an MCP tool, built per request for one caller. The list was filtered when the server booted,
 * and the Runner checks everything again on the call, so the tool itself gates nothing.
 *
 * @internal
 */
final class McpTool extends Tool
{
    /**
     * Create the tool from a listed entry, for this caller.
     *
     * @param  Form|null  $form  the form whose answer this call carries (filled()); null on every other call
     * @param  array<string, mixed>  $values  that answer's content(), which runs over the client's arguments
     */
    public function __construct(private readonly Entry $entry, private readonly ActionContext $context, private readonly ?Form $form = null, private readonly array $values = [])
    {
        $this->name = $entry->name;
        $this->title = Str::headline($entry->name);
        $this->description = $entry->description;
    }

    /**
     * What agents are offered: agentSchema() or schema(), minus the context's fixed keys.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return app(AdvertisedSchema::class)->types($this->entry->action(), $schema, $this->context);
    }

    /**
     * Hints for the client from the live effect; never the gate. A write may overwrite, so it says destructive.
     *
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        $read = $this->entry->effect === Effect::Read;

        return [
            'readOnlyHint' => $read,
            ...($read ? [] : ['destructiveHint' => true]),
            'idempotentHint' => $this->entry->idempotent,
            'openWorldHint' => false,
        ];
    }

    /**
     * The form a person fills for this call, or null when it runs or is refused as it is: Runner::preview() on the
     * MCP door, for an action with $askForMissing only.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function form(array $arguments): ?Form
    {
        $form = $this->entry->asks ? app(Runner::class)->preview($this->entry, $arguments, $this->context, [], Door::Mcp) : null;

        return $form instanceof Form ? $form : null;
    }

    /**
     * The same tool, run with the person's values over the client's arguments, on a ticket carrying the form (so no
     * validation message quoting a value reaches the model), and answering first which fields were filled.
     *
     * @param  array<string, mixed>  $values  $form->content() of the accepted answer
     */
    public function filled(Form $form, array $values): self
    {
        return new self($this->entry, $this->context, $form, $values);
    }

    /**
     * Run the action through the MCP door. A refusal is an error result carrying the agent sentence; never throws.
     */
    public function handle(Request $request): Response
    {
        try {
            $context = $this->form === null ? $this->context : $this->context->withApproval(new ApprovalTicket(null, null, $this->form));
            $arguments = $this->form?->merge($request->all(), $this->values) ?? $request->all();
            $outcome = app(Runner::class)->run($this->entry, $arguments, $context, Door::Mcp);
            $sentence = ($this->form === null ? '' : $this->form->filled($this->values).' ').$outcome->forModel();

            return $outcome->kind() === OutcomeKind::Ok ? Response::text($sentence) : Response::error($sentence);
        } catch (Throwable $exception) {
            report($exception);

            return Response::error((string) trans('agentic-actions::model.failed', [], $this->context->locale));
        }
    }
}
