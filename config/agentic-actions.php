<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | Where the scanner looks for Action classes. Paths are relative to the
    | application's base path unless they are absolute, and may use glob
    | patterns such as "Modules/*". A missing path is skipped. List
    | classes outside these paths under "classes".
    |
    */

    'discovery' => [
        'paths' => ['app'],
        'classes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Surfaces
    |--------------------------------------------------------------------------
    |
    | App-wide switches, read when routes and tools are built and again on
    | every call, so they still apply when routes are cached. They are on by
    | default; an environment variable only turns one off.
    |
    */

    'surfaces' => [
        'web' => (bool) env('AGENTIC_ACTIONS_WEB', true),
        'agents' => (bool) env('AGENTIC_ACTIONS_AGENTS', true),
        'mcp' => (bool) env('AGENTIC_ACTIONS_MCP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated routes
    |--------------------------------------------------------------------------
    |
    | Actions::routes() registers POST {group prefix}/{path}/{action} named
    | {group name prefix}{name}{action} inside whatever group calls it.
    |
    */

    'routes' => [
        'path' => 'actions',
        'name' => 'actions.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    |
    | Leave "model" null when the app has no tenants. Once it is set,
    | actions are tenant-scoped unless they set $tenantScoped = false, the
    | "parameter" route segment carries the tenant (resolved by route key),
    | and "membership" must name a ChecksMembership class. "parameter" is
    | "tenant" until you set it: name your routes' segment, such as "team"
    | for teams/{team}. "scope" names a ScopesToTenant class that keeps
    | ActionContext::find() and datasets to the tenant's rows; without it,
    | or Actions::scopeUsing(), they throw MissingContext.
    | See https://agentic-actions.com/concepts#tenants.
    |
    */

    'tenant' => [
        'model' => null,
        'parameter' => 'tenant',
        'membership' => null,
        'scope' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy bridge
    |--------------------------------------------------------------------------
    |
    | A Tenancy class run around every pipeline step, for example
    | AgenticActions\Tenancy\SpatieTeams. Null runs nothing.
    |
    */

    'tenancy' => null,

    /*
    |--------------------------------------------------------------------------
    | Token abilities
    |--------------------------------------------------------------------------
    |
    | The ability a token needs for each effect, and the prefix that binds a
    | token to one tenant: "tenant:{primary key}". On HTTP a "*" ability
    | counts, as it does for Sanctum's tokenCan().
    |
    */

    'abilities' => [
        'read' => 'actions:read',
        'write' => 'actions:write',
        'destructive' => 'actions:destructive',
        'external' => 'actions:external',
        'tenant' => 'tenant:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Agents
    |--------------------------------------------------------------------------
    |
    | Keys an agent may never be offered, matched as case-insensitive globs at
    | any depth of the advertised input. Route parameters, the tenant
    | parameter and the tenant model's foreign key are always forbidden too.
    | Id-shaped keys (id, *_id, uuid) are allowed, under the checks in
    | actions:check. Add them here to forbid ids outright.
    | "max_message_length" is the longest chat message ChatRequest reads,
    | in characters. Destructive and External actions reach an agent only
    | when #[Expose(agents: [...])] names its toolset, and run after the
    | person confirms each call.
    |
    */

    'agents' => [
        'forbidden_keys' => ['*password*', '*secret*', '*token*', 'api_key'],
        'forbidden_output_keys' => [],
        'max_tools_per_toolset' => 20,
        'max_message_length' => 4000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | An agent runs a Destructive or External action only after the person
    | confirms that call, and an action with $askForMissing asks the person
    | for the fields a call left out. Either waits "ttl" seconds, counted from
    | the pause, kept in the default cache store: use a database, redis,
    | memcached or dynamodb store that every server shares.
    |
    */

    'approvals' => [
        'ttl' => 1800,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP
    |--------------------------------------------------------------------------
    |
    | The package mounts one laravel/mcp server for the actions that allow
    | MCP, at "path", and at "tenant_path" for tenant-scoped actions. Null
    | mounts nothing there. A path another route already holds is left
    | alone, and actions:check fails. "middleware" must authenticate with a
    | token guard: inside MCP a session grants nothing. "tenant_pattern"
    | null derives one: digits for an incrementing route key, none otherwise.
    | Name a Passport guard here (auth:sanctum,api) and remote clients such
    | as Claude sign in with OAuth: see
    | https://agentic-actions.com/mcp#connect-claude-chatgpt-and-other-remote-clients-oauth.
    |
    */

    'mcp' => [
        'path' => 'mcp/actions',
        'tenant_path' => null,
        'tenant_pattern' => null,
        'middleware' => ['auth:sanctum', 'throttle:agentic-actions-mcp'],
        'per_minute' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Change feed
    |--------------------------------------------------------------------------
    |
    | Writes made elsewhere (over MCP, by the queue, in another tab) reach an
    | open page through a polled feed. It keeps touched keys in the default
    | cache store for "window" seconds; use a store every server shares
    | (database or redis), not array, file or session.
    |
    */

    'feed' => [
        'enabled' => true,
        'window' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | A Read action that implements ShowsTable shows the person its rows as a
    | table. max_rows is the most a table holds on any surface; model_rows is
    | the most the model reads while the person sees the table.
    |
    */

    'views' => [
        'max_rows' => 500,
        'model_rows' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Datasets
    |--------------------------------------------------------------------------
    |
    | The connection a dataset's queries run on (null: its model's own). Give
    | it a statement time limit, for example a read replica whose session sets
    | one: php artisan actions:check warns when it has none.
    |
    */

    'datasets' => [
        'connection' => env('AGENTIC_ACTIONS_DATASETS_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    |
    | While a Read action's own code runs (authorize() through handle() and
    | its reply), statements that write are refused before they execute.
    | Cache, session and queue tables are derived from the app's own config;
    | list any other table a Read may write here. The guard also stops a
    | Read's own code from queueing an action that is not a Read
    | (Action::dispatch()); off, both are off.
    |
    */

    'reads' => [
        'guard' => true,
        'writable_tables' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposure snapshot
    |--------------------------------------------------------------------------
    |
    | The committed, reviewable list of what every action exposes. Only
    | "php artisan actions:check --update" writes it.
    |
    */

    'snapshot' => 'actions.exposure.json',

    /*
    |--------------------------------------------------------------------------
    | TypeScript
    |--------------------------------------------------------------------------
    |
    | Where "php artisan actions:typescript" writes its file. Relative to the
    | base path unless absolute.
    |
    */

    'typescript' => [
        'path' => 'resources/js/agentic/actions.ts',
    ],

];
