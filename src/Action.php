<?php

namespace AgenticActions;

use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Http\ActionRequest;
use AgenticActions\Queue\RunAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * One operation, written once, served on every surface its #[Expose] and effect allow.
 *
 * An action declares two more methods, so that each one types its own dependencies (they are called through the
 * container): authorize(ActionContext $context[, ValidatedInput $input], ...$services): bool|Response, which runs
 * before any input is read, or after validation when it takes ValidatedInput (a missing authorize() is denied
 * everywhere); and handle(ActionContext $context, ValidatedInput $input, ...$services): mixed. A Read that lists tables
 * in $initializes also declares initialize(ActionContext $context[, ValidatedInput $input], ...$services): void.
 *
 * Properties are read as declared defaults through reflection, so an action never changes them in a constructor.
 *
 * @api
 */
abstract class Action
{
    /**
     * The tool, CLI and TypeScript name. Empty means the class basename with one trailing "Action" removed, in kebab
     * case with a run of capitals kept as one word: ImportCSVFile is import-csv-file. A word with a capital inside
     * still splits (SyncOAuthToken is sync-o-auth-token): set this, or write the class SyncOauthToken. The generated
     * route segment is Str::kebab() of it. Pin it once an agent has used it.
     */
    protected string $name = '';

    /**
     * What a model reads before calling the action. Required on agent surfaces.
     */
    protected string $description = '';

    /**
     * Read, Write, Destructive or External. Null exposes nothing remote and fails actions:check.
     */
    protected ?Effect $effect = null;

    /**
     * A hint only (MCP's idempotentHint). What a replay means stays the action's business.
     */
    protected bool $idempotent = false;

    /**
     * Neutral invalidation keys a success makes stale. The Inertia adapter reads them as prop keys and cache tags.
     *
     * @var list<string>
     */
    protected array $touches = [];

    /**
     * Ignored while config('agentic-actions.tenant.model') is null.
     */
    protected bool $tenantScoped = true;

    /**
     * Send validation messages, not only keys, to a model when a withMessages() failure is raised inside the action.
     */
    protected bool $validationMessagesToModel = false;

    /**
     * Allow a model-driven call with no actor (a signed-out support bot). Generated routes still need a user.
     */
    protected bool $guests = false;

    /**
     * The error bag browser visits receive. A #[\Illuminate\Foundation\Http\Attributes\ErrorBag] attribute on the class
     * wins (Laravel 13).
     */
    protected string $errorBag = 'default';

    /**
     * Follow the link after an agent's successful call: the open page visits redirectTo()'s URL when it is on this
     * site. Only for a redirectTo() built from the saved record, never one that points at an endpoint that redirects.
     * Read as a declared default, like every property here.
     */
    protected bool $followLink = false;

    /**
     * Ask the person, in a form, for the fields a model's call leaves missing or invalid, instead of refusing the call.
     * Only fields a form can hold are asked (text, numbers, dates, yes or no, choices); a call that also leaves out
     * anything else is refused as before. In the copilot, on agents whose conversations are stored. A Read or Write action
     * only: a Destructive or External action never asks, and its complete call is confirmed on the card. Read as a
     * declared default.
     */
    protected bool $askForMissing = false;

    /**
     * The tables a Read's initialize() may add rows to, for state that should exist once someone reads it. The
     * pipeline calls initialize() after every check, right before handle(), and never while a tool list, a preview or
     * a form is built. Inside it the Read guard allows an INSERT into these tables only: an update, delete, replace or
     * upsert, or a write to any other table, is refused as it is in handle(), which stays fully guarded. Whoever may
     * run the Read may cause the insert, a read-only token included. Empty, the default, calls no initialize(); on an
     * action that is not a Read, or on a dataset, it is ignored and fails actions:check. Read as a declared default.
     *
     * @var list<string>
     */
    protected array $initializes = [];

    /**
     * The canonical input: HTTP, JSON API, CLI and TypeScript. Pure and cheap. Defaults are advertised, never merged.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * The output allowlist, applied at every depth. Undeclared keys never leave the server.
     *
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Server-only rules, appended per key and never advertised: closures, dynamic bounds, scoped exists.
     *
     * @return array<string, mixed>
     */
    public function rules(ActionContext $context): array
    {
        return [];
    }

    /**
     * Normalise raw input before validation, after exposure, membership and an input-free authorize().
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function prepareForValidation(array $input, ActionContext $context): array
    {
        return $input;
    }

    /**
     * What an agent sees when its vocabulary differs from the canonical input. Null means schema().
     *
     * @return array<string, Type>|null
     */
    public function agentSchema(JsonSchema $schema): ?array
    {
        return null;
    }

    /**
     * Turn validated agentSchema() input into canonical input, which is then validated again.
     *
     * @return array<string, mixed>
     */
    public function fromAgent(ValidatedInput $input, ActionContext $context): array
    {
        return $input->all();
    }

