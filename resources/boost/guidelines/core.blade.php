# Agentic Actions for Laravel

- One `AgenticActions\Action` class per operation serves web routes, the CLI, laravel/ai agents, MCP clients, queued jobs and TypeScript. For step-by-step tasks, use the `agentic-actions-development` skill.
- Every caller runs the same pipeline: exposure, token abilities, tenant membership, `authorize()`, validation, `handle()`, and the output allowlist.

## Writing an action

- Extend `AgenticActions\Action`. Declare `protected ?Effect $effect` (`Effect::Read`, `Write`, `Destructive` or `External`) and a `protected string $description` a model can act on.
- Write `schema(JsonSchema $schema)` with `Illuminate\JsonSchema` types, `authorize(ActionContext $context)` returning bool (add a `ValidatedInput $input` parameter when it must check a record the input names), and `handle(ActionContext $context, ValidatedInput $input)`.

@verbatim
```php
#[Expose]
final class CreatePost extends Action
{
    protected string $description = 'Create a draft blog post.';
    protected ?Effect $effect = Effect::Write;

    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->max(120)->required()];
    }

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        return $context->actor(User::class)->posts()->create($input->all());
    }
}
```
@endverbatim

- Never call `auth()`, `request()`, `Auth::user()` or `session()` inside an action. Use `$context->actor(User::class)`, `$context->tenant()`, `$context->find(Post::class, $id)` and `$context->locale`.
- Exposure: a bare `#[Expose]` opens every surface the effect allows; `#[Expose(web: true)]` or `#[Expose(agents: ['support'])]` narrows it. A bare `#[Expose]` keeps Destructive and External actions off agents, and MCP never serves them. Never register a route to an action by hand when `#[Expose]` already generates it.
- Confirmations: a Destructive or External action reaches an agent only through `#[Expose(agents: [...])]`, and runs after the person confirms a card built by `approvalSummary()`.
- An action with `$askForMissing` asks the person, in a standard form, for the fields a model's call left out; build the form in `ask()`, never from the model's words.
- Refusals: `throw Refusal::make(__('You already have a post with that title.'))->on('title');`. Never put the caller's input in a replacement.
- Agents: `implements HasTools`, `use InteractsWithActions`, `#[UseToolset]`, and override `actionContext()` to return `ActionContext::agent(...)` built from the agent's own constructor state.
- Copilot chat: choose the conversation on the server (with tenants, `Actions::conversation($agent, $user, $tenant)`: `id()` for the reload, `open()` inside `respond()`), then `$chat = ChatRequest::from($request, $agent)` and `return $chat->respond(fn ($chat) => $agent->stream($chat)->usingProtocol(new ActionsProtocol(messageId: $chat->messageId())))` (`AgenticActions\Streaming`); never build history from the request.
- Copilot rows: label a write with `activityLabel(ActionContext $context, bool $finished)` returning fixed `__()` sentences, never arguments or state. A hand-written laravel/ai tool shows a row by implementing `DescribesActivity` and calling `Activity::record($request, ok: …)` with its outcome.
- `#[WithPageContext]` needs the agent to implement `HasMiddleware` and return `[...$this->actionMiddleware()]` from `middleware()`.
- MCP: the package mounts one server at `agentic-actions.mcp.path` (and `tenant_path`) for every action whose `#[Expose]` allows it; never mount another server on that path. Tokens name abilities literally (`actions:read`, `actions:write`, `tenant:{key}`); `*` never counts inside MCP.
- Queue: `CreatePost::dispatch($input, $context)` queues the whole pipeline as the caller; never wrap an action in a job of your own, and never queue a write from inside a Read.
- Change feed: pages that should hear of writes made elsewhere pass `feed: { url: route('actions._changes', …) }` to `useActionSync()` or `createActionSync()`.
- `php artisan actions:install` publishes the config and the migrations the app's features need, and asks before it migrates. After changing an action, run `php artisan actions:check --update` and review the diff of `actions.exposure.json`. Run `php artisan actions:typescript` when a routed action changed.
- Tests: `Actions::fake()`, `$this->assertToolset()` and `$this->assertAgentTools()` from the `AgenticActions\Testing\ActionAssertions` trait, and `$this->artisan('actions:check')->assertSuccessful()`.
