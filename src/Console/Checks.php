<?php

namespace AgenticActions\Console;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Ai\ConfirmingAgents;
use AgenticActions\Attributes\Expose;
use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Manifest;
use AgenticActions\Discovery\Scan;
use AgenticActions\Discovery\Scanner;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Exceptions\DuplicateActionName;
use AgenticActions\Exceptions\UnsupportedSchema;
use AgenticActions\Exposure\Entry;
use AgenticActions\Feed\ChangesController;
use AgenticActions\Http\ActionRequest;
use AgenticActions\Mcp\McpMount;
use AgenticActions\MissingContext;
use AgenticActions\OAuth\Discovery;
use AgenticActions\OAuth\McpConnection;
use AgenticActions\Pipeline\Authorizer;
use AgenticActions\Pipeline\AuthorizeTiming;
use AgenticActions\Schema\AdvertisedSchema;
use AgenticActions\Schema\RuleCompiler;
use AgenticActions\Schema\SchemaReader;
use AgenticActions\Security\ForbiddenKeys;
use AgenticActions\Security\TokenGrants;
use AgenticActions\Streaming\AgenticConversation;
use AgenticActions\Streaming\AgenticView;
use AgenticActions\Support\Migrations;
use AgenticActions\Support\Packages;
use AgenticActions\Support\PackageStatus;
use AgenticActions\Surface;
use AgenticActions\Tenancy\Tenants;
use AgenticActions\Views\RefreshView;
use AgenticActions\Views\ShowsTable;
use AgenticActions\Views\Table;
use Carbon\CarbonInterval;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Passport;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Laravel\Sanctum\Sanctum;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Every row of actions:check, run against a fresh scan and the routes. It reads the scan, never the registry or the
 * manifest, so it reports exposure errors instead of throwing in any context. A subject's "agent" field says whether a
 * model surface (agents or MCP) opens the action; the model rows read it.
 *
 * @internal
 *
 * @phpstan-type Subject array{entry: Entry, action: Action, reflection: ReflectionClass<Action>, expose: Expose|null, agent: bool, timing: AuthorizeTiming, rules: array<string, mixed>|null, skipped: string|null}
 */
final class Checks
{
    /**
     * The recipe every finding about agent input names.
     */
    private const RECIPE = 'the "Strict agent schemas (no ids)" recipe (https://agentic-actions.com/recipes#strict-agent-schemas-no-ids)';

    /**
     * Create the checks.
     */
    public function __construct(
        private readonly Application $app,
        private readonly Scanner $scanner,
        private readonly Router $router,
        private readonly SchemaReader $reader,
        private readonly AdvertisedSchema $advertised,
        private readonly RuleCompiler $compiler,
        private readonly ForbiddenKeys $forbidden,
        private readonly Packages $packages,
    ) {}

    /**
     * Run every row of 11.3 against a fresh scan and the routes.
     *
     * @return list<Finding>
     */
    public function run(bool $production = false): array
    {
        try {
            $scan = $this->scanner->scan(
                array_values(array_map(strval(...), (array) config('agentic-actions.discovery.paths', []))),
                array_values(array_map(strval(...), (array) config('agentic-actions.discovery.classes', []))),
            );
        } catch (DuplicateActionName $exception) {
            // Without a scan, only the rows that do not read the actions can run.
            return [
                self::fail('Names', $exception->getMessage()),
                ...$this->membership(),
                ...$this->tenantScope(),
                ...$this->abilities(),
                ...$this->cacheStore(),
                ...$this->npm(),
            ];
        }

        $all = $this->subjects($scan);

        // Every other row reads a dataset's schemas, which its declarations build: one whose declarations throw is the
        // Tables & datasets row's alone.
        $subjects = array_values(array_filter($all, fn (array $subject): bool => ! $subject['action'] instanceof Dataset
            || rescue(fn (): bool => $subject['action']->declared() !== [], false, false)));

        /** @var list<Route> $routes */
        $routes = array_values($this->router->getRoutes()->getRoutes());

        return [
            ...$this->snapshot($scan),
            ...$this->exposure($scan),
            ...$this->discovery($scan),
            ...$this->authorization($subjects),
            ...$this->effect($subjects),
            ...$this->membership(),
            ...$this->tenantScope(),
            ...$this->tenantKeys($subjects),
            ...$this->tenantRoutes($scan, $routes),
            ...$this->toolsets($scan, $subjects),
            ...$this->approvals($scan, $subjects),
            ...$this->pageContext($scan),
            ...$this->routes($scan, $routes),
            ...$this->rulesSkipped($subjects),
            ...$this->modelInput($subjects, $routes),
            ...$this->modelOutput($subjects),
            ...$this->ids($subjects),
            ...$this->summary($subjects),
            ...$this->output($subjects),
            ...$this->order($subjects),
            ...$this->schema($subjects),
            ...$this->views($all),
            ...$this->datasets($subjects),
            ...$this->oracles($subjects),
            ...$this->authGroup($routes),
            ...$this->httpOnly($subjects),
            ...$this->abilities(),
            ...$this->mcp($subjects, $routes),
            ...$this->oauth($subjects),
            ...$this->cacheStore($subjects),
            ...$this->tables($scan, $subjects),
            ...$this->npm(),
            ...($production ? $this->manifest($scan) : []),
        ];
    }

    /**
     * What the rows need to know about each action, read once.
     *
     * @return list<Subject>
     */
    private function subjects(Scan $scan): array
    {
        $subjects = [];

        foreach ($scan->actions as $entry) {
            $action = $entry->action();
            $reflection = new ReflectionClass($action);
            $rules = null;
            $skipped = null;

            // The static rows read rules() without an actor or a tenant; an action whose rules() needs one is skipped.
            try {
                $rules = [];

                foreach ($action->rules(ActionContext::system()) as $key => $rule) {
                    $rules[(string) $key] = $rule;
                }
            } catch (MissingContext) {
                [$rules, $skipped] = [null, "{$entry->class}: rules() needs a context; skipped"];
            } catch (Throwable $exception) {
                [$rules, $skipped] = [null, "{$entry->class}: rules() threw ".$exception::class.' with a system() context; skipped'];
            }

            $subjects[] = [
                'entry' => $entry,
                'action' => $action,
                'reflection' => $reflection,
                'expose' => ($reflection->getAttributes(Expose::class)[0] ?? null)?->newInstance(),
                'agent' => $entry->allows(Surface::Agent) || $entry->allows(Surface::Mcp),
                'timing' => Authorizer::timing($action),
                'rules' => $rules,
                'skipped' => $skipped,
            ];
        }

        return $subjects;
    }

    /**
     * Snapshot: the committed file is missing, or differs from the fresh scan. actions:install reads it too.
     *
     * @return list<Finding>
     */
    public function snapshot(Scan $scan): array
    {
        $label = (string) config('agentic-actions.snapshot', 'actions.exposure.json');
        $built = Snapshot::build($scan);

        if (! is_file(Snapshot::path())) {
            return [self::fail('Snapshot', "{$label} is missing: run php artisan actions:check --update")];
        }

        $stored = Snapshot::read();

        if ($stored !== null && Snapshot::encode($stored) === Snapshot::encode($built)) {
            return [];
        }

        return [self::fail('Snapshot', "{$label} is stale: review the change, then run php artisan actions:check --update".self::changes($stored ?? [], $built))];
    }

    /**
     * The added, removed and changed action names between the stored snapshot and the built one.
     *
     * @param  array<string, mixed>  $stored
     * @param  array{version: int, actions: array<string, array<string, mixed>>, agents: array<string, list<string>>}  $built
     */
    private static function changes(array $stored, array $built): string
    {
        $old = is_array($stored['actions'] ?? null) ? $stored['actions'] : [];
        $new = $built['actions'];
        $changed = [];

        foreach (array_intersect_key($new, $old) as $name => $row) {
            if (! is_array($old[$name]) || Snapshot::encode($old[$name]) !== Snapshot::encode($row)) {
                $changed[] = (string) $name;
            }
        }

        $parts = [
            'Added' => array_map(strval(...), array_keys(array_diff_key($new, $old))),
            'Removed' => array_map(strval(...), array_keys(array_diff_key($old, $new))),
            'Changed' => $changed,
        ];

        $text = '';

        foreach ($parts as $label => $names) {
            if ($names !== []) {
                sort($names, SORT_STRING);

                $text .= " {$label}: ".implode(', ', $names).'.';
            }
        }

        $agents = is_array($stored['agents'] ?? null) ? $stored['agents'] : [];

        if (Snapshot::encode(['agents' => $agents]) !== Snapshot::encode(['agents' => $built['agents']])) {
            $text .= ' Agents changed.';
        }

        return $text === '' ? '' : '.'.$text;
    }

