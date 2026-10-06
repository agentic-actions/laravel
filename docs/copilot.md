# The copilot

An agent built on your actions can run inside the page the person is using. While it works, each tool call shows up as a row ("Saving…", then "Draft saved"), and once a write succeeds the page reloads what it made stale, or waits while the person has unsaved work.

The package gives you the parts: a stream protocol for laravel/ai, a reader for the request, the transcript for a reload, a conversation per tenant, and the client helpers. It ships no chat endpoint and no agent. You write one route, as below.

You need laravel/ai 1.x and its conversation tables: `php artisan actions:install --copilot` publishes them and asks before it migrates ([setup](setup.md#what-each-feature-needs)). Set laravel/ai's default provider in `config/ai.php` and its key in `.env`, such as `OPENAI_API_KEY` (see laravel/ai's [configuration](https://laravel.com/docs/ai-sdk#configuration)). When the provider fails a turn, the person sees one fixed sentence, and the provider's own error goes to your exception handler ([the stream](#the-stream)).

The browser side uses the [AI SDK](https://ai-sdk.dev)'s `ai` 7 package through `@agentic-actions/client/ai-sdk`, and `useChat` from `@ai-sdk/react` 4, the line built on `ai` 7, if you build the panel in React. Install them beside the client itself ([setup](setup.md#what-the-package-requires)):

```bash
npm install ai@^7 @ai-sdk/react@^4
```

Two parts need Inertia: [page context](#page-context) (`#[WithPageContext]` on the agent, with the `HasMiddleware` and `middleware()` that carry it) and `@agentic-actions/client/react`, which also takes React 19 and `@inertiajs/react` 3. Without Inertia, leave those three off the agent below, and read the stream as [without React](#without-react) shows.

## The server

The agent is an ordinary laravel/ai agent that remembers its conversations:

```php
<?php

namespace App\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use AgenticActions\Attributes\WithPageContext;
use App\Models\User;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

#[UseToolset]
#[WithPageContext]
final class BlogAssistant implements Agent, Conversational, HasMiddleware, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

    public function __construct(public User $user) {}

    public function instructions(): string
    {
        return 'You help the signed-in author manage their blog posts.';
    }

    public function middleware(): array
    {
        return [...$this->actionMiddleware()];
    }

    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
```

`InteractsWithActions` supplies `tools()`, and `RemembersConversations` supplies `messages()`. `php artisan make:agent` writes its class to `App\Ai\Agents` (the package finds an agent anywhere under `discovery.paths`, `app` by default) with its own `tools()` and `messages()`, both returning `[]`. If you start from it, delete those two methods: a method the class declares takes the place of the trait's, so the agent would run with no action tools and no history. To add tools of your own, spread `actionTools()` into your `tools()`, as [hand-written tools](#hand-written-tools) shows. `actions:check` warns about an agent whose own `tools()` never calls `$this->actionTools()`, and `Actions::assertAgentTools()` fails one whose tools leave out the actions its toolsets give the person ([testing](testing.md#toolsets-and-agents)).

The route, in `routes/web.php`:

```php
use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\ChatRequest;
use App\Ai\BlogAssistant;
use Illuminate\Http\Request;

Route::middleware(['auth', 'throttle:20,1'])->post('/assistant', function (Request $request) {
    $chat = ChatRequest::from($request);

    if ($chat->isEmpty()) {
        return response()->json([
            'message' => __('agentic-actions::stream.empty', ['max' => config('agentic-actions.agents.max_message_length')]),
        ], 422);
    }

    return (new BlogAssistant($request->user()))
        ->continueLastConversation($request->user())
        ->stream($chat)
        ->usingProtocol(new ActionsProtocol);
})->name('assistant');
```

What each piece does:

- `ChatRequest::from()` reads one thing: the text of the last message in the body's `messages` (given the agent, the person's answers to a confirmation instead, see [confirmations](#confirmations)). `useChat` and most chat front ends post the whole history on every turn; everything before the last message is ignored, so they work unchanged. Only `text` parts are kept, joined by new lines. A last message that is not the user's, has no text, or runs over `agents.max_message_length` characters (4,000 by default) reads as empty, and the route answers 422 with one sentence, as JSON whatever the `Accept` header says.
- History never comes from the request. The agent's conversation supplies it, from laravel/ai's store.
- The server chooses the conversation. `continueLastConversation()` picks this user's latest conversation with this agent class. The store keeps no tenant, so a multi-tenant app keeps one conversation per user, agent and tenant with `Actions::conversation()` ([below](#one-conversation-per-tenant)). Check any conversation id that arrives from anywhere else before you use it: `conversationBelongsTo()` on laravel/ai's store for the user, and your own mapping for the tenant.
- Files come through your own upload path, passed as `stream()`'s second argument. The chat request never carries them.
- The route sits in the `web` group, so the session and CSRF apply. The client preset sends the `X-XSRF-TOKEN` header to a same-origin endpoint.
- `ActionsProtocol` writes the stream. It is the Vercel UI message stream that `useChat` reads, built from an allowlist (see [the stream](#the-stream)).

To reword the empty-message sentence, or any other line, publish the language files with `php artisan vendor:publish --tag=agentic-actions-lang`.

### One conversation per tenant

A person who belongs to two teams keeps one conversation in each. `Actions::conversation($agent, $user, $tenant)` finds it in the package's `agentic_conversations` table, which maps a person, a tenant and an agent class to one laravel/ai conversation:

```php
use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\ChatRequest;
use App\Ai\TeamAssistant;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

Route::middleware(['auth', 'throttle:20,1'])->post('/teams/{team}/assistant', function (Request $request, Team $team) {
    Gate::authorize('view', $team);

    $user = $request->user();
    $conversation = Actions::conversation(TeamAssistant::class, $user, $team);
    $agent = (new TeamAssistant($user, $team))->continueOrStart($conversation->id(), $user);

    return ChatRequest::from($request, $agent)->respond(fn (ChatRequest $chat) => $agent
        ->continue($conversation->open(), as: $user)
        ->stream($chat)
        ->usingProtocol(new ActionsProtocol(messageId: $chat->messageId())));
})->name('teams.assistant');
```

- `id()` returns the conversation's id, or null before the person's first message there. `open()` returns the same id, and the first time starts the conversation in laravel/ai's store, as the person, titled after the agent (`open('Acme board')` names it). The conversation is opened inside `respond()`, so a request that is refused (422, 409) opens nothing.
- Opened before the turn, the conversation needs no title from the model, and a first turn that fails still lands in the conversation the next turn continues.
- The key is what your route passes: the signed-in user, the tenant your route already checked, and the agent class (or the agent itself). No id from the request is ever read, so one person or tenant never reaches another's conversation through it. Check that the person belongs to the tenant before you call it, as the route's `Gate::authorize()` does, or mount the route in a group whose middleware does.
- Two first messages sent at the same instant share one conversation. Pass `null` as the tenant for a conversation outside any tenant; there, two first messages sent at the same instant can each open one, and the older is used from then on.
- The row is deleted with its laravel/ai conversation. `AgenticActions\Streaming\AgenticConversation` is the model, for listing or deleting a person's rows.

The reload reads the same id:

```php
$user = $request->user();
$conversationId = Actions::conversation(TeamAssistant::class, $user, $team)->id();

return Inertia::render('Board', [
    'transcript' => $conversationId === null ? [] : Transcript::forUseChat(
        $conversationId,
        $user,
        agent: (new TeamAssistant($user, $team))->continue($conversationId, as: $user),
    ),
]);
```

The table comes from `php artisan actions:install --copilot --tenancy`, or `php artisan vendor:publish --tag=agentic-actions-migrations` after laravel/ai's migrations, then `php artisan migrate`. It is never created unless you publish it, and it lives on laravel/ai's conversation connection, beside the tables of laravel/ai's database conversation store, which it needs.

### Your own parts

`ActionsProtocol` takes one closure, called once when the turn ends, after the rows close. It returns your own `data-*` parts, and its second argument is true when the turn failed:

```php
use Laravel\Ai\Responses\StreamableAgentResponse;

new ActionsProtocol(after: fn (StreamableAgentResponse $response, bool $failed): array => [
    ['type' => 'data-blog-stats', 'data' => ['drafts' => $request->user()->posts()->where('status', 'draft')->count()]],
]);
```

Each part needs a `data-` type and a `data` key, and may have a string `id`. `data-action`, `data-approval` and `data-elicitation` belong to the package. Any other key is dropped, and a part that breaks these rules is reported and left out. If the closure throws, the exception is reported and the stream still ends properly.

### Bookkeeping

To record spend or anything else per turn, use laravel/ai's `then()` (on success) and `catch()` (on failure) on the streamed response, before `usingProtocol()`. Both run after the person closes the tab too, because the request keeps running to the end of the turn.

### Reloading the chat

The browser keeps the chat only until the page reloads. The page's controller passes the stored words back (for a panel in a persistent layout, share them instead, as in [the transcript in a layout](#the-transcript-in-a-layout)):

```php
use AgenticActions\Streaming\Transcript;
use App\Ai\BlogAssistant;
use Illuminate\Http\Request;
use Inertia\Inertia;

public function index(Request $request)
{
    $user = $request->user();
    $conversationId = (new BlogAssistant($user))->continueLastConversation($user)->currentConversation();

    return Inertia::render('Posts/Index', [
        'transcript' => $conversationId === null ? [] : Transcript::forUseChat($conversationId, $user),
    ]);
}
```

`Transcript::forUseChat($conversationId, $user, $limit = 40)` returns the newest messages, oldest first, whatever `?cursor=` the page's own request carries for another list, in the shape `useChat` takes as its initial messages: one text part per user or assistant message, and nothing else. It never includes tool calls, their results or attachments; given the agent as well, it adds the card of a call still waiting for the person (see [confirmations](#reloading-a-waiting-card)) and the tables the conversation's turns showed (see [tables](data.md#kept-with-the-conversation)). It returns an empty list when laravel/ai's store says the conversation belongs to someone else. A store that cannot answer that question leaves the check to you, so pass only a conversation id you chose for this user.

The transcript shows the stored turns. A turn is stored once the model has finished its first step (its first answer, whether words or a tool call):

- A turn that fails after that, for example in a tool, keeps the person's words. The failed reply is left out, and the words that started it stay, because the model's history keeps them too.
- A turn that fails before that stores nothing, for example when the provider answers 401 or 429, or its stream breaks during the first answer. Neither the words nor the reply are stored, in a new conversation or an existing one. In a new conversation the conversation itself is not created either, so on the next turn `continueLastConversation()` finds the person's latest earlier conversation with the agent, or none.

The chat keeps showing those words until the page reloads. After that they are gone, and the history of later turns does not have them. If the person should be able to send them again, put the text back in the input when a turn fails.

Send the page the transcript, never the stored conversation models.

## The panel (Inertia React)

Mount the panel in a persistent layout, so the chat survives Inertia visits:

```tsx
import { Chat, useChat } from '@ai-sdk/react';
import { usePage } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { messageSegments } from '@agentic-actions/client';
import { actionsChat, refusalMessage, type ActionMessage } from '@agentic-actions/client/ai-sdk';
import { ActionActivity, useActionSync } from '@agentic-actions/client/react';

export function AssistantPanel({ transcript, feedUrl }: { transcript: ActionMessage[]; feedUrl: string | null }) {
    const page = usePage();
    const current = useRef(page);
    current.current = page;

    const [chat] = useState(() => new Chat<ActionMessage>(actionsChat({
        api: '/assistant',
        messages: transcript,
        body: () => ({ page: { url: current.current.url, component: current.current.component } }),
    })));

    const { messages, sendMessage, status, error } = useChat({ chat });
    const { waiting, apply } = useActionSync({ feed: feedUrl === null ? undefined : { url: feedUrl } });
    const [input, setInput] = useState('');
    const busy = status === 'submitted' || status === 'streaming';

    function submit(event: FormEvent) {
        event.preventDefault();
        sendMessage({ text: input });
        setInput('');
    }

    return (
        <aside>
            {messages.map((message, index) => (
                <div key={message.id} data-role={message.role}>
                    {messageSegments(message, { settled: !busy || index < messages.length - 1 }).map((segment, position) =>
                        segment.type === 'text' ? <p key={position}>{segment.text}</p> : <ActionActivity key={position} rows={segment.rows} />,
                    )}
                </div>
            ))}
            {waiting && <button type="button" onClick={apply}>Refresh</button>}
            {error && <p role="alert">{refusalMessage(error) ?? error.message}</p>}
            <form onSubmit={submit}>
                <textarea value={input} onChange={(event) => setInput(event.target.value)} />
                <button disabled={busy}>Send</button>
            </form>
        </aside>
    );
}
```

The pieces:

- `actionsChat()` returns the `ChatInit` for `new Chat(...)`. Its transport posts only the newest message's text, or the person's answers to a confirmation or a form (see [confirmations](#confirmations) and [asking the person](asking.md)), plus whatever `body()` returns at send time (here, the page for `#[WithPageContext]`). It sends `Accept: application/json, text/event-stream`, so Laravel answers errors (401, 419, 429) as JSON, and `X-XSRF-TOKEN` from the cookie when the endpoint is on the page's own origin. Options: `headers` (for example an `Authorization` header for a token client), `credentials` (`'include'` for a Sanctum SPA on another origin, which also sends the XSRF header), `deadlineMs`, and `fetch`.
- `messageSegments(message, { settled })` gives a message's content in the order it streamed: `{ type: 'text', text }` for each text part, and `{ type: 'rows', rows }` for each run of tool calls between them, one row per call. Models often write a sentence before they call a tool, so the rows sit between the words where they happened. A later part with the same id updates the row in place, in the run where it first appeared. Text that is only white space is left out, so it never splits a run.
- `settled: true` shows a row that is still `running` as `ended`, which claims nothing either way, as the server does for a row whose tool reported nothing. The server closes every row when a turn ends, but after Stop, a cut stream or a `deadlineMs` that passed, those closing parts never reach the browser, and the rows would spin forever. Pass it for every message except the one the chat is streaming: that is, unless the chat's `status` is `submitted` or `streaming` and the message is the last one, as the recipe does. Once the status is `ready` or `error`, every message is settled, whether the turn finished, was stopped or failed.
- `actionRows(message, { settled })` gives the same rows as one list, for a panel that shows them apart from the words.
- `<ActionActivity rows>` renders them unstyled: an `<ol aria-live="polite" data-agentic-activity>` with one `<li data-status data-effect>` per row, the label inside `<bdi>` (so a Latin name or a number does not reorder an Arabic line), a same-origin link as Inertia's `<Link>`, and the note in `<span data-note>`. Style it through those attributes, and show fewer rows with `rows.slice(-3)`. The text direction comes from the page.
- `useActionSync()` reloads what the agent's writes made stale (see [the page follows the writes](#the-page-follows-the-writes)), and with `feed`, what writes made elsewhere made stale (see [writes made elsewhere](#writes-made-elsewhere)). It listens to every `Chat` built with `actionsChat()`, so it needs no wiring. Every function it returns is a property, not a method, so destructuring `apply` and passing it to `onClick`, as above, keeps `@typescript-eslint/unbound-method` quiet.
- `refusalMessage(error)` returns the server's own sentence from a JSON error answer (the 422 above, a 409, a 503), or null. An error inside the stream arrives as `error.message`: the package's one fixed sentence, "The reply stopped before it finished. Anything already saved stays saved."
- `turnOutcome(event)`, called in `onFinish`, tells how a turn ended: `complete`, `stopped` (the person pressed Stop), `interrupted` (the stream was cut before it finished, for example by the network), or `failed` (an error, or `deadlineMs` passed).

Things `useChat` does that the recipe depends on:

- `useChat` stops its own stream when its component unmounts, unless it gets an external `Chat`. Create one `Chat` per mounted layout with `useState`, as above. Never create it at module scope: with SSR, one module-level `Chat` would be shared between requests.
- It always posts JSON, so files go through your own upload path.
- Leave `resume: true` off. Resuming needs a stream buffer the server does not keep.
- The recipe has no regenerate or edit control, and the transport refuses both before any request, because the server keeps the history. `useChat` trims its own list before it calls the transport, so after a refused regenerate or edit the browser shows less than the server keeps until the page reloads from the transcript.

### The transcript in a layout

A layout has no controller of its own, so it reads the transcript from the props every page shares. Build it in `HandleInertiaRequests` from the same conversation the route continues:

```php
// app/Http/Middleware/HandleInertiaRequests.php
use AgenticActions\Streaming\Transcript;
use App\Ai\BlogAssistant;
use Illuminate\Http\Request;

public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'transcript' => function () use ($request): array {
            $user = $request->user();

            if ($user === null) {
                return [];
            }

            $conversationId = (new BlogAssistant($user))->continueLastConversation($user)->currentConversation();

            return $conversationId === null ? [] : Transcript::forUseChat($conversationId, $user);
        },
    ];
}
```

With tenants, read the id from `Actions::conversation()` for the page's tenant instead, as in [one conversation per tenant](#one-conversation-per-tenant). The layout hands the prop to the panel, with the change feed's URL when you share it ([writes made elsewhere](#writes-made-elsewhere)):

```tsx
// resources/js/layouts/app-layout.tsx
import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import type { ActionMessage } from '@agentic-actions/client/ai-sdk';
import { AssistantPanel } from '../components/assistant-panel';

export default function AppLayout({ children }: { children: ReactNode }) {
    const { transcript, actionsFeed } = usePage<{ transcript: ActionMessage[]; actionsFeed: string | null }>().props;

    return (
        <>
            <main>{children}</main>
            <AssistantPanel transcript={transcript} feedUrl={actionsFeed} key={actionsFeed ?? 'none'} />
        </>
    );
}
```

The panel creates its `Chat` once, when it mounts, so only the transcript of the page the person lands on seeds it. Later visits keep the chat the browser holds. A panel that remounts, as the `key` makes it do when the feed URL changes after switching tenants, seeds again from the transcript of the page it remounts on.

## Confirmations

An agent can run a Destructive or External action, such as deleting a post or sending an email, only after the person confirms that one call. The model asks, the chat shows a card the server built, with Confirm and Decline, and the action runs once the person confirms, as that person. Until then nothing runs, and a decline runs nothing.

### Offer the action

A Destructive or External action reaches an agent only when its `#[Expose]` names a toolset. A bare `#[Expose]` keeps it off agents, and MCP never serves one, since MCP has no confirmation step. Give the action the two card methods:

```php
use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use App\Models\Post;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

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

- The card is built on the server, after `authorize()` allowed the call, from the validated input that will run. The browser receives only `approvalReason()`'s sentence and `approvalSummary()`'s rows, as plain text, never the model's arguments or words.
- An input value you show in the summary is exactly what will run.
- `approvalReason()` takes no input, so keep it one fixed sentence and put record values in `approvalSummary()`. Without it, the card says "This removes something and cannot be undone. Go ahead?" for a Destructive action and "This reaches people or systems outside the app. Go ahead?" for an External one.
- `approvalSummary()` returns label ⇒ value rows: at most eight, strings or numbers, each shown up to 200 characters. Null values are left out, and an empty summary gives a card with the sentence alone. Every value is plain text: markup and links show as written, and a character that would break a line or reorder the text around it shows as a space. Find the record through `$context->find()`, as `authorize()` does. More than eight rows, or a value of another type, gives no card: the call is refused, and the mistake is reported to your exception handler.
- The card is built again, from the same input, just before the call runs, and the call runs only when it reads exactly as the card the person confirmed. When the record changed in between, for example because someone renamed the post, or because the value the input names it by now names another record, nothing runs and the model asks again. So build the summary from what identifies the record and matters to the person, such as its title and status, and keep it free of values that change on their own, such as the time.
- `approvalBinding()` returns what the person confirms without reading it on the card, such as a message's whole body or every recipient, as label ⇒ value pairs of any JSON-encodable values. It is never shown; it joins the confirmation, so when one of those values changes between the card and the run (another session edited the draft), the call is refused and the model asks again. Build it like `approvalSummary()`, from the validated input and the record it names.
- While the card is built, and until the person's confirmation is checked, database writes and queued actions are refused. Keep `authorize()`, `prepareForValidation()`, `rules()`, `approvalSummary()` and `approvalBinding()` free of other effects too, such as mail, HTTP calls, notifications and files. One that writes to the database never gets a card.
- An action offered any agent input needs `approvalSummary()` and an `authorize()` that takes `ValidatedInput`. `actions:check` fails it otherwise (the Summary row).
- The call runs with the input the person confirmed. An action whose `prepareForValidation()` or `fromAgent()` depends on the clock, or on data that changes before the answer, is refused when the person confirms, and the model asks again.
- One step of the model asks the person about at most eight calls. Any further Destructive or External call of that step is refused, and its row says so.
- A confirmed call's own code cannot run or queue another Destructive or External action. Do that work in its own `handle()`.
- A provider that sends no tool-call id can never run a Destructive or External action: such a call is refused.
- The row reads "Removing…" then "Removed" for a Destructive action, and "Sending…" then "Sent" for an External one, unless `activityLabel()` says more.

### The agent

The agent above qualifies: it implements `Conversational` and uses laravel/ai's `RemembersConversations` trait, beside `InteractsWithActions`. Implementing the contract without the trait does not. The agent also needs a participant when its tools are built (`continue($id, as: $user)`, `continueLastConversation($user)` or `forUser($user)`), and laravel/ai's database conversation store, or a store of your own that implements `ResolvesPendingApprovals` and `VerifiesConversationOwnership`.

An agent that does not store its conversations is never offered a Destructive or External action, and `actions:check` warns about it (the Approvals row).

### The route

The route of [the server](#the-server), with the agent built first:

```php
Route::middleware(['auth', 'throttle:20,1'])->post('/assistant', function (Request $request) {
    $user = $request->user();
    $agent = (new BlogAssistant($user))->continueLastConversation($user);
    $chat = ChatRequest::from($request, $agent);

    return $chat->respond(fn (ChatRequest $chat) => $agent
        ->stream($chat)
        ->usingProtocol(new ActionsProtocol(messageId: $chat->messageId())));
})->name('assistant');
```

- Choose the conversation before `ChatRequest::from()`, as for any turn. A multi-tenant app uses `Actions::conversation()`, as in [one conversation per tenant](#one-conversation-per-tenant).
- With the agent, `ChatRequest::from()` also reads the person's answers: for each call the conversation is waiting on, approve or decline, and an optional reason, or for a form the person's answer to it ([asking the person](asking.md)). It reads nothing else of the call, so input sent with an answer is ignored. A waiting call the answer leaves out is declined.
- `respond()` answers 422 for an empty message, as the route above does by hand, and 409 with one sentence, "That confirmation is no longer waiting, so nothing ran again.", for an answer to a call that is no longer waiting: a second press, a replay or a stale tab. An answer that arrives while another request is already resuming the same turn, such as the same Confirm sent from two tabs, gets the same 409 before any agent work, so the turn resumes once and the conversation keeps the result of the call that ran. The same holds between an answer and a new message sent to the waiting conversation at the same moment: whichever arrives first goes on, and the other gets the 409. Pass the agent to `ChatRequest::from()` for new messages too, as above. Return the agent's stream from the closure, as above: `respond()` holds the turn until that stream ends, for `approvals.ttl` seconds at most. A request that dies holding it leaves that conversation answering 409 until then, when the confirmations it waited on have lapsed too.
- Keep the route in the `web` group. Only the session of the person the conversation belongs to answers a confirmation; a token never does. Keep its CSRF protection and never list it as a CSRF exception: the transport sends the token as any Inertia request does, and only a JSON boolean `approved` counts as an answer, so a form or a link never answers.
- Pass `messageId` to the protocol, so the reply continues the message the person answered in.

### Reloading a waiting card

```php
$agent = (new BlogAssistant($user))->continueLastConversation($user);
$conversationId = $agent->currentConversation();

return Inertia::render('Posts/Index', [
    'transcript' => $conversationId === null ? [] : Transcript::forUseChat($conversationId, $user, agent: $agent),
]);
```

With tenants, read the id from `Actions::conversation()` instead, as in [one conversation per tenant](#one-conversation-per-tenant). With the agent, a turn still waiting for the person comes back with its card, rebuilt on the server from the record. A call the person could no longer confirm comes back without a card: the post is gone, it no longer reads as the card first shown, or the time to answer ran out. The person's next message settles it as not confirmed. A reload does not extend the time the person has to answer.

### The page

```tsx
import { approvalCard } from '@agentic-actions/client';
import { ApprovalCard } from '@agentic-actions/client/react';

// Inside the panel above:
const { messages, sendMessage, status, error, addToolApprovalResponse } = useChat({ chat });
const approval = approvalCard(messages.at(-1));

{approval && <ApprovalCard approval={approval} onAnswer={(approved) => addToolApprovalResponse({ id: approval.id, approved })} />}
```

- `approvalCard(message)` returns the first call the message is still waiting on, with its card, or null. Once the person answers, the card is gone, and the next waiting card, if any, takes its place.
- `<ApprovalCard>` renders it unstyled: a `<section data-agentic-approval data-effect>` with the sentence, the rows as `<dt>` and `<dd>`, and two buttons whose labels come from the server. The sentence and the values sit inside `<bdi>`. `onAnswer(true)` confirms and `onAnswer(false)` declines.
- `actionsChat()` sends the answers by itself once every waiting call has one. The transport sends the answers only, never the call's input.
- A page that collects a reason passes `reason` to `addToolApprovalResponse()`. The model reads it with the decline, as one quoted string inside the package's sentence.

### What the person can rely on

- The call runs only after they confirm it: once, as them, in the tenant they were asked in, with what the card showed, only while the card would still read the same, and within `approvals.ttl` seconds of being asked (1800, 30 minutes, by default).
- A second press, a replay, a stale tab, another person, a token and a late answer run nothing. The endpoint answers 409, or the row reads refused and the model hears that the call was not confirmed.
- Two answers sent at once resume the turn once. The other gets 409, and the conversation keeps the result of the call that ran. A Confirm and a new message sent at once never both go on: the second gets 409.
- A decline runs nothing, and the row reads "Declined".
- Confirmations, and [forms](asking.md), are kept in the app's default cache store, which every server must share: database, redis, memcached or dynamodb. Outside local development, `actions:check` warns about any other driver (the Cache store row).
- Several calls paused in one step each get a card. The page shows one at a time, and the answers go out together once each has one.
- A `prompt()`, `queue()` or `broadcast()` turn pauses the same way, with no card in its response. A reload shows the card. A queued turn keeps the limits of the token that queued it ([security](security.md#queued-runs-and-the-change-feed)).

## Asking the person

A Read or Write action can ask the person for what a model's call left out instead of refusing it. With `protected bool $askForMissing = true;` and an optional `ask()`, the chat shows a form built on the server from the action's schema, shaped as MCP's form-mode elicitation, and the action runs once the person submits it, as that person, with their values. It uses the agent, the route and the reload of confirmations above, and the model reads which fields were filled, never the values. Destructive and External actions never ask. See [asking the person](asking.md) for the action, what a form can hold, and the rules for secrets.

On the page, show a waiting form beside a waiting card:

```tsx
import { approvalCard, elicitation } from '@agentic-actions/client';
import { answerElicitation } from '@agentic-actions/client/ai-sdk';
import { ApprovalCard, ElicitationForm } from '@agentic-actions/client/react';

// Inside the panel above, with the options passed to actionsChat() kept as `options`:
const approval = approvalCard(messages.at(-1));
const form = elicitation(messages.at(-1));

{approval && <ApprovalCard approval={approval} onAnswer={(approved) => addToolApprovalResponse({ id: approval.id, approved })} />}
{form && <ElicitationForm key={form.id} params={form.params} labels={form.labels} onAnswer={(result) => answerElicitation(chat, options, form.id, result)} />}
```

## The page follows the writes

When a write succeeds, its row carries what it made stale. `useActionSync()` collects those keys and reloads them in one go, 150 ms after the last successful row, so the writes of one step cause one reload.

```tsx
import { useForm } from '@inertiajs/react';
import { useActionEdits } from '@agentic-actions/client/react';

// In any editor the copilot must not overwrite mid-typing:
export function PostEditor({ post }: { post: Post }) {
    const form = useForm({ title: post.title, body: post.body });

    useActionEdits(form.isDirty);

    // ...
}
```

While any editor that called `useActionEdits(true)` is dirty, every reload and every followed link waits, and `waiting` turns true. The check happens when the reload is due, so an editor that turns dirty inside the 150 ms window still holds it. The panel can run a held reload at once with `apply()` (the Refresh button above).

A held reload also runs by itself 150 ms after the last dirty editor turns clean, unless an editor is dirty again by then, and `waiting` turns false, so the Refresh button goes away on its own. That includes an editor whose own save already reloaded the page: the held reload still runs once, for the props the agent's writes touched. `useActionSync({ blocked })` adds your own unsaved-work flag, which holds a reload the same way, and a held reload runs by itself once that flag is false and no editor is dirty. With `resumeWhenClean: false`, a held reload stays held until the next successful row or the end of a turn finds nothing dirty, or until the panel calls `apply()`.

What a successful row carries:

- A Write's `$touches`. A Write that declares no touches sends `['*']`, which reloads the whole page. On an HTTP call a Write with `$touches = []` reloads nothing, because the caller reads the response; a row has no response to read, so it reloads everything instead.
- A Read sends no touches.
- A hand-written tool sends the touches it reports with `Activity::record()` (below), and none by default.
- A link, when the action's `redirectTo()` returns a URL on this site (a path, or the app's own host), with no `.` or `..` segment, no backslash and no control character. The row shows it as a link. When the action sets `protected bool $followLink = true`, the page also visits it after the call succeeds. Set it only for a `redirectTo()` built from the saved record (`route('posts.edit', $post)`), never one that points at an endpoint that redirects somewhere else. The browser checks the origin again before it follows.

What `useActionSync()` does with them, through `inertiaApply()` from `@agentic-actions/client/inertia`:

| The collected request | What happens |
|---|---|
| a link to follow | `router.visit(url)`; pending reloads are dropped, since the new page is fresh |
| `how: 'remount'` | `router.visit(location.href, { preserveState, preserveScroll: true, replace: true })`, so a form seeded from props seeds again; `preserveState` is re-read when the response lands, so an editor dirtied during the visit keeps its input |
| touches include `*` | Inertia's prefetch cache is flushed, then `router.reload()` |
| other touches | prefetched pages tagged with those keys are flushed, then `router.reload({ only: [...touches] })` |
| nothing | nothing |

A touch names a top-level prop. A named touch reloads with `only`, which Inertia treats as a partial reload, so an `Inertia::once()` prop it names is resolved again. A `*` reload and a remount are not partial, so they skip once props the page already holds unless the prop is built with `->fresh()`.

Options of `useActionSync()`, read when it mounts, except `blocked`, which is read on every render:

- `when`: `'live'` (the default) reloads 150 ms after the last successful row; `'turn'` waits for the end of the turn.
- `how`: `'reload'` (the default) or `'remount'`, for pages whose forms are seeded from props.
- `apply`: your own refresh, which receives `{ touches, follow, how }`. To veto a follow, wrap the default: `apply: (request) => rule() && inertiaApply(request)`.
- `blocked`: your own unsaved-work flag.
- `resumeWhenClean`: `true` (the default) runs a held reload by itself once no editor is dirty and `blocked` is false; `false` keeps it held until the next successful row, the end of a turn, or `apply()`.
- `feed`: `{ url, interval }`, to poll the change feed (below). Off unless given.

## Writes made elsewhere

A write made over MCP, by a queued job, by someone else in the same tenant or in another tab has no row in this page. The change feed carries it: each completed write records the keys it touched, and the page polls its group's feed route, `POST …/actions/_changes`, for the keys touched since its last poll. Hand the page that URL, for example as a shared Inertia prop:

```php
// app/Http/Middleware/HandleInertiaRequests.php, share()
'actionsFeed' => fn (): ?string => match (true) {
    $request->user() === null => null,
    $request->route('team') !== null => route('teams.actions._changes', ['team' => $request->route('team')]),
    default => route('actions._changes'),
},
```

The route's name is the group's name prefix, then `routes.name` (`actions.`), then `_changes`. With the two groups of [tenants](concepts.md#tenants) (`teams/{team}` named `teams.`, with `tenant.parameter` set to `team`), a team's pages poll `teams.actions._changes`, which takes the `team` parameter, and every other page polls `actions._changes`, which takes none. Use the names of your own groups. The [layout](#the-transcript-in-a-layout) hands the URL to the panel as `feedUrl`.

A polled touch goes through the same path as a done row: one reload 150 ms later, held while an editor is dirty or `blocked` is true, and it applies with `when: 'turn'` too, since no turn will flush it. The feed option is read when the hook mounts, so a panel whose feed URL changes, such as after switching tenants, remounts with a `key`, as above.

How the poller behaves:

- It polls every 15 seconds (`interval`, in milliseconds) while the page is visible, and not at all during server rendering. It never polls more than once a second, whatever `interval` says or however often the page turns visible, and never sends a second poll while one is in flight.
- It pauses after 10 minutes with no pointer or keyboard input, and polls again at the next input, so an idle tab does not keep its session alive. Coming back to the tab counts as input.
- A tab away for longer than `feed.window` (600 seconds by default) reloads everything once.
- A 401, 403, 404 or 419 stops it for good: the person signed out, the feed is off, or the session expired. A 429, a server error or a network error waits for the next interval.
- It sends the `X-XSRF-TOKEN` header only to a URL on the page's own origin.

Each poll is a full request through the group's middleware, session included. Members of a tenant see which keys other members' writes touched, never ids or values. The server side is described in [concepts](concepts.md#the-change-feed).

## Labels

Each call of an action shows one row. It uses the package's label for its effect by default: "Looking it up…" and "Looked it up" for a Read, "Saving…" and "Saved" for a Write, "Removing…" and "Removed" for a Destructive action, "Sending…" and "Sent" for an External one, in the context's locale. An action can say more:

```php
use AgenticActions\ActionContext;

public function activityLabel(ActionContext $context, bool $finished): ?string
{
    return $finished ? __('Draft saved') : __('Saving the draft…');
}
```

`$finished` is false for the running label and true for the label after success. Null falls back to the package's label. The label is read with the context's locale set, so `__()` answers in the person's language.

Labels take no arguments, and both are read before the call runs. A label can never show what the model sent, so a document that tries to steer the model cannot write one. One instance can serve several calls in a turn, so return fixed sentences and never read state an earlier call left behind.

A refused or failed row keeps the running label and adds a note, "Not done" or "Couldn't finish", so an action never words its own failure.

### Hand-written tools

A laravel/ai tool you wrote yourself shows a row when it implements `AgenticActions\Contracts\DescribesActivity`, and reports its outcome with `AgenticActions\Streaming\Activity`:

```php
<?php

namespace App\Ai\Tools;

use AgenticActions\Contracts\DescribesActivity;
use AgenticActions\Streaming\Activity;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class CountDrafts implements DescribesActivity, Tool
{
    public function __construct(private User $user) {}

    public function description(): string
    {
        return 'Count the author\'s draft posts.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function activityLabel(bool $finished): ?string
    {
        return $finished ? __('Counted your drafts') : __('Counting your drafts…');
    }

    public function handle(Request $request): string
    {
        $count = $this->user->posts()->where('status', 'draft')->count();

        Activity::record($request, ok: true);

        return "The author has {$count} drafts.";
    }
}
```

The agent adds it beside its actions:

```php
public function tools(): iterable
{
    return [...$this->actionTools(), new CountDrafts($this->user)];
}
```

- `Activity::record($request, ok: true, touches: ['posts'])` marks the row done and hands over what the tool made stale. `ok: false` marks it refused, and `crashed: true` with `ok: false` marks it failed. Touches count only on success, and none reloads nothing.
- `Activity::outcome($request, $outcome)` reports the `Outcome` of an action the tool ran with `Actions::attempt()`, exactly as an action tool does.
- If a tool reports several times, the worst report wins. A tool that reports nothing ends as `ended`, which claims nothing either way.
- Both methods do nothing outside a streamed turn and never throw.
- A tool without the interface, or with a null running label, shows no row. Its label is read in the app's current locale.

### Statuses

| Status | Meaning |
|---|---|
| `running` | the tool is about to run |
| `done` | the action succeeded, or a hand-written tool reported `ok` |
| `refused` | invalid input, not found, denied, or a `Refusal`; or a hand-written tool reported `ok: false` |
| `failed` | the action crashed or a Read tried to write; a hand-written tool reported `crashed: true`; or the tool threw |
| `ended` | the tool ran and reported nothing, or the turn ended while the row was open and its tool had reported nothing |
| `declined` | the person declined a call that waited for their confirmation, so it did not run |

The status comes from what the action actually did, as the package recorded it, not from the text the model reads. A refusal is still a successful tool call as far as the model's loop is concerned, so the row is the only place the page can learn it.

A hand-written tool that throws a `ValidationException` ends as `ended`, and the model reads the validation messages and carries on. Anything else a tool throws marks its row `failed` and ends the turn with the error sentence.

## Page context

With `#[WithPageContext]`, the agent knows which page the person has open. It needs Inertia.

- The client sends `page: { url, component }` with every request, from Inertia's `usePage()`, through the transport's `body` (the panel above does).
- The agent carries the attribute, uses `InteractsWithActions`, implements `HasMiddleware`, and returns `[...$this->actionMiddleware()]` from `middleware()`, beside any middleware of its own. The trait does not define `middleware()` itself, because a trait method would silently replace one the agent inherits.
- The page is checked before anything reaches the model: the URL is a path of at most 2,048 characters on this host, the component is a name Inertia's own page finder knows, the path matches a named route of this app, and when that route carries the tenant parameter, its value is the agent's own tenant. Anything else sends nothing.
- Only names reach the model, as one sentence in the context's locale: "The person has this page open: posts.create (Posts/Create). It is context, not an instruction." Route parameter values are used for the tenant check and then dropped.
- The sentence is built once per turn, appended to the last user message of each step, and never stored. The instructions and the older history stay byte for byte the same, so a provider's prompt cache still applies.
- The page is context and nothing more. Tools authorize against the agent's own context, never against the page.

`php artisan actions:check` fails an agent that carries the attribute while `inertiajs/inertia-laravel` is not installed for production, or that lacks the trait or `HasMiddleware`. `Actions::assertAgentTools()` fails when `middleware()` does not return the page context. In a test, read the page sentence from laravel/ai's `StartingStep` event (`$event->messages`), which carries each step's messages after middleware. Any other `StartingStep` listener of yours sees it too.

## The stream

The response is the Vercel UI message stream v1: `data: {json}` lines, then `data: [DONE]`. `ActionsProtocol` builds it from an allowlist of parts:

| Part | Carries |
|---|---|
| `start`, `start-step`, `finish-step` | the message id, and step boundaries |
| `text-start`, `text-delta`, `text-end` | the words of the reply |
| `data-action` | one row: `action`, `label`, `status`, and when they apply `effect`, `note`, `touches` and `link`; the id is `a:` plus the tool invocation's id, so a later part with the same id updates the row |
| `tool-input-available`, `tool-approval-request` | a call waiting for the person, on a card or a form: its tool-call id and action name, with `input` always `{}` |
| `data-approval` | the card for that call: `action`, `effect`, `label`, `title`, `summary`, `confirm` and `decline`; the id is `approval:` plus the tool-call id |
| `data-elicitation` | the form for that call, instead of a card: `action`, `params` (MCP's form-mode params) and `labels`; the id is `elicitation:` plus the tool-call id ([asking the person](asking.md)) |
| `data-view` | the table a Read action showed, after its done row: `action`, `table` (its declared columns, rows, `truncated`, `chart`, `caption`), `at`, `ref` when it can be refreshed, and `labels`; the id is `view:` plus the tool-call id ([tables](data.md)) |
| `tool-output-available`, `tool-output-denied`, `tool-output-error` | an answered call settling, with no output |
| `data-*` | your own parts, from the `after` closure |
| `error` | the one fixed sentence, always last before `[DONE]` |
| `finish` | the finish reason; a turn without `finish` failed or was cut |

A successful turn, abbreviated:

```
data: {"type":"start","messageId":"…"}
data: {"type":"start-step"}
data: {"type":"data-action","id":"a:…1","data":{"action":"create-post","label":"Saving the draft…","status":"running","effect":"write"}}
data: {"type":"data-action","id":"a:…1","data":{"action":"create-post","label":"Draft saved","status":"done","effect":"write","touches":["posts"],"link":{"url":"/posts/42/edit","follow":false}}}
data: {"type":"finish-step"}
data: {"type":"start-step"}
data: {"type":"text-start","id":"…"}
data: {"type":"text-delta","id":"…","delta":"I saved the draft."}
data: {"type":"text-end","id":"…"}
data: {"type":"data-blog-stats","data":{"drafts":3}}
data: {"type":"finish-step"}
data: {"type":"finish","finishReason":"stop"}
data: [DONE]
```

Every other part type is dropped: every other tool part, tool arguments and results, reasoning, sources, files, usage and model names, and any type a later release adds. Empty keys are left out, never sent as `null`. On a failure, the rows still open close with what their tool reported, or as `ended`, your parts follow with `$failed` true, then one `error` part and `[DONE]`, with no `finish`. The provider's own error text is reported to your exception handler and never sent.

A row is written when its tool starts and again when that tool finishes, so the rows tick one at a time even when the model called several tools in one step.

## Without React

### The vanilla reader

`@agentic-actions/client/ai-sdk` needs only `ai`:

```ts
import { readUIMessageStream } from 'ai';
import { actionRows } from '@agentic-actions/client';
import { actionsTransport, type ActionMessage } from '@agentic-actions/client/ai-sdk';

const stream = await actionsTransport({ api: '/assistant' }).sendMessages({
    chatId: 'assistant',
    messages: [{ id: crypto.randomUUID(), role: 'user', parts: [{ type: 'text', text: 'Draft a post about tides' }] }],
    trigger: 'submit-message',
    messageId: undefined,
    abortSignal: undefined,
});

for await (const message of readUIMessageStream<ActionMessage>({ stream })) {
    render(actionRows(message));
}
```

When the loop ends or throws, render the last message once more with `actionRows(message, { settled: true })`. After a finished turn that changes nothing; after an aborted or broken one, no row stays `running`.

For the reloads, `createActionSync()` from `@agentic-actions/client` does what `useActionSync()` does, without React: feed it each `data-action` part with `onPart(part)`, and call `flush()` when the turn ends. By default it hands touches to your `onTouched()` handlers (with `null` in place of an action definition) and follows a link with `location.assign()`. The `editors` store it reads is the one `useActionEdits()` writes, and a held refresh runs by itself 150 ms after that store turns clean, unless you pass `resumeWhenClean: false`. The sync cannot see your own `blocked()` flag change, so call `flush()` when that flag clears while a refresh is held (after `onWaiting(true)`).

As with `useActionSync()`, every function of `editors`, `actionParts` and a sync is a property, not a method, so you can destructure it or pass it on unbound.

### A host that keeps its own reader

A front end with its own stream reader can adopt the rows first and the reloads later. It applies each `data-action` part with `applyActionPart(rows, part)`, and feeds a sync by hand: `onPart(part)` for each `data-action` part, `touch([...])` for a write it reported in its own `data-*` part, and `flush()` in `finally` when its turn ends.

## Limits

- A row appears when a tool starts, which is after the model has written the whole call. Show your own "thinking" state for that gap.
- A write made before the model runs, such as a form your route saves itself, gets no row. Send a `data-*` part for it and call `touch()` on the sync.
- Rows are live only. A reloaded chat shows words, a card or a form still waiting for the person, and the [tables](data.md) its turns showed, because the conversation store keeps no data parts.
- Stop ends the display, not the turn. The server finishes the turn and stores it, and your `then()` or `catch()` bookkeeping runs.
- With `#[WithPageContext]`, every step sends the full history instead of continuing the provider's previous response, since each step's messages are rebuilt. The prompt prefix stays the same, so prompt caching still applies.
- Whatever a tool echoes, prints or dumps goes straight into the response body, outside the allowlist, and can break the stream. Tools return their text and never echo.
- A sub-agent's tools, a queued run and a `prompt()` run write no rows. With `feed`, their writes still reach the page within one poll.

## Deploying

- Rows are tested on nginx with PHP-FPM. The protocol sends `X-Accel-Buffering: no` itself, so nginx passes each row on at once. Two nginx settings undo that and hold every row until the turn ends: `fastcgi_ignore_headers X-Accel-Buffering`, and `text/event-stream` in `gzip_types`. A proxy or CDN in front of nginx must pass the stream through unbuffered too.
- The request runs until the turn ends, whether the tab stays open or not. Size PHP-FPM's `request_terminate_timeout` and nginx's `fastcgi_read_timeout` for your longest turn, and pass the same limit to the client as `deadlineMs`. Keep it under `approvals.ttl` (30 minutes by default): a turn that answers a confirmation holds its conversation for that long at most, so a second answer sent while the confirmed call still runs gets 409.
- Under Octane, rows, a closed tab and confirmations are tested on FrankenPHP. Swoole and RoadRunner are untested. The request holds an Octane worker until the turn ends, so count workers as you would PHP-FPM children, and size Octane's `max_execution_time` for your longest turn.

See [security](security.md) for what the stream guarantees and the rules your app keeps.
