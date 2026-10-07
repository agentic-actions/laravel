---
name: agentic-actions-development
description: Add or change an Agentic Actions action, wire it into a laravel/ai agent, add the copilot panel with live rows, expose actions over MCP with scoped tokens, connect a remote MCP client such as a Claude or ChatGPT connector with OAuth, let the copilot run a Destructive or External action after the person confirms, let it ask the person in a form for what a call left out, show a Read action's rows as a table, or let the model ask questions about one model's rows with a dataset. Use when a task touches AgenticActions\Action classes, #[Expose], InteractsWithActions, ActionsProtocol, useActionSync, Action::dispatch(), approvalSummary(), approvalBinding(), ApprovalCard, $askForMissing, ask(), ElicitationForm, McpConnection, agentic-actions.mcp settings, ShowsTable, Column, ActionTable, Dataset, Dimension or Measure.
---

# Agentic Actions development

One `AgenticActions\Action` class per operation serves web routes, the CLI, laravel/ai agents, MCP clients, queued jobs and TypeScript. Every caller runs the same pipeline: exposure, token abilities, tenant membership, `authorize()`, validation, `handle()` and the output allowlist. Pick the task below and follow its steps in order.

## 1. Add an action

1. Run `php artisan make:agentic-action CreatePost`. The new class is discovered but exposed nowhere, and its `authorize()` returns false.
2. Set `protected ?Effect $effect` (`Effect::Read`, `Write`, `Destructive` or `External`) and a `protected string $description` a model can act on.
3. Write `schema()`, `authorize()` and `handle()`. Give `authorize()` a `ValidatedInput $input` parameter when it must check a record the input names. Read the caller from the context only: `$context->actor(User::class)`, `$context->tenant()`, `$context->find(Post::class, $id)`, never `auth()`, `request()` or `session()`.
4. Add `#[Expose]` for every surface the effect allows, or narrow it: `#[Expose(web: true)]`, `#[Expose(agents: ['default'])]`, `#[Expose(mcp: true)]`.
5. Declare `protected array $touches` with the Inertia prop keys a success makes stale, such as `['posts']`.
6. Say no with `throw Refusal::make(__('You already have a post with that title.'))->on('title');`.
7. To run it later, queue it with `CreatePost::dispatch($input, $context)` instead of a job of your own: the worker runs the whole pipeline as the caller. A job of your own that loops over work, such as the lines of an uploaded CSV, calls `CreatePost::run($input, ActionContext::http($user, $tenant, $locale))` for each item, as https://agentic-actions.com/recipes#your-own-jobs shows; never build a context with `ActionContext::queued()`, which is internal.

```php
use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;

#[Expose]
final class CreatePost extends Action
{
    protected string $description = 'Create a draft blog post.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['posts'];

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

Check: run `php artisan actions:check --update`, review the diff of `actions.exposure.json`, and run `php artisan actions:typescript` when the action has a route.

Mistakes to avoid: calling `auth()` or `request()` inside `handle()` (it breaks for agents, MCP, the queue and the CLI), forgetting `$effect` (the action is then exposed nowhere and `actions:check` fails), declaring a Read `Effect::Write` because its first call adds a row it needs (create the row with its owner, or list its table in `protected array $initializes` and add it with `firstOrCreate()` in `initialize(ActionContext $context)`, as https://agentic-actions.com/recipes#state-created-the-first-time-it-is-read shows), and writing a controller for a file upload (a `$schema->string()->format('binary')` field takes the file on the action's own route under `#[Expose(web: true)]`, as https://agentic-actions.com/recipes#file-uploads shows).

## 2. Wire an agent

1. Run `composer require laravel/ai`.
2. On the agent: `implements HasTools`, `use InteractsWithActions`, and `#[UseToolset('default')]` (from `AgenticActions\Ai\Concerns\InteractsWithActions` and `AgenticActions\Attributes\UseToolset`).
3. Override `actionContext()` to return `ActionContext::agent(...)` built from the agent's own constructor state.
4. When a model should not see ids, give the action an `agentSchema()` and a `fromAgent()` that turns the model's words into the canonical input.

