<?php

namespace AgenticActions;

use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Contracts\InterceptsActions;
use AgenticActions\Contracts\Tenancy;
use AgenticActions\Elicitation\Form;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionFailed;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exceptions\UnsupportedSchema;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Pipeline\Authorizer;
use AgenticActions\Pipeline\AuthorizeTiming;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\ExceptionMapper;
use AgenticActions\Pipeline\Halt;
use AgenticActions\Pipeline\ModelDriven;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Pipeline\RunningContexts;
use AgenticActions\Schema\AdvertisedSchema;
use AgenticActions\Schema\Coercer;
use AgenticActions\Schema\Projector;
use AgenticActions\Schema\RuleCompiler;
use AgenticActions\Schema\SchemaReader;
use AgenticActions\Security\ReadGuard;
use AgenticActions\Security\TokenCheck;
use AgenticActions\Tenancy\Tenants;
use AgenticActions\Views\ShowsTable;
use AgenticActions\Views\Table;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\ClosureValidationRule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator as LaravelValidator;
use LogicException;
use Throwable;
use WeakMap;

/**
 * The one pipeline every caller enters. The door decides only whether the class's own #[Expose] is consulted.
 *
 * @internal
 */
final class Runner
{
    /**
     * When each HTTP call's admission started, by the context the bridge passes, for the events' duration.
     *
     * @var WeakMap<ActionContext, int|float>
     */
    private WeakMap $started;

    /**
     * The action instance each HTTP call's hooks share, by the context the bridge passes and the class, so admission,
     * rules(), prepareForValidation() and handle() run on one instance, as they do in-process.
     *
     * @var WeakMap<ActionContext, array<string, Action>>
     */
    private WeakMap $instances;

    /**
     * The input each call's action instance was handed in prepareForValidation(), for its outcome.
     *
     * @var WeakMap<Action, array<string, mixed>>
     */
    private WeakMap $entered;