    /**
     * Exposure: every surface an action names on purpose that the rules refuse.
     *
     * @return list<Finding>
     */
    private function exposure(Scan $scan): array
    {
        return array_map(fn (string $error): Finding => self::fail('Exposure', $error), $scan->errors());
    }

    /**
     * Discovery: a class under the paths could not be loaded.
     *
     * @return list<Finding>
     */
    private function discovery(Scan $scan): array
    {
        return array_map(fn (string $warning): Finding => self::warn('Discovery', $warning), $scan->warnings);
    }

    /**
     * Authorization: an action without authorize() is denied everywhere.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function authorization(array $subjects): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            if ($subject['timing'] === AuthorizeTiming::Missing) {
                $findings[] = self::fail('Authorization', "{$subject['entry']->class}: authorize() is missing, so every caller is denied. Add authorize(ActionContext \$context), or authorize(ActionContext \$context, ValidatedInput \$input) to check the input.");
            }
        }

        return $findings;
    }

    /**
     * Effect: an action whose $effect is null exposes nothing remote.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function effect(array $subjects): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            if ($subject['entry']->effect === null) {
                $findings[] = self::fail('Effect', "{$subject['entry']->class}: \$effect is undeclared, so nothing remote reaches it. Declare Effect::Read, Effect::Write, Effect::Destructive or Effect::External.");
            }
        }

        return $findings;
    }

    /**
     * Tenancy: a tenant model without a membership check.
     *
     * @return list<Finding>
     */
    private function membership(): array
    {
        if (config('agentic-actions.tenant.model') === null || Tenants::hasMembership()) {
            return [];
        }

        return [self::fail('Tenancy', 'tenant.model is set, but no membership check is: set tenant.membership in config/agentic-actions.php, or call Actions::membershipUsing().')];
    }

    /**
     * Tenancy: a tenant model without a tenant scope. A warning: $context->find() and tenant-scoped datasets refuse
     * with MissingContext until one is set, and an app that calls neither runs without one.
     *
     * @return list<Finding>
     */
    private function tenantScope(): array
    {
        if (config('agentic-actions.tenant.model') === null || Tenants::hasScope()) {
            return [];
        }

        return [self::warn('Tenancy', 'tenant.model is set, but no tenant scope is, so $context->find() and tenant-scoped datasets throw MissingContext on their first call: set tenant.scope in config/agentic-actions.php, or call Actions::scopeUsing() (https://agentic-actions.com/concepts#tenants).')];
    }

    /**
     * Tenancy: a schema() or agentSchema() key named like the tenant, which would let a caller choose it.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function tenantKeys(array $subjects): array
    {
        if (config('agentic-actions.tenant.model') === null) {
            return [];
        }

        $names = array_filter([
            'the tenant parameter' => (string) config('agentic-actions.tenant.parameter'),
            "the tenant model's foreign key" => (string) Tenants::foreignKey(),
        ], fn (string $name): bool => $name !== '');

        $findings = [];

        foreach ($subjects as $subject) {
            $nodes = ['schema()' => $this->reader->input($subject['action'])];

            if ($subject['entry']->hasAgentSchema) {
                $nodes['agentSchema()'] = (array) $this->reader->agentInput($subject['action']);
            }

            foreach ($nodes as $hook => $node) {
                foreach (array_keys((array) ($node['properties'] ?? [])) as $key) {
                    foreach ($names as $what => $name) {
                        if (strcasecmp((string) $key, $name) === 0) {
                            $findings[] = self::fail('Tenancy', "{$subject['entry']->class}: {$hook} declares [{$key}], which is {$what}, so a caller could choose the tenant. The tenant comes from the route: read it with \$context->tenant().");
                        }
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Tenancy: a generated route of a tenant-scoped action whose URI has no tenant parameter.
     *
     * @param  list<Route>  $routes
     * @return list<Finding>
     */
    private function tenantRoutes(Scan $scan, array $routes): array
    {
        if (config('agentic-actions.tenant.model') === null) {
            return [];
        }

        $parameter = (string) config('agentic-actions.tenant.parameter');
        $entries = self::byClass($scan);
        $findings = [];

        foreach (self::generated($routes) as $route) {
            $entry = $entries[(string) $route->getControllerClass()] ?? null;

            if ($entry !== null && $entry->tenantScoped && ! in_array($parameter, $route->parameterNames(), true)) {
                $findings[] = self::fail('Tenancy', "{$entry->class}: the generated route [".self::routeName($route).'] has no {'.$parameter.'} parameter, so the action cannot find its tenant. Mount it with Actions::routes(tenant: true) inside a group whose prefix carries {'.$parameter.'}.');
            }
        }

        return $findings;
    }

    /**
     * Toolsets: a toolset an agent receives that no action joins, an agent whose own tools() leaves its toolsets out,
     * one no agent receives, and one that is too large.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function toolsets(Scan $scan, array $subjects): array
    {
        $members = [];

        foreach ($scan->actions as $entry) {
            foreach ($entry->toolsets as $toolset) {
                $members[$toolset][] = $entry->name;
            }
        }

        $received = array_merge([], ...array_values($scan->agents));
        $findings = [];

        foreach ($scan->agents as $agent => $toolsets) {
            foreach ($toolsets as $toolset) {
                if (! isset($members[$toolset])) {
                    $findings[] = self::fail('Toolsets', "{$agent}: #[UseToolset] names [{$toolset}], which no action joins.");
                }
            }

            if (($wiring = self::toolsWiring($agent)) !== null) {
                $findings[] = $wiring;
            }
        }

        foreach ($subjects as $subject) {
            // A bare #[Expose] joins "default" implicitly; only toolsets named on purpose are checked.
            if ($subject['expose'] === null || $subject['expose']->isBare()) {
                continue;
            }

            foreach ($subject['entry']->toolsets as $toolset) {
                if (! in_array($toolset, $received, true)) {
                    $findings[] = self::warn('Toolsets', "{$subject['entry']->class}: #[Expose(agents: …)] names the [{$toolset}] toolset, which no agent uses.");
                }
            }
        }

        $limit = (int) config('agentic-actions.agents.max_tools_per_toolset', 20);

        ksort($members, SORT_STRING);

        foreach ($members as $toolset => $names) {
            if (count($names) > $limit) {
                $findings[] = self::warn('Toolsets', "The [{$toolset}] toolset holds ".count($names)." actions, more than agents.max_tools_per_toolset ({$limit}). An agent reads every tool on every turn: split the toolset, or raise the limit.");
            }
        }

        return $findings;
    }

    /**
     * How an agent class carrying #[UseToolset] hands its toolsets' actions to laravel/ai: a failure when it uses
     * InteractsWithActions without implementing HasTools, whose tools() is the only one laravel/ai reads; a warning when
     * it does not use the trait, which is what turns the attribute into tools; and a warning when its own tools()
     * replaces the trait's and its source names neither actionTools(), a parent's tools() nor the trait's tools() under
     * an alias. The last is read from the source, so a warning: a tools() that reaches actionTools() through another
     * method is flagged too. A class that is not a laravel/ai agent is skipped.
     */
    private static function toolsWiring(string $agent): ?Finding
    {
        if (! class_exists($agent) || ! is_subclass_of($agent, Agent::class)) {
            return null;
        }

        if (! in_array(InteractsWithActions::class, class_uses_recursive($agent), true)) {
            return self::warn('Toolsets', "{$agent}: it carries #[UseToolset] but does not use InteractsWithActions, which turns its toolsets into tools, so the attribute alone gives it none of its toolsets' actions. Add use InteractsWithActions; and an actionContext() (https://agentic-actions.com/copilot#the-server).");
        }

        if (! is_subclass_of($agent, HasTools::class)) {
            return self::fail('Toolsets', "{$agent}: it uses InteractsWithActions but does not implement Laravel\\Ai\\Contracts\\HasTools, so laravel/ai never asks it for its tools and none of its toolsets' actions reach the model. Add implements HasTools to the class.");
        }

        $method = new ReflectionMethod($agent, 'tools');

        if (($file = $method->getFileName()) === false) {
            return null;
        }

        $lines = array_slice(file($file) ?: [], $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $source = implode('', $lines);

        foreach (['actionTools', 'parent::tools', ...self::traitAliases($agent)] as $call) {
            if (str_contains($source, "{$call}(")) {
                return null;
            }
        }

        return self::warn('Toolsets', "{$agent}: its own tools() replaces the one InteractsWithActions gives it, and its source calls neither \$this->actionTools() nor the trait's tools(), so its toolsets' actions may never reach the model. Delete the tools() it declares, or merge the package's tools into it: return [...\$this->actionTools(), ...].");
    }

    /**
     * The names an agent class or its parents give InteractsWithActions' tools() and actionTools() in a trait alias,
     * such as packageTools in `use InteractsWithActions { tools as packageTools; }`.
     *
     * @param  class-string  $agent
     * @return list<string>
     */
    private static function traitAliases(string $agent): array
    {
        $aliases = [];

        for ($class = new ReflectionClass($agent); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getTraitAliases() as $alias => $original) {
                if (in_array($original, [InteractsWithActions::class.'::tools', InteractsWithActions::class.'::actionTools'], true)) {
                    $aliases[] = $alias;
                }
            }
        }

        return $aliases;
    }

