<?php

namespace AgenticActions;

use AgenticActions\Ai\ActionTool;
use AgenticActions\Ai\AgentDoor;
use AgenticActions\Ai\AgentToolAudit;
use AgenticActions\Ai\ToolFactory;
use AgenticActions\Contracts\InterceptsActions;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Http\RouteRegistrar;
use AgenticActions\Pipeline\Door;
use AgenticActions\Streaming\ConversationSlot;
use AgenticActions\Testing\ActionsFake;
use AgenticActions\Testing\ToolsetAssertion;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Agent;
use LogicException;
use Throwable;

/**
 * The package's front door, behind the Actions facade. Module entry points delegate by class name.
 *
 * @api
 */
final class ActionsManager
{
    /**
     * The app's translator for its own refusal keys.
     *
     * @var (Closure(string, ?string, array<string, string|int|float>, string): string)|null
     */
    private ?Closure $translator = null;

    /**
     * The membership closure used when no tenant.membership class is configured.
     *
     * @var (Closure(Authenticatable, Model, ?Effect): bool)|null
     */
    private ?Closure $membership = null;

    /**
     * The scope closure used when no tenant.scope class is configured.
     *
     * @var (Closure(Builder<Model>, Model): Builder<Model>)|null
     */
    private ?Closure $scope = null;

    /**
     * Domain exceptions mapped to refusals, in registration order.
     *
     * @var list<array{0: class-string<Throwable>, 1: Closure(Throwable, ActionContext): Refusal}>
     */
    private array $refusals = [];

    /**
     * Register one POST route per web-exposed action inside the caller's route group.
     *
     * @param  bool|null  $tenant  true: tenant-scoped actions only; false: the others; null: all
     */
    public function routes(?bool $tenant = null): void
    {
        app(RouteRegistrar::class)->register($tenant);
    }

    /**
     * The laravel/ai tools for an agent's toolsets, built for this context. Destructive and External actions are
     * offered only with the agent, and only when it stores its conversations, so a person can confirm each call.
     *
     * @param  list<string>  $toolsets
     * @return list<ActionTool>
     *
     * @throws LogicException when laravel/ai is not installed
     */
    public function tools(ActionContext $context, array $toolsets, ?Agent $agent = null): array
    {
        return app(ToolFactory::class)->make($context, $toolsets, $agent);
    }

    /**
     * The conversation a participant continues with an agent in a tenant (or none), kept per participant, tenant and
     * agent class in the agentic_conversations table: id() finds it, open() starts it in laravel/ai's store. Pass the
     * server's own user and tenant, never an id from the request.
     *
     * @param  Agent|class-string<Agent>  $agent
     */
    public function conversation(Agent|string $agent, Model $participant, ?Model $tenant = null): ConversationSlot
    {
        return new ConversationSlot(is_string($agent) ? $agent : $agent::class, $participant, $tenant);
    }

    /**
     * The one door an agent tool uses.
     *
     * @internal
     *
     * @param  list<string>  $toolsets
     * @param  array<string, mixed>  $arguments
     */
    public function call(array $toolsets, string $name, array $arguments, ActionContext $context, ?string $callId = null): Outcome
    {
        return app(AgentDoor::class)->call($toolsets, $name, $arguments, $context, $callId);
    }

    /**
     * Run an action in-process with canonical input and return the outcome. Never runs Translate.
     *
     * @param  class-string<Action>|string  $action  a class, or a discovered action's name
     * @param  array<string, mixed>  $input
     */
    public function attempt(string $action, array $input, ActionContext $context): Outcome
    {
        $entry = class_exists($action) && is_subclass_of($action, Action::class)
            ? ClassExposure::of($action)
            : app(ActionRegistry::class)->find($action);

        if ($entry === null) {
            return Outcome::notFound(Entry::unknown($action), $context);
        }

        return app(Runner::class)->run($entry, $input, $context, Door::InProcess);
    }

    /**
     * What the snapshot stores: the actions:list --json content.
     *
     * @return array{version: int, actions: array<string, array<string, mixed>>, agents: array<string, list<string>>}
     */
    public function exposure(): array
    {
        return Snapshot::build(app(ActionRegistry::class));
    }

    /**
     * Translate the app's Refusal keys with the app's own translator.
     *
     * @param  (Closure(string $key, ?string $default, array<string, string|int|float> $replace, string $locale): string)|null  $translator
     */
    public function translateUsing(?Closure $translator): void
    {
        $this->translator = $translator;
    }

    /**
     * Decide membership with a closure instead of a tenant.membership class.
     *
     * @param  (Closure(Authenticatable, Model, ?Effect): bool)|null  $membership
     */
    public function membershipUsing(?Closure $membership): void
    {
        $this->membership = $membership;
    }

    /**
     * Scope ActionContext::find() with a closure instead of a tenant.scope class.
     *
     * @param  (Closure(Builder<Model>, Model): Builder<Model>)|null  $scope
     */
    public function scopeUsing(?Closure $scope): void
    {
        $this->scope = $scope;
    }

    /**
     * Turn an existing domain exception into a Refusal on every surface.
     *
     * @param  class-string<Throwable>  $exception
     * @param  Closure(Throwable, ActionContext): Refusal  $map
     */
    public function refuse(string $exception, Closure $map): void
    {
        $this->refusals[] = [$exception, $map];
    }

    /**
     * Fake every action: record calls, never run handle(). Only while the app runs its tests, because a fake switches
     * every gate off.
     *
     * @param  array<class-string<Action>, array<string, mixed>|Refusal|Closure>  $responses
     *
     * @throws LogicException outside tests
     */
    public function fake(array $responses = []): ActionsFake
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('Actions::fake() only works while the app runs its tests (APP_ENV=testing): a fake switches every gate off.');
        }

        $fake = new ActionsFake($responses);

        app()->instance(InterceptsActions::class, $fake);

        return $fake;
    }

    /**
     * Fail unless a toolset holds exactly these actions.
     *
     * @param  list<string>  $names
     */
    public function assertToolset(string $toolset, array $names): void
    {
        ToolsetAssertion::assert($toolset, $names);
    }

    /**
     * Fail on duplicate tool names, forbidden keys or an oversized toolset in one agent.
     */
    public function assertAgentTools(Agent $agent): void
    {
        app(AgentToolAudit::class)->assert($agent);
    }

    /**
     * The app's translator for its own refusal keys.
     *
     * @internal
     *
     * @return (Closure(string, ?string, array<string, string|int|float>, string): string)|null
     */
    public function translator(): ?Closure
    {
        return $this->translator;
    }

    /**
     * The membership closure.
     *
     * @internal
     *
     * @return (Closure(Authenticatable, Model, ?Effect): bool)|null
     */
    public function membership(): ?Closure
    {
        return $this->membership;
    }

    /**
     * The scope closure.
     *
     * @internal
     *
     * @return (Closure(Builder<Model>, Model): Builder<Model>)|null
     */
    public function scope(): ?Closure
    {
        return $this->scope;
    }

    /**
     * Map a throwable through refuse(), or null.
     *
     * @internal
     *
     * @throws LogicException when a registered map returns something other than a Refusal
     */
    public function refusalFor(Throwable $exception, ActionContext $context): ?Refusal
    {
        foreach ($this->refusals as [$class, $map]) {
            if ($exception instanceof $class) {
                $refusal = $map($exception, $context);

                if (! $refusal instanceof Refusal) {
                    throw new LogicException("The refusal map for [{$class}] must return a ".Refusal::class.'.');
                }

                return $refusal;
            }
        }

        return null;
    }
}
