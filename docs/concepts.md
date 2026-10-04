# Concepts

An action is one class that extends `AgenticActions\Action`. The package reads what the class declares and decides which callers may reach it: a route, the CLI, an agent's tool call, an MCP client, a queued job, or your own code calling `run()`. Whoever calls, the same pipeline runs.

## The action

An action declares facts as properties and behaviour as methods.

| Property | Meaning |
|---|---|
| `$name` | The tool, CLI and TypeScript name. Empty means the kebab-case class name, with one trailing `Action` removed (`ArchivePostAction` is `archive-post`). Pin it once an agent has used it. |
| `$description` | What a model reads before it calls the action. Required on model surfaces (agents and MCP). |
| `$effect` | `Effect::Read`, `Write`, `Destructive` or `External`. Null exposes nothing remote and fails `actions:check`. |
| `$touches` | Neutral keys a success makes stale, such as `['posts']`. The client hands them to your reload or cache code. |
| `$tenantScoped` | True by default. Ignored until a tenant model is configured. |
| `$guests` | Lets a model-driven call run without a signed-in person, for a public support bot. Generated routes still need a user. |
| `$followLink` | After an agent's successful call, the open page visits `redirectTo()`'s URL when it is on this site. Only for a `redirectTo()` built from the saved record. See [the copilot](copilot.md). |
| `$idempotent` | A hint only, sent to MCP clients as `idempotentHint`. What a replay means stays your business. |
| `$errorBag` | The error bag browser visits receive. A Laravel 13 `#[ErrorBag]` attribute on the class wins. |
| `$validationMessagesToModel` | Sends validation messages, and not only the failing keys, to a model when the action raises a `ValidationException` itself. |

The package reads these as the class's declared defaults, through reflection, without running a constructor. Do not change them in a constructor.