    /**
     * Approvals: an agent whose toolsets hold actions that wait on the person (a confirmation, or a form for the
     * fields a call left out), but that does not store its conversations, so it is never offered the first kind and
     * never asks for the second (safe at runtime: those calls are refused, so a warning); and a conversation store that
     * cannot read which calls wait or whom a conversation belongs to, so no agent can wait on the person at all.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function approvals(Scan $scan, array $subjects): array
    {
        if (($waiting = self::confirmed($subjects, asking: true)) === []) {
            return [];
        }

        $findings = [];

        foreach ($scan->agents as $agent => $toolsets) {
            $held = array_filter($waiting, fn (array $subject): bool => array_intersect($subject['entry']->toolsets, $toolsets) !== []);

            if ($held !== [] && ! ConfirmingAgents::classSupports($agent)) {
                $findings[] = self::warn('Approvals', "{$agent}: its toolsets hold [".self::names($held).'], which wait on the person (a confirmation, or a form for missing fields), but it does not store its conversations, so it is never offered the first kind and never asks for the second: those calls are refused instead. Implement Laravel\\Ai\\Contracts\\Conversational and use Laravel\\Ai\\Concerns\\RemembersConversations.');
            }
        }

        if (! ConfirmingAgents::storeSupports()) {
            $findings[] = self::fail('Approvals', 'The conversation store bound for laravel/ai cannot read which calls a conversation is waiting on, or whom it belongs to, so no agent can wait on the person for ['.self::names($waiting).']. Keep laravel/ai\'s database store, or implement ResolvesPendingApprovals and VerifiesConversationOwnership on yours.');
        }

        return $findings;
    }

    /**
     * Page context: an agent carrying #[WithPageContext] without Inertia for production, or without the middleware
     * wiring that makes the attribute take effect.
     *
     * @return list<Finding>
     */
    private function pageContext(Scan $scan): array
    {
        $findings = [];

        foreach ($scan->pageContext as $agent) {
            if ($this->packages->status('inertiajs/inertia-laravel') !== PackageStatus::Installed) {
                $findings[] = self::fail('Page context', "{$agent}: #[WithPageContext] needs Inertia, and inertiajs/inertia-laravel is not installed for production, so the page never reaches the agent. Remove the attribute, or composer require inertiajs/inertia-laravel.");
            }

            if (! is_subclass_of($agent, HasMiddleware::class) || ! in_array(InteractsWithActions::class, class_uses_recursive($agent), true)) {
                $findings[] = self::fail('Page context', "{$agent}: #[WithPageContext] takes effect only on an agent that uses InteractsWithActions and implements Laravel\\Ai\\Contracts\\HasMiddleware, with middleware() returning [...\$this->actionMiddleware()].");
            }
        }

        return $findings;
    }

    /**
     * Routes: a cached route set older than the actions, and an action with a generated route and a hand-written one.
     *
     * @param  list<Route>  $routes
     * @return list<Finding>
     */
    private function routes(Scan $scan, array $routes): array
    {
        $entries = self::byClass($scan);
        $generated = self::generated($routes);
        $findings = [];

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            $served = [];

            foreach ($generated as $route) {
                $class = (string) $route->getControllerClass();
                $served[$class] = true;

                if (! ($entries[$class] ?? null)?->allows(Surface::Http)) {
                    $findings[] = self::fail('Routes', 'The cached route ['.self::routeName($route)."] serves {$class}, which the actions on disk no longer open on the web: run php artisan route:cache again.");
                }
            }

            if ($generated !== []) {
                foreach ($entries as $class => $entry) {
                    if ($entry->allows(Surface::Http) && ! isset($served[$class])) {
                        $findings[] = self::fail('Routes', "{$class} opens on the web but has no cached generated route: run php artisan route:cache again.");
                    }
                }
            }
        }

        foreach ($entries as $class => $entry) {
            $own = array_filter($routes, fn (Route $route): bool => $route->getControllerClass() === $class);
            $handWritten = array_filter($own, fn (Route $route): bool => $route->getAction(ActionRequest::GENERATED) !== true);

            if ($handWritten !== [] && count($handWritten) < count($own)) {
                $findings[] = self::fail('Routes', "{$class} has a generated route and a hand-written one [".implode(', ', array_map(self::routeName(...), $handWritten)).']: keep one.');
            }
        }

