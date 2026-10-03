# Setup

What the package depends on, what each feature needs from your app, and the command that sets most of it up.

## What the package requires

Installing the package brings in one other package: laravel/mcp 1.x, which serves MCP. Everything else is optional, and a feature that needs a missing package is skipped with the reason in `php artisan actions:list`.

| Package | Required? | What it is for |
|---|---|---|
| PHP 8.3+, Laravel 12.62+ or 13.15+ | yes | everything |
| `laravel/mcp` 1.x | yes, installed with the package | the MCP server |
| `laravel/ai` 1.x | for agents | agent tools, the copilot, confirmations and forms, the per-tenant conversation store |
| `laravel/sanctum` 4.x | for tokens | token clients over HTTP, and MCP's default guard |
| `laravel/passport` 13.8+ | for OAuth | remote MCP clients that sign in with OAuth, such as Claude and ChatGPT connectors |
| `inertiajs/inertia-laravel` 3.x | for Inertia | flash results on Inertia visits, and `#[WithPageContext]` |
| `spatie/laravel-permission` 6 to 8 | no | the `SpatieTeams` tenancy bridge |

The npm client, `@agentic-actions/client`, installs from the Composer package (`"file:vendor/agentic-actions/laravel/js"`), so the two versions always match. It is also on npm, where you install the same version as the Composer package. Its root has no dependencies. Each entry point needs its own:

| Entry point | Needs |
|---|---|
| `@agentic-actions/client` | nothing |
| `@agentic-actions/client/inertia` | `@inertiajs/core` 3 |
| `@agentic-actions/client/react` | `@inertiajs/react` 3 and React 19 |
| `@agentic-actions/client/ai-sdk` | `ai` 7, and `@ai-sdk/react` for `useChat` |

`/react` needs React 19 because Inertia 3's React adapter does.

## actions:install

```bash
php artisan actions:install
```

It asks which features the app uses: web routes, agents and a copilot, confirmations, MCP, and tenants. Then it:

- publishes `config/agentic-actions.php` when the app has none;
- for agents, a copilot or confirmations, publishes laravel/ai's config, migrations and stubs, as laravel/ai's own installation does, or says to `composer require laravel/ai` first;
- for a copilot, publishes the package's `agentic_views` migration, which keeps the [tables](data.md) its turns show, and with tenants, its `agentic_conversations` migration, both after laravel/ai's, beside its conversations table;
- for MCP, publishes Sanctum's `personal_access_tokens` migration, or, when Sanctum is missing, offers to run `php artisan install:api`, which installs it; an app whose user model uses Passport's trait gets neither. With OAuth on (a Passport guard in `mcp.middleware`), it also publishes the `agentic_mcp_connections` migration;
- prints the steps that come next, leaving out what the app already has: the `model:prune` schedule for the kept tables with a copilot, the `Actions::routes()` lines once generated routes exist, the tenant settings once `tenant.model` is set, Sanctum's trait once the user model has it, `mcp.tenant_path` once it is set, each [OAuth](mcp.md#connect-claude-chatgpt-and-other-remote-clients-oauth) step once it is taken, `make:agentic-action` once an action is discovered, `actions:check --update` while the snapshot is current and an action exists, and `make:agent` while laravel/ai is missing. The tenant route line uses the prefix the app's web routes already put before the tenant parameter, such as `{current_team}` or `teams/{team}`;
- asks before it runs `php artisan migrate`, while a migration it publishes has not run. The app's own pending migrations never bring the question.

Run it again at any time. It publishes nothing that is already in `database/migrations` or `config/`. When a step fails, such as a publish that leaves nothing behind, `install:api` or `migrate`, its row reads FAILED or the command's own error shows, it skips the steps that need it and never offers the migrations, and it exits with a failure, so a setup script stops there. Declining one of its questions is not a failure. In CI, pass the features as flags:

```bash
php artisan actions:install --no-interaction --web --copilot --tenancy --mcp
```