| Method | Called |
|---|---|
| `schema(JsonSchema $schema)` | The input, as `Illuminate\JsonSchema` types. It is compiled to validation rules, advertised to agents and typed in TypeScript. Declare `required()` and `nullable()` only in their positive form. |
| `outputSchema(JsonSchema $schema)` | The output allowlist, applied at every depth. Undeclared keys never leave the server. |
| `rules(ActionContext $context)` | Server-only rules appended per key and never advertised: closures, dynamic bounds, scoped `exists`. |
| `prepareForValidation(array $input, ActionContext $context)` | Normalises raw input before validation. |
| `authorize(ActionContext $context[, ValidatedInput $input], ...$services)` | Not declared on the base class. Without it, the action is denied everywhere. Without a `ValidatedInput` parameter it runs before any input is read; with one, after validation. |
| `handle(ActionContext $context, ValidatedInput $input, ...$services)` | Does the work. Services are injected by the container. |
| `shouldRegister(ActionContext $context)` | Exposure, not authorization: false reads exactly like an action that does not exist. |
| `agentSchema()` and `fromAgent()` | A separate vocabulary for agents, translated into the canonical input. See [strict agent schemas](recipes.md#strict-agent-schemas-no-ids). |
| `requiredForAgents()` | Fields a model's call must give, agents' and MCP clients' alike, though the route, the CLI and your own code may leave them out. They are offered as required, and a model's call without one is refused or [asks the person](asking.md#fields-only-a-model-must-give). |
| `modelReply($result, ActionContext $context)` | The sentence a model reads on success. Null means "Done.", or "Found." above the output for a Read. |
| `redirectTo($result, ActionContext $context)` | Where a browser visit lands after success. Null redirects back. |
| `activityLabel(ActionContext $context, bool $finished)` | The copilot row's label while the call runs and after it succeeded. Null means the package's label for the effect. |

`run()` and `__invoke()` are final. A class that also uses a trait defining either method fails to load.

## Context

`AgenticActions\ActionContext` carries who is calling and from where. An action reads the actor, the tenant and the locale from it and never from `auth()`, `request()` or the session, so the same code works for a route, a queued job, the CLI and an agent.

| Constructor | For |
|---|---|
| `ActionContext::fromRequest($request)` | The HTTP edge. The package builds it for generated and hand-written routes: the request's user, the guard that authenticated it, the tenant route parameter, the locale, the `Idempotency-Key` header, and the route's parameters as fixed input. |
| `ActionContext::http($actor, $tenant, $locale)` | Your own code inside a request: Livewire, Filament, a Blade controller. |
| `ActionContext::agent($actor, $tenant, $locale)` | An agent's tools, built from the agent's own state. |
| `ActionContext::system($tenant, $locale)` | Webhooks and scheduled work: no actor and no token check, but `authorize()` still runs. |

`actions:run` builds a console context from `--as`, `--tenant`, `--locale` and `--key`. The actor may be null everywhere; `authorize()` decides what a guest may do.

Inside an action:

- `$context->actor(User::class)` returns the actor, or throws `MissingContext` when there is none or it is another type. `$context->actor` is the nullable property.
- `$context->tenant(Team::class)` does the same for the tenant.
- `$context->find(Post::class, $id)` finds a row through the tenant scope. A missing or foreign row throws `ModelNotFoundException`, which every surface renders as not found.
- `$context->locale` is the caller's locale; the pipeline already runs inside it.
- `$context->requireIdempotencyKey()` returns a key namespaced by action, actor and tenant, or refuses with 428 when the caller sent none.
- `$context->withFixed([...])` adds input the surface fixes, such as the record an agent is editing. Fixed input overwrites the caller's and is never advertised.

## Surfaces

A surface is where a call comes from: `Http`, `Agent`, `Mcp`, `Queue`, `Console` or `System`.

A call is model-driven when its surface is `Agent` or `Mcp`, when it runs inside a laravel/ai tool call, or when it runs inside an MCP request. A queued run keeps the answer of the call that queued it, and so does every call its own code makes. The call stack decides, not the context a caller built, so a hand-written agent tool that calls `PublishPost::run($input, ActionContext::http($user))` is still model-driven. A model-driven call reaches only Read and Write actions, and needs an actor unless the action sets `$guests`. The one exception is an agent's call to a Destructive or External action, which runs only after the person confirms it (see [confirmations](copilot.md#confirmations)).

## Effects

| Effect | Use it when the action | Models may call it |
|---|---|---|
| Read | changes nothing | yes |
| Write | changes the actor's own data, which the actor could enter again, and affects nobody else yet | yes |
| Destructive | removes something other people rely on, or something the actor cannot recreate | agents only, after a person confirms each call; never MCP |
| External | reaches people or systems outside the actor's own data: an email, a payment, a webhook | agents only, after a person confirms each call; never MCP |

A bare `#[Expose]` does not offer a Destructive or External action to agents: name its toolset with `#[Expose(agents: [...])]` ([confirmations](copilot.md#confirmations)).

A Read or Write action may ask the person, in a form in the chat, for the fields a model's call left out ([asking the person](asking.md)); a Destructive or External action never asks.

The effect also picks the token ability a call needs (`actions:read` and so on) and whether the Read guard runs. See [security](security.md).

## Doors

Every caller enters the pipeline through one of five doors. The door decides only whether the class's own `#[Expose]` is consulted.

| Door | Callers | Checks `#[Expose]` |
|---|---|---|
| In-process | `Action::run()`, `Action::dispatch()`, `Actions::attempt()`, `actions:run` | no: discovery alone reaches it |
| Route | a route you wrote whose controller is the action | no: a route you wrote is its own allowlist |
| Generated route | a route from `Actions::routes()` | yes: the web surface must be open, and `surfaces.web` on |
| Agent | an agent's tool call | yes: the agent surface open, `surfaces.agents` on, and a toolset the agent shares |
| MCP | an MCP client's tool call | yes: the MCP surface open, `surfaces.mcp` on, and, with tenants, a tenant-scoped action on the tenant path or another action on the base path |

## The pipeline

1. The door, as above. A model-driven call also needs a Read or Write effect and an actor (or `$guests`), except an agent's Destructive or External call, which needs an actor and an agent that can wait for the person's confirmation.
2. The token check: the credential needs the effect's ability, and a tenant-bound token only reaches its tenant.
3. `shouldRegister()`.
4. Tenant membership, when the call has a tenant.
5. `authorize()` without input, when it takes none.
6. For agents and MCP: the arguments are cut to the advertised schema, then translated through `fromAgent()` when the class overrides `agentSchema()`.
7. The fixed input overlays the body, strings are converted where no information is lost (`"12"` to `12`), an empty string, or one of only whitespace, becomes null for every key at every depth, a string field and a key only `rules()` declares included, as Laravel's `TrimStrings` and `ConvertEmptyStringsToNull` make it on the web, so validation refuses it unless the field is nullable (no other string is trimmed, and a blank `password`, `password_confirmation` or `current_password` stays as given, as `TrimStrings` leaves it), and `prepareForValidation()` runs.
8. Validation: the compiled `schema()` plus `rules()`.
9. `authorize()` with input, when it takes `ValidatedInput`.
10. For an agent's Destructive or External call: the person's confirmation, taken once. Then `handle()`, the output projection, and `modelReply()`.
11. One event: `ActionCompleted`, `ActionRefused` or `ActionFailed`. Events carry no input values.

Steps 1 to 4 answer "not found" when they stop, so an action a caller may not see reads exactly like one that does not exist. A denied `authorize()` answers 403, or 404 when it returns `Response::denyAsNotFound()`. A crash is reported once and rendered by your exception handler.

`Actions::attempt($action, $input, $context)` runs the same pipeline and returns an `Outcome` instead of throwing: `ok()`, `status()`, `output()`, `result()`, `errors()` and `refusal()`.

### What an agent's tool list shows

Before each turn, an agent's tools are built by running steps 1 to 5 for every action in its toolsets. An action leaves the list when one of those steps says no (the door, the token, `shouldRegister()`, membership, or an `authorize()` that takes no input), or when it would offer a [forbidden key](security.md). An `authorize()` that takes `ValidatedInput` needs the model's arguments, so it runs only when the model calls the tool. Until then the tool stays listed, and a person who may never run it still sees it in the agent's list, and is refused when the model calls it.

So put checks on the caller (a role, a permission) in an `authorize()` without input, and checks on a particular row in `handle()` or in an `authorize()` that takes `ValidatedInput`:

```php
public function authorize(ActionContext $context): bool
{
    return $context->actor(User::class)->can('edit-tasks');
}

public function handle(ActionContext $context, ValidatedInput $input): Task
{
    $task = $context->find(Task::class, $input->integer('task')); // A task outside the tenant is not found.

    // ...
}
```

An action has one `authorize()`. When it has to take input, as it does when agents are offered an id, put the check on the caller in `shouldRegister()`, as the [strict agent schemas](recipes.md#strict-agent-schemas-no-ids) recipe does.

## Refusals

An action says no with `AgenticActions\Refusal`:

```php
throw Refusal::make(__('You already have a post with that title.'))->on('title');
```

`make()` takes a translation key or a sentence, an optional default and replacements. Replacements come from your own text or the actor's own saved rows, never from the caller's input. `on()` puts the message on a field, `status()` sets the HTTP status (409 by default), `details()` adds data for web and API callers only, and `listing()` adds text other people wrote, which a model receives framed as data. `Actions::refuse(DomainException::class, fn ($e, $context) => Refusal::make(...))` turns an exception your domain already throws into a refusal on every surface, and `Actions::translateUsing()` hands your refusal keys to your own translator.

## Exposure

`#[Expose]` is the only way onto a network or model surface.

```php
#[Expose]                                   // every surface the effect and shape allow
#[Expose(web: true)]                        // the generated route only
#[Expose(agents: ['support'])]              // the "support" toolset only
#[Expose(web: true, agents: ['default'])]   // both
#[Expose(mcp: true)]                        // MCP only
```

A bare `#[Expose]` skips a surface the action cannot use quietly: agents and MCP for a Destructive or External action, agents while laravel/ai is not installed, MCP for an action without a description, the route for an action with an `agentSchema()`. Naming that surface explicitly is an error instead, except a toolset named for a Destructive or External action, which offers it to agents behind a confirmation. An error throws inside your test suite and on a local web request, fails `actions:cache` (and so `php artisan optimize`), and is reported in production while the surface stays closed. `actions:list` and `actions:check` show every decision with its reason.

The route is skipped for an `agentSchema()` because `schema()` is then the input `fromAgent()` builds, such as ids an agent never saw, not what a form sends, so a route to it is yours to write. When agents only need to give more fields than the web does, keep `schema()` alone and name those fields in [`requiredForAgents()`](asking.md#fields-only-a-model-must-give): the generated route stays.

A class without `#[Expose]` is still discovered: `run()`, `attempt()` and `actions:run` reach it. Attributes are not inherited, so a subclass of an exposed action is exposed only if it repeats the line. The config switches `surfaces.web`, `surfaces.agents` and `surfaces.mcp` turn a surface off for the whole app. See [MCP](mcp.md) for where the MCP server is mounted and which tokens reach it.

## Discovery, the manifest and the snapshot

The scanner walks `discovery.paths` (default `app`; globs and absolute paths work) and reads each file that mentions `AgenticActions\`. It takes the class name from the file's tokens, never from its path. Classes outside those paths go in `discovery.classes`.

`php artisan optimize` (or `actions:cache`) writes a manifest to `bootstrap/cache/agentic-actions.php`, so production requests do not scan. The console, local requests and test runs always scan. The manifest only nominates: every gate re-reads the class, so a stale manifest can hide a new action but never widen an old one.

`actions.exposure.json` is the reviewable form of the same facts, committed with your code. Only `php artisan actions:check --update` writes it. `actions:check` fails while it differs from what the classes declare, so a new route or a widened toolset is a diff someone approved.

## Tenants

A tenant is the model your app's rows belong to, such as a team. Four keys in `config/agentic-actions.php` turn tenancy on:

```php
'tenant' => [
    'model' => App\Models\Team::class,
    'parameter' => 'team',
    'membership' => App\Tenancy\TeamMembership::class,
    'scope' => App\Tenancy\TeamScope::class,
],
```

`tenant.parameter` is the route segment that carries the tenant. It defaults to `tenant`, so set it to the segment your prefix uses. `tenant.membership` answers whether a person may enter a tenant, and `tenant.scope` narrows `$context->find()` to it; [membership and scope classes](#membership-and-scope-classes) shows both. From then on actions are tenant-scoped unless they set `$tenantScoped = false`, and a tenant-scoped action called without a tenant throws `MissingContext`, which is a programming error.

### What your tenant model needs

- **A route key.** The URL segment is resolved through the model's own route binding, so the model sets its key: `getRouteKeyName()` returning `'slug'`, or UUID primary keys. Write the prefix as `teams/{team}`. A binding field in the prefix, such as `{team:slug}`, is not read: with the default `id` route key, members get a 404 at `/teams/acme` while `/teams/1` answers, and `actions:check` passes. An unknown key is a 404. The MCP tenant path and `actions:run --tenant=acme` take the same route key.
- **A primary key.** A token binds to a tenant by its primary key, with the ability `tenant:{key}` (`tenant:1`, never the slug).
- **A foreign key on the rows it owns.** Your scope filters by it, and the package reads its name from the model's `getForeignKey()` (`team_id` for `Team`). No `schema()` or `agentSchema()` key may be named after it or after the parameter (`team`): the tenant comes from the URL or the token, never from input, and `actions:check` fails such a key.
- **Membership, stored your way.** The package stores none. A `team_user` table (`team_id`, `user_id`) and two relations are enough; a starter kit with teams already has `belongsToTeam()`.

```php
// app/Models/Team.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Team extends Model
{
    protected $fillable = ['name', 'slug'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
```

```php
// app/Models/User.php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// inside the class, beside its other methods:

/** @return BelongsToMany<Team, $this> */
public function teams(): BelongsToMany
{
    return $this->belongsToMany(Team::class);
}

public function belongsToTeam(Team $team): bool
{
    return $this->teams()->whereKey($team->getKey())->exists();
}
```

### Membership and scope classes

Each is an invokable class that the container builds, so its constructor may take services:

```php
namespace AgenticActions\Contracts;

interface ChecksMembership
{
    public function __invoke(Authenticatable $actor, Model $tenant, ?Effect $effect): bool;
}

interface ScopesToTenant
{
    public function __invoke(Builder $query, Model $tenant): Builder;
}
```

```php
// app/Tenancy/TeamMembership.php
namespace App\Tenancy;

use AgenticActions\Contracts\ChecksMembership;
use AgenticActions\Effect;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class TeamMembership implements ChecksMembership
{
    public function __invoke(Authenticatable $actor, Model $tenant, ?Effect $effect): bool
    {
        return $actor instanceof User && $tenant instanceof Team && $actor->belongsToTeam($tenant);
    }
}
```

```php
// app/Tenancy/TeamScope.php
namespace App\Tenancy;

use AgenticActions\Contracts\ScopesToTenant;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TeamScope implements ScopesToTenant
{
    public function __invoke(Builder $query, Model $tenant): Builder
    {
        return match ($query->getModel()::class) {
            Post::class => $query->where('team_id', $tenant->getKey()),
            default => throw new LogicException('TeamScope cannot scope '.$query->getModel()::class.'.'),
        };
    }
}
```

Give the scope one arm per model your actions find; a model that belongs to the team through another, such as a comment through its post, gets an arm with `whereHas()`. The rules:

- **Only `true` admits.** The answer is compared with `=== true`, so `1` or a model is a no.
- **`$effect` is null when the question is the tenant as a whole**: when an MCP client lists its tools on the tenant path, and when a person approves an OAuth client for a tenant. A call asks with its action's effect, and so does each action a tool list weighs, so one MCP `tools/list` asks with `null` first, then once per action with its effect, such as `Read`, then `Write`. A no to `null` gives that client an empty list. To let some members only read, answer `null` and `Effect::Read` with true for them, and the other effects with false. The change feed asks with `Read`.
- **Membership is side-effect free.** It runs on every surface, often several times in one request.
- **A scope throws for a model it does not handle.** `find()` and [datasets](data.md), with their relations, all read through it, so a missing arm stops loudly instead of reading every team's rows. It returns a query for the model it was given. Which rows are the team's is its decision: see [what `find()` guarantees](security.md).
- **A configured class wins.** While `tenant.membership` names a class, `Actions::membershipUsing()` is never called, and the same holds for `tenant.scope` and `Actions::scopeUsing()`.
- **Without a scope, `find()` throws.** With `tenant.model` set and neither `tenant.scope` nor `scopeUsing()`, the first `$context->find()` throws `MissingContext` ("A tenant model is configured, but no tenant scope is"), which answers 500.

The closure forms take the same arguments and are registered in a service provider's `boot()`. Each is used only while its config key is null:

```php
// app/Providers/AppServiceProvider.php
namespace App\Providers;

use AgenticActions\Effect;
use AgenticActions\Facades\Actions;
use App\Models\Post;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Actions::membershipUsing(fn (Authenticatable $actor, Model $tenant, ?Effect $effect): bool => $actor instanceof User && $tenant instanceof Team && $actor->belongsToTeam($tenant));

        Actions::scopeUsing(fn (Builder $query, Model $tenant): Builder => match ($query->getModel()::class) {
            Post::class => $query->where('team_id', $tenant->getKey()),
            default => throw new LogicException('No tenant scope for '.$query->getModel()::class.'.'),
        });
    }
}
```

### Mounting the routes

`Actions::routes()` with no argument mounts every action, tenant-scoped or not. Once tenants are on, mount two groups: `tenant: true` under the parameter, and `tenant: false` for the actions that belong to the account, such as editing a profile.

```php
// routes/web.php
use AgenticActions\Facades\Actions;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')
    ->prefix('teams/{team}')
    ->name('teams.')
    ->group(fn () => Actions::routes(tenant: true));

Route::middleware('auth')->group(fn () => Actions::routes(tenant: false));
```

For token clients, mount the same pair in `routes/api.php`, which Laravel serves under `/api`:

```php
// routes/api.php
use AgenticActions\Facades\Actions;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')
    ->prefix('teams/{team}')
    ->name('api.teams.')
    ->group(fn () => Actions::routes(tenant: true));

Route::middleware('auth:sanctum')->name('api.')->group(fn () => Actions::routes(tenant: false));
```

A token bound with `tenant:{key}` then reaches only its own team's `api/teams/{team}` routes: never another team's, and never the account-level group. Give each `Actions::routes()` group its own name prefix, as these do. Every group gains the change feed's route, named `_changes` within the group, so two groups under one name prefix share a route name, which `route:cache` refuses. `actions:check` fails a tenant-scoped action whose generated route has no `{team}`.

### When your own middleware checks membership first

An unknown team and a team the person does not belong to get the same 404, so a stranger cannot tell which tenants exist. That holds when the package is the first to check membership. Inside a group whose own middleware already checks it, a starter kit's team middleware for example, a non-member gets that middleware's answer, often a 403, before the package's check runs. To keep the uniform 404, mount `Actions::routes(tenant: true)` in a group that authenticates and leaves membership to `tenant.membership`.

A starter kit with teams that routes its pages under `{current_team}` needs `'parameter' => 'current_team'`, its own `belongsToTeam()` in the membership class, and a group of the package's own beside the kit's, keeping its `verified` middleware:

```php
// routes/web.php
Route::middleware(['auth', 'verified'])
    ->prefix('{current_team}')
    ->name('current_team.')
    ->group(fn () => Actions::routes(tenant: true));
```

Mounted inside the kit's group instead, a stranger and an unknown team both get the kit's 403, so nothing leaks either way, but your pages then answer differently from your token clients, which get the 404.

### A tenancy bridge

`AgenticActions\Tenancy\SpatieTeams`, set as `tenancy` in the config, switches spatie/laravel-permission's team to the tenant around each pipeline step and restores it afterwards. Any class implementing `AgenticActions\Contracts\Tenancy`, whose `run(?Model $tenant, ?Authenticatable $actor, Closure $callback): mixed` calls `$callback` with the tenant's state switched on, fits there too.

## Queued runs

`Action::dispatch()` queues the whole pipeline to run in a worker, as the caller:

```php
ImportPosts::dispatch(['url' => $url], ActionContext::fromRequest($request))->onQueue('imports');
```

The job keeps the caller's actor and tenant, the input and the fixed input, the locale, the idempotency key and the token grants the caller had, and every check runs again in the worker: the token, membership, `authorize()` and validation. A call the job's own code makes, and a job it queues, reads the same grants, whatever context that code builds. It gets its own request id. `dispatch()` returns Laravel's `PendingDispatch`, so `onQueue()`, `delay()` and `afterCommit()` chain as usual, and, like `run()`, it is reached by discovery alone. The input must serialize: pass scalars and arrays, and store an uploaded file first and pass its path. A job queued by an agent's tool or inside an MCP call stays model-driven in the worker, so it still reaches only Read and Write actions. A refusal ends the job; a crash fires `ActionFailed` and fails it, and the worker retries it per its `--tries`, so an action that may run twice declares `$idempotent` and takes an idempotency key. While a Read's own code runs, `dispatch()` refuses an action that is not a Read. See [testing](testing.md#queued-runs) for `Queue::fake()`.

## The change feed

A copilot turn reloads what its own rows touched. Writes made elsewhere, over MCP, by the queue, by another person in the same tenant or in another tab, reach an open page through the change feed. Each completed write keeps its `$touches` keys (`*` when it declares none) in your default cache store for `feed.window` seconds: per tenant when the write had one, so every member's open page hears of it, and otherwise per person. Each `Actions::routes()` group gains `POST …/actions/_changes`, named `_changes` within the group, which a page polls through `useActionSync({ feed: { url } })` or `createActionSync()` ([the copilot](copilot.md#writes-made-elsewhere)). It answers a signed-in session only, with keys only, never ids or values. No action may be named `_changes`.

The feed is on by default, and each completed write pays one cache write for it. An app with no polling page turns it off with `feed.enabled`. Keep it on a cache store every server shares, such as database or redis: `actions:check` warns outside local development about one that forgets its entries, keeps them per visitor or keeps them on one server.

## The copilot

When an agent streams its turn through `ActionsProtocol`, each call of an action tool shows the person a row: a label while it runs, then done, refused, failed or ended, taken from what the pipeline recorded rather than from the model's text. An action's label is its `activityLabel()`, or the package's sentence for its effect, and a hand-written tool gets a row by implementing `DescribesActivity`. A done row carries the action's `$touches`, which the page reloads once the burst of writes is over, unless an editor on the page has unsaved work. On an HTTP call a Write with `$touches = []` reloads nothing; its copilot row sends `['*']`, which reloads the whole page, because a row has no response to read. With `#[WithPageContext]` the agent also learns which page is open, by route name and component only. A Read action that implements `ShowsTable` also shows the person its rows as a table after its row, and the model reads a short copy ([tables](data.md)). See [the copilot](copilot.md).