        return $findings;
    }

    /**
     * The warning for an action whose rules() could not run with a system() context, under the first row it skips.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function rulesSkipped(array $subjects): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            if ($subject['skipped'] !== null) {
                $findings[] = self::warn($subject['agent'] ? 'Model input' : 'Oracles', $subject['skipped']);
            }
        }

        return $findings;
    }

    /**
     * Model input: an advertised key an agent may never see, and a rules()-only key an agent can never send.
     *
     * @param  list<Subject>  $subjects
     * @param  list<Route>  $routes
     * @return list<Finding>
     */
    private function modelInput(array $subjects, array $routes): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            if (! $subject['agent']) {
                continue;
            }

            $class = $subject['entry']->class;
            $own = [];

            foreach ($routes as $route) {
                if ($route->getControllerClass() === $class) {
                    $own[self::routeName($route)] = $route->parameterNames();
                }
            }

            $node = $this->advertisedNode($subject['action']);

            foreach ($this->forbidden->inInput($node, array_values(array_unique(array_merge([], ...array_values($own))))) as $path) {
                $reason = $this->forbiddenReason(Str::afterLast($path, '.'), $own);

                $findings[] = self::fail('Model input', "{$class}: agents cannot be offered input [{$path}]: it {$reason}. Remove the key from schema(), or follow ".self::RECIPE.': agentSchema() plus fromAgent().');
            }

            if ($subject['entry']->hasAgentSchema || $subject['rules'] === null) {
                continue;
            }

            $declared = array_map(strval(...), array_keys((array) ($this->reader->input($subject['action'])['properties'] ?? [])));
            $keys = array_unique(array_map(fn (string $key): string => Str::before($key, '.'), array_keys($subject['rules'])));

            foreach ($keys as $key) {
                if (! in_array($key, $declared, true)) {
                    $findings[] = self::fail('Model input', "{$class}: rules() checks [{$key}], which schema() does not declare, so agents can never send it. Declare it in schema(), drop the rule, or follow ".self::RECIPE.': agentSchema() plus fromAgent().');
                }
            }
        }

        return $findings;
    }

    /**
     * Why a forbidden key is forbidden, in ForbiddenKeys' own order: a configured pattern, a route parameter, the
     * tenant parameter, the tenant model's foreign key.
     *
     * @param  array<string, list<string>>  $routes  route name or URI => parameters
     */
    private function forbiddenReason(string $name, array $routes): string
    {
        foreach (array_map(strval(...), (array) config('agentic-actions.agents.forbidden_keys', [])) as $pattern) {
            if (Str::is(strtolower($pattern), strtolower($name))) {
                return "matches agents.forbidden_keys pattern \"{$pattern}\"";
            }
        }

        foreach ($routes as $route => $parameters) {
            foreach ($parameters as $parameter) {
                if (strcasecmp($name, $parameter) === 0) {
                    return "is a route parameter of {$route}";
                }
            }
        }

        $foreignKey = Tenants::foreignKey();

        return match (true) {
            strcasecmp($name, (string) config('agentic-actions.tenant.parameter')) === 0 => 'is the tenant parameter',
            $foreignKey !== null && strcasecmp($name, $foreignKey) === 0 => "is the tenant model's foreign key",
            default => 'is forbidden',
        };
    }

    /**
     * Model output: an output key agents may never receive.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function modelOutput(array $subjects): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            if (! $subject['agent']) {
                continue;
            }

            // A table's own keys are the package's; only its columns are data.
            foreach ($this->forbidden->inOutput($subject['entry']->shows() ? Table::rowNode($this->outputNode($subject)) : $this->outputNode($subject)) as $path) {
                $findings[] = self::fail('Model output', "{$subject['entry']->class}: agents cannot receive output [{$path}]: it matches agents.forbidden_output_keys. ".($subject['entry']->shows() ? 'Remove the column from columns().' : 'Remove the key from outputSchema().'));
            }

            // A dataset's values come from the columns its dimensions and measures read, whatever their names.
            foreach ($subject['action'] instanceof Dataset ? $this->forbidden->inOutput(self::readNode($subject['action'])) : [] as $path) {
                $findings[] = self::fail('Model output', "{$subject['entry']->class}: agents cannot receive the column [{$path}] a dimension or measure reads: it matches agents.forbidden_output_keys. Remove it from dimensions() or measures().");
            }
        }

        return $findings;
    }

    /**
     * Ids: an id offered to agents with no input-aware authorize(), and an unscoped exists or unique on an id.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function ids(array $subjects): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            if (! $subject['agent']) {
                continue;
            }

            $class = $subject['entry']->class;

            if (! $subject['timing']->takesInput()) {
                foreach ($this->forbidden->idShaped($this->advertisedNode($subject['action'])) as $path) {
                    $findings[] = self::fail('Ids', "{$class}: agents are offered [{$path}], which looks like an id, and authorize() does not take ValidatedInput, so the record is never checked before handle(). Check it in authorize(ActionContext \$context, ValidatedInput \$input), or follow ".self::RECIPE.': agentSchema() plus fromAgent().');
                }
            }

            foreach ($this->databaseRules($subject['rules'] ?? []) as [$key, $kind, $scoped]) {
                if (! $scoped && $this->isIdShaped($key)) {
                    $findings[] = self::fail('Ids', "{$class}: rules() checks [{$key}] with an unscoped {$kind} rule, which tells any caller whether a record exists in any tenant. Scope it to the actor or tenant (Rule::exists(...)->where(...)), or follow ".self::RECIPE.': agentSchema() plus fromAgent().');
                }
            }
        }

        return $findings;
    }

    /**
     * Summary: an action a person confirms that agents are offered input for, whose card cannot say what the call acts
     * on (no approvalSummary()), or could show a record authorize() never checked (an input-free authorize()). Key
     * presence counts, whatever the key is named.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function summary(array $subjects): array
    {
        $findings = [];

        foreach (self::confirmed($subjects) as $subject) {
            $keys = array_map(strval(...), array_keys((array) ($this->advertisedNode($subject['action'])['properties'] ?? [])));

            if ($keys === []) {
                continue;
            }

            $offered = "{$subject['entry']->class}: agents are offered [".implode(', ', $keys).'], and a person confirms this action on a card, but ';

            if (! self::overrides($subject['reflection'], 'approvalSummary')) {
                $findings[] = self::fail('Summary', $offered.'approvalSummary() is not written, so the card cannot say what the call acts on. Build it from the validated input and the record authorize() allowed (https://agentic-actions.com/copilot#confirmations).');
            }

            if (! $subject['timing']->takesInput()) {
                $findings[] = self::fail('Summary', $offered.'authorize() does not take ValidatedInput, so the card could show a record the person may not read. Check it in authorize(ActionContext $context, ValidatedInput $input).');
            }
        }

        return $findings;
    }

    /**
     * Output: a non-empty outputSchema() that marks no key required.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function output(array $subjects): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            $node = $this->outputNode($subject);

            if ((array) ($node['properties'] ?? []) !== [] && (array) ($node['required'] ?? []) === []) {
                $findings[] = self::fail('Output', "{$subject['entry']->class}: outputSchema() marks no key required, so a caller cannot rely on any key being there. Mark the keys handle() always returns with required().");
            }
        }

        return $findings;
    }

    /**
     * Order: fromAgent() runs before an input-taking authorize(), with nothing in front of it.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function order(array $subjects): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            $reflection = $subject['reflection'];

            if ($subject['agent']
                && self::overrides($reflection, 'fromAgent')
                && $subject['timing'] === AuthorizeTiming::Late
                && ! self::overrides($reflection, 'shouldRegister')) {
                $findings[] = self::fail('Order', "{$subject['entry']->class}: fromAgent() runs before authorize(), which takes ValidatedInput, and shouldRegister() is not overridden, so any caller who reaches the tool runs fromAgent() unchecked. Override shouldRegister(), or let authorize() take ?ValidatedInput \$input = null and check the caller when \$input is null, refusing everyone who may not use the action: that call runs before fromAgent(), and returning true there checks no one.");
            }
        }

        return $findings;
    }

    /**
     * Schema: a schema the rule compiler refuses, and a file field offered to agents.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function schema(array $subjects): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            $class = $subject['entry']->class;
            $input = $this->reader->input($subject['action']);

            // Keys rules() owns may use shapes the compiler refuses; when rules() could not run, any key may be one.
            $owned = $subject['rules'] === null
                ? array_map(strval(...), array_keys((array) ($input['properties'] ?? [])))
                : array_values(array_unique(array_map(fn (string $key): string => Str::before($key, '.'), array_keys($subject['rules']))));

            $nodes = [['schema()', $input, Surface::Http, $owned]];

            if ($subject['entry']->hasAgentSchema) {
                $nodes[] = ['agentSchema()', (array) $this->reader->agentInput($subject['action']), Surface::Agent, []];
            }

            foreach ($nodes as [$hook, $node, $surface, $keys]) {
                try {
                    $this->compiler->compile($node, $surface, $keys);
                } catch (UnsupportedSchema $exception) {
                    $findings[] = self::fail('Schema', "{$class}: {$hook}: {$exception->getMessage()}");
                }
            }

            if (! $subject['agent']) {
                continue;
            }

            foreach (self::binaryPaths($this->advertisedNode($subject['action'])) as $path) {
                $findings[] = self::fail('Schema', "{$class}: agents cannot send the file field [{$path}]. Give agents an agentSchema() without it, following ".self::RECIPE.'.');
            }
        }

        return $findings;
    }

    /**
     * Tables & datasets: a table's columns() the package cannot show (a key that is not snake case or comes twice, a
     * currency that is not ISO 4217, decimals out of range, no column) or that throws otherwise, such as a dataset's
     * measure whose where() PHP refuses, and a ShowsTable action that is not a Read, which shows no table.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function views(array $subjects): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            $entry = $subject['entry'];

            if (! $subject['action'] instanceof ShowsTable) {
                continue;
            }

            if (! $entry->shows()) {
                $findings[] = self::fail('Tables & datasets', "{$entry->class}: it implements ShowsTable, but its effect is ".($entry->effect->value ?? 'undeclared').', so it shows no table: a table is a Read action\'s. Declare Effect::Read, or remove ShowsTable.');

                continue;
            }

            try {
                Table::columns($subject['action']);
            } catch (InvalidArgumentException $exception) {
                $findings[] = self::fail('Tables & datasets', $exception->getMessage());
            } catch (Throwable $exception) {
                // Table::columns() names the class in its own refusals only.
                $findings[] = self::fail('Tables & datasets', "{$entry->class}: ".Str::finish($exception->getMessage(), '.'));
            }
        }

        return $findings;
    }

    /**
     * Tables & datasets, for datasets: one that is not tenant-scoped and declares no scope(), which outside a tenant
     * counts every tenant's rows; a relation it reads in the tenant's scope that the scope refuses, which fails it
     * inside a tenant; a connection whose driver datasets do not support; and one that sets no statement
     * time limit, so a question runs as long as it takes (SQLite never sets one). A connection the check cannot read
     * reports nothing.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function datasets(array $subjects): array
    {
        [$connections, $findings] = [[], []];

        foreach ($subjects as $subject) {
            $dataset = $subject['action'];

            if (! $dataset instanceof Dataset || ! $subject['entry']->shows()) {
                continue;
            }

            if (config('agentic-actions.tenant.model') !== null && ! $subject['entry']->tenantScoped
                && (new ReflectionClass($dataset))->getMethod('scope')->getDeclaringClass()->getName() === Dataset::class) {
                $findings[] = self::warn('Tables & datasets', "{$subject['entry']->class}: it is not tenant-scoped and declares no scope(), so a call outside a tenant, such as an MCP client on the account's URL, counts every row of [{$dataset->model()}], whichever tenant it belongs to. Add a scope() that keeps the rows its callers may count, or leave it tenant-scoped.");
            }

            foreach (config('agentic-actions.tenant.model') === null ? [] : self::refusedRelations($dataset) as $relation => $reason) {
                $findings[] = self::fail('Tables & datasets', "{$subject['entry']->class}: inside a tenant it reads [{$relation}] in the tenant's scope, which refuses it ({$reason}). Name it in \$shared if every tenant shares those rows, or let the tenant scope scope them.");
            }

            $name = config('agentic-actions.datasets.connection') ?: (new ($dataset->model()))->getConnectionName() ?: config('database.default');
            $connections[(string) $name][] = $subject;
        }

        foreach ($connections as $name => $datasets) {
            $driver = config("database.connections.{$name}.driver");
            $on = 'The datasets ['.self::names($datasets)."] run on the connection [{$name}]";

            if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
                $findings[] = self::fail('Tables & datasets', "{$on}, whose driver [{$driver}] datasets do not support: use SQLite, MySQL, MariaDB or Postgres.");

                continue;
            }

            $limit = $driver === 'sqlite' ? 'SQLite sets none' : rescue(fn (): ?string => self::timeLimit(DB::connection($name)), null, false);

            if (is_string($limit)) {
                $findings[] = self::warn('Tables & datasets', "{$on}, which has no statement time limit ({$limit}), so a question runs as long as it takes. Give it one (https://agentic-actions.com/data#give-the-datasets-connection-a-time-limit).");
            }
        }

        return $findings;
    }

    /**
     * The setting that leaves a connection with no statement time limit, such as "@@max_execution_time is 0", or null
     * when it has one.
     */
    private static function timeLimit(Connection $connection): ?string
    {
        [$setting, $query] = match (true) {
            $connection->getDriverName() === 'pgsql' => ['statement_timeout', 'show statement_timeout'],
            $connection->getDriverName() === 'mariadb' || ($connection instanceof MySqlConnection && $connection->isMaria()) => ['@@max_statement_time', 'select @@max_statement_time'],
            default => ['@@max_execution_time', 'select @@max_execution_time'],
        };

        $value = $connection->scalar($query);

        return is_numeric($value) && (float) $value === 0.0 ? "{$setting} is 0" : null;
    }

    /**
     * The columns a dataset's dimensions and measures read, as an object node whose property paths end in each
     * column's last segment.
     *
     * @return array<string, mixed>
     */
    private static function readNode(Dataset $dataset): array
    {
        return ['type' => 'object', 'properties' => array_fill_keys($dataset->columnsRead(), ['type' => 'string'])];
    }

    /**
     * Oracles: every exists or unique rule the Ids row did not already fail, since each tells a caller who reaches
     * the action whether a record exists.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function oracles(array $subjects): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            foreach ($this->databaseRules($subject['rules'] ?? []) as [$key, $kind, $scoped]) {
                if ($subject['agent'] && ! $scoped && $this->isIdShaped($key)) {
                    continue;
                }

                $findings[] = self::warn('Oracles', "{$subject['entry']->class}: rules() checks [{$key}] with an {$kind} rule, so any caller who reaches the action learns whether a matching record exists.");
            }
        }

        return $findings;
    }

    /**
     * Auth group: a generated route mounted outside any auth middleware.
     *
     * @param  list<Route>  $routes
     * @return list<Finding>
     */
    private function authGroup(array $routes): array
    {
        $findings = [];

        foreach (self::generated($routes) as $route) {
            if (! $this->authenticates($route)) {
                $findings[] = self::warn('Auth group', 'The generated route ['.self::routeName($route).'] has no auth middleware. It still refuses guests, but mount Actions::routes() inside your own auth group, such as Route::middleware(\'auth\').');
            }
        }

        return $findings;
    }

    /**
     * Whether a route's middleware names auth, or resolves to Laravel's Authenticate middleware.
     */
    private function authenticates(Route $route): bool
    {
        $gathered = array_filter($route->gatherMiddleware(), is_string(...));

        try {
            $resolved = array_filter($this->router->resolveMiddleware($gathered), is_string(...));
        } catch (Throwable) {
            $resolved = [];
        }

        foreach ($gathered as $middleware) {
            if (str_starts_with($middleware, 'auth')) {
                return true;
            }
        }

        foreach ([...$gathered, ...$resolved] as $middleware) {
            if (is_a(Str::before($middleware, ':'), Authenticate::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * HTTP-only: Laravel 13's controller attributes on an agent-exposed class, which only HTTP runs.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function httpOnly(array $subjects): array
    {
        // Both attributes are Laravel 13's; each is named only behind its own class_exists() check.
        $attributes = [];

        if (class_exists(Middleware::class)) {
            $attributes['#[Middleware]'] = Middleware::class;
        }

        if (class_exists(Authorize::class)) {
            $attributes['#[Authorize]'] = Authorize::class;
        }

        $findings = [];

        foreach ($subjects as $subject) {
            if (! $subject['agent']) {
                continue;
            }

            foreach ($attributes as $label => $attribute) {
                if ($subject['reflection']->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                    $findings[] = self::warn('HTTP-only', "{$subject['entry']->class}: {$label} runs on HTTP only, so agents never pass through it. Check the same thing in authorize() or shouldRegister().");
                }
            }
        }

        return $findings;
    }

    /**
     * Abilities: two effects sharing a token ability, an ability the package does not accept, and a tenant prefix that
     * is empty or that an effect's ability starts with.
     *
     * @return list<Finding>
     */
    private function abilities(): array
    {
        $abilities = [];

        foreach (['read', 'write', 'destructive', 'external'] as $effect) {
            $abilities[$effect] = (string) config("agentic-actions.abilities.{$effect}");
        }

        $prefix = (string) config('agentic-actions.abilities.tenant');
        $findings = [];

        foreach (array_count_values($abilities) as $ability => $count) {
            if ($count > 1) {
                $findings[] = self::fail('Abilities', "Two effects share the token ability [{$ability}] in agentic-actions.abilities, so a token for one reaches the other. Give each effect its own ability.");
            }
        }

        if ($prefix === '') {
            $findings[] = self::fail('Abilities', 'agentic-actions.abilities.tenant is empty, so no token can be bound to one tenant and every tenant:… ability is ignored. Set it back to tenant: or another prefix of your own.');
        }

        foreach ($abilities as $effect => $ability) {
            if (in_array($ability, [Registrar::OAUTH_SCOPE, '*'], true)) {
                $findings[] = self::fail('Abilities', "The ability for {$effect} is [{$ability}], which the package does not accept: * never counts inside MCP, and mcp:use is the MCP OAuth scope. Use a name of your own, such as actions:{$effect}.");
            } elseif ($prefix !== '' && str_starts_with($ability, $prefix)) {
                $findings[] = self::fail('Abilities', "The ability for {$effect} is [{$ability}], which starts with the tenant prefix [{$prefix}] and would read as a tenant binding. Use a name of your own, such as actions:{$effect}.");
            }
        }

        return $findings;
    }

    /**
     * MCP guard, MCP route and Token routes, when some action allows MCP. They read config, McpMount::unmounted() and
     * the final route collection, never mount().
     *
     * @param  list<Subject>  $subjects
     * @param  list<Route>  $routes
     * @return list<Finding>
     */
    private function mcp(array $subjects, array $routes): array
    {
        $open = array_filter($subjects, fn (array $subject): bool => $subject['entry']->allows(Surface::Mcp));

        if ($open === []) {
            return [];
        }

        $tenanted = config('agentic-actions.tenant.model') !== null;
        $parameter = (string) config('agentic-actions.tenant.parameter');
        $reasons = [false => McpMount::unmounted(false), true => $tenanted ? McpMount::unmounted(true) : null];
        $findings = [];
        $drivers = array_map(fn (string $guard): mixed => config("auth.guards.{$guard}.driver"), McpMount::guards());

        foreach (McpMount::guards() as $guard) {
            if (config('agentic-actions.surfaces.mcp') && config("auth.guards.{$guard}.driver") === 'session') {
                $findings[] = self::fail('MCP guard', "The MCP guard [{$guard}] uses the session driver. MCP clients send tokens, and inside MCP a session grants nothing: name a token guard in agentic-actions.mcp.middleware.");
            }

            // Sanctum reads a token only for a model on its trait, which also serves a Passport guard, so a Passport
            // guard beside a Sanctum one is left to the Sanctum guard's line; a model on Passport's trait is the
            // Passport-only setup. A guard without a provider reads the users provider's model.
            $model = config('auth.providers.'.(config("auth.guards.{$guard}.provider") ?? 'users').'.model');
            $package = match (config("auth.guards.{$guard}.driver")) {
                'sanctum' => 'Sanctum',
                'passport' => in_array('sanctum', $drivers, true) ? null : 'Passport',
                default => null,
            };

            if (config('agentic-actions.surfaces.mcp') && $package !== null && is_string($model) && class_exists($model)
                && array_intersect([HasApiTokens::class, 'Laravel\Passport\HasApiTokens'], class_uses_recursive($model)) === []) {
                $findings[] = self::warn('MCP guard', "The MCP guard [{$guard}] reads {$package} tokens, but its user model does not use {$package}'s HasApiTokens, so no token signs anyone in. Add this line inside {$model}: use \\Laravel\\{$package}\\HasApiTokens;");
            }
        }

        foreach ($open as $subject) {
            $class = $subject['entry']->class;
            $reason = $reasons[$tenanted && $subject['entry']->tenantScoped];

            // A bare #[Expose] skips quietly here, as ExposureRule does; actions:list still says why nothing mounts.
            if ($subject['expose']?->mcp === true && $reason === 'mcp.middleware names no configured guard') {
                $findings[] = self::warn('MCP guard', "{$class} names mcp: true, but agentic-actions.mcp.middleware names no guard that auth.guards defines, so MCP is not mounted. Add auth:{guard} for a token guard (auth:sanctum after php artisan install:api).");
            }

            if ($reason === 'agentic-actions.mcp.tenant_path is null') {
                $findings[] = self::warn('MCP route', "{$class} is tenant-scoped and allows MCP, but agentic-actions.mcp.tenant_path is null, so no MCP client can reach it. Set a tenant path such as mcp/t/{{$parameter}}.");
            }
        }

        foreach ($tenanted ? [false, true] : [false] as $scope) {
            $path = (string) config('agentic-actions.mcp.'.($scope ? 'tenant_path' : 'path'));

            if ($reasons[$scope] === "agentic-actions.mcp.tenant_path has no {{$parameter}}") {
                $findings[] = self::fail('MCP route', "agentic-actions.mcp.tenant_path [{$path}] must contain {{$parameter}}, the tenant route parameter.");
            }

            if ($reasons[$scope] === null) {
                array_push($findings, ...self::mcpRoute(trim($path, '/'), $routes));
            }
        }

        return [...$findings, ...$this->tokenRoutes($routes)];
    }

    /**
     * MCP route, for a path that should mount: nothing there, or routes there that are not the package's.
     *
     * @param  list<Route>  $routes
     * @return list<Finding>
     */
    private static function mcpRoute(string $uri, array $routes): array
    {
        $held = array_filter($routes, fn (Route $route): bool => $route->uri() === $uri && array_intersect(['GET', 'HEAD', 'POST', 'DELETE'], $route->methods()) !== []);

        if ($held === []) {
            return [self::warn('MCP route', "Nothing answers POST [{$uri}]. After changing MCP settings, run php artisan route:cache again and reload PHP-FPM.")];
        }

        // The package's POST route carries the mark; Mcp::web() adds the GET and DELETE routes beside it.
        if (array_filter($held, fn (Route $route): bool => $route->getAction(McpMount::MARK) === true) !== []) {
            return [];
        }

        return array_values(array_map(
            fn (Route $route): Finding => self::fail('MCP route', 'The route ['.implode('|', $route->methods())." {$uri}] is not the package's MCP server, so the package did not mount there. Move that route, or set agentic-actions.mcp.path (or tenant_path)."),
            $held,
        ));
    }

    /**
     * Token routes: an app route that authenticates with an MCP token guard and checks no ability, which an MCP token
     * reaches too. The package's own routes are left out: actions check abilities themselves, and the feed refuses
     * tokens. A session guard is the MCP guard row's failure, not this row's.
     *
     * @param  list<Route>  $routes
     * @return list<Finding>
     */
    private function tokenRoutes(array $routes): array
    {
        // With OAuth on, every Passport guard takes the connector's token, whether mcp.middleware names it or not.
        $passport = McpMount::oauth() ? array_keys(array_filter((array) config('auth.guards'), fn (mixed $guard): bool => is_array($guard) && ($guard['driver'] ?? null) === 'passport')) : [];
        $guards = array_values(array_unique([...array_filter(McpMount::guards(), fn (string $guard): bool => config("auth.guards.{$guard}.driver") !== 'session'), ...$passport]));
        $open = $this->router->has(Discovery::METADATA);

        if (McpMount::guard() === null || $guards === []) {
            return [];
        }

        $findings = [];

        foreach ($routes as $route) {
            $controller = (string) $route->getControllerClass();

            // The change feed and the tables' refresh answer sessions only, and refuse a token themselves.
            if ($route->getAction(McpMount::MARK) === true || is_a($controller, Action::class, true) || in_array($controller, [ChangesController::class, RefreshView::class], true)) {
                continue;
            }

            // A Passport guard's route fails while registration is open to any client.
            if (($guard = $this->unscopedGuard($route, $guards)) !== null) {
                $oauth = config("auth.guards.{$guard}.driver") === 'passport';
                $findings[] = self::{$oauth && $open ? 'fail' : 'warn'}('Token routes', 'The route ['.implode('|', $route->methods()).' '.$route->uri()."] authenticates with the MCP guard [{$guard}] and checks no ability, so an MCP token reaches it too. Add ".($oauth ? 'scope:… or scopes:…' : 'abilities:…').' middleware, or move it off that guard.');
            }
        }

        return $findings;
    }

    /**
     * The MCP guard a route authenticates with, from its resolved middleware, unless the route checks a token ability.
     *
     * @param  array<int, string>  $guards
     */
    private function unscopedGuard(Route $route, array $guards): ?string
    {
        try {
            $middleware = array_filter($this->router->gatherRouteMiddleware($route), is_string(...));
        } catch (Throwable) {
            return null;
        }

        $guard = null;

        foreach ($middleware as $item) {
            [$class, $parameters] = array_pad(explode(':', $item, 2), 2, '');

            // Sanctum's and Passport's classes are compared as strings, so the row runs without them; an alias left
            // unresolved counts.
            if (is_a($class, CheckAbilities::class, true) || is_a($class, CheckForAnyAbility::class, true) || in_array($class, ['abilities', 'ability', 'scopes', 'scope'], true)
                || is_a($class, 'Laravel\Passport\Http\Middleware\CheckToken', true) || is_a($class, 'Laravel\Passport\Http\Middleware\CheckTokenForAnyScope', true)) {
                return null;
            }

            if ($guard === null && is_a($class, Authenticate::class, true)) {
                $named = $parameters === '' ? [(string) config('auth.defaults.guard')] : explode(',', $parameters);
                $guard = array_values(array_intersect($named, $guards))[0] ?? null;
            }
        }

        return $guard;
    }

    /**
     * OAuth, when some action allows MCP and Passport is installed, with OAuth on or Mcp::oauthRoutes() registered:
     * what stops a remote client from connecting, or connects it more widely than the consent screen says.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function oauth(array $subjects): array
    {
        $routes = $this->router->has(Discovery::METADATA);

        if (array_filter($subjects, fn (array $subject): bool => $subject['entry']->allows(Surface::Mcp)) === [] || $this->packages->passport() === PackageStatus::Missing || (! McpMount::oauth() && ! $routes)) {
            return [];
        }

        if (! McpMount::oauth()) {
            return [self::warn('OAuth', 'Mcp::oauthRoutes() lets clients sign in, but agentic-actions.mcp.middleware names no Passport guard, so the package\'s paths take no OAuth token. Add it after Sanctum\'s: auth:sanctum,api.')];
        }

        $drivers = array_map(fn (string $guard): mixed => config("auth.guards.{$guard}.driver"), McpMount::guards());
        [$passport, $sanctum] = [array_search('passport', $drivers, true), array_search('sanctum', $drivers, true)];
        $lifetime = Passport::tokensExpireIn();
        $reader = $this->app->make(ReadsTokenGrants::class);
        $shadowed = [];

        foreach ([McpMount::ROUTE, McpMount::TENANT_ROUTE] as $name) {
            $path = $this->router->getRoutes()->getByName($name)?->uri();
            $answer = $path === null ? null : rescue(fn () => $this->router->getRoutes()->match(Request::create('/.well-known/oauth-protected-resource/'.preg_replace('/\{[^}]+\}/', '1', $path))), null, false);

            if ($answer instanceof Route && ($answer->getName() !== Discovery::METADATA || ! in_array(Discovery::class, $answer->middleware(), true))) {
                $shadowed[] = self::fail('OAuth', 'The route [GET '.$answer->uri()."] answers the resource metadata of [{$path}] before the package, so clients never learn its scopes. Move that route.");
            }
        }

        return [
            ...(array_filter(['private', 'public'], fn (string $type): bool => (string) config("passport.{$type}_key") === '' && ! is_readable(Passport::keyPath("oauth-{$type}.key"))) === [] ? [] : [self::fail('OAuth', 'Passport has no keys, so no client can get a token. Run php artisan passport:keys.')]),
            ...(is_int($passport) && is_int($sanctum) && $passport < $sanctum ? [self::fail('OAuth', 'agentic-actions.mcp.middleware tries ['.McpMount::guards()[$passport].'] before ['.McpMount::guards()[$sanctum].'], so every Sanctum token answers 401. List Sanctum first: auth:sanctum,api.')] : []),
            ...($reader instanceof TokenGrants ? [] : [self::fail('OAuth', '['.$reader::class.'] replaces the package\'s token reader, which keeps each OAuth connection to the URL and tenant the person approved. Remove that binding, or take the Passport guard out of agentic-actions.mcp.middleware.')]),
            ...$shadowed,
            ...($routes ? [] : [self::warn('OAuth', "The MCP paths read Passport tokens, but no client can discover how to sign in. In routes/ai.php: Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes());")]),
            ...($this->router->has('login') ? [] : [self::warn('OAuth', 'No route is named login, so a signed-out person has nowhere to sign in before approving a client.')]),
            ...((new \DateTime('@0'))->add($lifetime)->getTimestamp() <= 86400 ? [] : [self::warn('OAuth', 'Passport\'s access tokens last ['.CarbonInterval::instance($lifetime)->forHumans().'], so a leaked connector token works that long. In AppServiceProvider::boot(): Passport::tokensExpireIn(CarbonInterval::hour()); it applies to every Passport client.')]),
            ...($this->app->environment('local', 'testing') || ! in_array('*', (array) config('mcp.redirect_domains', []), true) ? [] : [self::warn('OAuth', 'config/mcp.php redirect_domains accepts any site, so any site can register as a client. List the ones you allow (https://agentic-actions.com/mcp#harden-the-oauth-setup).')]),
        ];
    }

    /**
     * Cache store: outside local development and tests, the change feed on a store that forgets its entries, keeps
     * them per visitor, or keeps them on one server; and confirmations and forms on any store whose add() is not atomic
     * and shared by every server (an allowlist); on a store without locks every answer is refused.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function cacheStore(array $subjects = []): array
    {
        if ($this->app->environment('local', 'testing')) {
            return [];
        }

        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver");

        $feed = ! config('agentic-actions.feed.enabled') ? null : match ($driver) {
            'array', 'null' => "The change feed keeps touches in the [{$store}] cache store, which forgets them after each request, so open pages never hear of writes made elsewhere. Use a shared store such as database or redis.",
            'session' => "The change feed keeps touches in the [{$store}] cache store, which keeps them per visitor, so other people's pages never hear of writes. Use a shared store such as database or redis.",
            'file', 'apc', 'octane' => "The change feed keeps touches in the [{$store}] cache store ({$driver}), which servers do not share. With more than one server, use a shared store such as database or redis.",
            default => null,
        };

        $confirmations = self::confirmed($subjects, asking: true) === [] || in_array($driver, ['database', 'redis', 'memcached', 'dynamodb'], true) ? null : match ($driver) {
            'array', 'null' => "Confirmations and forms are kept in the [{$store}] cache store, which forgets them after each request, so every answer is refused. Use a database, redis, memcached or dynamodb store.",
            'apc', 'session', 'storage' => "Confirmations and forms are kept in the [{$store}] cache store ({$driver}), which cannot hold a lock, so every answer is refused. Use a database, redis, memcached or dynamodb store.",
            default => "Confirmations and forms are kept in the [{$store}] cache store ({$driver}). An answer is single-use only on a database, redis, memcached or dynamodb store every server shares. Use one of those.",
        };

        return array_map(fn (string $message): Finding => self::warn('Cache store', $message), array_values(array_filter([$feed, $confirmations])));
    }

    /**
     * Tables: a feature in use whose tables the database lacks. Agents that store their conversations in laravel/ai's
     * database store need its two tables, a published agentic_conversations migration its table, an agent offered an
     * action that shows a table the agentic_views table, and MCP on the sanctum guard Sanctum's token table. Each
     * warning names the actions:install flags that publish them. A warning, since a database the check runs against
     * may not be migrated yet; a database it cannot read reports nothing.
     *
     * @param  list<Subject>  $subjects
     * @return list<Finding>
     */
    private function tables(Scan $scan, array $subjects): array
    {
        $remembering = array_values(array_filter(array_keys($scan->agents), ConfirmingAgents::classSupports(...)));
        $needs = [];

        if ($remembering !== [] && class_exists(DatabaseConversationStore::class) && rescue(fn (): bool => app(ConversationStore::class) instanceof DatabaseConversationStore, false, false)) {
            $needs['The agents ['.implode(', ', $remembering).'] store their conversations in laravel/ai\'s database'] = [config('ai.conversations.connection'), [
                (string) config('ai.conversations.tables.conversations', 'agent_conversations'),
                (string) config('ai.conversations.tables.messages', 'agent_conversation_messages'),
            ], '--copilot'];
        }

        if (Migrations::published('create_agentic_conversations_table')) {
            $needs['Actions::conversation() keeps a conversation per tenant'] = [(new AgenticConversation)->getConnectionName(), ['agentic_conversations'], '--copilot --tenancy'];
        }

        $received = array_merge([], ...array_values($scan->agents));
        $showing = array_filter($subjects, fn (array $subject): bool => $subject['entry']->shows() && array_intersect($subject['entry']->toolsets, $received) !== []);

        if ($showing !== []) {
            $needs['Agents show the tables of ['.self::names($showing).'], kept with each conversation'] = [(new AgenticView)->getConnectionName(), ['agentic_views'], '--copilot'];
        }

        $guard = McpMount::guard();

        if (config('agentic-actions.surfaces.mcp') && $guard !== null && config("auth.guards.{$guard}.driver") === 'sanctum' && class_exists(Sanctum::class)
            && array_filter($subjects, fn (array $subject): bool => $subject['entry']->allows(Surface::Mcp)) !== []) {
            $tokens = new (Sanctum::personalAccessTokenModel());
            $needs['MCP clients sign in with Sanctum tokens'] = [$tokens->getConnectionName(), [$tokens->getTable()], '--mcp'];
        }

        $findings = McpMount::oauth() && Migrations::missingTables((new McpConnection)->getConnectionName(), ['agentic_mcp_connections']) !== []
            ? [self::fail('Tables', 'MCP clients sign in with OAuth, but the database has no [agentic_mcp_connections] table: run php artisan actions:install --mcp')] : [];

        foreach ($needs as $need => [$connection, $tables, $flags]) {
            if (($missing = Migrations::missingTables(is_string($connection) ? $connection : null, $tables)) !== []) {
                $findings[] = self::warn('Tables', "{$need}, but the database has no [".implode(', ', $missing).'] '.Str::plural('table', count($missing)).": run php artisan actions:install {$flags}");
            }
        }

        return $findings;
    }

    /**
     * npm: the installed client's major.minor differs from the PHP package's.
     *
     * @return list<Finding>
     */
    private function npm(): array
    {
        $file = $this->app->basePath('node_modules/@agentic-actions/client/package.json');

        if (! is_file($file)) {
            return [];
        }

        $package = json_decode((string) file_get_contents($file), true);
        $client = is_array($package) && is_string($package['version'] ?? null) ? $package['version'] : '';
        $server = (string) $this->packages->version('agentic-actions/laravel');

        // A version that does not start with major.minor (a dev-main checkout) cannot be compared.
        if (preg_match('/^(\d+)\.(\d+)/', $client, $npm) !== 1 || preg_match('/^(\d+)\.(\d+)/', $server, $php) !== 1) {
            return [];
        }

        if ([(int) $npm[1], (int) $npm[2]] === [(int) $php[1], (int) $php[2]]) {
            return [];
        }

        return [self::warn('npm', "@agentic-actions/client {$client} is installed, but agentic-actions/laravel is {$server}: run npm install to update the client.")];
    }

    /**
     * Manifest (--production): the manifest is missing, stale, or differs from the fresh scan.
     *
     * @return list<Finding>
     */
    private function manifest(Scan $scan): array
    {
        if (! is_file(Manifest::path($this->app))) {
            return [self::fail('Manifest', 'The actions manifest is missing: run php artisan optimize, or actions:cache, on deploy.')];
        }

        if (($manifest = Manifest::readFresh($this->app)) === null) {
            return [self::fail('Manifest', 'The actions manifest is older than the route cache, unreadable or from another release: run php artisan optimize, or actions:cache, on deploy.')];
        }

        $expected = [
            'version' => ActionRegistry::VERSION,
            'actions' => array_map(fn (Entry $entry): array => $entry->toManifest(), $scan->actions),
            'agents' => $scan->agents,
        ];

        // The snapshot's canonical text compares both sides key-order-free and type-exact.
        if (Snapshot::encode($manifest) === Snapshot::encode($expected)) {
            return [];
        }

        return [self::fail('Manifest', 'The actions manifest differs from the actions on disk: run php artisan actions:cache.')];
    }

    /**
     * The output node the Model output and Output rows read; none for a table whose columns() throws, which the Tables
     * & datasets row reports.
     *
     * @param  Subject  $subject
     * @return array<string, mixed>
     */
    private function outputNode(array $subject): array
    {
        try {
            return $this->reader->output($subject['action']);
        } catch (Throwable $exception) {
            return $subject['entry']->shows() ? [] : throw $exception;
        }
    }

    /**
     * The node an agent is offered, as a provider receives it, for a context with no fixed input.
     *
     * @return array<string, mixed>
     */
    private function advertisedNode(Action $action): array
    {
        return $this->advertised->node($action, ActionContext::system());
    }

    /**
     * Every exists and unique rule in a rules() array, with its key, its kind and whether it is scoped.
     *
     * @param  array<string, mixed>  $rules
     * @return list<array{0: string, 1: 'exists'|'unique', 2: bool}>
     */
    private function databaseRules(array $rules): array
    {
        $found = [];

        foreach ($rules as $key => $value) {
            $list = match (true) {
                is_string($value) => explode('|', $value),
                is_array($value) => array_values($value),
                default => [$value],
            };

            foreach ($list as $rule) {
                if ($rule instanceof Exists || $rule instanceof Unique) {
                    // An object is scoped by a where clause in its string form, or by a query callback.
                    $found[] = [(string) $key, $rule instanceof Exists ? 'exists' : 'unique', $rule->queryCallbacks() !== [] || self::scopedRule((string) $rule)];
                } elseif (is_string($rule) && preg_match('/^(exists|unique):/i', $rule, $match) === 1) {
                    $found[] = [(string) $key, strtolower($match[1]) === 'exists' ? 'exists' : 'unique', self::scopedRule($rule)];
                }
            }
        }

        return $found;
    }

    /**
     * Whether a string exists or unique rule carries a where clause: more than two parameters for exists, more than
     * four for unique.
     */
    private static function scopedRule(string $rule): bool
    {
        [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, '');

        return count(str_getcsv($parameters, ',', '"', '')) > (strtolower($name) === 'exists' ? 2 : 4);
    }

    /**
     * Whether a rules() key names an id: its last segment is id, *_id or uuid, as ForbiddenKeys decides.
     */
    private function isIdShaped(string $key): bool
    {
        $name = Str::afterLast($key, '.');

        return $this->forbidden->idShaped(['type' => 'object', 'properties' => [$name => ['type' => 'string']]]) !== [];
    }

    /**
     * The actions agents are offered only after a person confirms each call: open to agents, Destructive or External.
     * With $asking, also the agent-open actions whose incomplete calls ask the person in a form (Entry::$asks).
     *
     * @param  list<Subject>  $subjects
     * @return list<Subject>
     */
    private static function confirmed(array $subjects, bool $asking = false): array
    {
        return array_values(array_filter($subjects, fn (array $subject): bool => $subject['entry']->allows(Surface::Agent) && ($subject['entry']->effect?->isModelSafe() === false || ($asking && $subject['entry']->asks))));
    }

    /**
     * The subjects' action names, comma-separated, in scan order.
     *
     * @param  array<int, Subject>  $subjects
     */
    private static function names(array $subjects): string
    {
        return implode(', ', array_map(fn (array $subject): string => $subject['entry']->name, $subjects));
    }

    /**
     * The relations a dataset reads in the tenant's scope that the tenant scope refuses, by name with the reason, tried
     * against a tenant with no key. A dataset whose declarations do not resolve reports nothing here: its row names the
     * error.
     *
     * @return array<string, string>
     */
    private static function refusedRelations(Dataset $dataset): array
    {
        $class = (string) config('agentic-actions.tenant.model');
        $tenant = new $class;
        $refused = [];

        foreach (rescue(fn (): array => $dataset->declared()[3], [], false) as $name => $relation) {
            $related = $relation->getRelated();

            if (in_array($name, $dataset->shared(), true) || ! $tenant instanceof Model || $related instanceof $tenant) {
                continue;
            }

            try {
                Tenants::scope($related->newQuery(), $tenant);
            } catch (Throwable $exception) {
                $refused[$name] = $related::class.': '.$exception->getMessage();
            }
        }

        return $refused;
    }

    /**
     * Whether the class overrides one of Action's methods.
     *
     * @param  ReflectionClass<Action>  $reflection
     */
    private static function overrides(ReflectionClass $reflection, string $method): bool
    {
        return $reflection->getMethod($method)->getDeclaringClass()->getName() !== Action::class;
    }

    /**
     * Dot paths of binary (file) fields in a node, lists of files included; list items appear as "*".
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function binaryPaths(array $node, string $prefix = ''): array
    {
        $paths = [];

        foreach ((array) ($node['properties'] ?? []) as $key => $property) {
            if (! is_array($property)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $items = is_array($property['items'] ?? null) ? $property['items'] : [];

            if (($property['format'] ?? null) === 'binary' || ($items['format'] ?? null) === 'binary') {
                $paths[] = $path;
            }

            array_push($paths, ...self::binaryPaths($property, $path), ...self::binaryPaths($items, "{$path}.*"));
        }

        return $paths;
    }

    /**
     * The scan's entries by class.
     *
     * @return array<string, Entry>
     */
    private static function byClass(Scan $scan): array
    {
        $entries = [];

        foreach ($scan->actions as $entry) {
            $entries[$entry->class] = $entry;
        }

        return $entries;
    }

    /**
     * The routes Actions::routes() generated.
     *
     * @param  list<Route>  $routes
     * @return list<Route>
     */
    private static function generated(array $routes): array
    {
        return array_values(array_filter($routes, fn (Route $route): bool => $route->getAction(ActionRequest::GENERATED) === true));
    }

    /**
     * A route's name, or its URI when it has none.
     */
    private static function routeName(Route $route): string
    {
        return $route->getName() ?? $route->uri();
    }

    /**
     * A failing finding.
     */
    private static function fail(string $row, string $message): Finding
    {
        return new Finding('fail', $row, $message);
    }

    /**
     * A warning.
     */
    private static function warn(string $row, string $message): Finding
    {
        return new Finding('warn', $row, $message);
    }
}
