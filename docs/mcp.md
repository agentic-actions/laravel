# MCP

The package serves your actions to MCP clients, such as Claude Code, Cursor and Claude Desktop, through one [laravel/mcp](https://github.com/laravel/mcp) server. A client connects with a person's token and reaches only what that person could do in the app: the same pipeline runs for every call, with membership, `authorize()` and validation. Remote clients that sign in with OAuth, such as a Claude custom connector or ChatGPT, connect once the app has Laravel Passport ([OAuth](#connect-claude-chatgpt-and-other-remote-clients-oauth)).

## What a client sees

One tool per action whose `#[Expose]` allows MCP. MCP follows the rules agents follow, and does not need laravel/ai:

| Effect, description | bare `#[Expose]` | `#[Expose(mcp: true)]` |
|---|---|---|
| Read or Write, with a description | listed | listed |
| Read or Write, no description | skipped: "no description" | error: `mcp: no description` |
| Destructive or External | never listed, never run: MCP has no confirmation step | error, same reason |
| no effect | error: `mcp: effect undeclared` | error, same reason |

`#[Expose(web: true)]` or `#[Expose(agents: [...])]` without `mcp: true` keeps an action off MCP. `php artisan actions:list` shows every decision on its `mcp` line.

A tool is named as the action (`create-post`), with its description and the input agents are offered: `agentSchema()` when the action has one, otherwise `schema()`, with the fields `requiredForAgents()` names required, minus the keys the call fixes. Keys the [forbidden-key rules](security.md) hide are never offered, and an action that would offer one is not listed. Arguments are cut to the advertised schema before anything reads them, and `agentSchema()` input goes through `fromAgent()`, exactly as for an agent.

The list comes in pages of 15 tools, and clients follow the cursor. Each tool carries hints for the client. They describe the action; the pipeline is still the gate.

| Effect | `readOnlyHint` | `destructiveHint` | `idempotentHint` | `openWorldHint` |
|---|---|---|---|---|
| Read | true | not sent | `$idempotent` | false |
| Write | false | true | `$idempotent` | false |

A Write carries `destructiveHint`, since a write may overwrite something, so a client that asks before destructive calls asks before each write.

### What a call answers

A client's model reads the sentence an agent reads: "Done.", your `modelReply()`, or "Found." with a Read's output after a line of three dashes. A refusal, invalid input, a denial and not found come back as an error result carrying the refusal's sentence, so the client shows a failed call. A crash is reported once and answers the fixed failed sentence.

Only the data after the dashes, and a refusal's `listing()`, are framed as data; the server's instructions tell the client's model that data is never instructions. The sentence itself is not framed, and not every client passes the instructions on. Keep record values (a title, a name, a message someone wrote) in the output or in a refusal's listing, never in `modelReply()` or a refusal message.

MCP has no idempotency key: an action that calls `$context->requireIdempotencyKey()` refuses every MCP call with its usual sentence.

A call to an action with `$askForMissing` that leaves fields out asks the person when the client can show a form, and is refused, naming them, when it cannot ([asking over MCP](#asking-over-mcp)).

A call fires `ActionCompleted`, `ActionRefused` or `ActionFailed` with `surface: mcp` and `modelDriven: true`. A completed write reaches open pages through the [change feed](concepts.md#the-change-feed).

## Where it is mounted

| Setting | Default | Serves |
|---|---|---|
| `mcp.path` | `mcp/actions` | actions that are not tenant-scoped, or every MCP action when `tenant.model` is null |
| `mcp.tenant_path` | null | tenant-scoped actions, for example `mcp/t/{tenant}`; it must contain `{` + `tenant.parameter` + `}` |

A path is mounted when MCP is on (`surfaces.mcp`), `mcp.middleware` names a guard `auth.guards` defines, the path is set, and at least one action of its kind allows MCP. A fresh app without a token guard mounts nothing. `actions:list` shows where each action is served, or why it is not:

```
  mcp        open  POST mcp/t/{tenant}
  mcp        open, not mounted: mcp.middleware names no configured guard
```

The package mounts after every other route and never replaces one. When a route of your own already holds a path, the package leaves that path alone, and `actions:check` fails naming your route. The routes are named `agentic-actions.mcp` and `agentic-actions.mcp.tenant`, sit outside the `web` group (no session, no CSRF), and answer a GET or DELETE with 405.

`mcp.tenant_pattern` constrains the tenant segment. Left null, it is digits when the tenant model's route key is its incrementing primary key, so `abc` or `007` is a 404 before anything runs, and no pattern otherwise, so a slug works.

## Tokens and abilities

`mcp.middleware` defaults to `['auth:sanctum', 'throttle:agentic-actions-mcp']`. Any configured guard that is not a session guard works; `actions:check` fails when an MCP guard uses the session driver, and warns when a Sanctum or Passport guard's user model uses neither Sanctum's nor Passport's `HasApiTokens`, since no token then signs anyone in. A request that accepts JSON, as every MCP client's does, and has no valid credential is answered 401 with a `WWW-Authenticate: Bearer` header, and never reaches the server.

A request that does not accept JSON, such as a bare `curl -X POST`, gets Laravel's answer to a signed-out browser instead: a redirect to the `login` route, or a 500 when the app has none. To answer every request on the MCP paths with the 401, add their prefix to the JSON rule in `bootstrap/app.php` (a new app already has the `api/*` part; `mcp/*` covers both default paths):

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;

->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*', 'mcp/*') || $request->expectsJson(),
    );
})
```

The abilities are the ones HTTP uses, read through the same `ReadsTokenGrants` reader:

| Ability | Reaches over MCP |
|---|---|
| `actions:read` | Read actions |
| `actions:write` | Write actions |
| `tenant:{key}` | only that tenant's path, by the tenant's primary key |

Over MCP an ability counts only when the token lists it by name. `*` never counts, and a session grants nothing, so a Sanctum token made with `createToken()`'s default `['*']` lists no tools. `actions:destructive` and `actions:external` reach nothing over MCP, which has no confirmation step. Passport tokens are read by default, on the MCP URL of their [connection](#what-a-connection-reaches) only. A JWT or API-key guard works once you bind your own reader ([recipe](recipes.md#a-token-reader-for-a-jwt-or-api-key-guard)).

An MCP token authenticates on its guard everywhere, so it also reaches your other routes on that guard. `actions:check` warns about each one that checks no ability, such as the `GET api/user` route `install:api` adds. Give those routes `abilities:` middleware, or delete them.

`actions:check` also fails when two effects share an ability, when an effect's ability is `*` or `mcp:use`, and when `abilities.tenant` is empty or an effect's ability starts with it.

## Tenants

On the tenant path, the segment is resolved by the tenant model's route key, and every comparison uses the primary key.

- A token bound with `tenant:{key}` reaches only that tenant's path, and nothing on the base path.
- The tenant path lists and runs only tenant-scoped actions, and the base path only the rest, so a bound token never reaches an account-level action. A client that needs both connects both URLs.
- An unbound token reaches every tenant its person belongs to, one URL per tenant. Membership runs for each action, as on every surface.
- An unknown tenant and a tenant the person does not belong to answer the same empty tool list, with the same instructions in the same language. A call to any tool there answers laravel/mcp's "Tool not found" error.

## Asking over MCP

An action with [`$askForMissing`](asking.md) asks over MCP too, when the client can show a form. That is a client on protocol 2026-07-28 whose request declares form elicitation in its `_meta` (`"elicitation": {}` or `"elicitation": {"form": {}}`). When its call leaves out fields a form can hold, or your rules refuse them, the answer is an `InputRequiredResult`: one `elicitation/create` request holding the form the copilot would show, in MCP's standard keys only (the textarea hint stays in the app), and a `requestState`. The client shows the form, then sends the same call again with the answer under `inputResponses` and the state unchanged. The action runs then, with the answer over the call's arguments and every check a call runs, and the client's model reads "The person filled in: title, body, status." before the usual sentence. It never reads the values from the package.

- Every other client gets what it got before: the call is refused, naming the fields. That is a client on the `initialize` handshake, whatever it declared there, a 2026-07-28 client that declares no elicitation or only `url`, and laravel/mcp's own client. The server never answers -32021 for a form.
- An answer your rules refuse gets the form again, with the values they accepted filled in and each refused field's messages added to its description. Decline and cancel run nothing, and the client's model reads the sentences it reads [in the app](asking.md#what-the-model-reads).
- The request state is encrypted and authenticated with your app's key (`APP_KEY`; keep the old one in `APP_PREVIOUS_KEYS` when you rotate it) and lasts `approvals.ttl` seconds (1800 by default). It binds the credential (the request's bearer token, whichever guard reads it: a Sanctum token, an OAuth access token or your own), the person, the tenant, the tool, the call's arguments and the form, and holds none of the person's values. A guard that reads its credential from anywhere but the `Authorization: Bearer` header binds the person alone. It needs no cache store.
- A state that does not decrypt to the package's own gets JSON-RPC error -32602 and runs nothing. A state that has expired, or was made for another bearer token, person, tenant, tool, arguments or form, is set aside, and the client gets a fresh form for its own call. The form counts `ask()`'s choices and defaults, so keep them stable between requests: a default that changes on every request, such as a timestamp or a random suggestion, never verifies, and the client gets a fresh form each time.
- A state is not spent by its retry. Sent again in time, it runs the action again, through every check, as the token could by sending those values in a call of its own.
- Destructive and External actions are still never listed, so they never ask. A read-only token still reaches no Write, and a token bound to a tenant still reaches only that tenant.

In form mode everything in the form reaches the client: `ask()`'s defaults (a value from the person's profile, say), its choices (such as the tenant's members), the values already given on a form asked again, and your rules' messages. The client may pass them to its model, or fill the form with no person looking. `ask()` runs with the call's context, so check `$context->surface === Surface::Mcp` there to leave a profile default out over MCP. Treat an answer over MCP as the client's, as you treat its call: the package knows which token sent it, not who typed it.

Which clients show the form, as of 26 September 2026: the MCP Inspector 2.8.0's web page does, on a server it connects to with the modern protocol era (`--protocol-era modern`; its default for a server named on the command line is legacy, which gets the refusal). The official TypeScript client, `@modelcontextprotocol/client` 2.1.0, answers it through its `elicitation/create` handler when it negotiates 2026-07-28. The Inspector's CLI, laravel/mcp 1.0.1's client and clients on the `initialize` handshake, such as Claude Desktop through `mcp-remote` 0.14, get the refusal.

## Throttle

Every MCP request counts against `mcp.per_minute` (60 by default), per person: all of one person's tokens and tenant URLs share one budget. Past it, the answer is 429 before the server runs.

- **What counts.** Each request a client sends: `initialize`, its notifications, every `tools/list` page and every `tools/call`. A model's turn can make several calls, and a client may list the tools again on its own, so leave room. A request without a valid credential is answered 401 before the throttle, and does not count.
- **At the limit.** The 429 carries `Retry-After` and `X-RateLimit-Limit`, as any Laravel throttle does.
- **The number.** `per_minute` is a plain value in `config/agentic-actions.php`, with no environment variable.

For a budget of your own, register a named limiter and put it in `mcp.middleware` in place of `throttle:agentic-actions-mcp`:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(120)->by('mcp:'.$request->user()?->getAuthIdentifier()));
}
```

```php
// config/agentic-actions.php
'mcp' => [
    // ...
    'middleware' => ['auth:sanctum', 'throttle:mcp'],
],
```

Authentication runs before the throttle, so `$request->user()` is the token's person.

## Handshakes and language

Both handshakes work on one URL: the `initialize` exchange (protocol versions 2025-11-25 and 2025-06-18), and the 2026-07-28 exchange, where each request carries its protocol version in `_meta` and the matching headers. The package adds nothing to either.

Sentences and the server's instructions follow the person's `HasLocalePreference::preferredLocale()`, or `app.locale`. The instructions are the language line `agentic-actions::mcp.instructions`, which says "tenant"; publish the language files (`php artisan vendor:publish --tag=agentic-actions-lang`) and edit `lang/vendor/agentic-actions/{locale}/mcp.php` to use your own word. The server is named after `app.name`.

## Recipe: tokens and clients

The package ships no token page and no command that mints tokens: minting is your app's decision. This recipe uses Sanctum. Each step gives the app without tenants first, served at `mcp/actions`, then what changes when actions are tenant-scoped, with tenants routed by slug.

### 1. Give the app a token guard

```sh
php artisan install:api          # installs laravel/sanctum, its migration and routes/api.php
php artisan migrate
```

```php
// app/Models/User.php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

`install:api` also adds `GET /api/user` under `auth:sanctum`. It checks no ability, so `actions:check` lists it. Delete it, or give it an ability and mint that ability only where it is meant. Register Sanctum's alias in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias(['abilities' => CheckAbilities::class]);
})
```

and check it on the route:

```php
// routes/api.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware(['auth:sanctum', 'abilities:profile:read']);
```

### 2. Point the package at the paths

**Without tenants**, nothing changes: `mcp.path` is `mcp/actions` by default and serves every MCP action, and the default `middleware` names Sanctum's guard.

**With tenants**, tenant-scoped actions need a path of their own (the [tenant model and membership](concepts.md#tenants) are set up first):

```php
// config/agentic-actions.php
'mcp' => [
    'path' => 'mcp/actions',              // account-level actions; null when every MCP action is tenant-scoped
    'tenant_path' => 'mcp/t/{tenant}',
],
```

The segment's name is `tenant.parameter`. Keep `path` while the app has account-level actions that allow MCP: a null `path` takes them off MCP. After a change on an app with cached routes, run `php artisan route:cache` and reload PHP-FPM.

`php artisan actions:list` now shows `mcp  open  POST mcp/actions` (or `POST mcp/t/{tenant}` for a tenant-scoped action) for each MCP-exposed action, and `php artisan actions:check` passes the MCP guard and MCP route rows.

### 3. Mint a token

Name the abilities and give the token an expiry:

```php
// php artisan tinker, or your app's own "Connect an AI client" page
$token = $user->createToken('Claude Code', ['actions:read', 'actions:write'], now()->addDays(90));

$token->plainTextToken;   // shown once: "1|…"
```

With tenants, bind the token to one tenant too:

```php
$tenant = Team::where('slug', 'acme')->firstOrFail();

$token = $user->createToken(
    'Claude Code',
    ['actions:read', 'actions:write', 'tenant:'.$tenant->getKey()],   // the primary key, never the slug
    now()->addDays(90),
);
```

A bound token reaches only that tenant's path, never `mcp/actions` ([Tenants](#tenants)). A read-only client gets `['actions:read']`, plus its `tenant:…`. Revoke with `$user->tokens()->where('name', 'Claude Code')->delete()`. A page in your app for this lets the person choose read or read and write, sets the expiry, lists tokens with their last use (`last_used_at`), and revokes them.

Once the token expires, every request answers 401 and the client stops connecting. Mint a new one and replace the header in the client's settings.

### 4. Check it with curl

```sh
curl -s http://example.test/mcp/actions \
  -H 'Authorization: Bearer 1|…' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

The answer lists one tool per MCP-exposed Read and Write action the token reaches. A wrong or expired token answers 401. With tenants, send it to `http://example.test/mcp/t/acme`: it lists the tenant-scoped actions, and a tenant the person does not belong to answers an empty list.

Call a tool the same way:

```sh
curl -s http://example.test/mcp/actions \
  -H 'Authorization: Bearer 1|…' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"create-post","arguments":{"title":"Hello","body":"My first post."}}}'
```

No `initialize` is needed first: each request is answered on its own. The call answers one of three results, as [What a call answers](#what-a-call-answers) says:

| The call | `result.content[0].text` | `result.isError` |
|---|---|---|
| a Write that ran | `Done.`, or your `modelReply()` | false |
| a Read that ran | `Found.`, a line of three dashes, then the output as JSON | false |
| refused, invalid, denied or not found | the sentence, such as `Not done. Rejected: title (required).` | true |

A tool the token cannot reach, such as a Write for a read-only token, is not on its list, so the call gets a JSON-RPC error instead of a result, with HTTP 400: `{"error":{"code":-32602,"message":"Tool [create-post] not found."}}`.

### 5. Connect a client

The URL is `mcp/actions` on your app, or the tenant path with a real segment (`mcp/t/acme`), and the credential is a bearer header. Locally, use Herd's `http://` URL; with an `https://` Herd site, Node-based clients must trust Herd's certificate authority (for example through `NODE_EXTRA_CA_CERTS`).

**Claude Code:**

```sh
claude mcp add --transport http my-app http://example.test/mcp/actions \
  --header "Authorization: Bearer 1|…"
```

`/mcp` inside Claude Code shows the server and its tools. `--scope project` writes `.mcp.json` for your team; keep the token out of the repository by writing the header value as `"Bearer ${MY_APP_MCP_TOKEN}"` there, which Claude Code reads from the environment. When the token expires, set the new one in that variable, or run `claude mcp remove my-app` and add it again.

Once the app has [OAuth](#connect-claude-chatgpt-and-other-remote-clients-oauth), Claude Code can sign in instead of carrying a pasted token: add the server without `--header`, then choose Authenticate for it in `/mcp`. The person approves on the consent screen, which names "an app on this device" as where the answer goes, and Claude Code refreshes its token itself. Its callback is a `http://localhost` address, which [Harden](#harden-the-oauth-setup) step 2's allowlist keeps.

**Cursor** (`.cursor/mcp.json` in the project, or `~/.cursor/mcp.json`):

```json
{
    "mcpServers": {
        "my-app": {
            "url": "http://example.test/mcp/actions",
            "headers": { "Authorization": "Bearer 1|…" }
        }
    }
}
```

**Claude Desktop.** Its "Add custom connector" screen signs in with OAuth: see [OAuth](#connect-claude-chatgpt-and-other-remote-clients-oauth). Its config file starts local processes, so a bearer token goes through a local bridge such as the [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) npm package, in `~/Library/Application Support/Claude/claude_desktop_config.json` on macOS:

```json
{
    "mcpServers": {
        "my-app": {
            "command": "npx",
            "args": ["-y", "mcp-remote", "http://example.test/mcp/actions", "--header", "Authorization:${MCP_AUTH}", "--allow-http"],
            "env": { "MCP_AUTH": "Bearer 1|…" }
        }
    }
}
```

Write the header with no space after the colon and keep the value in `env`, as above: some clients split arguments on spaces. `--allow-http` is for a local `http://` URL other than `localhost`; leave it out for an `https://` one. `mcp-remote` also reads headers from a file (`--header-file /path/to/headers.txt`), which keeps the token out of the process list. Restart Claude Desktop after editing the file. The bridge is a third-party tool; these flags are those of mcp-remote 0.14.

### What the client can and cannot do

- It lists and calls the Read and Write actions whose `#[Expose]` allows MCP, as the token's person, inside the URL's tenant, within the token's abilities, and never more than the person could do in the app.
- It never sees Destructive or External actions, ids an action does not advertise, or keys the forbidden-key rules hide.
- When it can show a form, it answers the forms of actions with `$askForMissing`, and sees what those forms hold.
- It is throttled per person: all of one person's tokens and tenant URLs share one budget.
- The writes it makes reach the person's open pages, and those of everyone else in the tenant, within one feed poll.

## Connect Claude, ChatGPT and other remote clients (OAuth)

A Claude custom connector, ChatGPT and other remote clients connect from their provider's cloud and sign in with OAuth 2.1: they learn from your app how to sign in, register themselves, send the person to your app to approve, and use the token they get back. There is no field for a pasted token. The package does this on [Laravel Passport](https://laravel.com/docs/passport): Passport is the OAuth server, laravel/mcp's `Mcp::oauthRoutes()` publishes discovery and registration, and the package publishes each MCP path's scopes, shows the consent screen and binds each connection to the one URL the person approved.

It is optional. Nothing changes until a Passport guard is named in `agentic-actions.mcp.middleware`, even in an app that already runs Passport for other clients.

### Before you start

- The app is served over public HTTPS. A connector reaches it from the internet: Claude from Anthropic's addresses (`160.79.104.0/21`, IPv4 only).
- It has a route named `login`, as every starter kit does. A signed-out person signs in there before approving.
- Behind a load balancer or proxy that ends TLS, `bootstrap/app.php` trusts it (`$middleware->trustProxies(at: …)`), so the metadata names the `https://` URL people enter.
- The user model keeps Sanctum's `HasApiTokens` and nothing more: that trait serves Passport's guard too. Do not add Passport's `HasApiTokens` or `OAuthenticatable` beside it; the two traits cannot be combined on one class. An app without Sanctum uses Passport's trait and `OAuthenticatable`, as Passport's docs say.

No starter kit? Any session sign-in works, as long as its route is named `login` and it ends with `redirect()->intended()`, which takes the person back to the consent screen they were sent from:

```php
// routes/web.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::view('/login', 'auth.login')->middleware('guest')->name('login');   // a form posting email and password, with @csrf

Route::post('/login', function (Request $request) {
    $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);

    if (! Auth::attempt($credentials)) {
        return back()->withErrors(['email' => __('These credentials do not match our records.')])->onlyInput('email');
    }

    $request->session()->regenerate();

    return redirect()->intended('/');
})->middleware(['guest', 'throttle:5,1']);
```

### Connect

Three files are edited by hand: `config/auth.php`, `config/agentic-actions.php` and `routes/ai.php`.

1. `composer require laravel/passport`
2. `php artisan passport:install` (the keys and Passport's tables). It asks two questions: answer yes to running the migrations, and no to the "personal access" grant client. Connectors register their own clients through `oauth/register`, and the package needs no personal access client.
3. In `config/auth.php`, under `guards`:

    ```php
    'api' => ['driver' => 'passport', 'provider' => 'users'],
    ```

4. In `config/agentic-actions.php`, name that guard after Sanctum's. This line is the switch: with it, the package registers its scopes, its consent screen and its checks.

    ```php
    'mcp' => [
        // ...
        'middleware' => ['auth:sanctum,api', 'throttle:agentic-actions-mcp'],
    ],
    ```

    Keep Sanctum first. With `auth:api,sanctum`, every Sanctum token answers 401, and `actions:check` fails. An app without Sanctum writes `auth:api`.

5. In `routes/ai.php` (`php artisan vendor:publish --tag=ai-routes` creates it):

    ```php
    use Laravel\Mcp\Facades\Mcp;

    Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes());
    ```

6. `php artisan actions:install --mcp` publishes and runs the `agentic_mcp_connections` migration, then prints the steps still missing.
7. `php artisan actions:check` passes the OAuth row.
8. In Claude: Settings, Connectors, "Add custom connector", with the URL `https://example.com/mcp/t/acme`, then Connect. The person signs in, reads "Connect Claude to Blog?", sees `claude.ai`, Acme and the two abilities, and chooses Allow. Claude lists the tenant's Read and Write actions as tools.

Each path answers discovery on its own: a request without a token gets a 401 whose `WWW-Authenticate` header names the path's metadata and scopes, and that metadata lists the scopes the path's actions need, `actions:read` and `actions:write` when it serves both. Clients ask for exactly those.

### What the person sees

Every authorization that names one of the package's MCP URLs shows the consent screen, even for a client the person approved before. It names the app, the client, where the answer goes (the host of the client's redirect, or "an app on this device" for a loopback address or a desktop app's scheme), the tenant, and each ability in plain words, then Allow and Deny. It cannot be framed.

The package shows its own plain page when the app has set no authorization view. It loads as a full page, even when the person comes back to it from an Inertia form such as a starter kit's sign-in. Restyle it by copying it to `resources/views/vendor/agentic-actions/consent.blade.php`. For a page of your own, keep Passport's view closure and hand it the package's data:

```php
use AgenticActions\OAuth\Consent;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

Passport::authorizationView(fn (array $parameters): Response => Inertia::render('oauth/consent', Consent::from($parameters)->toArray())->toResponse(request()));
```

`toArray()` holds `app`, `client`, `redirect` (`host`, `local`), `tenant` (a name, or null), `abilities`, `person`, and `approve` and `deny`, each with a `url`, a `method` and the `fields` to post. Post Allow and Deny as plain HTML forms with those fields as hidden inputs, never as an Inertia visit or `fetch()`: the answer redirects to the client. An app that followed laravel/mcp's docs (`view('mcp.authorize', $parameters)`) switches to `Consent`, which names the tenant and where the answer goes.

The package refuses, with one and the same 403 page, an unknown tenant or one the person does not belong to, a client registered with a grant type other than authorization code and refresh, a client with a redirect address outside plain ASCII or holding a backslash, and a client the person connected asking without one of the package's URLs. A request for a package URL without S256 PKCE, or with a scope the path does not take, answers 400. laravel/mcp's own scope, `mcp:use`, is accepted beside the path's scopes and grants nothing: a client that asks for it alone connects, and lists no tools.

### What a connection reaches

- The client's token reaches no action except, through the package's MCP server, on the one MCP URL the person approved (the base path or one tenant's path), within the read and write abilities they approved. It never reaches a Destructive or External action.
- It is a Passport token. Like any Passport token, it signs the person in on every route of a Passport guard, so your own `auth:api` routes check a scope (`scope:…` or `scopes:…` middleware) or leave that guard. `actions:check` fails an unscoped one while registration is open.
- One URL per client and person. Approving the same client for another URL moves the connection there, and every token and code it held for the earlier URL stops working.
- Approving revokes every token, refresh token and unused code the client held for the person before, so the connection's tokens all come from that approval.
- An authorization without `resource`, or naming a URL on another host, connects nothing, and its token reaches no action. Every client the package targets sends `resource`.
- A refresh keeps the connection, whatever `resource` it names. A refresh that narrows `scope` reaches only what it still covers.
- When a path gains its first Write action, connected clients keep the scopes they were granted: people connect again to use it.

### Already on Passport

The switch is the Passport guard in `mcp.middleware`; before it, nothing changes. After it:

- Passport's scope list gains `actions:read` and `actions:write`, with your own entries and descriptions kept.
- The package's consent view becomes the default only when the app has set none.
- Every `auth:api` route without a scope check fails the Token routes row of `actions:check`.
- The token lifetimes of [Harden](#harden-the-oauth-setup) step 1 are Passport's, so they apply to every Passport client of the app.
- A client connected to an MCP URL gets the 403 when it asks the same person for anything else.

### Harden the OAuth setup

Before real people connect:

1. **Short tokens.** Passport's tokens last a year by default. An hour, with 30-day refresh tokens that rotate, keeps a leaked token short-lived while Claude refreshes on its own. In `AppServiceProvider::boot()`:

    ```php
    use Carbon\CarbonInterval;
    use Laravel\Passport\Passport;

    Passport::tokensExpireIn(CarbonInterval::hour());
    Passport::refreshTokensExpireIn(CarbonInterval::days(30));
    ```

    These apply to every Passport client of the app, a mobile app's included. `actions:check` warns above one day.

2. **A redirect allowlist.** laravel/mcp's registration accepts any site by default. In `config/mcp.php` (`php artisan vendor:publish --tag=mcp-config`):

    ```php
    'redirect_domains' => ['https://claude.ai/', 'https://chatgpt.com/', 'https://vscode.dev/', 'https://insiders.vscode.dev/', 'http://localhost'],
    ```

    Add `custom_schemes` only for the desktop apps you allow (`cursor` for Cursor). `actions:check` warns on `*` outside local and testing.

3. **Rate limits for remote clients.** Every Claude user reaches your app from Anthropic's addresses, so a limit per address is shared by all of them. As connectors grow, replace `throttle:60,1` around `Mcp::oauthRoutes()` with a named limiter:

    ```php
    // app/Providers/AppServiceProvider.php, in boot()
    use Illuminate\Cache\RateLimiting\Limit;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\RateLimiter;

    RateLimiter::for('mcp-oauth', fn (Request $request): Limit => Limit::perMinute(300)->by($request->ip()));
    ```

    ```php
    // routes/ai.php
    Route::middleware('throttle:mcp-oauth')->group(fn () => Mcp::oauthRoutes());
    ```

    For Passport's `oauth/token`, an app with many connected people registers Passport's routes itself (`Passport::ignoreRoutes()`) with a limiter keyed by `client_id`. The MCP throttle itself counts per person, after sign-in ([Throttle](#throttle)).

4. **Quiet logs.** A bearer token Passport's guard cannot read, an expired one included, is otherwise logged as an error. In `bootstrap/app.php`:

    ```php
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReport(\League\OAuth2\Server\Exception\OAuthServerException::class);
    })
    ```

    It is league's class, not Passport's class of the same name.

5. **A Connected apps list** on the settings page ([below](#connected-apps)).

6. **Revoke a person's connections to a tenant they leave:**

    ```php
    McpConnection::for($user)->whereMorphedTo('tenant', $team)->get()->each->revoke();
    ```

### Connected apps

`McpConnection::for($user)` is a query over the person's connections, newest first:

```php
use AgenticActions\OAuth\McpConnection;

$connections = McpConnection::for($user)->with('client', 'tenant')->get();

// Disconnect, from a form on the person's own settings page:
McpConnection::for($request->user())->findOrFail($id)->revoke();
```

Show each one's client name and redirect host, its tenant, and the date it connected (`created_at`; `updated_at` changes when it moves). `revoke()` ends the client's tokens, refresh tokens and unused codes for that person, and deletes the connection; the next call answers 401 and the client asks the person to connect again. Runs already queued keep the grants they captured. The client itself stays, and may connect again through the screen.

Show the URL to paste into a connector too, with a copy button: `route('agentic-actions.mcp.tenant', $team)`, or `route('agentic-actions.mcp')` for the base path. With tenants routed by key, nobody guesses `mcp/t/42`.

### A public URL for a local test

The challenge and the metadata name the URL the app sees, built from the request's host and scheme. A tunnel ends TLS, and passes the public host and scheme in `X-Forwarded-Host` and `X-Forwarded-Proto`, which Laravel reads only from a proxy it trusts. Trust the tunnel, which connects from the same machine, in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '127.0.0.1');
})
```

Then open the tunnel. With Herd, which picks the site by its host name, the tunnel sets the Host header to the site's and passes its own host in `X-Forwarded-Host`:

```sh
cloudflared tunnel --url https://blog.test --http-host-header blog.test --no-tls-verify
```

Without Herd:

```sh
php artisan serve
cloudflared tunnel --url http://127.0.0.1:8000
```

Before adding the connector, run check 2 of [When Claude cannot connect](#when-claude-cannot-connect) on the tunnel's URL: `resource` must be `https://<tunnel>/mcp/t/acme`, not `blog.test` or `http://`. The connector URL is then `https://<tunnel>/mcp/t/acme`. Keep the tunnel short-lived, and never open one on an app with a known password.

### When Claude cannot connect

| What happens | Cause | Fix |
|---|---|---|
| "Couldn't reach the MCP server" | the app is not public over HTTPS; a firewall, WAF or bot rule blocks Anthropic's addresses (`160.79.104.0/21`) on the MCP, `.well-known` or `oauth` paths; the host has no IPv4 address | serve it publicly; allow those addresses on those paths; add an A record |
| sign-in never starts, or fails at once | no `Mcp::oauthRoutes()` (the 401 has no `resource_metadata`); the metadata's `resource` is `http://` or differs from the URL entered (a proxy or tunnel that ends TLS or rewrites the host without `trustProxies`, a trailing slash, another host) | `routes/ai.php`; `trustProxies` for any proxy; enter the URL exactly as the app shows it |
| registration fails | `mcp.redirect_domains` does not list the client's callback | add its prefix ([Harden](#harden-the-oauth-setup) step 2) |
| the page after sign-in answers 500 at `oauth/token`, or the MCP URL answers 500 where it should answer 401 | Passport has no keys on the server (a deploy without them); `actions:check` fails | `passport:keys` in the deploy, or `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` |
| a signed-out person lands nowhere | no route named `login` | add one |
| "This app cannot be connected to this address" | an unknown tenant, or one the person is not in; a client connected elsewhere asking without the URL; a client with other grants or an unusual redirect address | paste the URL from the app's page; remove and add the connector |
| 400 at `oauth/authorize` | the client did not use S256, or asked for scopes the path does not take | a client the package does not support |
| connected, but no tools | no Passport guard in `mcp.middleware`, or `auth:api,sanctum`; the client sent no `resource`, so no connection; the client asked only for `mcp:use`; a changed tenant slug in the saved URL; the person left the tenant; the path had only Read actions when they connected | fix the guard list; connect again; see `actions:check` |

Three `curl` checks show what a client sees:

```sh
# The challenge: a 401 naming the metadata and the path's scopes. Without the Accept header,
# the request is answered as a signed-out browser's (see Tokens and abilities).
curl -si -X POST https://example.com/mcp/t/acme -H 'Accept: application/json, text/event-stream' | grep -i www-authenticate

# The path's metadata: resource equals the URL entered, scopes_supported the path's.
curl -s https://example.com/.well-known/oauth-protected-resource/mcp/t/acme

# The authorization server: the endpoints, and S256.
curl -s https://example.com/.well-known/oauth-authorization-server
```

The authorization server's `scopes_supported` lists laravel/mcp's `mcp:use`, as does the metadata at `/.well-known/oauth-protected-resource` with no path. That is expected: clients take a path's scopes from its 401 and its own metadata, the first two checks.

### Testing OAuth

`Passport::actingAs()` builds a token without a client, which reaches no action. Create a connection with the scopes the person approved, and act with a token that names its client:

```php
use AgenticActions\OAuth\McpConnection;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;

$client = Client::factory()->create();   // or app(ClientRepository::class)->createAuthorizationCodeGrantClient(...)
$connection = new McpConnection(['client_id' => $client->id, 'scopes' => ['actions:read']]);
$connection->user()->associate($user)->tenant()->associate($team)->save();

$user->withAccessToken(new AccessToken(['oauth_client_id' => $client->id, 'oauth_user_id' => $user->id, 'oauth_scopes' => ['actions:read']]));
$this->actingAs($user, 'api');

$this->postJson('/mcp/t/'.$team->slug, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
    ->assertJsonPath('result.tools.0.name', 'list-team-posts');
```

The whole flow also runs in one process over `http://localhost`, with Passport's keys as PEM strings in `passport.private_key` and `passport.public_key`: register through `POST /oauth/register`, `GET /oauth/authorize` as the person, post the approval with the session's `authToken`, then `POST /oauth/token` with the verifier. Call `$this->app['auth']->forgetGuards()` before each bearer request, or the session of `actingAs()` answers for it.

## Testing

Send a real token, so the guard reads it:

```php
it('lists the posts tool for a read token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test', ['actions:read'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/mcp/actions', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'list-posts');
});
```

Within one test, the auth manager keeps the user a token guard resolved first, so a test that sends a second token calls `$this->app['auth']->forgetGuards()` between the two requests. A test that signed someone in with `actingAs()` calls it too before sending a bearer token.

## Turning MCP off

- `AGENTIC_ACTIONS_MCP=false` (`surfaces.mcp`) mounts nothing, lists nothing, and answers every call as not found. Jobs an MCP call queued earlier are refused in the worker.
- A null `mcp.path` (and `mcp.tenant_path`) mounts nothing at that path.

On an app with cached routes, a change reaches the routes after `php artisan route:cache` and a PHP-FPM reload. Until then, with `surfaces.mcp` off, the server still lists nothing and refuses every call.

## What is not mounted

No local (stdio) server, no OAuth server of the package's own (Passport and laravel/mcp's `Mcp::oauthRoutes()` are the app's), no resources or prompts, and no listing of any kind without a signed-in person. An app that wants its own laravel/mcp server keeps writing one, and calls `Actions::attempt()` inside its tools: the rule that only abilities listed by name count applies there too.
