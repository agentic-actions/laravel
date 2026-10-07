# Troubleshooting

Each entry starts with what you see: a message copied from the package, Laravel's own message, or a status code. Then it gives the cause and the fix. Most of these show up before any request does: run `php artisan actions:check` and `php artisan actions:list` first. `actions:list` prints, for each action and surface, whether it is open and why.

## Install and routes

### Route [login] not defined

```text
Symfony\Component\Routing\Exception\RouteNotFoundException: Route [login] not defined.
```

A signed-out request reached a generated route, or a route behind `auth`, and the app has no route named `login`. A generated route refuses a guest whatever middleware its group has. A request that accepts JSON gets 401 `{"message": "Unauthenticated."}`. Any other request goes to Laravel's guest redirect, which is `route('login')` unless you change it.

Fix: add sign-in, such as a starter kit, or a route named `login`. Or point the guest redirect somewhere that exists, in `bootstrap/app.php`, which already imports `Middleware`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo('/');
})
```

A client that sends `Accept: application/json` gets the 401 instead. A bare `curl` does not send that header.

### 419 CSRF token mismatch

```text
419 {"message": "CSRF token mismatch."}
```

You called the web mount, `Actions::routes()` in `routes/web.php`, without a session. That mount sits in the `web` group, so Laravel's CSRF check runs before the action does. A `curl` call or a request from another application gets 419 there, and so does a request with a bearer token, because the web mount never reads one.

Fix: a Blade form needs `@csrf`. In the browser, `callAction()` sends the `X-XSRF-TOKEN` header to URLs on the page's own origin. Token clients call the `api.` mount in `routes/api.php` ([Getting started](../README.md#installation)).

### 403 You are not allowed to do this

```text
403 {"message": "You are not allowed to do this."}
```

The action's `authorize()` returned false. `make:agentic-action` writes one that returns false, so nobody can run the action until you decide who may. An action with no `authorize()` at all is denied everywhere too, and its `authorize` line in `actions:list` reads `missing: denied everywhere`. Write the check ([concepts](concepts.md#the-action)).

### Your app's own 404 for an action

`POST /actions/create-post` returns your app's usual 404 page, not `{"message": "Not found."}`. That means no route was generated for the action. Its `web` line in `actions:list` gives the reason:

- `skipped: no #[Expose]`: the class has no `#[Expose]`;
- `skipped: not declared`: `#[Expose]` names other surfaces and leaves out `web: true`;
- `skipped: agentSchema(): …`: an action with an `agentSchema()` gets no generated route, so write that route yourself;
- `open, no route yet`: the action is open on the web, but no `Actions::routes()` group serves it. Mount one, or mount `tenant: true` for a tenant-scoped action ([tenants](concepts.md#tenants)).

### A second React or Inertia

React reports an invalid hook call. Or an action succeeds but the page does not reload its props. Or `tsc` reports TS2345 on the `actionsChat()` options passed to `new Chat()`, because two copies of `ai` declare `ChatInit`.

In each case Vite or TypeScript loads a package from `vendor/agentic-actions/laravel/js/node_modules` as well as from your app's `node_modules`. That folder exists when Composer installs the package from a `path` repository, which symlinks a checkout with its own `node_modules`. Installed from the Composer package, the client brings no packages of its own since 0.9.0-beta.3, but an app that installed it from `vendor/` on an earlier version keeps the copies its lock file recorded. Check whether the folder is there:

```bash
ls vendor/agentic-actions/laravel/js/node_modules
```

Fix: after upgrading from 0.9.0-beta.2 or earlier, run `npm dedupe` once and commit `package-lock.json`. For a `path` repository, dedupe each package that folder holds, `ai` included, in `vite.config.ts`, as [Installation](../README.md#installation) shows. Dedupe changes what Vite bundles, not what `tsc` reads: for the type check, install the client from npm at the version `composer show agentic-actions/laravel` prints, without the `v`; its React, Inertia and `ai` are peer dependencies. The lockfile can still keep a copy the `file:` install brought in, such as an older `@inertiajs/core` beside the one your `@inertiajs/react` uses. Then run `npm dedupe`, or delete `node_modules` and `package-lock.json` and run `npm install`, and check that `npm ls @inertiajs/core ai` lists one version of each.

## Actions and schemas

### DuplicateActionName

```text
Actions App\Actions\CreatePost and App\Actions\Admin\CreatePost share the name or route segment [create-post].
```

Two classes resolve to one name or one route segment. When `$name` is empty, the name is the class name in kebab case, with one trailing `Action` removed and a run of capitals kept as one word. So `CreatePost` in two namespaces collide, and so do `CreatePost` and `CreatePostAction`, or `ExportCSV` and `ExportCsv`. Every scan throws this: a local request, the console, your tests and `actions:cache`. `actions:check` reports it in its Names row and skips the rows that need the action list.

Fix: pin the name on one of the two classes, as you would before an agent first uses it:

```php
protected string $name = 'admin-create-post';
```

### MisconfiguredExposure

```text
App\Actions\DeletePost: mcp: effect destructive: MCP has no confirmation step
```

An `#[Expose]` names a surface on purpose, and the rules refuse it there. Each line reads `{class}: {surface}: {reason}`:

| Reason | Fix |
|---|---|
| `effect undeclared` | set `$effect` |
| `no description` | set `$description`; agents and MCP need one |
| `effect destructive: MCP has no confirmation step` (or `external`) | remove `mcp: true`; MCP never reaches Destructive or External actions |
| `laravel/ai is not installed` | `composer require laravel/ai`, or remove `agents:` |
| `laravel/ai is installed only as a dev requirement: move it to "require"` | move it from `require-dev` to `require` |
| `agentSchema(): …` | remove `web: true` and write that route yourself, or replace `agentSchema()` with `requiredForAgents()` |
| `#[Expose] names no surface` | name a surface, or remove the attribute |

The error throws in your test suite and on a local web request. It also fails `actions:cache`, and with it `php artisan optimize`. In production it is reported at most once an hour, and the surface stays closed. The same lines show in red under the action in `actions:list` and in the Exposure row of `actions:check` ([exposure](concepts.md#exposure), [tests](testing.md#misconfigured-actions-fail-the-suite)).

A route conflict reads the same way:

```text
App\Actions\CreatePost: http: the hand-written route [POST posts] already serves this class, so Actions::routes() would add a twin. Remove that route, or leave web out of #[Expose].
```

Two routes to one class would give it two doors, and the hand-written one skips the class's own `#[Expose]`. Keep one of them.

### UnsupportedSchema

```text
App\Actions\CreatePost: [tags] anyOf is not supported: declare one type, or let rules() own the key
```

`schema()` uses a shape that the package cannot turn into Laravel validation rules. The other reasons are:

- `a union of string and integer is not supported: declare one type, or let rules() own the key`;
- `format hostname is not supported`: the formats that work are `email`, `uri`, `url`, `uuid`, `date`, `date-time`, `time`, `ipv4` and `ipv6`;
- `pattern lookaround (?= is not supported` (also `(?!`, `(?<=` and `(?<!`);
- `format binary (a file) is accepted on HTTP only`: a file field works only on the `Http` surface: a multipart upload to the action's route, or your own code's call with `ActionContext::http()`. A call from `actions:run`, a queued run or `ActionContext::system()` fails on it once it reaches validation, and agents and MCP clients are never offered an action whose `schema()` holds one ([file uploads](recipes.md#file-uploads)).

It is thrown when the action is called, so the call fails as a crash. `actions:check` finds the other reasons first, in its Schema row, with `schema()` or `agentSchema()` before the message. For a file it does not, since it compiles `schema()` as the web does: the row gives this message only for a file field in `agentSchema()`, and fails a file field in an action whose `#[Expose]` opens agents or MCP with `agents cannot send the file field` (a file in such an action's `agentSchema()` gets both messages). A file field on an action you only run from the CLI, the queue or `ActionContext::system()` passes the row, and shows up when it is called. Fix: declare one type. For a top-level key that must take several types, give it rules in `rules()` too: the package then leaves its type to those rules. For a format or pattern the package cannot compile, drop it from `schema()` and put the rule in `rules()`. For a file, call the action on the web or with `ActionContext::http()`, and give agents another action without the file ([file uploads](recipes.md#file-uploads)).

### ReadActionWrote

```text
A Read action tried to write [posts]. The statement did not run: give the action a writing effect, or list the table in agentic-actions.reads.writable_tables.
```

An action declared `Effect::Read` sent a statement that writes, for example by stamping `last_viewed_at`, logging to a table of its own, or creating a row it needs the first time it is read. The guard refused the statement before it ran, and the call failed as a crash. Fix: if the action changes data, make it `Effect::Write`. If the row should exist once someone reads it, such as a post's share link, add it in `initialize()` and list its table in `$initializes` ([a Read that creates its own state](concepts.md#a-read-that-creates-its-own-state)). If the table is bookkeeping that a read may touch, list it in `reads.writable_tables`, which lets every Read update and delete its rows too. Your cache, session and queue tables are already writable.

Related messages:

```text
A Read action sent a statement the Read guard could not check: […]. …
A Read action tried to queue [App\Actions\ImportPosts], which is not a Read. Nothing was queued: give the calling action a writing effect.
A Read action's initialize() tried to change rows of [share_links]. The statement did not run: initialize() only adds rows, and an update, delete, replace or upsert belongs in a Write action.
A Read action's initialize() tried to write [posts]. The statement did not run: initialize() only adds rows to the tables its $initializes lists.
```

The guard refuses a statement it cannot read to the end. On Postgres, a `LIKE` escape written as a backslash is the usual cause: use `like ? escape '!'`. A Read may only queue Reads. The last two come from `initialize()`, which may only add rows to the tables `$initializes` lists: an `updateOrCreate()` that finds the row, an upsert, an Eloquent event that writes elsewhere, or a model whose `$touches` updates its parent sends one of them. Use `firstOrCreate()`, inside `Model::withoutTouching()` when the model touches its parent. See [security](security.md) for what the guard covers.

## Tokens and MCP

### 404 Not found for a token

```text
404 {"message": "Not found."}
```

The package answers 404, the same status an action that does not exist gets, when:

- the token lacks the ability for the action's effect (`actions:read`, `actions:write`, `actions:destructive`, `actions:external`);
- the token is bound to one tenant with `tenant:{key}` and called another tenant, or an action outside any tenant;
- the person is not a member of the tenant in the URL;
- `shouldRegister()` returned false.

Fix: mint the token with the abilities it needs, for example `$user->createToken('importer', ['actions:read', 'actions:write'])`. A guard other than the session or Sanctum has no abilities until you bind a reader ([recipe](recipes.md#a-token-reader-for-a-jwt-or-api-key-guard)).

### An MCP client lists no tools

The client connects, and `tools/list` comes back empty. Check, in order:

- The token. Over MCP an ability counts only when the token names it, and `*` never counts. A Sanctum token made with `createToken('name')` has the default `['*']`, so it reaches nothing. Mint it with `['actions:read', 'actions:write']`.
- The effect. Destructive and External actions are never listed over MCP.
- The description. Under a bare `#[Expose]`, an action without a `$description` is left off MCP.
- The path. With tenants, tenant-scoped actions are listed only on `mcp.tenant_path`, and the rest only on `mcp.path`. A token bound to a tenant reaches nothing on the base path. An unknown tenant, or one the person is not in, answers an empty list ([MCP tenants](mcp.md#tenants)).

`actions:list` shows each action's `mcp` line, and [tokens and abilities](mcp.md#tokens-and-abilities) has the rules. For OAuth clients, see [when Claude cannot connect](mcp.md#when-claude-cannot-connect).

### MCP is not mounted, or is mounted when you did not ask for it

```text
  mcp        open, not mounted: mcp.middleware names no configured guard
```

`actions:list` gives the reason the MCP path is missing:

- `surfaces.mcp is off`: `AGENTIC_ACTIONS_MCP` is false;
- `mcp.middleware names no configured guard`: no guard matches `auth:sanctum`. Run `php artisan install:api`, or name your own token guard;
- `agentic-actions.mcp.tenant_path is null`, or `… has no {team}`: set `mcp.tenant_path`, such as `mcp/t/{team}`, using your `tenant.parameter`;
- `no action of this scope allows MCP`;
- `the path belongs to another route`: a route of your own already holds the path.

The opposite happens too. Once `install:api` has defined the Sanctum guard, MCP mounts at `mcp/actions`, even if you skipped MCP in the installer ([actions:install](setup.md#actionsinstall)). [Turning MCP off](mcp.md#turning-mcp-off) keeps it closed for the app, and `#[Expose(web: true)]` keeps one action off it.

### curl to the MCP URL gets a redirect or a 500

Without `Accept: application/json`, Laravel's `auth` middleware handles a missing token as a browser visit. You get a 302 to `/login`, or [Route \[login\] not defined](#route-login-not-defined), instead of the 401 and its `WWW-Authenticate` header. MCP clients send the header. Send it from `curl` too:

```bash
curl -si -X POST https://example.com/mcp/actions -H 'Accept: application/json, text/event-stream'
```

## Agents and the copilot

### The agent calls no actions, or forgets the conversation

The agent answers in words, calls no action and shows no rows. Or it forgets what was said a turn earlier. If you started it with `php artisan make:agent`, the generated class declares `tools()` and `messages()`, and both return `[]`. A class's own methods win over the methods of its traits. So these two replace `InteractsWithActions::tools()` and `RemembersConversations::messages()`.

`actions:check` warns about an agent whose own `tools()` never calls `$this->actionTools()`, and `Actions::assertAgentTools()` fails such an agent in a test.

Fix: delete both generated methods. Then make sure the class has what [the server](copilot.md#the-server) shows: `#[UseToolset]`, `use InteractsWithActions`, `use RemembersConversations` for a copilot, and an `actionContext()`. An agent that has tools of its own spreads the action tools into its `tools()`:

```php
public function tools(): iterable
{
    return [new SearchDocs, ...$this->actionTools()];
}
```

### The agent's class is not set up

```text
App\Ai\Agents\Assistant must override actionContext() and return ActionContext::agent($actor, $tenant, $locale).
App\Ai\Agents\Assistant has no #[UseToolset]: name the toolsets it receives.
```

The first message means the class uses `InteractsWithActions` and does not say whom its tools act for. Add `actionContext()` and build the context from the agent's own state. The second means the class has neither `#[UseToolset]` nor `#[DeferToolset]`. A bare `#[UseToolset]` means the `default` toolset. To name toolsets, pass them as separate strings: `#[UseToolset('default', 'support')]`. An array, `#[UseToolset(['support'])]`, fails every scan with PHP's `UseToolset::__construct(): Argument #1 must be of type string, array given`.

### An action is missing from the agent's tools

Before each turn, every action in the agent's toolsets runs the first five steps of [the pipeline](concepts.md#what-an-agents-tool-list-shows). It is left out when one of them says no. Look at its `agents` line in `actions:list` and at the Toolsets row of `actions:check`, which fails on `#[UseToolset] names [support], which no action joins.` The usual causes:

- the action names another toolset, or a Destructive or External action has a bare `#[Expose]`, which offers it to no agent until `#[Expose(agents: [...])]` names a toolset;
- a Destructive or External action, offered to an agent that cannot wait for a confirmation: one without `Conversational` and `RemembersConversations`, or one run without a conversation participant (`forUser()` or `continueLastConversation()`);
- an `authorize()` without input that says no for this person, or one whose `ValidatedInput` may be null that says no when called with null;
- a file field in `schema()`, even behind an `agentSchema()` without it, since a model cannot send a file ([file uploads](recipes.md#file-uploads)). It throws as a forbidden key does, with `agents cannot send the file field`;
- a forbidden key. Locally and in tests this throws:

```text
App\Actions\UpdateProfile: agents cannot be offered input [api_token]: it matches agents.forbidden_keys pattern "*token*". The tool is left out. Remove the key from schema(), or follow the "Strict agent schemas (no ids)" recipe (docs/recipes.md#strict-agent-schemas-no-ids): agentSchema() plus fromAgent().
```

- a dataset's or a table's declaration that throws, such as a measure whose `where()` takes `like`. Locally and in tests this throws too, and the Tables & datasets row of `actions:check` gives the same reason:

```text
App\Actions\PostActivity: agents cannot be offered it: The measure [posts] compares with [like]: use one of = != <> < <= > >=. The tool is left out.
```

In production such a tool is left out quietly, the agent keeps its other tools, and the error is reported at most once an hour per class.

### An agent loads more action tools than agents.max_tools

```text
App\Ai\Assistant: its #[UseToolset] toolsets hold 87 actions, all loaded on every step, more than agents.max_tools (20). Move the toolsets it needs only sometimes to #[DeferToolset], or raise the limit (https://agentic-actions.com/copilot#many-actions).
App\Ai\Assistant loads 87 action tools on every step, more than agents.max_tools (20). Move the toolsets it needs only sometimes to #[DeferToolset], or raise the limit.
```

The first is the Toolsets row of `actions:check`, a warning; the second is `Actions::assertAgentTools()` failing in a test. Both count the actions one agent loads across all of its `#[UseToolset]` toolsets, each action once, since the model reads every one of them on every step: an agent whose toolsets each hold fewer is flagged when together they hold more, and splitting a toolset in two changes nothing. With laravel/ai 1.0, the `tools()` of `InteractsWithActions` sends an agent's `#[DeferToolset]` toolsets as ordinary tools, so `assertAgentTools()` counts them too, and its message says to update laravel/ai to 1.1 instead.

Fix: give the agent only the toolsets its job needs, move the ones it needs only sometimes to `#[DeferToolset]`, or raise `agents.max_tools`, as [many actions](copilot.md#many-actions) describes. A published config that still sets the old `agents.max_tools_per_toolset` keeps its value: rename the key to `agents.max_tools`.

### #[DeferToolset] needs laravel/ai 1.1

```text
#[DeferToolset] on [App\Ai\Assistant] needs laravel/ai 1.1 or later: with the version installed, their deferred toolsets are loaded on every step. Run composer require laravel/ai:^1.1.
```

The Tool search row of `actions:check`. With laravel/ai 1.0, the package sends the actions of `#[DeferToolset]` toolsets as ordinary tools, to every provider, so nothing is deferred until you update laravel/ai.

### The reply stopped before it finished

```text
The reply stopped before it finished. Anything already saved stays saved.
```

Every turn that fails shows the person only this sentence. The real error goes to your exception handler, so locally it is in `storage/logs/laravel.log`, for example `Laravel\Ai\Exceptions\ProviderConnectionException: Could not connect to AI provider [openai].` The usual causes are a missing or wrong provider key, a provider that cannot be reached, a 401 or 429 from it, and a hand-written tool that throws (an action tool answers the model with its failed sentence instead).

Fix: set laravel/ai's default provider and its key, as [the copilot](copilot.md) says at the top, and for anything else read the reported exception. What the person keeps from a failed turn is in [reloading the chat](copilot.md#reloading-the-chat). To reword the sentence, publish the language files (`php artisan vendor:publish --tag=agentic-actions-lang`) and edit `stream.interrupted`.

## Tenants

### MissingContext: the action is tenant-scoped

```text
The action [App\Actions\UpdatePost] is tenant-scoped, but its context has no tenant. Pass the tenant to the context, or set $tenantScoped = false on the action.
```

Once `tenant.model` is set, every action is tenant-scoped unless it says otherwise, and it needs a tenant on every call. The usual causes:

- The route segment does not match `tenant.parameter`, which defaults to `tenant`. With `teams/{team}`, set `'parameter' => 'team'`. `actions:check` fails its Tenancy row on this: `… has no {tenant} parameter, so the action cannot find its tenant.`
- `Actions::routes()` is mounted outside the tenant prefix. Mount `Actions::routes(tenant: true)` under the prefix and `Actions::routes(tenant: false)` beside it ([mounting the routes](concepts.md#mounting-the-routes)).
- Your own code built the context without a tenant. Use `ActionContext::http($user, $team)`, pass `--tenant=` (the tenant's route key) to `actions:run`, and dispatch queued runs with a tenant.
- The action belongs to the account, not a tenant. Set `protected bool $tenantScoped = false;`.

### MissingContext: no tenant scope

```text
A tenant model is configured, but no tenant scope is: set agentic-actions.tenant.scope, or call Actions::scopeUsing().
```

An action called `$context->find()`, which finds a row through the tenant scope, or used a tenant-scoped dataset, and you have not configured one. `actions:check` warns about this before any call: `tenant.model is set, but no tenant scope is, …`. Write a class that implements `AgenticActions\Contracts\ScopesToTenant` (`__invoke(Builder $query, Model $tenant): Builder`) and name it in `tenant.scope`. Or register a closure with `Actions::scopeUsing()` in a service provider's `boot()` ([membership and scope classes](concepts.md#membership-and-scope-classes)). The scope must narrow the query it was given. If it returns a query for another model, the call fails with `The tenant scope returned a query for another model than [App\Models\Post].`

### MissingContext: no actor or tenant of a type

```text
The action context has no actor of type [App\Models\User].
The action context has no tenant of type [App\Models\Team].
```

`$context->actor(User::class)` or `$context->tenant(Team::class)` found none, or found another type. The actor is missing in a `system()` context, in `actions:run` without `--as`, and for a guest on an action with `$guests`. Read `$context->actor` when the action allows a guest. The tenant is missing when an action with `$tenantScoped = false` calls `$context->tenant()` or `$context->find()` without one.

### Every tenant URL answers 404

Every call under `teams/{team}` answers `{"message": "Not found."}`, even for a member. The usual causes:

- No membership check. Without `tenant.membership` or `Actions::membershipUsing()`, nobody is a member. `actions:check` fails on this: `tenant.model is set, but no membership check is: set tenant.membership in config/agentic-actions.php, or call Actions::membershipUsing().`
- An untyped membership closure that returns something other than `true`, such as a model or `1`. Only `true` admits ([the rules](concepts.md#membership-and-scope-classes)).
- `{team:slug}` in the prefix. The package resolves the segment with the model's own route key and ignores a binding field. Write `teams/{team}`, and return `'slug'` from the model's `getRouteKeyName()` ([what your tenant model needs](concepts.md#what-your-tenant-model-needs)).
- A token bound to another tenant, as in [404 for a token](#404-not-found-for-a-token).

A 403 instead of a 404 comes from your own middleware, such as a starter kit's team check, which answers before the package does ([when your own middleware checks membership first](concepts.md#when-your-own-middleware-checks-membership-first)).

## Deploy

### StaleActionManifest in your error tracker

```text
AgenticActions\Exceptions\StaleActionManifest: The actions manifest is missing or older than the route cache, so this process scanned for actions: run php artisan optimize, or actions:cache, on deploy.
```

It is reported, never thrown, at most once an hour. The request still worked: it scanned `discovery.paths` for actions, as a local request does, instead of reading `bootstrap/cache/agentic-actions.php`. The manifest is missing or stale when:

- the deploy runs neither `php artisan optimize` nor `actions:cache`;
- `route:clear`, `actions:clear` or `optimize:clear` ran after it, since all three delete it;
- the manifest is older than the route cache, or an upgrade of the package changed the manifest's format.

Fix: run `php artisan optimize` in the deploy, then `php artisan actions:check --production`, as [Deploying](setup.md#deploying) shows. `php artisan about` shows `Manifest` as `CACHED` or `NOT CACHED`.

### actions:check --production fails its Manifest row

```text
The actions manifest is missing: run php artisan optimize, or actions:cache, on deploy.
The actions manifest is older than the route cache, unreadable or from another release: run php artisan optimize, or actions:cache, on deploy.
The actions manifest differs from the actions on disk: run php artisan actions:cache.
```

`--production` adds this row to the usual checks. The first two have the causes above. The third means actions changed after the manifest was written. Run the check after the deploy's cache step.

### php artisan optimize fails with MisconfiguredExposure

`actions:cache` refuses to write a manifest while any action has an exposure error, and `optimize` runs it. Fix the lines it prints, as in [MisconfiguredExposure](#misconfiguredexposure). `actions:check` lists the same lines without stopping at the first one.

### actions.exposure.json is stale

```text
actions.exposure.json is stale: review the change, then run php artisan actions:check --update. Added: publish-post. Changed: create-post.
actions.exposure.json is missing: run php artisan actions:check --update
```

The committed snapshot no longer matches what the classes declare. The message names the actions that were added, removed or changed, and ends with `Agents changed.` when an agent's toolsets did. Changes you did not make in an action's class come from elsewhere: `composer require laravel/ai` opens the agent surface, and a new tenant model changes every action's scoping. Fix:

```bash
php artisan actions:check --update
git diff actions.exposure.json
```

Read the diff. Every new route, toolset or MCP tool in it is a decision. Commit the file once you agree with it ([the snapshot](concepts.md#discovery-the-manifest-and-the-snapshot)).

### An action's default name changed

```text
App\Actions\ImportCSVFile: its name is now [import-csv-file], since a run of capitals is one word; 0.9.0-beta.3 and earlier named it [import-c-s-v-file]. What calls it by the old name, such as its route's name or URL, the TypeScript file, an agent, an MCP client or actions:run, needs the new one. Keep the old name with protected string $name = 'import-c-s-v-file'; or update those callers, then run php artisan actions:check --update.
```

An action without `$name` takes its name from its class name, where a run of capitals is now one word ([the action](concepts.md#the-action)). Only classes with such a run changed, such as `ImportCSVFile`, `SendSMS` or `GetUserID`; `CreatePost`, `CreateAPost` and every class that sets `$name` kept their names. `actions:check` warns about each one in its Names row while `actions.exposure.json` still lists it under the old name, or while there is no snapshot, and the [Snapshot row](#actionsexposurejson-is-stale) fails beside it until you update the file.

Fix: choose for each action. To keep the name that agents, MCP clients and your own code already use, set it on the class:

```php
protected string $name = 'import-c-s-v-file';
```

To take the new one, update what calls the action by name: `route('actions.import-c-s-v-file')` and links to its URL, a scheduled `actions:run`, listeners and front-end code that read the action's name, and your tests. Run `php artisan actions:typescript`, where `importCSVFile` is now `importCsvFile`. Then run `php artisan actions:check --update`, which names each action it renamed, and commit `actions.exposure.json`.

## Other messages

| Message | Cause and fix |
|---|---|
| `Actions::fake() only works while the app runs its tests (APP_ENV=testing): a fake switches every gate off.` | `Actions::fake()` ran outside a test. Call it only in tests. |
| `laravel/ai is not installed: composer require laravel/ai.` | Code asked for agent tools without laravel/ai. Install it. |
| `[App\Actions\ImportPosts] cannot be queued for an actor that is not an Eloquent model: the worker restores the actor by its key.` | `dispatch()` with a non-Eloquent actor. Queue for a model, or call `run()`. |
| `App\Actions\Changes: the name [_changes] is the change feed's route; set another $name.` | The names `_changes` and `_views` belong to the package's routes. Set another `$name`. |
| `The action [create_post] is exported to TypeScript as [createPost], a name the action [create-post] already takes. Give one of them another name.` | Two names that differ only in punctuation become one TypeScript function. Rename one. |
| `[App\Tenancy\TeamMembership] must implement AgenticActions\Contracts\ChecksMembership.` | The class in `tenant.membership` (or `tenant.scope`, with `ScopesToTenant`) lacks the interface. Add `implements`. |
| `agentic-actions.tenancy must name a AgenticActions\Contracts\Tenancy class.` | `tenancy` names a class that is not a bridge. Use `AgenticActions\Tenancy\SpatieTeams`, or null. |
| `App\Actions\DeletePost: approvalSummary() returned 9 rows; a card shows at most 8.` | A confirmation card holds eight rows. Return fewer ([confirmations](copilot.md#confirmations)). |
| `[App\Actions\CreatePost] ask() names [status], which agents are not offered.` | `ask()` refers to a field the tool does not offer. Offer it, or remove it from `ask()` ([asking the person](asking.md)). |
