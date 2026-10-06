<?php

namespace AgenticActions;

use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Pipeline\ModelDriven;
use AgenticActions\Pipeline\RunningContexts;
use AgenticActions\Tenancy\Tenants;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who calls, for which tenant, from which surface and in which language. Every action reads the caller from here,
 * never from auth(), request() or the session.
 *
 * @api
 */
final class ActionContext
{
    /**
     * A fresh ULID unless one is given. Never taken from a client header.
     */
    public readonly string $requestId;

    /**
     * Create a context. Private: only the named constructors below build one, so only console(), system() and
     * queued() can produce a context without a guard.
     *
     * @param  array<string, mixed>  $fixed  input the edge supplies (route parameters, host presets); it overwrites caller input and is hidden from advertised schemas
     *
     * @throws InvalidArgumentException when the locale could name a directory outside the language directories
     */
    private function __construct(
        public readonly Surface $surface,
        public readonly ?Authenticatable $actor,
        public readonly ?Model $tenant,
        public readonly string $locale,
        public readonly ?string $guard = null,
        public readonly ?string $idempotencyKey = null,
        public readonly array $fixed = [],
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $action = null,

        /**
         * The surface a queued run was dispatched from; null on every other surface.
         *
         * @internal
         */
        public readonly ?Surface $origin = null,

        /**
         * The token grants a queued run keeps from its caller; null means no token limits. Read only on the Queue
         * surface.
         *
         * @internal
         *
         * @var list<string>|null
         */
        public readonly ?array $grants = null,

        /**
         * The confirmation a Destructive or External agent call carries: its conversation and tool-call id. A ticket
         * with no ids lets a catalog list those actions for an agent that can pause. Null on every other call, inside
         * handle() and on ActionContext::current(); the package never serializes it, so a queued run never carries one.
         *
         * @internal
         */
        public readonly ?ApprovalTicket $approval = null,
        ?string $requestId = null,

        /**
         * The tenant's route parameter, when the route named the tenant: a body never supplies it, as it never
         * supplies the route's other parameters.
         *
         * @internal
         */
        public readonly ?string $routeTenantParameter = null,
    ) {
        // The translator reads files from a directory the locale names, so a locale never leaves the language
        // directories: the same rule Laravel's own setLocale() applies.
        if (str_contains($locale, '/') || str_contains($locale, '\\')) {
            throw new InvalidArgumentException('Invalid characters present in locale.');
        }

        $this->requestId = $requestId ?: (string) Str::ulid();
    }

    /**
     * The context of the action running now, for code it calls that is not handed the context, such as a model event,
     * an observer or a service: its surface (who drove the call: the web, an agent, MCP, the CLI, a queued run), its
     * actor and its tenant. Inside a nested run it is the innermost one; null outside any run.
     */
    public static function current(): ?self
    {
        return app(RunningContexts::class)->current();
    }

    /**
     * The HTTP edge: the request's user, the guard that authenticated it, the tenant route parameter, the locale after
     * the app's middleware, the Idempotency-Key header and the route's parameters as fixed input.
     *
     * @throws NotFoundHttpException when the tenant parameter resolves to nothing
     */
    public static function fromRequest(Request $request): self
    {
        $route = $request->route();
        $bound = $route instanceof Route && $route->hasParameters();

        /** @var array<string, mixed> $fixed */
        $fixed = $bound ? $route->originalParameters() : [];
        $tenant = null;
        $routeTenant = null;

        if (($model = config('agentic-actions.tenant.model')) !== null) {
            $parameter = (string) config('agentic-actions.tenant.parameter');
            $tenant = self::tenantFrom($bound ? $route->parameter($parameter) : null, $model);
            $routeTenant = $bound && $route->hasParameter($parameter) ? $parameter : null;

            unset($fixed[$parameter]);
        }

        $key = trim((string) $request->header('Idempotency-Key', ''));
        $user = $request->user();

        return new self(
            surface: Surface::Http,
            actor: $user instanceof Authenticatable ? $user : null,
            tenant: $tenant,
            locale: app()->getLocale(),
            guard: self::defaultGuard(),
            idempotencyKey: $key !== '' && strlen($key) <= 255 ? $key : null,
            fixed: $fixed,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            routeTenantParameter: $routeTenant,
        );
    }

    /**
     * An in-request caller outside a route: Livewire, Filament, a Blade controller.
     */
    public static function http(?Authenticatable $actor, ?Model $tenant = null, ?string $locale = null): self
    {
        return new self(Surface::Http, $actor, $tenant, $locale ?? app()->getLocale(), self::defaultGuard());
    }