    /**
     * Create the runner.
     */
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ExceptionMapper $mapper,
        private readonly TokenCheck $tokens,
        private readonly SchemaReader $reader,
        private readonly RuleCompiler $compiler,
        private readonly Coercer $coercer,
        private readonly Projector $projector,
        private readonly AdvertisedSchema $advertised,
    ) {
        $this->started = new WeakMap;
        $this->instances = new WeakMap;
        $this->entered = new WeakMap;
    }

    /**
     * Run the whole pipeline. Never throws for a refusal. A crash or MissingContext is reported and returned as a
     * Failed outcome unless $rethrow is true, in which case it propagates unreported after ActionFailed fires.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $toolsets  the calling agent's toolsets, for Door::Agent only
     */
    public function run(Entry $entry, array $input, ActionContext $context, Door $door, array $toolsets = [], bool $rethrow = false): Outcome
    {
        $context = self::onDoor($context, $door);

        if (($fake = $this->interceptor()) !== null) {
            return $fake->respond($entry, $input, $context);
        }

        $started = hrtime(true);

        if (($live = $this->live($entry)) === null) {
            return $this->conclude(Outcome::notFound($entry, $context), $started, $rethrow);
        }

        $context = $context->forAction($live->name);

        try {
            $outcome = $this->scoped($context, fn (): Outcome => $this->pipeline($live, $input, $context, $door, $toolsets));
        } catch (Throwable $exception) {
            $outcome = Outcome::failed($live, $context, $exception);
        }

        return $this->conclude($outcome, $started, $rethrow);
    }

    /**
     * Action::run(): the in-process door with the throwing contract of Action::run().
     *
     * @param  class-string<Action>  $class
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws Refusal
     */
    public function runInProcess(string $class, array $input, ActionContext $context): mixed
    {
        $outcome = $this->run(ClassExposure::of($class), $input, $context, Door::InProcess, rethrow: true);

        return match ($outcome->kind()) {
            OutcomeKind::Ok => $outcome->result(),
            OutcomeKind::Invalid => throw ValidationException::withMessages($outcome->errors())->errorBag($outcome->entry()->errorBag),
            default => throw $outcome->refusal() ?? new LogicException("[{$class}] stopped without a refusal."),
        };
    }

    /**
     * Steps 1 to 3 for the HTTP bridge, which runs them inside scoped() and, for a Read, the Read guard. Fires
     * ActionRefused or ActionFailed when it stops.
     *
     * @throws Halt
     */
    public function admit(Entry $entry, ActionContext $context, Door $door): void
    {
        $this->started[$context] = $started = hrtime(true);

        if ($this->interceptor() !== null) {
            return;
        }

        if (($live = $this->live($entry)) === null) {
            throw new Halt($this->conclude(Outcome::notFound($entry, $context), $started, false, report: false));
        }

        $call = $context->forAction($live->name);

        try {
            $action = $this->instance($live, $context);
            $stop = $this->admission($live, $action, $call, $door, []) ?? $this->authorizeEarly($live, $action, $call);
        } catch (Throwable $exception) {
            $stop = Outcome::failed($live, $call, $exception);
        }

        if ($stop !== null) {
            throw new Halt($this->conclude($stop, $started, false, report: false));
        }
    }

    /**
     * Step 5: fixed overlay, coercion, prepareForValidation().
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function assemble(Entry $entry, array $input, ActionContext $context): array
    {
        return $this->assembled($this->instance($entry, $context), $input, $context);
    }

    /**
     * Step 6's rules: the compiled schema() plus rules($context).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(Entry $entry, ActionContext $context): array
    {
        return $this->rulesFor($this->instance($entry, $context), $context, $entry->class);
    }

    /**
     * Steps 7 to 10 on input the HTTP bridge already validated, with steps 7 to 9 inside the Read guard for a Read.
     */
    public function complete(Entry $entry, ValidatedInput $input, ActionContext $context, bool $rethrow): Outcome
    {
        $started = $this->started[$context] ?? hrtime(true);

        if (($live = $this->live($entry)) === null) {
            return $this->conclude(Outcome::notFound($entry, $context), $started, $rethrow);
        }

        $call = $context->forAction($live->name);

        try {
            $outcome = $this->scoped($call, fn (): Outcome => $this->guarded($live, function () use ($live, $context, $input, $call): Outcome {
                $action = $this->instance($live, $context);

                return $this->authorizeLate($live, $action, $input, $call) ?? $this->finish($live, $action, $input, $call);
            }));
        } catch (Throwable $exception) {
            $outcome = Outcome::failed($live, $call, $exception);
        }

        return $this->conclude($outcome, $started, $rethrow);
    }

    /**
     * Step 6's stop for the HTTP bridge, whose FormRequest validates: fire ActionRefused, as every other door does.
     * FormRequest then throws the ValidationException itself.
     */
    public function recordInvalid(Entry $entry, ValidationException $exception, ActionContext $context): void
    {
        $started = $this->started[$context] ?? hrtime(true);
        $live = $this->live($entry) ?? $entry;

        $this->record($this->invalid($live, $context->forAction($live->name), $exception), $started);
    }

    /**
     * Whether a model-surface catalog may list the action for this context: steps 1 to 3 without input, no events,
     * never throws (a throwable from authorize() is reported and reads as false).
     *
     * @param  list<string>  $toolsets
     */
    public function exposed(Entry $entry, ActionContext $context, Door $door, array $toolsets = []): bool
    {
        $context = self::onDoor($context, $door);

        try {
            if (($live = $this->live($entry)) === null) {
                return false;
            }

            $context = $context->forAction($live->name);

            $stop = $this->scoped($context, function () use ($live, $context, $door, $toolsets): ?Outcome {
                $action = $live->action();

                return $this->admission($live, $action, $context, $door, $toolsets)
                    ?? $this->guarded($live, fn (): ?Outcome => $this->authorizeEarly($live, $action, $context));
            });
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        if ($stop?->kind() === OutcomeKind::Failed && ($exception = $stop->exception()) !== null) {
            report($exception);
        }

        return $stop === null;
    }

    /**
     * What a model's call waits on, from these arguments, all inside the Read guard: the card of a Destructive or
     * External call that would pass (built after both authorize steps from the validated input), or the form of a call
     * to an asking Read or Write action whose validation refused only fields a form can hold. Admission and the
     * input-free authorize() run first, so a person who may not run the action is never asked. Never calls handle(),
     * fires no event, takes no claim, never throws: null when the call would run at once or be refused (the model then
     * hears the real answer), reported when a step threw.
     *
     * @param  array<string, mixed>  $arguments  the model's arguments, as laravel/ai or the MCP client holds them
     * @param  list<string>  $toolsets  the calling agent's toolsets; [] on the MCP door
     * @param  Door  $door  the agent door, or the MCP door (where admission refuses every Destructive or External call)
     */
    public function preview(Entry $entry, array $arguments, ActionContext $context, array $toolsets, Door $door = Door::Agent): ApprovalCard|Form|null
    {
        $context = self::onDoor($context, $door);

        try {
            if (($live = $this->live($entry)) === null || ($live->effect?->isModelSafe() !== false && ! $live->asks)) {
                return null;
            }

            $context = $context->forAction($live->name);

            return $this->scoped($context, fn (): ApprovalCard|Form|null => app(ReadGuard::class)->run(function () use ($live, $arguments, $context, $toolsets, $door): ApprovalCard|Form|null {
                $action = $live->action();

                if ($this->admission($live, $action, $context, $door, $toolsets) !== null) {
                    return null;
                }

                $input = $this->validated($live, $action, $arguments, $context, $door);

                if ($input instanceof Outcome) {
                    // $asks is never true for a Destructive or External action. A fresh instance: nothing the model's
                    // arguments left on $action reaches ask(), rules() or the node.
                    if ($live->asks && $input->kind() === OutcomeKind::Invalid) {
                        $asking = $live->action();

                        return Form::build($live, $asking, $context, $this->advertised->node($asking, $context), $arguments, $input->errors());
                    }

                    return $this->reported($input);
                }

                if ($live->effect?->isModelSafe() !== false) {
                    return null;
                }

                return ($late = $this->authorizeLate($live, $action, $input, $context)) !== null
                    ? $this->reported($late)
                    : ApprovalCard::build($live, $action, $context, $input);
            }));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Run a callback inside the context's tenancy and locale, as the current context (ActionContext::current()).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function scoped(ActionContext $context, Closure $callback): mixed
    {
        $running = app(RunningContexts::class);
        $running->push($context);

        try {
            return app(Tenancy::class)->run(
                $context->tenant,
                $context->actor,
                fn (): mixed => $this->withLocale($context->locale, $callback),
            );
        } finally {
            $running->pop();
        }
    }

    /**
     * The context a door runs with: the agent door always runs as the Agent surface and the MCP door as the Mcp
     * surface, model-driven, whatever context it is handed.
     */
    private static function onDoor(ActionContext $context, Door $door): ActionContext
    {
        return match (true) {
            $door === Door::Agent && $context->surface !== Surface::Agent => $context->withSurface(Surface::Agent),
            $door === Door::Mcp && $context->surface !== Surface::Mcp => $context->withSurface(Surface::Mcp),
            default => $context,
        };
    }

    /**
     * Steps 1 to 9 for a live entry.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $toolsets
     */
    private function pipeline(Entry $live, array $input, ActionContext $context, Door $door, array $toolsets): Outcome
    {
        $action = $live->action();

        if (($stop = $this->admission($live, $action, $context, $door, $toolsets)) !== null) {
            return $stop;
        }

        // A Destructive or External call a model drives: every step before step 8 runs inside the Read guard, whatever
        // reads.guard says; step 8 and handle() run outside it. Every other call keeps guarded() unchanged.
        if ($live->effect?->isModelSafe() === false && ModelDriven::detect($context)) {
            $prepared = app(ReadGuard::class)->run(fn (): Outcome|ValidatedInput => $this->prepared($live, $action, $input, $context, $door));

            return $prepared instanceof Outcome ? $prepared : $this->finish($live, $action, $prepared, $context);
        }

        return $this->guarded($live, fn (): Outcome => ($prepared = $this->prepared($live, $action, $input, $context, $door)) instanceof Outcome
            ? $prepared
            : $this->finish($live, $action, $prepared, $context));
    }

    /**
     * Steps 3 to 7: validated() then authorizeLate().
     *
     * @param  array<string, mixed>  $input
     */
    private function prepared(Entry $live, Action $action, array $input, ActionContext $context, Door $door): Outcome|ValidatedInput
    {
        $validated = $this->validated($live, $action, $input, $context, $door);

        if ($validated instanceof Outcome) {
            return $validated;
        }

        return $this->authorizeLate($live, $action, $validated, $context) ?? $validated;
    }

    /**
     * Steps 3 to 6: an input-free authorize(), the model doors' prune and translation, the fixed overlay and
     * prepareForValidation(), validation.
     *
     * @param  array<string, mixed>  $input
     */
    private function validated(Entry $live, Action $action, array $input, ActionContext $context, Door $door): Outcome|ValidatedInput
    {
        if (($stop = $this->authorizeEarly($live, $action, $context)) !== null) {
            return $stop;
        }

        $advertised = null;

        if ($door->isModel()) {
            // Step 4a: a model's arguments keep only what the agent was offered, at every depth.
            $node = $this->advertised->node($action, $context);
            $input = $this->advertised->prune($input, $node);

            // Step 4b: the agent's own vocabulary, validated, then turned into canonical input.
            if ($live->hasAgentSchema) {
                $translated = $this->translate($live, $action, $input, $node, $context);

                if ($translated instanceof Outcome) {
                    return $translated;
                }

                $input = $translated;
                $advertised = array_keys((array) ($node['properties'] ?? []));
            }
        }

        $input = $this->assembled($action, $input, $context);
        $rules = $this->rulesFor($action, $context, $live->class);

        // A model's call to an action without agentSchema() gives each field requiredForAgents() names: `required` in
        // place of every rule Laravel reads as `sometimes`, schema()'s, rules()' or a Rule::when()'s resolved against
        // this input, so no rule lets the key be absent. With agentSchema(), step 4b's node already required them.
        foreach ($door->isModel() && ! $live->hasAgentSchema ? $action->requiredForAgents() : [] as $key) {
            $own = (array) ValidationRuleParser::filterConditionalRules([$key => $rules[$key] ?? []], $input)[$key];
            $rules[$key] = ['required', ...array_filter($own, fn (mixed $rule): bool => ! (is_string($rule) || is_array($rule)) || ValidationRuleParser::parse($rule)[0] !== 'Sometimes')];
        }

        $validator = Validator::make(self::capped($input, $rules), $rules);

        if ($validator->fails()) {
            $exception = new ValidationException($validator);

            if ($advertised !== null && ($stray = $this->unadvertised($exception, $advertised)) !== []) {
                return Outcome::failed($live, $context, new LogicException(
                    "{$live->class}: fromAgent() produced input that fails validation on [".implode(', ', $stray).'], which agentSchema() does not advertise.',
                ));
            }

            return $this->invalid($live, $context, $exception);
        }

        return self::validatedInput($validator);
    }

    /**
     * Steps 1a to 2: the class door, the model gate, the token, the exposure hook and the tenant scope. Null when the
     * call may go on.
     *
     * @param  list<string>  $toolsets
     *
     * @throws MissingContext when a tenant-scoped action runs without a tenant
     */
    private function admission(Entry $live, Action $action, ActionContext $context, Door $door, array $toolsets): ?Outcome
    {
        $notFound = Outcome::notFound($live, $context);

        // With tenancy on, the MCP door runs tenant-scoped actions only with a tenant and the rest only without one,
        // the split the mount makes. A queued run whose origin's switch is now off is closed, as the surface is.
        $closed = match ($door) {
            Door::GeneratedRoute => ! $live->allows(Surface::Http) || ! config('agentic-actions.surfaces.web'),
            Door::Agent => ! $live->allows(Surface::Agent)
                || ! config('agentic-actions.surfaces.agents')
                || array_intersect($live->toolsets, $toolsets) === [],
            Door::Mcp => ! $live->allows(Surface::Mcp)
                || ! config('agentic-actions.surfaces.mcp')
                || (config('agentic-actions.tenant.model') !== null && $live->tenantScoped !== ($context->tenant !== null)),
            Door::InProcess => $context->surface === Surface::Queue && match ($context->origin) {
                Surface::Mcp => ! config('agentic-actions.surfaces.mcp'),
                Surface::Agent => ! config('agentic-actions.surfaces.agents'),
                default => false,
            },
            Door::Route => false,
        };

        if ($closed) {
            return $notFound;
        }

        // A Destructive or External call passes only on the agent door, for a person, with a ticket (an agent that can
        // pause). Step 8 then needs its claim. Every other door, MCP included, refuses it as before.
        $confirmable = $door === Door::Agent && $context->approval !== null && $context->actor !== null
            && $live->effect?->isModelSafe() === false;

        if (ModelDriven::detect($context) && ! $confirmable
            && ($live->effect?->isModelSafe() !== true || ($context->actor === null && ! $live->guests))) {
            return $notFound;
        }

        if (! $this->tokens->allows($context, $live) || $action->shouldRegister($context) !== true) {
            return $notFound;
        }

        if ($live->tenantScoped && $context->tenant === null) {
            throw MissingContext::tenantScoped($live->class);
        }

        if ($context->tenant !== null) {
            if ($context->actor === null) {
                return $context->isSystem() ? null : $notFound;
            }

            if (! Tenants::member($context->actor, $context->tenant, $live->effect)) {
                return $notFound;
            }
        }

        return null;
    }

    /**
     * Step 3: a missing authorize() is denied; an input-free one, or the first run of one whose input may be null, runs
     * now. Null when the call may go on.
     */
    private function authorizeEarly(Entry $live, Action $action, ActionContext $context): ?Outcome
    {
        $timing = Authorizer::timing($action);

        return match (true) {
            $timing === AuthorizeTiming::Missing => Outcome::denied($live, $context),
            $timing->early() => $this->authorizing($live, $action, $context, null),
            default => null,
        };
    }

    /**
     * Step 7: an input-taking authorize(), with the validated input. Null when the call may go on.
     */
    private function authorizeLate(Entry $live, Action $action, ValidatedInput $input, ActionContext $context): ?Outcome
    {
        return Authorizer::timing($action)->takesInput() ? $this->authorizing($live, $action, $context, $input) : null;
    }

    /**
     * Steps 8 and 9: the claim of a model-driven Destructive or External call, or of a form's answer; handle() and the
     * projection, or the table's output for an action that shows one.
     */
    private function finish(Entry $live, Action $action, ValidatedInput $input, ActionContext $context): Outcome
    {
        // Step 8: a Destructive or External call a model drives runs only on its card's claim, as in 0.4, whatever its
        // ticket carries; a Read or Write call carrying a form's answer runs only on that form's claim, or over MCP on
        // the request state CallAction verified (not single use). Taken here, once, after both authorize steps and
        // before handle(), which never sees the ticket.
        $ticket = $context->approval;
        $form = $ticket?->form;
        $gated = $live->effect?->isModelSafe() === false && ModelDriven::detect($context);

        if ($gated || $form !== null) {
            $won = $gated
                ? ($card = $this->rebuilt($live, $action, $context, $input)) !== null && app(ApprovalClaims::class)->claim($card)
                : $ticket !== null && $form !== null && $form->entry->name === $live->name
                    && [$form->context->actorKey(), $form->context->tenantKey()] === [$context->actorKey(), $context->tenantKey()]
                    && ($context->surface === Surface::Mcp
                        || ($ticket->names() && app(ApprovalClaims::class)->take($ticket->conversationId, $ticket->toolCallId, $form)));

            if (! $won) {
                return Outcome::refusedBy($live, $context, Refusal::make('agentic-actions::model.not_confirmed')->status(409));
            }

            $context = $context->withApproval(null);
        }

        try {
            $result = app()->call([$action, 'handle'], [
                ActionContext::class => $context,
                ValidatedInput::class => $input,
            ]);
        } catch (Throwable $exception) {
            // A form's run keeps its ticket on the outcome, so a validation message handle() raises stays off the model.
            return $this->mapped($exception, $live, $form === null ? $context : $context->withApproval($ticket));
        }

        $output = $live->shows() && $action instanceof ShowsTable
            ? Table::output($live, $action, $result)
            : $this->projector->project($result, $this->reader->output($action));

        return Outcome::completed($live, $context, $result, $output, $action->modelReply($result, $context), $this->entered[$action] ?? []);
    }

    /**
     * Step 8's card, rebuilt inside the Read guard from the input that will run, for a ticket that names a call; null
     * otherwise, or when building it threw (reported).
     */
    private function rebuilt(Entry $live, Action $action, ActionContext $context, ValidatedInput $input): ?ApprovalCard
    {
        $card = $context->approval?->names() === true
            ? rescue(fn (): ApprovalCard => app(ReadGuard::class)->run(fn (): ApprovalCard => ApprovalCard::build($live, $action, $context, $input)))
            : null;

        return $card instanceof ApprovalCard ? $card : null;
    }

    /**
     * A preview's stop: a crash or a guard refusal is reported, like a throwable; any other stop is the call's answer.
     */
    private function reported(Outcome $outcome): null
    {
        if ($outcome->kind() === OutcomeKind::Failed && ($exception = $outcome->exception()) !== null) {
            report($exception);
        }

        return null;
    }

    /**
     * Call authorize() and turn its answer, or its throwable, into a stop. Null when allowed.
     */
    private function authorizing(Entry $live, Action $action, ActionContext $context, ?ValidatedInput $input): ?Outcome
    {
        try {
            $kind = $this->authorizer->check($action, $context, $input);
        } catch (Throwable $exception) {
            return $this->mapped($exception, $live, $context);
        }

        return match ($kind) {
            null => null,
            OutcomeKind::NotFound => Outcome::notFound($live, $context),
            default => Outcome::denied($live, $context),
        };
    }

    /**
     * Step 4b: coerce and validate the agent's arguments against agentSchema(), then fromAgent().
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $node  the advertised agent node
     * @return array<string, mixed>|Outcome
     */
    private function translate(Entry $live, Action $action, array $input, array $node, ActionContext $context): array|Outcome
    {
        $input = $this->coercer->coerce($input, $node, Surface::Agent);

        $rules = $this->compile($node, Surface::Agent, [], $live->class);
        $validator = Validator::make(self::capped($input, $rules), $rules);

        if ($validator->fails()) {
            return $this->invalid($live, $context, new ValidationException($validator));
        }

        try {
            return $action->fromAgent(self::validatedInput($validator), $context);
        } catch (Throwable $exception) {
            return $this->mapped($exception, $live, $context);
        }
    }

    /**
     * Step 5 on one action instance.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function assembled(Action $action, array $input, ActionContext $context): array
    {
        $node = $this->reader->input($action);
        $properties = (array) ($node['properties'] ?? []);

        // The edge wins over the body: a key schema() declares takes the fixed value, and a caller can never supply
        // any other key the edge fixes, even one only rules() checks.
        foreach ($context->fixed as $key => $value) {
            if (array_key_exists($key, $properties)) {
                $input[$key] = $value;
            } else {
                unset($input[$key]);
            }
        }

        // The tenant's route parameter names the tenant, never input, so a body cannot carry another one.
        if ($context->routeTenantParameter !== null) {
            unset($input[$context->routeTenantParameter]);
        }

        $this->entered[$action] = $input = $this->coercer->coerce($input, $node, $context->surface);

        return $action->prepareForValidation($input, $context);
    }

    /**
     * The compiled schema() plus rules($context), appended per key.
     *
     * @param  class-string<Action>  $class
     * @return array<string, list<mixed>>
     */
    private function rulesFor(Action $action, ActionContext $context, string $class): array
    {
        $extra = $action->rules($context);
        $rules = $this->compile($this->reader->input($action), $context->surface, array_map(strval(...), array_keys($extra)), $class);

        foreach ($extra as $key => $value) {
            $key = (string) $key;

            $appended = match (true) {
                is_string($value) => explode('|', $value),
                is_array($value) => array_values($value),
                default => [$value],
            };

            $rules[$key] = [...($rules[$key] ?? []), ...$appended];
        }

        return $rules;
    }

    /**
     * The validated input, each list item in its place. validated() rebuilds a list item that is an object or a list
     * from its keys' rules: it adds a list's null items before those, and leaves out an item none of whose declared keys
     * was given, which keeps its place here as []. Only lists are put in order, never a map with integer keys.
     *
     * @internal
     */
    public static function validatedInput(LaravelValidator $validator): ValidatedInput
    {
        $values = $validator->validated();
        $rules = $validator->getRules();

        // A child's rules come after its parent's, so this goes from the deepest, and a list is sorted once it is whole.
        foreach (array_reverse($rules, true) as $key => $own) {
            $parent = Str::beforeLast((string) $key, '.');

            if ($parent !== (string) $key && in_array('list', $rules[$parent] ?? [], true)
                && is_array(Arr::get($validator->getData(), $key)) && ! Arr::has($values, $key)) {
                Arr::set($values, $key, []);
            }

            if (in_array('list', $own, true) && is_array($list = Arr::get($values, $key))) {
                ksort($list);
                Arr::set($values, $key, $list);
            }
        }

        return new ValidatedInput($values);
    }

    /**
     * Compile a node, naming the class when the compiler refuses it.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $owned
     * @return array<string, list<mixed>>
     */
    private function compile(array $node, Surface $surface, array $owned, string $class): array
    {
        try {
            return $this->compiler->compile($node, $surface, $owned);
        } catch (UnsupportedSchema $exception) {
            throw $exception->withClass($class);
        }
    }

    /**
     * The input with each list its rules cap (array or list, and max:N) cut to N + 1 items, outer lists first. The same max
     * rule refuses the call, and validation reads at most N + 1 items whatever the body carried.
     *
     * @upstream An oversized list is cut before validation reads it.
     *
     * @internal
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function capped(array $input, array $rules): array
    {
        $caps = [];

        foreach ($rules as $path => $rule) {
            $parts = array_filter(is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : []), is_string(...));

            foreach (array_intersect(['array', 'list'], $parts) !== [] ? $parts : [] as $part) {
                if (preg_match('/^max:(\d+)$/D', $part, $max) === 1) {
                    $caps[(string) $path] = (int) $max[1] + 1;
                }
            }
        }

        uksort($caps, fn (string $a, string $b): int => substr_count($a, '.') <=> substr_count($b, '.'));

        foreach ($caps as $path => $keep) {
            $input = self::cut($input, explode('.', $path), $keep);
        }

        return $input;
    }

    /**
     * The value with the list at the path, a * for each item, cut to its first items.
     *
     * @param  list<string>  $segments
     */
    private static function cut(mixed $value, array $segments, int $keep): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($segments === []) {
            return count($value) > $keep ? array_slice($value, 0, $keep, true) : $value;
        }

        $segment = array_shift($segments);

        foreach ($segment === '*' ? array_keys($value) : (array_key_exists($segment, $value) ? [$segment] : []) as $key) {
            $value[$key] = self::cut($value[$key], $segments, $keep);
        }

        return $value;
    }

    /**
     * An outcome for a throwable raised by fromAgent(), authorize() or handle().
     */
    private function mapped(Throwable $exception, Entry $live, ActionContext $context): Outcome
    {
        $mapped = $this->mapper->map($exception, $context);

        return match ($mapped->kind) {
            OutcomeKind::Refused => $mapped->refusal !== null
                ? Outcome::refusedBy($live, $context, $mapped->refusal)
                : Outcome::failed($live, $context, $exception),
            OutcomeKind::Invalid => $mapped->validation !== null
                ? $this->invalid($live, $context, $mapped->validation)
                : Outcome::failed($live, $context, $exception),
            OutcomeKind::NotFound => Outcome::notFound($live, $context),
            OutcomeKind::Denied => Outcome::denied($live, $context),
            default => Outcome::failed($live, $context, $exception),
        };
    }

    /**
     * An Invalid outcome: messages by key, and rule names by key as a model reads them.
     */
    private function invalid(Entry $live, ActionContext $context, ValidationException $exception): Outcome
    {
        $errors = [];

        foreach ($exception->errors() as $key => $messages) {
            $errors[(string) $key] = array_values(array_map(strval(...), (array) $messages));
        }

        $validator = $exception->validator;
        $failed = $validator->failed();
        $named = $validator instanceof LaravelValidator ? NamedRule::failuresFor($validator) : [];
        $rules = [];

        foreach (array_keys($errors) as $key) {
            $names = [];

            foreach (array_keys($failed[$key] ?? []) as $rule) {
                $rule = (string) $rule;

                if ($rule === NamedRule::class) {
                    array_push($names, ...($named[$key] ?? ['invalid']));
                } elseif ($rule === ClosureValidationRule::class) {
                    $names[] = 'invalid';
                } else {
                    $names[] = Str::snake(class_basename($rule));
                }
            }

            $rules[$key] = array_values(array_unique($names));
        }

        return Outcome::invalid($live, $context, $errors, $rules);
    }

    /**
     * The failing keys whose top-level key the agent schema does not advertise.
     *
     * @param  list<array-key>  $advertised
     * @return list<string>
     */
    private function unadvertised(ValidationException $exception, array $advertised): array
    {
        $keys = array_map(strval(...), array_keys($exception->errors()));

        return array_values(array_filter(
            $keys,
            fn (string $key): bool => ! in_array(Str::before($key, '.'), array_map(strval(...), $advertised), true),
        ));
    }

    /**
     * Step 10 and the Failed contract: fire the event, then report or rethrow a crash.
     */
    private function conclude(Outcome $outcome, int|float $started, bool $rethrow, bool $report = true): Outcome
    {
        $this->record($outcome, $started);

        $exception = $outcome->kind() === OutcomeKind::Failed ? $outcome->exception() : null;

        if ($exception !== null) {
            if ($rethrow) {
                throw $exception;
            }

            if ($report) {
                report($exception);
            }
        }

        return $outcome;
    }

    /**
     * Fire ActionCompleted, ActionRefused or ActionFailed, always with the Read guard off.
     */
    private function record(Outcome $outcome, int|float $started): void
    {
        $context = $outcome->context();
        $entry = $outcome->entry();
        $actor = $context->actor;

        $fields = [
            'action' => $entry->name,
            'class' => $entry->class,
            'surface' => $context->surface,
            'effect' => $entry->effect,
            'modelDriven' => ModelDriven::detect($context),
            'actorType' => $actor instanceof Model ? $actor->getMorphClass() : ($actor === null ? null : $actor::class),
            'actorId' => self::identifier($actor?->getAuthIdentifier()),
            'tenantId' => self::identifier($context->tenant?->getKey()),
            'requestId' => $context->requestId,
            'durationMs' => (hrtime(true) - $started) / 1e6,
        ];

        $event = match ($outcome->kind()) {
            OutcomeKind::Ok => new ActionCompleted(...$fields),
            OutcomeKind::Failed => new ActionFailed(...$fields, exceptionClass: $outcome->exception() === null ? LogicException::class : $outcome->exception()::class),
            default => new ActionRefused(...$fields, reason: $outcome->kind()->value, status: $outcome->status(), failedRules: $outcome->failedRules()),
        };

        app(ReadGuard::class)->unguarded(fn () => event($event));
    }

    /**
     * An identifier as the events carry it.
     */
    private static function identifier(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) || $value === null ? $value : (string) $value;
    }

    /**
     * Run a callback inside the Read guard when the live entry is a Read and reads.guard is on.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guarded(Entry $live, Closure $callback): mixed
    {
        if ($live->effect === Effect::Read && config('agentic-actions.reads.guard')) {
            return app(ReadGuard::class)->run($callback);
        }

        return $callback();
    }

    /**
     * Run a callback in the given locale, restoring the previous one after.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withLocale(string $locale, Closure $callback): mixed
    {
        $app = app();
        $original = $app->getLocale();

        if ($locale === '' || $locale === $original) {
            return $callback();
        }

        try {
            $app->setLocale($locale);

            return $callback();
        } finally {
            $app->setLocale($original);
        }
    }

    /**
     * The fake bound, if any. A fake switches every gate off, so one answers only while the app runs its tests: a
     * fake left bound anywhere else is ignored.
     */
    private function interceptor(): ?InterceptsActions
    {
        if (! app()->runningUnitTests() || ! app()->bound(InterceptsActions::class)) {
            return null;
        }

        $fake = app(InterceptsActions::class);

        return $fake instanceof InterceptsActions ? $fake : null;
    }

    /**
     * The one action instance for the HTTP bridge's call: built at its first hook, shared by the rest.
     */
    private function instance(Entry $entry, ActionContext $context): Action
    {
        $instances = $this->instances[$context] ?? [];
        $instances[$entry->class] ??= $entry->action();

        $this->instances[$context] = $instances;

        return $instances[$entry->class];
    }

    /**
     * What the class declares now, or null when the class is gone or is not a concrete action.
     */
    private function live(Entry $entry): ?Entry
    {
        try {
            return is_subclass_of($entry->class, Action::class) ? ClassExposure::of($entry->class) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