```php
#[UseToolset('default')]
final class BlogAssistant implements Agent, HasTools
{
    use InteractsWithActions, Promptable;

    public function __construct(public User $user) {}

    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
```

Check: in a test that uses `AgenticActions\Testing\ActionAssertions`, call `$this->assertAgentTools(new BlogAssistant($user))`, and run `php artisan actions:check`.

Mistakes to avoid: building the context from `auth()->user()` inside a tool or `actionContext()` (agents also run in queued jobs and commands, where no one is signed in), and expecting a bare `#[Expose]` to offer a Destructive or External action to agents (only `#[Expose(agents: [...])]` does, each call then waits for the person to confirm, and MCP never serves one; see task 5).

## 3. Add the copilot panel

1. Write the chat endpoint of https://agentic-actions.com/copilot#the-server: `$chat = ChatRequest::from($request)`, answer 422 when `$chat->isEmpty()`, and stream with `->usingProtocol(new ActionsProtocol)` (`AgenticActions\Streaming\ChatRequest` and `AgenticActions\Streaming\ActionsProtocol`).
2. Give writes a row label with `activityLabel(ActionContext $context, bool $finished)`, returning fixed `__()` sentences.
3. For `#[WithPageContext]` (`AgenticActions\Attributes\WithPageContext`), make the agent implement laravel/ai's `HasMiddleware` and return `[...$this->actionMiddleware()]` from `middleware()`.
4. In React: `useChat` with a `Chat` built from `actionsChat()`, `<ActionActivity rows>` for the rows, `useActionSync({ feed: { url } })` with the group's `_changes` route, and `useActionEdits(form.isDirty)` on every form the copilot must not overwrite.

```tsx
const [chat] = useState(() => new Chat<ActionMessage>(actionsChat({ api: '/assistant', messages: transcript })));
const { messages, sendMessage } = useChat({ chat });
const { waiting, apply } = useActionSync({ feed: feedUrl === null ? undefined : { url: feedUrl } });
```

Check: a Pest test that fakes the agent (`BlogAssistant::fake([new ToolCall('call_1', 'create-post', [...]), 'Done.'])`), posts one message to the endpoint and asserts the streamed body contains a `data-action` part.

Mistakes to avoid: building the chat history from the request (history comes only from the conversation store; `ChatRequest` reads the newest message only), and echoing or dumping inside a tool (it breaks the stream).

## 4. Expose over MCP

1. Give the app a token guard: `php artisan install:api`, then `use HasApiTokens` on the `User` model.
2. Set `agentic-actions.mcp.path` (default `mcp/actions`) for actions that are not tenant-scoped, and `agentic-actions.mcp.tenant_path`, such as `mcp/t/{tenant}`, for tenant-scoped ones. Keep `agentic-actions.mcp.middleware` on a token guard.
3. Mint tokens with named abilities only: `actions:read`, `actions:write`, and `tenant:{key}` (the primary key) to bind one tenant. Never rely on the default `['*']`, which lists nothing over MCP.
4. Add `abilities:` middleware to your other routes on the token guard, such as `GET api/user`, or delete them.
5. Connect a client with the URL and a bearer header, as https://agentic-actions.com/mcp#5-connect-a-client shows for Claude Code, Cursor and Claude Desktop.

```php
$token = $user->createToken('Claude Code', ['actions:read', 'actions:write', 'tenant:'.$tenant->getKey()], now()->addDays(90));
```

Check: run `php artisan actions:list` (each MCP action reads `open  POST …`) and `php artisan actions:check` (the MCP guard, MCP route and Token routes rows), and write a test that posts `tools/list` with a real token:

```php
$this->withToken($token->plainTextToken)
    ->postJson('/mcp/actions', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
    ->assertOk();
```

Mistakes to avoid: minting `createToken('mcp')` with its `*` default, and mounting another server or route on the path the package uses (it then does not mount, and `actions:check` fails).