    /**
     * Fields agents are offered that a model's call must give a value, over the agent door and MCP alike, where the
     * route, the CLI and the app's own code may leave them out or null: agents are offered them as required and not
     * nullable, and a model's call without one is refused naming it, or asks the person with $askForMissing. Fields of
     * agentSchema() when the class overrides it, else of schema().
     *
     * @return list<string>
     */
    public function requiredForAgents(): array
    {
        return [];
    }

    /**
     * Exposure, not authorization: false reads exactly like an unknown action. Side-effect free.
     */
    public function shouldRegister(ActionContext $context): bool
    {
        return true;
    }

    /**
     * The sentence a model reads on success. Null means "Done." for writes and "Found." above the output for reads.
     */
    public function modelReply(mixed $result, ActionContext $context): ?string
    {
        return null;
    }

    /**
     * Where a browser visit lands after success. Null redirects back.
     */
    public function redirectTo(mixed $result, ActionContext $context): ?string
    {
        return null;
    }

    /**
     * The copilot row's label while the tool runs ($finished false) and after it succeeded ($finished true). Null means
     * the package's label for the action's effect. It takes no arguments and is read, with the context's locale set,
     * before its own call runs, on the instance the container resolves: return fixed sentences, and read no state an
     * earlier call left behind.
     */
    public function activityLabel(ActionContext $context, bool $finished): ?string
    {
        return null;
    }

    /**
     * The one sentence a person reads on the confirmation card of a Destructive or External agent call. It takes no
     * input: record values go in approvalSummary(). Null means the package's line for the effect. Read in the
     * context's locale.
     */
    public function approvalReason(ActionContext $context): ?string
    {
        return null;
    }

    /**
     * What the person confirms, as label => value rows, built from the validated input that will run and the record it
     * names. Called after both authorize steps allowed this input, inside the tenant scope, with database writes and
     * queued actions refused, and never after handle(). Find the record through $context->find(), as authorize() does.
     * An input value shown here is exactly what will run. At most 8 rows; null values are left out; every value is
     * shown as plain text. Empty gives a card with the sentence alone. An action offered any agent input must fill it
     * (actions:check, Summary).
     *
     * @return array<string, string|int|float|null>
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        return [];
    }

    /**
     * What the person confirms without reading it on the card: values the run depends on that approvalSummary() cannot
     * show, such as a message's whole body or every recipient. Same rules as approvalSummary(): built from the
     * validated input that will run and the record it names, after both authorize steps, inside the tenant scope,
     * with writes refused. Its fingerprint joins the claim, so a call whose bound values changed between the card and
     * the run is refused and the person confirms again. Strings, numbers, booleans, null and arrays of them; an
     * HtmlString and another Stringable are read as their text, and a backed enum as its value. Bind attributes, not models: a model, a
     * collection, a date or any other object, a Stringable one that is also Arrayable, Jsonable or JsonSerializable
     * included, gives no card, so the call is refused, and the mistake is reported. Empty binds nothing more.
     *
     * @return array<string, mixed>
     */
    public function approvalBinding(ActionContext $context, ValidatedInput $input): array
    {
        return [];
    }

    /**
     * What the form says beyond schema(): its sentence, the fields always shown for review, choices, defaults and
     * widgets; titles are the schema's own (title()). Called only when $askForMissing is true and a model's call needs a
     * form, in the context's locale, inside the Read guard, on a fresh instance that never saw the model's arguments:
     * read only what the actor and tenant may see (their own profile, their tenant's members). Return the Ask it was given.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        return $ask;
    }

    /**
     * Run the full pipeline in-process and return handle()'s result.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when the input is invalid
     * @throws Refusal when the action refuses, is not found for this context, or is denied
     * @throws MissingContext when the context lacks what the action needs
     */
    final public static function run(array $input, ActionContext $context): mixed
    {
        return app(Runner::class)->runInProcess(static::class, $input, $context);
    }

    /**
     * Queue the full pipeline to run in a worker, as the caller: its actor, tenant, fixed input, token grants and locale
     * go with the job, and every check runs again there. The run gets its own request id. Returns Laravel's
     * PendingDispatch, so onQueue(), delay() and afterCommit() chain as usual.
     *
     * @param  array<string, mixed>  $input  canonical input of scalars and arrays: the job is serialized
     *
     * @throws ReadActionWrote when a Read action's own code queues an action that is not a Read
     * @throws LogicException when the context has an actor that is not an Eloquent model
     */
    final public static function dispatch(array $input, ActionContext $context): PendingDispatch
    {
        return new PendingDispatch(RunAction::capture(static::class, $input, $context));
    }

    /**
     * Serve a route whose controller is this action.
     *
     * @internal
     */
    final public function __invoke(ActionRequest $request): Response
    {
        return $request->respond($this);
    }
}