    /**
     * An agent's tools, built from the agent's own state.
     */
    public static function agent(?Authenticatable $actor, ?Model $tenant = null, ?string $locale = null): self
    {
        return new self(Surface::Agent, $actor, $tenant, $locale ?? app()->getLocale(), self::defaultGuard());
    }

    /**
     * Webhooks and scheduled work: no actor, no token check, still authorize().
     */
    public static function system(?Model $tenant = null, ?string $locale = null): self
    {
        return new self(Surface::System, null, $tenant, $locale ?? app()->getLocale());
    }

    /**
     * An operator at the CLI.
     *
     * @internal
     */
    public static function console(?Authenticatable $actor, ?Model $tenant, string $locale, ?string $idempotencyKey): self
    {
        return new self(Surface::Console, $actor, $tenant, $locale, idempotencyKey: $idempotencyKey);
    }

    /**
     * An MCP client's call, for the actor the MCP guard authenticated.
     *
     * @internal
     */
    public static function mcp(Authenticatable $actor, ?Model $tenant, ?string $locale = null): self
    {
        return new self(Surface::Mcp, $actor, $tenant, $locale ?? app()->getLocale(), self::defaultGuard());
    }

    /**
     * A queued run in the worker: the caller's actor, tenant and fixed input, the grants captured at dispatch, the
     * caller's surface. No ip, user agent or guard; a new request id.
     *
     * @internal
     *
     * @param  array<string, mixed>  $fixed
     * @param  list<string>|null  $grants  null: no token limits (a session, the console, system work)
     */
    public static function queued(Surface $origin, ?Authenticatable $actor, ?Model $tenant, string $locale, array $fixed, ?array $grants, ?string $idempotencyKey): self
    {
        return new self(Surface::Queue, $actor, $tenant, $locale, idempotencyKey: $idempotencyKey, fixed: $fixed, origin: $origin, grants: $grants);
    }

    /**
     * The context without these fixed keys: a route parameter of the package's own route that names no input, such as
     * the tables' refresh route's view.
     *
     * @internal
     */
    public function withoutFixed(string ...$keys): self
    {
        return $this->copy(fixed: array_diff_key($this->fixed, array_flip($keys)));
    }

    /**
     * Add input the surface fixes, e.g. the record an agent is editing.
     *
     * @param  array<string, mixed>  $input
     */
    public function withFixed(array $input): self
    {
        return $this->copy(fixed: array_replace($this->fixed, $input));
    }

    /**
     * Set the raw idempotency key. A host key wins over a tool-call id.
     */
    public function withIdempotencyKey(?string $key): self
    {
        return $this->copy(idempotencyKey: $key);
    }

    /**
     * The same context on another surface.
     *
     * @internal
     */
    public function withSurface(Surface $surface): self
    {
        return $this->copy(surface: $surface);
    }

    /**
     * The same context, naming the action it is running.
     *
     * @internal
     */
    public function forAction(string $name): self
    {
        return $this->copy(action: $name);
    }

    /**
     * The same context carrying a confirmation ticket, or none.
     *
     * @internal
     */
    public function withApproval(?ApprovalTicket $ticket): self
    {
        return $this->copy(approval: $ticket);
    }

    /**
     * The actor, or MissingContext when absent or not an instance of $as.
     *
     * @template T of Authenticatable
     *
     * @param  class-string<T>  $as
     * @return T
     *
     * @throws MissingContext
     */
    public function actor(string $as = Authenticatable::class): Authenticatable
    {
        if (! $this->actor instanceof $as) {
            throw MissingContext::actor($as);
        }

        return $this->actor;
    }

    /**
     * The tenant, or MissingContext when absent or not an instance of $as.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $as
     * @return T
     *
     * @throws MissingContext
     */
    public function tenant(string $as = Model::class): Model
    {
        if (! $this->tenant instanceof $as) {
            throw MissingContext::tenant($as);
        }

        return $this->tenant;
    }

    /**
     * Find a row through the tenant scope. A missing or foreign row throws ModelNotFoundException, which every surface
     * renders as not found. MissingContext when a tenant model is configured and this context has no tenant.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     *
     * @throws ModelNotFoundException
     * @throws MissingContext
     */
    public function find(string $model, int|string $key): Model
    {
        if (config('agentic-actions.tenant.model') === null) {
            return $model::query()->findOrFail($key);
        }

        if ($this->tenant === null) {
            throw MissingContext::tenant((string) config('agentic-actions.tenant.model'));
        }

        $found = Tenants::scope(self::query($model), $this->tenant)->findOrFail($key);

        if (! $found instanceof $model) {
            throw new LogicException("The tenant scope returned a query for another model than [{$model}].");
        }

        return $found;
    }