Without interaction, it runs `install:api` and the migrations as if you had said yes, and `migrate` still refuses to run in production without `--force`, which fails the command as it fails `migrate`. With no flag, it sets up the web routes alone.

`php artisan actions:check` warns in its Tables row when a feature in use lacks its tables: agents that store their conversations without laravel/ai's tables, agents offered a table action without `agentic_views`, a published `agentic_conversations` migration that has not run, and MCP on the `sanctum` guard without `personal_access_tokens`. With OAuth on, a missing `agentic_mcp_connections` table fails the row. Each finding ends with the command that publishes the missing tables, such as "run php artisan actions:install --mcp". A database the check cannot reach reports nothing.

## What each feature needs

### Web routes

| | |
|---|---|
| Packages | none |
| Tables | none of the package's |
| Routes | `Route::middleware('auth')->group(fn () => Actions::routes());` in `routes/web.php`. Each group also gains the change feed's `POST …/actions/_changes` |
| Config and env | `discovery.paths` (default `app`), `routes.path` and `routes.name`; `AGENTIC_ACTIONS_WEB=false` turns the surface off |
| Install flag | `--web` |

### Token clients over HTTP

| | |
|---|---|
| Packages | `laravel/sanctum`, through `php artisan install:api` |
| Tables | `personal_access_tokens`, from Sanctum's migration, which `install:api` publishes |
| Routes | `Route::middleware('auth:sanctum')->name('api.')->group(fn () => Actions::routes());` in `routes/api.php` |
| Config and env | `abilities.*`; the `HasApiTokens` trait on your `User` model |
| Install flag | none: run `php artisan install:api` (`--mcp` runs it too) |

### Agents

| | |
|---|---|
| Packages | `laravel/ai` |
| Tables | none, for an agent that keeps no conversations |
| Routes | none |
| Config and env | laravel/ai's `config/ai.php` and your provider's key; `agents.*`; `AGENTIC_ACTIONS_AGENTS=false` turns the surface off |
| Install flag | `--copilot` |

### Copilot

