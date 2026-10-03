<?php

namespace AgenticActions;

use AgenticActions\Exposure\Entry;
use AgenticActions\Pipeline\ModelSentences;
use AgenticActions\Pipeline\OutcomeKind;
use Throwable;

/**
 * How one call ended, for every surface to render in its own way.
 *
 * @api
 */
final class Outcome
{
    /**
     * Create an outcome. Only the named constructors below build one.
     *
     * @param  array<string, mixed>|null  $output
     * @param  array<string, list<string>>  $errors
     * @param  array<string, list<string>>  $failedRules
     * @param  array<string, mixed>  $input
     */
    private function __construct(
        private readonly OutcomeKind $kind,
        private readonly Entry $entry,
        private readonly ActionContext $context,
        private readonly mixed $result = null,
        private readonly ?array $output = null,
        private readonly ?string $reply = null,
        private readonly ?Refusal $ownRefusal = null,
        private readonly array $errors = [],
        private readonly array $failedRules = [],
        private readonly ?Throwable $exception = null,
        private readonly array $input = [],
    ) {}

    /**
     * handle() ran and returned.
     *
     * @internal
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input  the canonical input as it entered prepareForValidation()
     */
    public static function completed(Entry $entry, ActionContext $context, mixed $result, array $output, ?string $modelReply, array $input = []): self
    {
        return new self(OutcomeKind::Ok, $entry, $context, result: $result, output: $output, reply: $modelReply, input: $input);
    }

    /**
     * The input was invalid.
     *
     * @internal
     *
     * @param  array<string, list<string>>  $errors
     * @param  array<string, list<string>>  $failedRules
     */
    public static function invalid(Entry $entry, ActionContext $context, array $errors, array $failedRules): self
    {
        return new self(OutcomeKind::Invalid, $entry, $context, errors: $errors, failedRules: $failedRules);
    }

    /**
     * The action is not available to this caller, which reads exactly like an unknown action.
     *
     * @internal
     */
    public static function notFound(Entry $entry, ActionContext $context): self
    {
        return new self(OutcomeKind::NotFound, $entry, $context);
    }

    /**
     * authorize() said no.
     *
     * @internal
     */
    public static function denied(Entry $entry, ActionContext $context): self
    {
        return new self(OutcomeKind::Denied, $entry, $context);
    }

    /**
     * The action refused.
     *
     * @internal
     */
    public static function refusedBy(Entry $entry, ActionContext $context, Refusal $refusal): self
    {
        return new self(OutcomeKind::Refused, $entry, $context, ownRefusal: $refusal);
    }

    /**
     * The action crashed.
     *
     * @internal
     */
    public static function failed(Entry $entry, ActionContext $context, Throwable $exception): self
    {
        return new self(OutcomeKind::Failed, $entry, $context, exception: $exception);
    }

    /**
     * Whether handle() ran and returned.
     */
    public function ok(): bool
    {
        return $this->kind === OutcomeKind::Ok;
    }

    /**
     * Whether the call did not succeed, for any reason.
     */
    public function refused(): bool
    {
        return $this->kind !== OutcomeKind::Ok;
    }

    /**
     * What HTTP would have answered: 200, 403, 404, 409 or the refusal's status, 422, 428, 500.
     */
    public function status(): int
    {
        return match ($this->kind) {
            OutcomeKind::Ok => 200,
            OutcomeKind::Invalid => 422,
            OutcomeKind::NotFound => 404,
            OutcomeKind::Denied => 403,
            OutcomeKind::Refused => $this->ownRefusal?->field() !== null ? 422 : ($this->ownRefusal?->statusCode() ?? 409),
            OutcomeKind::Failed => 500,
        };
    }

    /**
     * The projected output; null unless ok().
     *
     * @return array<string, mixed>|null
     */
    public function output(): ?array
    {
        return $this->kind === OutcomeKind::Ok ? ($this->output ?? []) : null;
    }

    /**
     * handle()'s raw value, for the host. Never sent anywhere by the package.
     */
    public function result(): mixed
    {
        return $this->result;
    }

    /**
     * A Refusal describing any non-ok outcome: the action's own, or one built from the package's fixed sentence.
     */
    public function refusal(): ?Refusal
    {
        return match ($this->kind) {
            OutcomeKind::Refused => $this->ownRefusal,
            OutcomeKind::Invalid => Refusal::make('agentic-actions::http.invalid')->status(422),
            OutcomeKind::NotFound => Refusal::make('agentic-actions::http.not_found')->status(404),
            OutcomeKind::Denied => Refusal::make('agentic-actions::http.denied')->status(403),
            OutcomeKind::Ok, OutcomeKind::Failed => null,
        };
    }

    /**
     * Validation messages by key (web and API only).
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The sentence the Agent surface sends: for a table the person sees ($shown), a compact copy of it.
     */
    public function forModel(bool $shown = false): string
    {
        return ModelSentences::for($this, $shown);
    }

    /**
     * How the call ended.
     *
     * @internal
     */
    public function kind(): OutcomeKind
    {
        return $this->kind;
    }

    /**
     * The live entry of the action that ran.
     *
     * @internal
     */
    public function entry(): Entry
    {
        return $this->entry;
    }

    /**
     * The context the action ran in.
     *
     * @internal
     */
    public function context(): ActionContext
    {
        return $this->context;
    }

    /**
     * The throwable behind a Failed outcome, for renderers that rethrow.
     *
     * @internal
     */
    public function exception(): ?Throwable
    {
        return $this->exception;
    }

    /**
     * Rule names by key, as a model reads them.
     *
     * @internal
     *
     * @return array<string, list<string>>
     */
    public function failedRules(): array
    {
        return $this->failedRules;
    }

    /**
     * The action's modelReply(), computed when the call succeeded.
     *
     * @internal
     */
    public function modelReply(): ?string
    {
        return $this->reply;
    }

    /**
     * The canonical input as it entered prepareForValidation(), after an agent's translation, so a call can run again
     * as it ran; empty unless the call succeeded.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return $this->input;
    }

    /**
     * The input the edge fixed for the call, the context's fixed keys.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function fixed(): array
    {
        return $this->context->fixed;
    }
}