    /**
     * The namespaced key an action stores: a UUIDv5 of action, actor key, tenant and raw key.
     *
     * @throws Refusal status 428 when there is no raw key, or when the call is model-driven and has no actor
     */
    public function requireIdempotencyKey(): string
    {
        $modelDriven = $this->isModelDriven();

        if ($this->idempotencyKey === null || ($modelDriven && $this->actor === null)) {
            throw Refusal::make($modelDriven
                ? 'agentic-actions::model.idempotency_required'
                : 'agentic-actions::http.idempotency_required')->status(428);
        }

        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "{$this->action}|{$this->actorKey()}|{$this->tenantKey()}|{$this->idempotencyKey}");
    }

    /**
     * "{morph class}:{key}" of the tenant, or "-" without one: what idempotency keys and confirmations bind to.
     *
     * @internal
     */
    public function tenantKey(): string
    {
        return $this->tenant === null ? '-' : $this->tenant->getMorphClass().':'.$this->tenant->getKey();
    }

    /**
     * "{morph class}:{identifier}" for a model actor, "{class}:{identifier}" otherwise, "system" for the app's own
     * work (isSystem()), "guest" when there is no actor.
     */
    public function actorKey(): string
    {
        if ($this->isSystem()) {
            return 'system';
        }

        if ($this->actor === null) {
            return 'guest';
        }

        $type = $this->actor instanceof Model ? $this->actor->getMorphClass() : $this->actor::class;

        return $type.':'.$this->actor->getAuthIdentifier();
    }

    /**
     * Whether a model is driving this call.
     */
    public function isModelDriven(): bool
    {
        return ModelDriven::detect($this);
    }

    /**
     * Whether this is the app's own work with no caller: system(), or a job queued from it.
     */
    public function isSystem(): bool
    {
        return $this->surface === Surface::System || ($this->surface === Surface::Queue && $this->origin === Surface::System);
    }

    /**
     * The same context with some fields changed, keeping the request id. False keeps the idempotency key and the
     * ticket as they are.
     *
     * @param  array<string, mixed>|null  $fixed
     */
    private function copy(
        ?Surface $surface = null,
        ?array $fixed = null,
        ?string $action = null,
        string|false|null $idempotencyKey = false,
        ApprovalTicket|false|null $approval = false,
    ): self {
        return new self(
            surface: $surface ?? $this->surface,
            actor: $this->actor,
            tenant: $this->tenant,
            locale: $this->locale,
            guard: $this->guard,
            idempotencyKey: $idempotencyKey === false ? $this->idempotencyKey : $idempotencyKey,
            fixed: $fixed ?? $this->fixed,
            ip: $this->ip,
            userAgent: $this->userAgent,
            action: $action ?? $this->action,
            origin: $this->origin,
            grants: $this->grants,
            approval: $approval === false ? $this->approval : $approval,
            requestId: $this->requestId,
            routeTenantParameter: $this->routeTenantParameter,
        );
    }

    /**
     * The tenant a route parameter names: a bound tenant model as is, a scalar resolved by route key.
     *
     * @throws NotFoundHttpException when the parameter names no tenant
     */
    private static function tenantFrom(mixed $value, string $model): ?Model
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $model && $value instanceof Model) {
            return $value;
        }

        $tenant = is_int($value) || is_string($value) ? Tenants::resolve($value) : null;

        if ($tenant === null) {
            throw new NotFoundHttpException((string) trans('agentic-actions::http.not_found'));
        }

        return $tenant;
    }

    /**
     * A new query for a model class.
     *
     * @return Builder<Model>
     */
    private static function query(string $model): Builder
    {
        $instance = new $model;

        if (! $instance instanceof Model) {
            throw new LogicException("[{$model}] is not an Eloquent model.");
        }

        return $instance->newQuery();
    }

    /**
     * The default guard, which the Authenticate middleware sets to the guard that authenticated the request.
     */
    private static function defaultGuard(): ?string
    {
        $guard = Auth::getDefaultDriver();

        return is_string($guard) && $guard !== '' ? $guard : null;
    }
}