| | |
|---|---|
| Packages | `laravel/ai`; in the browser, `ai` 7, `@ai-sdk/react` and, for the panel's pieces, Inertia 3 with React 19 |
| Tables | `agent_conversations` and `agent_conversation_messages`, from laravel/ai's migration (`php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"`) |
| Routes | your chat route ([the copilot](copilot.md#the-server)), and the feed route `Actions::routes()` adds |
| Config and env | `agents.max_message_length`; the change feed keeps its keys in the default cache store (below) |
| Install flag | `--copilot` |

### Confirmations

| | |
|---|---|
| Packages | `laravel/ai`; the agent implements `Conversational` and uses `RemembersConversations` |
| Tables | laravel/ai's two tables, as for the copilot; with `CACHE_STORE=database`, Laravel's own `cache` table |
| Routes | the chat route of [confirmations](copilot.md#the-route) |
| Config and env | `approvals.ttl` (1800 seconds); a default cache store every server shares: database, redis, memcached or dynamodb |
| Install flag | `--approvals` (it brings `--copilot`) |

### Asking the person

| | |
|---|---|
| Packages | as for confirmations; in the browser, `@agentic-actions/client/react` for `<ElicitationForm>` and `/ai-sdk` for `answerElicitation()` |
| Tables | as for confirmations |
| Routes | the chat route of confirmations, with its `throttle` ([asking the person](asking.md#opt-in)) |
| Config and env | `$askForMissing` on each Read or Write action that asks; `approvals.ttl` and a shared default cache store, as for confirmations |
| Install flag | `--approvals` |

### Tables

| | |
|---|---|
| Packages | none; for the copilot, as for confirmations; in the browser, `@agentic-actions/client/views` for `<ActionTable>`, React 19 |
| Tables | `agentic_views`, for the tables kept with stored conversations |
| Routes | the refresh route `Actions::routes()` adds (`_views/{ref}`) |
| Config and env | `views.max_rows`, `views.model_rows`; schedule `model:prune` for `AgenticView` ([tables](data.md#kept-with-the-conversation)) |
| Install flag | `--copilot` |

### Datasets

| | |
|---|---|
| Packages | none; as for tables to show them in the copilot |
| Tables | your model's own; `agentic_views` to keep them with a conversation, as for tables |
| Routes | none of their own: the refresh route `Actions::routes()` adds serves them as it serves tables |
| Config and env | `datasets.connection` (`AGENTIC_ACTIONS_DATASETS_CONNECTION`), null for each model's own; a statement time limit on that connection: MySQL `max_execution_time`, MariaDB `max_statement_time`, Postgres `statement_timeout` ([datasets](data.md#give-the-datasets-connection-a-time-limit)); SQLite has none; SQL Server is not supported |
| Install flag | none |

### Tenants

| | |
|---|---|
| Packages | none (`spatie/laravel-permission` for the `SpatieTeams` bridge) |
| Tables | your tenant model's own |
| Routes | `Route::middleware('auth')->prefix('teams/{team}')->name('teams.')->group(fn () => Actions::routes(tenant: true));` beside `Actions::routes(tenant: false)` ([tenants](concepts.md#tenants)) |
| Config and env | `tenant.model`, `tenant.parameter`, `tenant.membership`, `tenant.scope`, and `tenancy` for a bridge |
| Install flag | `--tenancy` |

### A copilot conversation per tenant

| | |
|---|---|
| Packages | `laravel/ai` |
| Tables | `agentic_conversations`, from the package's migration (`php artisan vendor:publish --tag=agentic-actions-migrations`, after laravel/ai's), on laravel/ai's conversation connection |
| Routes | your chat route, with `Actions::conversation()` ([one conversation per tenant](copilot.md#one-conversation-per-tenant)) |
| Config and env | laravel/ai's database conversation store, its default; `ai.conversations.connection`, when laravel/ai's tables live on another connection |
| Install flag | `--copilot --tenancy` |

### MCP

| | |
|---|---|
| Packages | `laravel/mcp` (installed with the package), and `laravel/sanctum` for the default guard; `laravel/passport` for remote clients that sign in with OAuth |
| Tables | `personal_access_tokens`, from Sanctum's migration; with OAuth, Passport's tables and `agentic_mcp_connections`, from the package's migration (`php artisan vendor:publish --tag=agentic-actions-oauth-migrations`) |
| Routes | none to write: the package mounts `POST mcp/actions`, and with tenants `mcp.tenant_path` ([MCP](mcp.md#where-it-is-mounted)); with OAuth, `Mcp::oauthRoutes()` in `routes/ai.php` |
| Config and env | `mcp.*`; the `HasApiTokens` trait on your `User` model; with OAuth, an `api` guard on the `passport` driver named after Sanctum's in `mcp.middleware` (`auth:sanctum,api`), which is the switch ([OAuth](mcp.md#connect-claude-chatgpt-and-other-remote-clients-oauth)); `AGENTIC_ACTIONS_MCP=false` turns the surface off |
| Install flag | `--mcp` |

### The change feed

| | |
|---|---|
| Packages | none |
| Tables | with `CACHE_STORE=database`, Laravel's own `cache` table |
| Routes | `POST …/actions/_changes`, which every `Actions::routes()` group adds |
| Config and env | `feed.enabled` and `feed.window`; a default cache store every server shares, such as database or redis |
| Install flag | none: it is on by default |

### The TypeScript client

| | |
|---|---|
| Packages | `@agentic-actions/client` from `file:vendor/agentic-actions/laravel/js` |
| Tables | none |
| Routes | the web routes above |
| Config and env | `typescript.path` (default `resources/js/agentic/actions.ts`), which `php artisan actions:typescript` writes |
| Install flag | none |