## 5. Let the copilot delete, after a confirmation

1. Name the toolset on the Destructive or External action: `#[Expose(web: true, agents: ['default'])]`. A bare `#[Expose]` never offers it to agents, and MCP never serves it.
2. Check the record in an `authorize()` that takes `ValidatedInput`, through `$context->find()`.
3. Write `approvalReason()`, one fixed sentence with no record values, and `approvalSummary()`, at most 8 label => value rows built from the validated input and the record `authorize()` allowed. An input value the summary shows is exactly what will run. The card is built again just before the call runs, and a card that reads otherwise then (the record changed, or the summary shows the time) runs nothing, so show what identifies the record and matters to the person. When the call depends on more than the card can show (a whole body, every recipient), return that from `approvalBinding()` too, as strings, numbers, booleans, null and arrays of them, never a model: it is never shown, and a change before the call runs refuses it.
4. Keep `authorize()`, `prepareForValidation()`, `rules()`, `approvalSummary()` and `approvalBinding()` free of side effects: while the card is built, database writes and queued actions are refused.
5. Give the agent stored conversations: `implements Conversational` and `use RemembersConversations` (laravel/ai's contract and trait), beside `InteractsWithActions`.
6. In the chat route, choose the conversation on the server (with tenants, `Actions::conversation(TeamAssistant::class, $user, $team)`: `continueOrStart($conversation->id(), $user)` before, and `continue($conversation->open(), as: $user)` inside `respond()`), pass the agent to `ChatRequest::from($request, $agent)`, return through `respond()`, and pass `messageId` to `ActionsProtocol` (`AgenticActions\Streaming\ChatRequest`).
7. Reload with `Transcript::forUseChat($conversationId, $user, agent: $agent)` (`AgenticActions\Streaming\Transcript`). In React, render `approvalCard(messages.at(-1))` with `<ApprovalCard onAnswer>` and `addToolApprovalResponse()`.

```php
#[Expose(web: true, agents: ['default'])]
final class DeletePost extends Action
{
    protected string $description = 'Delete one of your posts.';

    protected ?Effect $effect = Effect::Destructive;

    protected array $touches = ['posts'];

    public function schema(JsonSchema $schema): array
    {
        return ['post' => $schema->integer()->required()];
    }

    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return $context->find(Post::class, $input->integer('post'))->user()->is($context->actor());
    }

    public function approvalReason(ActionContext $context): string
    {
        return __('Delete this post? This cannot be undone.');
    }

    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        $post = $context->find(Post::class, $input->integer('post'));

        return [__('Post') => $post->title, __('Status') => $post->status];
    }

    public function handle(ActionContext $context, ValidatedInput $input): void
    {
        $context->find(Post::class, $input->integer('post'))->delete();
    }
}
```

```php
Route::middleware(['auth', 'throttle:20,1'])->post('/assistant', function (Request $request) {
    $user = $request->user();
    $agent = (new BlogAssistant($user))->continueLastConversation($user);
    $chat = ChatRequest::from($request, $agent);

    return $chat->respond(fn (ChatRequest $chat) => $agent->stream($chat)->usingProtocol(new ActionsProtocol(messageId: $chat->messageId())));
});
```

A confirmation lapses after `agentic-actions.approvals.ttl` seconds (1800), and is kept in the default cache store: use database, redis, memcached or dynamodb.

Check: run `php artisan actions:check` (the Approvals, Summary and Cache store rows), and write a test that installs laravel/ai's `FakeTextGateway` on the provider (`Ai::textProvider()->useTextGateway(...)`) with a `ToolCall` for `delete-post`, posts a turn and asserts the post still exists and the stream holds a `data-approval` part, then posts the answer (an assistant message whose tool part is `approval-responded` with `approval: {id, approved: true}`) as the same user and asserts the post is gone, then posts it again and asserts 409. https://agentic-actions.com/testing#confirmations has the whole test.

Mistakes to avoid: faking the agent for the confirm half (laravel/ai resumes approvals against the provider's gateway, so an agent-level fake tests the pause only), putting record values in `approvalReason()`, listing the chat route as a CSRF exception or authenticating it with a token (only the person's session answers), and a `prepareForValidation()` or `fromAgent()` that reads the clock (the input must be the same at the answer, or the call is refused).

## 6. Ask the person for what the model left out

1. On a Read or Write action the agent is offered, set `protected bool $askForMissing = true;`. When a model's call leaves fields out or gets them wrong, the chat then shows a form instead of refusing the call, and the action runs once the person submits it, with their values. A Destructive or External action never asks: its incomplete call is refused, and its complete call gets the card of task 5. A field the web may leave out but a model must give goes in `requiredForAgents(): array`, not in an `agentSchema()`, which costs the generated route: the form then marks it required.
2. Give every field a schema title (`$schema->string()->title(__('Body'))`): it is the form's label, in the person's locale.
3. Optionally write `ask(Ask $ask, ActionContext $context): Ask` (`AgenticActions\Ask`): `message()` for the sentence above the form, `confirm()` for fields always shown for review, `choices()`, `default()` and `textarea()`. It runs on a fresh instance with the context alone, never the model's arguments, and database writes are refused while it runs: read only what the person and the tenant may see.
4. Give the agent stored conversations and the chat route of task 5: `ChatRequest::from($request, $agent)`, `respond()` and `messageId`, keeping the route's `throttle`, since the answers and their checks go through it.
5. In React, show `elicitation(messages.at(-1))` beside `approvalCard()` with `<ElicitationForm>` (`@agentic-actions/client/react`), and answer with `answerElicitation(chat, options, form.id, result)` (`@agentic-actions/client/ai-sdk`), where `options` is what `actionsChat()` was given. A submit is checked against the action's rules first, and its errors show under the fields.

```php
#[Expose(web: true, agents: ['default'])]
final class DraftPost extends Action
{
    protected string $description = 'Draft a post for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    protected bool $askForMissing = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title(__('Title'))->max(120)->required(),
            'body' => $schema->string()->title(__('Body'))->max(5000)->required(),
            'status' => $schema->string()->title(__('Status'))->enum(['draft', 'published'])->required(),
        ];
    }

    public function ask(Ask $ask, ActionContext $context): Ask
    {
        return $ask
            ->message(__('A few details for your post.'))
            ->confirm('title')
            ->textarea('body')
            ->choices('status', ['draft' => __('Draft'), 'published' => __('Published')]);
    }

    // authorize() and handle() as usual
}
```

```tsx
const form = elicitation(messages.at(-1));

{form && <ElicitationForm key={form.id} params={form.params} labels={form.labels} onAnswer={(result) => answerElicitation(chat, options, form.id, result)} />}
```

A form waits `agentic-actions.approvals.ttl` seconds (1800), like a confirmation. The model reads which fields the person filled, never the values.

The same action asks over MCP with nothing more to write, when the action allows MCP and the client is on protocol 2026-07-28 and declares form elicitation: the client gets the form as an `InputRequiredResult` and calls the tool again with the answer. Other MCP clients get the refusal naming the fields. Everything in the form reaches an MCP client, so leave profile defaults out of `ask()` when `$context->surface === Surface::Mcp`.

Check: run `php artisan actions:check` (the Approvals row), and write a test that installs laravel/ai's `FakeTextGateway` on the provider with a `ToolCall` for `draft-post` that leaves `body` and `status` out, posts a turn and asserts nothing was saved and the stream holds a `data-elicitation` part, then posts the answer as the same user (an assistant message whose tool part is `approval-responded` with `approval: {id, approved: true}` and `elicitation: {action: 'accept', content: {...}}`) and asserts the post holds the person's body and the `agent_conversation_messages` table does not. https://agentic-actions.com/testing#asking-the-person has the whole test.

Mistakes to avoid: asking for a secret (a password, a token, a PIN, a card number: such a call is refused; send the person to a page of the app instead, as https://agentic-actions.com/asking#sensitive-data-send-the-person-to-a-page shows), building a choice, a default or the message from anything the model sent, putting a URL in the message or a title, echoing the person's values in `modelReply()` (the model then reads them), and expecting a form for an object or a list of objects (never asked: the call is refused, naming the fields).

## 7. Connect a remote MCP client (OAuth)

A Claude custom connector or ChatGPT signs in with OAuth and takes no pasted token. Follow https://agentic-actions.com/mcp#connect-claude-chatgpt-and-other-remote-clients-oauth:

1. `composer require laravel/passport`, then `php artisan passport:install`.
2. Add an `api` guard with the `passport` driver in `config/auth.php`, and name it after Sanctum's in `agentic-actions.mcp.middleware`: `['auth:sanctum,api', 'throttle:agentic-actions-mcp']`. That line turns OAuth on.
3. Add `Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes());` to `routes/ai.php`.
4. Run `php artisan actions:install --mcp`, then `php artisan actions:check` (the OAuth row).
5. Give people a "Connected apps" list: `AgenticActions\OAuth\McpConnection::for($user)->with('client', 'tenant')->get()`, with `->revoke()` to disconnect, and the MCP URL to paste into the connector, with a copy button (https://agentic-actions.com/mcp#connected-apps).

Mistakes to avoid: adding Passport's `HasApiTokens` beside Sanctum's on the user model (keep Sanctum's alone; it serves both guards), listing the Passport guard before Sanctum's (every Sanctum token then answers 401), leaving `auth:api` routes without `scope:` or `scopes:` middleware (a connector's token signs the person in there), and keeping Passport's one-year token lifetime (https://agentic-actions.com/mcp#harden-the-oauth-setup).

## 8. Show a table

1. On a Read action, implement `AgenticActions\Views\ShowsTable` and declare `columns(): array` with `AgenticActions\Views\Column`: `text`, `integer`, `number`, `money` (with an ISO 4217 currency), `percent` (a fraction), `date`, `datetime` and `boolean`, each a snake-case key and a label in `__()`. The columns are the output allowlist: do not also write `outputSchema()`.
2. Return the rows from `handle()`: an array or a collection of arrays or objects, or a query builder or relation, which the package limits to `views.max_rows` (500).
3. In the copilot panel, render `viewsOf(message)` with `<ActionTable>` from `@agentic-actions/client/views`, beside the rows, and draw a chart with the app's own components from `view.table.chart` and `view.table.rows`. Give `refreshUrl` (the group's `/actions/_views`) when the action is exposed on the web.
4. For tables that survive a reload: an agent with stored conversations, `php artisan actions:install --copilot` (the `agentic_views` table), `Transcript::forUseChat($conversationId, $user, agent: $agent)`, and `model:prune` for `AgenticView` in `routes/console.php`.

```php
#[Expose(web: true, agents: ['default'])]
final class WordsPerAuthor extends Action implements ShowsTable
{
    protected string $description = 'Posts and words per author in the current tenant.';

    protected ?Effect $effect = Effect::Read;

    public function columns(): array
    {
        return [
            Column::text('author', __('Author')),
            Column::integer('posts', __('Posts')),
            Column::integer('words', __('Words')),
        ];
    }

    public function handle(ActionContext $context): Builder
    {
        return Post::query()->whereBelongsTo($context->tenant)
            ->join('users', 'users.id', '=', 'posts.author_id')
            ->groupBy('users.name')
            ->selectRaw('users.name as author, count(*) as posts, sum(posts.words) as words');
    }

    // authorize() as usual
}
```

The person sees the rows as a table; the model reads a short copy (the count, the first `views.model_rows` rows, and "say what stands out; do not repeat the rows"), so it never retypes the numbers. The chart follows the rows' shape: a date column first gives a line, a text column first gives a bar, one row of numbers gives a metric.

Check: run `php artisan actions:check` (the Tables & datasets row, and the Tables row for `agentic_views`), run the action with `php artisan actions:run words-per-author` (it prints the table), and write a test that asserts `Actions::attempt(WordsPerAuthor::class, [], $context)->output()['rows']` holds only the declared keys.

Mistakes to avoid: a table on a Write action (it shows none), a column key the rows name differently (the cell reads null), selecting a whole record into a text column (it reads null, never the record's JSON), and computing a percentage or a total for the model to repeat (put it in a column instead).

## 9. Declare a dataset

1. Extend `AgenticActions\Datasets\Dataset` (a Read action that shows a table) and set `protected string $model` to the Eloquent model whose rows it counts. With tenants, the package scopes that model's query to the tenant as `$context->find()` does.
2. Declare `dimensions(): array` with `AgenticActions\Datasets\Dimension`: at most one `time()`, plus `text()` and `enum()` (a backed enum), each on a column of the model or `relation.column` through a public method declared to return `BelongsTo`. Inside a tenant, a related row is read in the tenant's scope too; name a relation whose rows every tenant shares (countries, the users who write) in `protected array $shared`.
3. Declare `measures(): array` with `AgenticActions\Datasets\Measure`: `count`, `countDistinct`, `sum`, `avg`, `min`, `max`, `->where($column, $value)` or `->where($column, $operator, $value)` on a count or a sum (the operator is `=`, `!=`, `<>`, `<`, `<=`, `>` or `>=`, and null means "is null" with `=` and "is not null" with `!=`), `->money('EUR')`, and `ratio($name, $label, $numerator, $denominator)`. Give each a snake-case name, a label in `__()`, and a `description()` where the name does not say it all.
4. Write `authorize()` as usual, and `scope(Builder $query, ActionContext $context): void` for conditions of your own, such as the actor's rows. Do not write `schema()`, `rules()`, `columns()`, `handle()`, `agentSchema()` or `outputSchema()`: the package generates them.
5. Give the connection a statement time limit (https://agentic-actions.com/data#give-the-datasets-connection-a-time-limit), or run datasets on a replica that has one with `agentic-actions.datasets.connection`.

```php
#[Expose(web: true, agents: ['default'])]
final class PostActivity extends Dataset
{
    protected string $description = 'Posts of the current tenant: how many, how many featured, and by how many authors.';

    protected string $model = Post::class;

    protected array $shared = ['author'];   // users belong to no one tenant

    public function dimensions(): array
    {
        return [
            Dimension::time('published', __('Published'), 'published_at'),
            Dimension::text('author', __('Author'), 'author.name'),
            Dimension::enum('status', __('Status'), 'status', PostStatus::class),
        ];
    }

    public function measures(): array
    {
        return [
            Measure::count('posts', __('Posts')),
            Measure::count('featured', __('Featured'))->where('featured', true),
            Measure::countDistinct('authors', __('Authors'), 'author_id'),
            Measure::ratio('featured_share', __('Featured share'), 'featured', 'posts'),
        ];
    }

    // authorize() as usual
}
```

The model calls it with names only, such as `{"measures": ["posts"], "by": ["author"], "since": "-1m", "compare": true}`, and the person sees the table with a caption that says what was asked. Everything else is refused before any query.

Check: run `php artisan actions:check` (the Tables & datasets row reports each declaration error, a relation the tenant scope refuses, an unsupported driver and a connection with no time limit; the Model output row, a column that matches `agents.forbidden_output_keys`), run a question with `php artisan actions:run post-activity --as=1 --input='{"measures": ["posts"], "by": ["author"]}'`, and write a test that asserts `Actions::attempt(PostActivity::class, $input, $context)->output()['rows']` for rows you created.

Mistakes to avoid: a measure whose values the person may not see (a model can ask for any measure grouped by any dimension, so `authorize()` must allow every value, or the measure belongs in another dataset), `withoutGlobalScopes()`, a join or an order in `scope()` (the call fails; declare a relation dimension instead), a relation that is a `MorphTo` or has conditions of its own, and storing times in a zone other than UTC.
