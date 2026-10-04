# Agentic Actions for Laravel

<!-- #region getting-started -->
> **Beta.** 0.9.0-beta.2 is the current release. The API can still change before 1.0: [the changelog](https://github.com/agentic-actions/laravel/blob/main/CHANGELOG.md) lists every change and how to upgrade.

**Try it first** at [demo.agentic-actions.com](https://demo.agentic-actions.com): a team task board built with the package, where you get a private sandbox for 24 hours with no sign-up. Its forms, its copilot and MCP clients all call the same actions. The source is [agentic-actions/demo](https://github.com/agentic-actions/demo).

Write an operation once, as an Action class. Mark it `#[Expose]` and the same class answers a web route (JSON and browser forms, with Precognition), an Artisan command, a tool call from a [laravel/ai](https://github.com/laravel/ai) agent or an MCP client, and a typed TypeScript function. Every caller goes through one pipeline: exposure, token abilities, tenant membership, `authorize()`, validation, `handle()`, and an allowlist on the output.

<!-- #region create-post -->
```php
<?php

namespace App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

#[Expose]
final class CreatePost extends Action
{
    protected string $description = 'Create a draft blog post. Publishing is a separate action.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['posts'];

    protected bool $tenantScoped = false; // Only matters once config('agentic-actions.tenant.model') is set.

    /**
     * The post's fields.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(120)->required(),
            'body' => $schema->string()->required(),
            'excerpt' => $schema->string()->max(200)->nullable()->description('One line for listings. Left empty when omitted.'),
        ];
    }

    /**
     * What the caller gets back. Keys not declared here never leave the server.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
        ];
    }

    /**
     * Any signed-in author may draft a post.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * Save the draft.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        $author = $context->actor(User::class);

        if ($author->posts()->where('title', $input->string('title')->toString())->exists()) {
            throw Refusal::make(__('You already have a post with that title.'))->on('title');
        }

        return $author->posts()->create([...$input->all(), 'status' => 'draft']);
    }
}
```
<!-- #endregion create-post -->

That class is already a JSON endpoint:

```bash
curl https://example.com/api/actions/create-post \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"title": "Hello", "body": "My first post."}'

{"id":1,"title":"Hello"}
```

And a plain Blade form can post to it:

```blade
<form method="POST" action="{{ route('actions.create-post') }}">
    @csrf
    <input name="title" value="{{ old('title') }}">
    <textarea name="body">{{ old('body') }}</textarea>
    <button>Save draft</button>
</form>
```

## What that one class gets

| Surface | What you get |
|---|---|
| Web | `POST /actions/create-post`, named `actions.create-post`, for JSON callers and browser forms, with Precognition. Mount it in as many route groups as you need (web, `api.`, a tenant group). |
| CLI | `php artisan actions:run create-post title=Hi body=… --as=1` |
| TypeScript | `createPost()` with typed input and output, in the file `php artisan actions:typescript` writes |
| Agents | a tool in the `default` toolset, after `composer require laravel/ai` |
| MCP | a tool on the package's MCP server, for a token with the `actions:write` ability ([MCP](https://github.com/agentic-actions/laravel/blob/main/docs/mcp.md)) |
| Queue | `CreatePost::dispatch($input, $context)` runs the same pipeline in a worker, as the caller |

An action that removes something other people rely on (`Effect::Destructive`) or reaches outside the app (`Effect::External`) gets the web route and the CLI. MCP clients never reach it. An agent reaches it only when `#[Expose(agents: [...])]` names a toolset, and each call then waits for the person to confirm it on a card the server builds: see [confirmations](https://github.com/agentic-actions/laravel/blob/main/docs/copilot.md#confirmations). A Read or Write action with `$askForMissing` asks the person, in a form in the chat, for the fields a model's call left out, instead of refusing the call: see [asking the person](https://github.com/agentic-actions/laravel/blob/main/docs/asking.md).

## Installation

You need PHP 8.3 or later and Laravel 12.62+ or 13.15+. laravel/ai 1.x is optional, for agent tools.

```bash
composer require agentic-actions/laravel:^0.9@beta
php artisan actions:install
```

`actions:install` asks which features your app uses (web routes, agents and a copilot, confirmations, MCP, tenants), publishes the config and the migrations those features need, prints the route lines, and asks before it migrates. Run it again after adding a feature. [Setup](https://github.com/agentic-actions/laravel/blob/main/docs/setup.md) lists what each feature needs and what the package depends on.

Mount the generated routes inside your own middleware, in `routes/web.php`:

```php
use AgenticActions\Facades\Actions;

Route::middleware('auth')->group(fn () => Actions::routes());
```

For token clients, run `php artisan install:api`, add the `HasApiTokens` trait to your `User` model as it asks, then in `routes/api.php`:

```php
use AgenticActions\Facades\Actions;

Route::middleware('auth:sanctum')->name('api.')->group(fn () => Actions::routes());
```

Until one of these lines exists, `php artisan actions:list` prints both whenever an action is open on the web.

Then record what your actions expose:

```bash
php artisan actions:check --update
```

That writes `actions.exposure.json`, which you commit. From then on `actions:check` fails whenever an action's exposure changes (a new route, a new toolset, another effect) until someone reviews the diff and runs `--update` again. Run `php artisan actions:check` in CI, or inside your test suite.

For the TypeScript client, install the npm package from the Composer package, so the two versions always match. In `package.json`:

```json
"dependencies": {
    "@agentic-actions/client": "file:vendor/agentic-actions/laravel/js"
}
```

The client is also on npm (`npm install @agentic-actions/client`); install the same version as the Composer package. Run `npm install`, then `php artisan actions:typescript`, which writes `resources/js/agentic/actions.ts`.

Installed that way, the client's imports of React and Inertia resolve to your app's own copies. When Composer installs the package from a local path instead (a `path` repository, which symlinks a checkout that has its own `js/node_modules`), Vite follows the symlink and can load a second React or Inertia: React reports an invalid hook call, or the client reloads through a router that is not your app's. Dedupe them in `vite.config.ts`:

```ts
export default defineConfig({
    resolve: {
        dedupe: ['react', 'react-dom', '@inertiajs/core', '@inertiajs/react'],
    },
    // ...
});
```

`php artisan make:agentic-action CreatePost` writes a new action that is discovered but exposed nowhere, whose `authorize()` returns false until you decide who may run it. Actions are discovered under `app/`; `php artisan vendor:publish --tag=agentic-actions-config` publishes the config if yours live elsewhere. The tags `agentic-actions-lang` and `agentic-actions-stubs` publish the sentences callers and agents read, and the stub `make:agentic-action` writes from.

## Calling an action

### Blade

```blade
<form method="POST" action="{{ route('actions.create-post') }}">
    @csrf

    <input name="title" value="{{ old('title') }}">
    @error('title') <p>{{ $message }}</p> @enderror

    <textarea name="body">{{ old('body') }}</textarea>
    @error('body') <p>{{ $message }}</p> @enderror

    @error('action') <p>{{ $message }}</p> @enderror

    <button>Save draft</button>
</form>

@if (session('action'))
    <p>Saved {{ session('action')['output']['title'] }}.</p>
@endif
```

On success the visitor is redirected back with a 303, and `session('action')` holds `['name' => 'create-post', 'output' => ['id' => 1, 'title' => 'Hello']]`. Override `redirectTo()` on the action to send them somewhere else. Invalid input comes back the way it does from a form request: errors in the `default` bag (or the action's `$errorBag`), and the old input without your exception handler's `dontFlash` keys. The duplicate-title refusal lands on `title` because of `->on('title')`; a refusal without a field lands on `action`.

### Token client

A Sanctum token calls the `api.` mount. Sanctum's default token (`['*']`) passes the ability check for every effect. To narrow one, grant an ability per effect:

```php
$token = $user->createToken('importer', ['actions:read', 'actions:write'])->plainTextToken;
```

| Ability | Reaches |
|---|---|
| `actions:read` | Read actions |
| `actions:write` | Write actions |
| `actions:destructive` | Destructive actions |
| `actions:external` | External actions |
| `tenant:{id}` | only that tenant, by its primary key; the token then reaches no action outside a tenant |

From another application:

```php
$post = Http::withToken($token)
    ->acceptJson()
    ->post('https://example.com/api/actions/create-post', [
        'title' => 'Hello',
        'body' => 'My first post.',
    ])
    ->throw()
    ->json();
```

A token without the ability gets a 404, the status an action that does not exist gets. The web mount never reads a bearer token. A guard that is neither the session nor Sanctum reads as having no abilities until you bind your own reader ([recipe](https://github.com/agentic-actions/laravel/blob/main/docs/recipes.md#a-token-reader-for-a-jwt-or-api-key-guard)), and `php artisan actions:list` shows how each configured guard is read.

### curl

```bash
curl https://example.com/api/actions/create-post \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"title": "Hello", "body": "My first post."}'
```

| Case | Status | Body |
|---|---|---|
| Success | 200 | `{"id": 1, "title": "Hello"}`: only the keys `outputSchema()` declares, or `{}` |
| Invalid input | 422 | `{"message": "…", "errors": {"title": ["…"]}}` |
| A refusal on a field (the same title twice) | 422 | `{"message": "…", "errors": {"title": ["You already have a post with that title."]}}` |
| A refusal without a field | 409, or the status it sets | `{"message": "…", "code": "…", "details": {}}` |
| Token without the ability, not a member of the tenant, `shouldRegister()` said no | 404 | `{"message": "Not found."}` |
| No such action on the web (unknown, or not exposed there) | 404 | your app's usual 404: no route matches |
| `authorize()` said no | 403 | `{"message": "You are not allowed to do this."}` |
| No user | 401 | `{"message": "Unauthenticated."}` |

`Idempotency-Key` is optional. An action that needs one calls `$context->requireIdempotencyKey()`, which answers 428 when the header is missing. Send `Precognition: true` to validate without running the action.

### Livewire

```php
<?php

namespace App\Livewire;

use AgenticActions\ActionContext;
use AgenticActions\Refusal;
use App\Actions\CreatePost;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class CreatePostForm extends Component
{
    public string $title = '';

    public string $body = '';

    public function save(): void
    {
        try {
            CreatePost::run(['title' => $this->title, 'body' => $this->body], ActionContext::http(Auth::user()));
        } catch (Refusal $r) {
            throw $r->toValidationException();
        }

        $this->redirectRoute('posts.index');
    }
}
```

`run()` goes through the same pipeline in-process. It returns what `handle()` returned, throws a `ValidationException` for invalid input (Livewire shows it as field errors), and throws a `Refusal` for anything else that stopped the call. `toValidationException()` puts the refusal's message on its field, here `title`, or on `action`. The same three lines work in a Blade controller.

### Other front ends

`@agentic-actions/client` has no dependencies. `callAction(createPost(), { title, body })` posts JSON with an `Idempotency-Key` and, on the same origin, the XSRF header; it resolves the typed output or throws `ActionValidationError`, `ActionRefusedError` or `ActionFailedError`. After each success it hands the action's `$touches` (here `['posts']`) to every handler registered with `onTouched()`, so your own store or cache can refetch what went stale. On Inertia, `@agentic-actions/client/inertia` reloads the props those keys name, and `@agentic-actions/client/react` wraps Inertia's `useHttp` in `useAction()` ([recipe](https://github.com/agentic-actions/laravel/blob/main/docs/recipes.md#forms-on-inertia-react)).

<!-- #endregion getting-started -->

## Agents

After `composer require laravel/ai`, an agent receives the actions of its toolsets as tools:

```php
<?php

namespace App\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

#[UseToolset]
final class BlogAssistant implements Agent, HasTools
{
    use Promptable;
    use InteractsWithActions;

    public function __construct(public User $user) {}

    public function instructions(): string
    {
        return 'You help the signed-in author manage their blog posts.';
    }

    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
```

The tools are built for that user on every turn, from the agent's own state. The agent sees only the actions of its toolsets that pass for this user before any arguments exist: `shouldRegister()`, membership and an `authorize()` that takes no input. An `authorize()` that takes `ValidatedInput` runs when the model calls the tool, so put role and permission checks in an `authorize()` without input, and row checks in `handle()` or an input-aware `authorize()` ([what the tool list shows](https://github.com/agentic-actions/laravel/blob/main/docs/concepts.md#what-an-agents-tool-list-shows)). Each tool answers the model with a short sentence, such as "Done." or "Not done. Rejected: title (max).", and exception messages and submitted values never reach it. Run `php artisan actions:check --update` after installing laravel/ai: `create-post` joining the `default` toolset shows up in `actions.exposure.json` as a change to review.

## MCP

MCP clients such as Claude Code, Cursor and Claude Desktop reach the same actions through one server the package mounts at `mcp/actions`, once the app has a token guard (`php artisan install:api`). Each client connects with a person's token, and a token lists only the abilities it names: `actions:read`, `actions:write`, and `tenant:{id}` to bind it to one tenant.

```php
$token = $user->createToken('Claude Code', ['actions:read', 'actions:write'], now()->addDays(90))->plainTextToken;
```

```bash
claude mcp add --transport http my-app https://example.com/mcp/actions --header "Authorization: Bearer $TOKEN"
```

The client sees the Read and Write actions whose `#[Expose]` allows MCP, runs them as that person through the whole pipeline, and reads the same sentences an agent reads. Sanctum's default `['*']` token lists nothing over MCP. See [docs/mcp.md](https://github.com/agentic-actions/laravel/blob/main/docs/mcp.md) for the tenant path, the throttle, Cursor and Claude Desktop.

Remote clients that sign in with OAuth, such as a Claude custom connector or ChatGPT, connect once the app adds Laravel Passport and names its guard in `mcp.middleware`: the person approves one URL on a consent screen, and the client's token reaches only that URL ([OAuth](https://github.com/agentic-actions/laravel/blob/main/docs/mcp.md#connect-claude-chatgpt-and-other-remote-clients-oauth)).

## Copilot

Stream the agent into your page and each tool call shows up as a live row, "Saving…" and then "Draft saved", while the page reloads the props the write touched. The package ships the pieces. Your app gives the agent above laravel/ai's `Conversational` contract and `RemembersConversations` trait, so each turn is stored, and writes one route:

```php
use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\ChatRequest;

Route::middleware('auth')->post('/assistant', function (Request $request) {
    $chat = ChatRequest::from($request);

    if ($chat->isEmpty()) {
        return response()->json(['message' => __('agentic-actions::stream.empty', ['max' => config('agentic-actions.agents.max_message_length')])], 422);
    }

    return (new BlogAssistant($request->user()))
        ->continueLastConversation($request->user())
        ->stream($chat)
        ->usingProtocol(new ActionsProtocol);
});
```

`ChatRequest` reads only the newest message's words, since the history comes from laravel/ai's conversation store. With tenants, `Actions::conversation(BlogAssistant::class, $user, $team)` keeps one conversation per person, agent and tenant ([one conversation per tenant](https://github.com/agentic-actions/laravel/blob/main/docs/copilot.md#one-conversation-per-tenant)). `ActionsProtocol` sends the stream `useChat` reads, built from an allowlist: the reply's words, one `data-action` row per tool call, and nothing of the tools' arguments or results. On the page, `actionsChat()` from `@agentic-actions/client/ai-sdk` wires a `useChat` panel, `<ActionActivity>` renders the rows, and `useActionSync()` reloads what went stale, holding back while an editor has unsaved work. `#[WithPageContext]` tells the agent which Inertia page is open. See [docs/copilot.md](https://github.com/agentic-actions/laravel/blob/main/docs/copilot.md) for the whole recipe.

**Confirmations.** A Destructive or External action that names the agent's toolset runs only after the person confirms that call on a card the server builds, from their own session, once. See [confirmations](https://github.com/agentic-actions/laravel/blob/main/docs/copilot.md#confirmations).

**Asking the person.** When the model calls a Read or Write action with `$askForMissing` and leaves fields out, the chat shows a form in the shape of MCP's form elicitation, built on the server from the action's schema and `ask()`. The action runs once the person submits it, with their values, which the model never reads. See [asking the person](https://github.com/agentic-actions/laravel/blob/main/docs/asking.md).

**Tables.** A Read action that implements `ShowsTable` declares its columns and returns rows. The person sees them as a table in the chat, drawn by your page with `<ActionTable>` from `@agentic-actions/client/views`, and a chart your own components draw. The model reads a short copy and says what stands out, so the numbers the person reads are the query's, never retyped. A table is kept with its conversation for reloads, and can be refreshed. See [tables](https://github.com/agentic-actions/laravel/blob/main/docs/data.md).

## Testing

```php
$fake = Actions::fake([CreatePost::class => ['id' => 1, 'title' => 'Hello']]);

// ... the code under test calls CreatePost::run(), a route or an agent tool ...

$fake->assertRan(CreatePost::class, fn (array $input) => $input['title'] === 'Hello');
```

The `ActionAssertions` trait adds `assertToolset()` and `assertAgentTools()` to a PHPUnit or Pest test case. See [docs/testing.md](https://github.com/agentic-actions/laravel/blob/main/docs/testing.md).

## Documentation

- [Setup](https://github.com/agentic-actions/laravel/blob/main/docs/setup.md): what the package depends on, what each feature needs (packages, tables, routes, config), and `actions:install`
- [Concepts](https://github.com/agentic-actions/laravel/blob/main/docs/concepts.md): context, surfaces, effects, doors, exposure, the manifest and the snapshot, tenants, queued runs and the change feed
- [Copilot](https://github.com/agentic-actions/laravel/blob/main/docs/copilot.md): live rows while an agent works, confirmations before Destructive and External calls, the page following its writes and writes made elsewhere, and the page context
- [Asking the person](https://github.com/agentic-actions/laravel/blob/main/docs/asking.md): a form in the chat for the fields a model's call left out, what it can hold, and why it never asks for secrets
- [Tables](https://github.com/agentic-actions/laravel/blob/main/docs/data.md): a Read action's rows as a table the person sees, the model's short copy, the chart, and tables kept with the conversation
- [MCP](https://github.com/agentic-actions/laravel/blob/main/docs/mcp.md): the MCP server, its tokens and tenant paths, connecting Claude Code, Cursor and Claude Desktop, and OAuth for Claude and ChatGPT connectors
- [Security](https://github.com/agentic-actions/laravel/blob/main/docs/security.md): what the package guarantees, and where each guarantee stops
- [Testing](https://github.com/agentic-actions/laravel/blob/main/docs/testing.md): fakes, toolset assertions, confirmations and forms, queued runs and tokens in tests
- [Recipes](https://github.com/agentic-actions/laravel/blob/main/docs/recipes.md): strict agent schemas, controllers and Livewire, discovery layouts, custom token guards
- [Migrating from laravel-actions](https://github.com/agentic-actions/laravel/blob/main/docs/migrating-from-laravel-actions.md)

## Security

Please report vulnerabilities privately, as [SECURITY.md](SECURITY.md) describes.

## License

Agentic Actions is open-source software released under the [MIT license](LICENSE.md).
