# Concepts

An action is one class that extends `AgenticActions\Action`. The package reads what the class declares and decides which callers may reach it: a route, the CLI, an agent's tool call, an MCP client, a queued job, or your own code calling `run()`. Whoever calls, the same pipeline runs.

## The action

An action declares facts as properties and behaviour as methods.

| Property | Meaning |
|---|---|
| `$name` | The tool, CLI and TypeScript name. Empty means the class name in kebab case, with one trailing `Action` removed and a run of capitals kept as one word: `ArchivePostAction` is `archive-post`, and `ImportCSVFile` is `import-csv-file`. A word with a capital inside still splits, as `SyncOAuthToken` is `sync-o-auth-token`, and so does a plural run, as `SendSMSs` is `send-sm-ss`: set `$name`, or write the word as one in the class name (`SyncOauthToken`). Pin it once an agent has used it. |
| `$description` | What a model reads before it calls the action. Required on model surfaces (agents and MCP). |
| `$effect` | `Effect::Read`, `Write`, `Destructive` or `External`. Null exposes nothing remote and fails `actions:check`. |
| `$touches` | Neutral keys a success makes stale, such as `['posts']`. The client hands them to your reload or cache code. |
| `$tenantScoped` | True by default. Ignored until a tenant model is configured. |
| `$guests` | Lets a model-driven call run without a signed-in person, for a public support bot. Generated routes still need a user. |
| `$followLink` | After an agent's successful call, the open page visits `redirectTo()`'s URL when it is on this site. Only for a `redirectTo()` built from the saved record. See [the copilot](copilot.md). |
| `$idempotent` | A hint only, sent to MCP clients as `idempotentHint`. What a replay means stays your business. |
| `$errorBag` | The error bag browser visits receive. A Laravel 13 `#[ErrorBag]` attribute on the class wins. |
| `$validationMessagesToModel` | Sends validation messages, and not only the failing keys, to a model when the action raises a `ValidationException` itself. |
| `$askForMissing` | A Read or Write action asks the person, in a form in the chat, for the fields a model's call left out or got wrong, instead of refusing the call. See [asking the person](asking.md). |
| `$initializes` | The tables a Read's `initialize()` may add rows to, for state that should exist once someone reads it. Empty by default. See [a Read that creates its own state](#a-read-that-creates-its-own-state). |

The package reads these as the class's declared defaults, through reflection, without running a constructor. Do not change them in a constructor.

| Method | Called |
|---|---|
| `schema(JsonSchema $schema)` | The input, as `Illuminate\JsonSchema` types. It is compiled to validation rules, advertised to agents and typed in TypeScript. Declare `required()` and `nullable()` only in their positive form. |
| `outputSchema(JsonSchema $schema)` | The output allowlist, applied at every depth. Undeclared keys never leave the server. |
| `rules(ActionContext $context)` | Server-only rules appended per key and never advertised: closures, dynamic bounds, scoped `exists`. |
| `prepareForValidation(array $input, ActionContext $context)` | Normalises raw input before validation. |
| `authorize(ActionContext $context[, ValidatedInput $input], ...$services)` | Not declared on the base class. Without it, the action is denied everywhere. Without a `ValidatedInput` parameter it runs before any input is read; with one, after validation; with one that may be null (`?ValidatedInput $input = null`), both: first with null before any input is read, then with the input after validation (see [what an agent's tool list shows](#what-an-agents-tool-list-shows)). The input is passed by the parameter's name, so type it `ValidatedInput` or a contract it implements, such as `ValidatedData` (alone or in a union): a subclass fails the call. |
| `handle(ActionContext $context, ValidatedInput $input, ...$services)` | Does the work. Services are injected by the container. |
| `initialize(ActionContext $context[, ValidatedInput $input], ...$services)` | Not declared on the base class. Called only on a Read that lists tables in `$initializes`, right before `handle()`, to add the rows it finds missing: inserts into those tables only. See [a Read that creates its own state](#a-read-that-creates-its-own-state). |
| `shouldRegister(ActionContext $context)` | Exposure, not authorization: false reads exactly like an action that does not exist. |
| `agentSchema()` and `fromAgent()` | A separate vocabulary for agents, translated into the canonical input. See [strict agent schemas](recipes.md#strict-agent-schemas-no-ids). |
| `requiredForAgents()` | Fields a model's call must give, agents' and MCP clients' alike, though the route, the CLI and your own code may leave them out. They are offered as required, and a model's call without one is refused or [asks the person](asking.md#fields-only-a-model-must-give). |
| `modelReply($result, ActionContext $context)` | The sentence a model reads on success. Null means "Done.", or "Found." above the output for a Read. |
| `redirectTo($result, ActionContext $context)` | Where a browser visit lands after success. Null redirects back. |
| `activityLabel(ActionContext $context, bool $finished)` | The copilot row's label while the call runs and after it succeeded. Null means the package's label for the effect. |
| `approvalReason(ActionContext $context)` | The one sentence on the card a person confirms before an agent's Destructive or External call runs. Null means the package's sentence for the effect. See [offer the action](copilot.md#offer-the-action). |
| `approvalSummary(ActionContext $context, ValidatedInput $input)` | The rows that card shows, as label => value, built from the validated input that will run. At most 8. |
| `approvalBinding(ActionContext $context, ValidatedInput $input)` | What the person confirms without reading it on the card (a whole body, every recipient): never shown, but a change before the run refuses the call. |
| `ask(Ask $ask, ActionContext $context)` | What the form says beyond `schema()` when `$askForMissing` asks the person: its sentence, choices, defaults. See [the `Ask` builder](asking.md#the-ask-builder). |

`run()`, `dispatch()` and `__invoke()` are final. A class that also uses a trait defining any of them fails to load.

### What a model reads for a failed rule

A model's failed call names each failing key with the rules that failed, as in "Not done. Rejected: title (max).", and never the validation messages (`$validationMessagesToModel` sends them only for a `ValidationException` the action raises itself). A rule's name is Laravel's (`max`, `unique`), or the snake-case class name of a rule object (`UniqueTitle` is `unique_title`). A closure reads as `invalid`. To give a closure a name a model can act on, wrap it in `AgenticActions\NamedRule`:

```php
use AgenticActions\ActionContext;
use AgenticActions\NamedRule;
use App\Models\User;

public function rules(ActionContext $context): array
{
    $user = $context->actor(User::class);

    return [
        'title' => [new NamedRule(
            'title_taken',
            fn (mixed $title): bool => ! $user->posts()->where('title', $title)->exists(),
            'You already have a post with that title.',
        )],
    ];
}
```

The closure returns true when the value passes. The third argument is the message web and API callers see, translated when the rule fails, and `The :attribute field is invalid.` when you leave it out.

## Schema to rules

`schema()` is compiled to Laravel validation rules for every call, and `rules()` appends its own per key. The strict type rules run after step 7 of [the pipeline](#the-pipeline) has converted strings where no information is lost, so a form's `"12"` passes as an integer. A `default()` is advertised to agents and MCP clients, never merged into the input.

| `schema()` declares | Rules |
|---|---|
| a key without `required()` | `sometimes` |
| `required()` | `required`; with `nullable()`, `present` and `nullable` |
| `nullable()` | `nullable` |
| a required key of a nested object | `required_with:{parent}`; with `nullable()`, `present_with:{parent}` and `nullable` |
| `string()` | `string`; `min()` and `max()` count characters; `pattern()` becomes `regex:/…/uD` |
| `integer()` | `integer:strict`; `min()`, `max()` and `multipleOf()` become `min`, `max` and `multiple_of` |
| `number()` | `numeric:strict`, a finite number, and the same bounds |
| `boolean()` | `boolean:strict` |
| `array()` | `list`; `min()` and `max()` count items; `items()` compiles at `{key}.*`; `unique()` adds `distinct:strict` to the items |
| `object([...])` | `array`, its keys at `{key}.{child}`; with `withoutAdditionalProperties()`, `array:{child keys}` |
| `enum([...])` | `Rule::in()` with those values |

A string's `format()`:

| Format | Rule |
|---|---|
| `email` | `email` |
| `uri`, `url` | `url` |
| `uuid` | `uuid` |
| `date` | `date_format:Y-m-d` |
| `date-time` | `date` |
| `time` | `date_format:H:i:s` |
| `ipv4`, `ipv6` | `ipv4`, `ipv6` |
| `binary` | `file`, on HTTP only (below) |

The compiler refuses, with `UnsupportedSchema`, what it cannot turn into rules: `anyOf`, a union of two types or more (`null` aside), a `pattern()` with a lookaround (`(?=`, `(?!`, `(?<=`, `(?<!`), and any other format, such as `hostname`. `actions:check` names each one in its Schema row, and a call that reaches one fails as a crash. A top-level key that `rules()` also defines may be an `anyOf` or a union: the compiler leaves its type to your rules. Otherwise declare the key as a string and check it in `rules()`.

### File uploads

A `string()->format('binary')` field is a file, validated with `file`, and `rules()` adds the rest:

```php
<?php

namespace App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

#[Expose(web: true)]
final class UpdateAvatar extends Action
{
    protected ?Effect $effect = Effect::Write;

    public function schema(JsonSchema $schema): array
    {
        return [
            'avatar' => $schema->string()->format('binary')->required(),
        ];
    }

    public function rules(ActionContext $context): array
    {
        return ['avatar' => ['image', 'max:2048']];
    }

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        $path = $input->input('avatar')->store('avatars', 'public');

        $context->actor(User::class)->update(['avatar_path' => $path]);

        return ['path' => $path];
    }
}
```

The example assumes an `avatar_path` column on `users`, in the model's fillable list. A file arrives only on the HTTP surface, as `multipart/form-data`:

- A Blade form posts to `route('actions.update-avatar')` with `enctype="multipart/form-data"` and `@csrf`.
- The TypeScript client types the field `File | Blob`, and `callAction()` sends the whole input as multipart when it holds one, nested keys in bracket notation (`tags[0]`) and booleans as `1` and `0`.
- Your own code calls `UpdateAvatar::run(['avatar' => $file], ActionContext::http($user))` with an `Illuminate\Http\UploadedFile`.

Every other surface refuses the field. A call from the CLI, a queued run or `ActionContext::system()` that reaches validation fails with `UnsupportedSchema`, so `actions:run` exits with 4. Agents and MCP clients are never offered an action whose `schema()` holds a file field, even behind an `agentSchema()` without it, since a model's call is validated against `schema()` too: it is left out of their tool lists (your tests and local requests throw instead), and `actions:check` fails it while `#[Expose]` opens agents or MCP. Expose it with `#[Expose(web: true)]`, as above, and write another action without the file for agents. To do the slow work in a worker, store the file in `handle()` and queue another action with its path ([queued runs](#queued-runs)). `actions:check` compiles `schema()` as the web does, so it does not flag a file field on an action you only run from the CLI or the queue. The [file uploads](recipes.md#file-uploads) recipe shows an upload end to end, from the form to the job that imports it.

## Context

`AgenticActions\ActionContext` carries who is calling and from where. An action reads the actor, the tenant and the locale from it and never from `auth()`, `request()` or the session, so the same code works for a route, a queued job, the CLI and an agent.

| Constructor | For |
|---|---|
| `ActionContext::fromRequest($request)` | The HTTP edge. The package builds it for generated and hand-written routes: the request's user, the guard that authenticated it, the tenant route parameter, the locale, the `Idempotency-Key` header, and the route's parameters as fixed input. |
| `ActionContext::http($actor, $tenant, $locale)` | Your own code acting for a person: Livewire, Filament, a Blade controller, or [a job of your own](recipes.md#your-own-jobs). |
| `ActionContext::agent($actor, $tenant, $locale)` | An agent's tools, built from the agent's own state. |
| `ActionContext::system($tenant, $locale)` | Webhooks and scheduled work: no actor and no token check, but `authorize()` still runs. |

`actions:run` builds a console context from `--as`, `--tenant`, `--locale` and `--key`. The constructors the package uses for its own callers, `console()` for the CLI, `mcp()` for MCP clients and `queued()` for a [queued run](#queued-runs) in the worker, are `@internal`: a job of your own builds its context with `http()` or `system()`. The actor may be null everywhere; `authorize()` decides what a guest may do.

Inside an action:

- `$context->actor(User::class)` returns the actor, or throws `MissingContext` when there is none or it is another type. `$context->actor` is the nullable property.
- `$context->tenant(Team::class)` does the same for the tenant.
- `$context->find(Post::class, $id)` finds a row through the tenant scope. A missing or foreign row throws `ModelNotFoundException`, which every surface renders as not found.
- `$context->locale` is the caller's locale; the pipeline already runs inside it.
- `$context->requireIdempotencyKey()` returns a key namespaced by action, actor and tenant, or refuses with 428 when the caller sent none.
- `$context->withFixed([...])` adds input the surface fixes, such as the record an agent is editing. Fixed input overwrites the caller's and is never advertised.

The context is immutable: each `with…()` method returns a new one. Its other members:

| Member | What it holds |
|---|---|
| `surface` | The `AgenticActions\Surface` the call came from ([surfaces](#surfaces)). |
| `isModelDriven()` | Whether a model drives the call, decided by the call stack as [below](#surfaces). Ask this rather than compare `surface`, so a hand-written tool and a queued run answer as their caller. |
| `isSystem()` | Whether the call is the app's own work: `ActionContext::system()`, or a job queued from it. |
| `idempotencyKey` | The raw key: the `Idempotency-Key` header, `--key` on the CLI, or an agent's tool-call id when no key was set. `requireIdempotencyKey()` namespaces it. |
| `withIdempotencyKey($key)` | Sets the raw key, for your own code that has one, such as a webhook's delivery id. A key you set wins over an agent's tool-call id. |
| `actorKey()` | The actor as `{morph class}:{id}`, `system` for the app's own work, or `guest`: what idempotency keys bind to. |
| `fixed` | The fixed input, as an array. |
| `guard` | The guard that authenticated the request, on HTTP. |
| `requestId` | A ULID made with the context, never read from a header. The [events](#events) carry it; a queued run gets its own. |
| `ip`, `userAgent` | The request's, from `fromRequest()`; null elsewhere. |
| `action` | The running action's name, on the context the pipeline hands your action's methods. |

Code an action calls without handing it the context, such as a model event, an observer or a service, reads it with `ActionContext::current()`: the context of the action running now (the innermost one inside a nested run), or null outside any run. The action itself keeps using the `$context` each of its methods is handed. A change log, for example, can record who made a change, from which surface, and whether a model drove it:

```php
Post::updated(function (Post $post) {
    $context = ActionContext::current();

    Audit::record(
        $post,
        by: $context?->actor,
        via: $context?->surface->value ?? 'outside an action',
        modelDriven: $context?->isModelDriven() ?? false,
    );
});
```

`current()` answers for the moment your code runs. A listener that waits for a commit or the queue, and deferred code such as a `DB::afterCommit()` or `defer()` callback, can run after their call has ended and see an outer action's context or null; the package's [events](#events) carry their own call's surface, actor and tenant. Listing actions for an agent or an MCP client sets it too, while each action's `shouldRegister()` and `authorize()` run, though no call is made. Null means only that no action is running, never that the caller is unrestricted: code outside every action, such as a seeder or a scheduled task, sees null too, so never lift a check or a scope because `current()` is null.

## Surfaces

A surface is where a call comes from: `Http`, `Agent`, `Mcp`, `Queue`, `Console` or `System`, the cases of `AgenticActions\Surface`, read from `$context->surface`.

A call is model-driven when its surface is `Agent` or `Mcp`, when it runs inside a laravel/ai tool call, or when it runs inside an MCP request. A queued run keeps the answer of the call that queued it, and so does every call its own code makes. The call stack decides, not the context a caller built, so a hand-written agent tool that calls `PublishPost::run($input, ActionContext::http($user))` is still model-driven. `$context->isModelDriven()` gives this answer inside an action. A model-driven call reaches only Read and Write actions, and needs an actor unless the action sets `$guests`. The one exception is an agent's call to a Destructive or External action, which runs only after the person confirms it (see [confirmations](copilot.md#confirmations)).

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

### A Read that creates its own state

A Read never writes: the Read guard refuses the statement. Some reads need state that should exist before they can answer, such as a team's inbound email address or a post's share link, and the first read finds it missing.

Create it with its owner when you can. The action or observer that creates the team also creates its inbox, a one-off command adds one to each team that has none, and the Read treats a missing row as not set up:

```php
Team::query()->whereDoesntHave('inbox')->each(fn (Team $team) => $team->inbox()->create(['address' => Str::lower(Str::random(24))]));
```

Then no Read writes, whoever calls it.

For state that should exist only once someone looks, such as a share link for each post, list its table in `$initializes` and add the row in `initialize()`. It takes what `handle()` takes, through the container:

```php
protected array $initializes = ['share_links'];

public function initialize(ValidatedInput $input): void
{
    ShareLink::query()->firstOrCreate(['post_id' => $input->integer('post')], fn (): array => ['token' => Str::random(32)]);
}
```

- `initialize()` runs on every call, after every check (the token, membership, both `authorize()` steps, validation and a form's claim) and right before `handle()`. It never runs while an agent's tool list, a card or a form is built, and a call that any check refuses never reaches it.
- While it runs, the Read guard allows a write only when it adds rows to the tables `$initializes` lists, besides what `handle()` may already send: an insert, `insertOrIgnore()`, or `firstOrCreate()` on a row that is missing. An update, a delete, a replace or an upsert, and a write to any other table, are refused. So is what it runs: a Write's statements are held to the same rule, a Read it runs is fully guarded again (apart from the rows that Read's own `initialize()` adds), and it cannot queue a Write. `handle()` stays fully guarded.
- Give the table a unique key on what the row belongs to. Two first reads at once then add one row, and `firstOrCreate()` returns that row to both ([the recipe](recipes.md#state-created-the-first-time-it-is-read)).
- Whoever may run the Read may cause the insert: a token with only `actions:read`, an OAuth client approved to read, and a member whose membership answers only for a Read. Build the row's values on the server; the caller's input may name the record, never what the row holds.
- Every caller still sees a Read: the token ability, the membership check, MCP's `readOnlyHint`, "Found." above the output, the copilot's label, the events and the change feed are a Read's.
- With `reads.guard` off nothing is guarded, `initialize()` included.

Use a Write instead when making the state turns something on, costs money or counts against a limit, reaches outside the app, or takes its values from the caller. `reads.writable_tables` is no narrower way: it makes a table writable for every Read and every statement, updates and deletes included. `actions:list` shows the tables an action's `initialize()` adds rows to, and `actions:check` fails `$initializes` that can never add one: on an action that is not a Read, on a dataset, or without a public `initialize()`. See [security](security.md) for what the guarantee covers.

## Doors

Every caller enters the pipeline through one of five doors. The door decides only whether the class's own `#[Expose]` is consulted.

| Door | Callers | Checks `#[Expose]` |
|---|---|---|
| In-process | `Action::run()`, `Action::dispatch()`, `Actions::attempt()`, `actions:run` | no: discovery alone reaches it |
| Route | a route you wrote whose controller is the action | no: a route you wrote is its own allowlist |
| Generated route | a route from `Actions::routes()` | yes: the web surface must be open, and `surfaces.web` on |
| Agent | an agent's tool call | yes: the agent surface open, `surfaces.agents` on, and a toolset the agent shares |
| MCP | an MCP client's tool call | yes: the MCP surface open, `surfaces.mcp` on, and, with tenants, a tenant-scoped action on the tenant path or another action on the base path |

Middleware declared on the action class, through Laravel's `HasMiddleware` or Laravel 13's `#[Middleware]` and `#[Authorize]` attributes, is controller middleware: Laravel runs it on a route whose controller is the action, generated or yours, and nowhere else. Agents, MCP clients, the CLI, queued runs and `run()` never pass through it. Put a check that must hold for every caller in `authorize()` or `shouldRegister()`; `actions:check` warns about the two attributes on a class open to agents or MCP (its HTTP-only row).

## The pipeline

1. The door, as above. A model-driven call also needs a Read or Write effect and an actor (or `$guests`), except an agent's Destructive or External call, which needs an actor and an agent that can wait for the person's confirmation.
2. The token check: the credential needs the effect's ability, and a tenant-bound token only reaches its tenant.
3. `shouldRegister()`.
4. Tenant membership, when the call has a tenant.
5. `authorize()` without input: when it takes none, or, with null, when its `ValidatedInput` may be null.
6. For agents and MCP: the arguments are cut to the advertised schema, then translated through `fromAgent()` when the class overrides `agentSchema()`.
7. The fixed input overlays the body, strings are converted where no information is lost (`"12"` to `12`), an empty string, or one of only whitespace, becomes null for every key at every depth, a string field and a key only `rules()` declares included, as Laravel's `TrimStrings` and `ConvertEmptyStringsToNull` make it on the web, so validation refuses it unless the field is nullable (no other string is trimmed, and a blank `password`, `password_confirmation` or `current_password` stays as given, as `TrimStrings` leaves it), and `prepareForValidation()` runs.
8. Validation: the compiled `schema()` plus `rules()`.
9. `authorize()` with input, when it takes `ValidatedInput` (nullable or not).
10. For an agent's Destructive or External call: the person's confirmation, taken once. Then, for a Read that lists tables in `$initializes`, `initialize()` ([a Read that creates its own state](#a-read-that-creates-its-own-state)), then `handle()`, the output projection, and `modelReply()`.
11. One event: `ActionCompleted`, `ActionRefused` or `ActionFailed` ([events](#events)). Events carry no input values.

Steps 1 to 4 answer "not found" when they stop, so an action a caller may not see reads exactly like one that does not exist. A denied `authorize()` answers 403, or 404 when it returns `Response::denyAsNotFound()`. A crash is reported once and rendered by your exception handler.

`Actions::attempt($action, $input, $context)` runs the same pipeline and returns an `AgenticActions\Outcome` instead of throwing. `$action` is a class or a discovered action's name.

| Method | Returns |
|---|---|
| `ok()` | Whether `handle()` ran and returned. |
| `refused()` | Whether the call did not succeed, for any reason, a crash included. |
| `status()` | What HTTP would have answered: 200, 403, 404, 409 or the refusal's status, 422, 428 or 500. |
| `output()` | The output after the `outputSchema()` allowlist; null unless `ok()`. |
| `result()` | `handle()`'s raw value, for your code. The package never sends it anywhere. |
| `errors()` | Validation messages by key. |
| `refusal()` | A `Refusal` for any outcome that is neither ok nor a crash: the action's own, or one with the package's sentence for invalid, not found or denied. |
| `forModel(bool $shown = false)` | The sentence a model reads for this outcome, as an action tool sends it. `$shown` is true when the person already sees the action's table. |

A tool you write yourself, for laravel/ai or laravel/mcp, returns `forModel()`, so the model reads what it reads from an action tool and never an exception message. In a laravel/ai tool such as the one in [hand-written tools](copilot.md#hand-written-tools):

```php
use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\Activity;
use App\Actions\PublishPost;
use Laravel\Ai\Tools\Request;

public function handle(Request $request): string
{
    $outcome = Actions::attempt(PublishPost::class, ['post' => $request['post']], ActionContext::agent($this->user));

    Activity::outcome($request, $outcome); // the copilot row

    return $outcome->forModel();
}
```

### What an agent's tool list shows

Before each turn, an agent's tools are built by running steps 1 to 5 for every action in its toolsets. An action leaves the list when one of those steps says no (the door, the token, `shouldRegister()`, membership, or an `authorize()` that takes no input), when it would offer a [forbidden key](security.md), or when reading its schema throws, as it does for a [dataset](data.md#declare-a-dataset) whose declaration the package refuses. The other actions stay listed. An `authorize()` that takes `ValidatedInput` needs the model's arguments, so it runs only when the model calls the tool. Until then the tool stays listed, and a person who may never run it still sees it in the agent's list, and is refused when the model calls it, unless that `ValidatedInput` may be null (below).

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

An action has one `authorize()`. When it has to take input, as it does when agents are offered an id, let its `ValidatedInput` be null: it then runs twice, first before any input is read with `null` (step 5, and when the tool list is built), then after validation with the input (step 9). Check the caller on the first run and the record on the second:

```php
public function authorize(ActionContext $context, ?ValidatedInput $input = null): bool
{
    if (! $context->actor(User::class)->can('edit-tasks')) {
        return false; // Off the tool list, and refused before any input is read.
    }

    return $input === null || $context->find(Task::class, $input->integer('task'))->isOpen();
}
```

`shouldRegister()` can hold the check on the caller instead, as the [strict agent schemas](recipes.md#strict-agent-schemas-no-ids) recipe does.

## Events

Every call fires one event from `AgenticActions\Events`: `ActionCompleted` when `handle()` returned, `ActionRefused` when the call stopped before that, or `ActionFailed` when it crashed. They carry no input values and no exception message. Each has these properties:

| Property | Holds |
|---|---|
| `action`, `class` | The action's name and class. |
| `surface`, `effect` | Its `Surface` and `Effect`. |
| `modelDriven` | Whether a model drove the call. |
| `actorType`, `actorId` | The actor's morph class and key, or null for no actor. |
| `tenantId` | The tenant's key, or null. |
| `requestId` | The context's request id. |
| `durationMs` | How long the call took, as a float. |

`ActionRefused` adds `reason` (`invalid`, `not_found`, `denied` or `refused`), `status` (what HTTP would answer, as `Outcome::status()` gives it) and `failedRules` (the failing rule names by key, as a model reads them). `ActionFailed` adds `exceptionClass`.

The three implement Laravel's `ShouldDispatchAfterCommit`: fired inside a database transaction, they reach your listeners after it commits, and never if it rolls back. Listeners run outside the Read guard. An audit log is one listener:

```php
<?php

namespace App\Listeners;

use AgenticActions\Events\ActionCompleted;
use Illuminate\Support\Facades\Log;

final class LogCompletedAction
{
    public function handle(ActionCompleted $event): void
    {
        Log::info('Action completed', [
            'action' => $event->action,
            'surface' => $event->surface->value,
            'model_driven' => $event->modelDriven,
            'actor' => $event->actorId,
            'tenant' => $event->tenantId,
            'request' => $event->requestId,
        ]);
    }
}
```

Laravel discovers a listener in `app/Listeners` by the event its `handle()` takes.

## Refusals

An action says no with `AgenticActions\Refusal`:

```php
throw Refusal::make(__('You already have a post with that title.'))->on('title');
```

`make()` takes a translation key or a sentence, an optional default and replacements. Replacements come from your own text or the actor's own saved rows, never from the caller's input. `on()` puts the message on a field, `status()` sets the HTTP status (409 by default), `details()` adds data for web and API callers only, and `listing()` adds text other people wrote, which a model receives framed as data. `Actions::refuse(DomainException::class, fn ($e, $context) => Refusal::make(...))` turns an exception your domain already throws into a refusal on every surface.

A JSON caller receives a refusal without a field as `{"message": "…", "code": "…", "details": {}}` with its status, and `code` is `make()`'s first argument. `Refusal::make(__('…'))` therefore sends the translated sentence as the code. When API clients branch on the code, pass a key and a default sentence instead, and the message is translated in the caller's locale:

```php
throw Refusal::make('posts.publish_limit', 'You have published the most posts your plan allows today.');
```

The code reaches only a refusal without a field. A refusal on a field reaches a JSON caller as a 422 validation error on that field, with no `code`, and a browser visit as a validation error in the action's error bag. `Actions::translateUsing(fn (string $key, ?string $default, array $replace, string $locale): string => …)` hands your refusal keys to your own translator; the package's own keys stay with Laravel's.

Code that catches a `Refusal` reads it with `key()`, `field()`, `statusCode()`, `getDetails()`, `getListing()`, `translate($locale)`, and `toValidationException()` for a Livewire or Blade form.

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

### Toolsets

`#[Expose]` puts an action in toolsets, and `#[UseToolset]` on an agent class names the toolsets it receives. A bare `#[Expose]` puts the action in `default` when agents may receive it; `agents: [...]` names its toolsets; an `#[Expose]` that names other surfaces and not `agents` puts it in none.

```php
use AgenticActions\Attributes\UseToolset;

#[UseToolset]                        // "default"
#[UseToolset('support')]             // "support" only
#[UseToolset('default', 'support')]  // both
```

The names are separate strings, not an array: `#[UseToolset(['support'])]` throws a `TypeError` as soon as the package reads it. An agent that uses `InteractsWithActions` without the attribute throws a `LogicException` when its tools are built. The attribute is not inherited either. `assertToolset()` and `assertAgentTools()` pin both sides in your tests ([testing](testing.md#toolsets-and-agents)).

## Discovery, the manifest and the snapshot

The scanner walks `discovery.paths` (default `app`; globs and absolute paths work) and reads each file that mentions `AgenticActions\`. It takes the class name from the file's tokens, never from its path. Classes outside those paths go in `discovery.classes`.

`php artisan optimize` (or `actions:cache`) writes a manifest to `bootstrap/cache/agentic-actions.php`, so production requests do not scan. The console, local requests and test runs always scan. The manifest only nominates: every gate re-reads the class, so a stale manifest can hide a new action but never widen an old one. [Deploying](setup.md#deploying) says what to run on each deploy.

`actions.exposure.json` is the reviewable form of the same facts, committed with your code. Only `php artisan actions:check --update` writes it. `actions:check` fails while it differs from what the classes declare, so a new route or a widened toolset is a diff someone approved. `--update` also prints each action it now lists under another name, since a new name moves its route, its tools and its TypeScript export.

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

- **A route key.** The URL segment is resolved through the model's own route binding, so the model sets its key: `getRouteKeyName()` returning `'slug'`, or UUID primary keys. Write the prefix as `teams/{team}`. A binding field in the prefix, such as `{team:slug}`, is not read: with the default `id` route key, members get a 404 at `/teams/acme` while `/teams/1` answers, and `actions:check` passes. An unknown key is a 404, except on Postgres, where a key the column cannot hold, such as a slug for an integer `id`, fails the query and answers 500. The MCP tenant path and `actions:run --tenant=acme` take the same route key.
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

- **Only `true` admits.** The answer is compared with `=== true`. Return a real boolean: with the `bool` return type the contract requires, PHP turns `1` into `true` (in a file without `strict_types`), and a model throws a `TypeError`. An untyped `membershipUsing()` closure that returns anything but `true` is a no.
- **`$effect` is null when the question is the tenant as a whole**: on every request to the MCP tenant path, a `tools/call` included, and when a person approves an OAuth client for a tenant. A call then asks again with its action's effect, and so does each action a tool list weighs, so one MCP `tools/list` asks with `null` first, then once per action with its effect, such as `Read`, then `Write`. A no to `null` gives that client an empty list, and every call it makes there answers "Tool not found", while the same token's HTTP call asks only with the action's effect. To let some members only read, answer `null` and `Effect::Read` with true for them, and the other effects with false. The change feed asks with `Read`.
- **Membership is side-effect free.** It runs on every surface, often several times in one request.
- **A scope throws for a model it does not handle.** `find()` and [datasets](data.md), with their relations, all read through it, so a missing arm stops loudly instead of reading every team's rows. It returns a query for the model it was given. Which rows are the team's is its decision: see [what `find()` guarantees](security.md).
- **A configured class wins.** While `tenant.membership` names a class, `Actions::membershipUsing()` is never called, and the same holds for `tenant.scope` and `Actions::scopeUsing()`.
- **Without a scope, `find()` throws.** With `tenant.model` set and neither `tenant.scope` nor `scopeUsing()`, the first `$context->find()`, or a tenant-scoped dataset, throws `MissingContext` ("A tenant model is configured, but no tenant scope is"), which answers 500. `actions:check` warns about it.

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

### A tenant-scoped action

An action is tenant-scoped unless it says otherwise, so `CreatePost` from [Getting started](../README.md#try-it-in-a-new-app), without its `$tenantScoped = false` line, runs inside a team: `POST /teams/acme/actions/create-post` on the web group above, `/api/teams/acme/actions/create-post` for a token. The team comes from the URL or the token, never from input, so the action reads it from the context and sets the foreign key itself. The posts table gains the column, `$table->foreignId('team_id')->constrained()->cascadeOnDelete();`, and `Post`'s `$fillable` lists `team_id`, which input can never carry, since no `schema()` key may be named after it. `handle()` becomes:

```php
use App\Models\Team;

public function handle(ActionContext $context, ValidatedInput $input): Post
{
    $author = $context->actor(User::class);

    if ($author->posts()->where('title', $input->string('title')->toString())->exists()) {
        throw Refusal::make(__('You already have a post with that title.'))->on('title');
    }

    return $author->posts()->create([
        ...$input->all(),
        'team_id' => $context->tenant(Team::class)->getKey(),
        'status' => 'draft',
    ]);
}
```

Membership is checked before `authorize()` runs, so `authorize()` stays as it was. `$context->find()` is the one read the scope narrows for you; a query of your own filters by the tenant itself, as a Read that lists the team's posts does: `Post::query()->where('team_id', $context->tenant(Team::class)->getKey())->latest()->get()`.

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

The `tenancy` config key names a class implementing `AgenticActions\Contracts\Tenancy`, which the container builds once. Each call's pipeline runs inside its `run(?Model $tenant, ?Authenticatable $actor, Closure $callback): mixed`, so another package's idea of the current tenant matches the call's. The package ships `AgenticActions\Tenancy\SpatieTeams`, which switches spatie/laravel-permission's team to the tenant around each pipeline step and restores it afterwards. Your own sets the state, runs the callback, and restores what was there in `finally`:

```php
<?php

namespace App\Tenancy;

use AgenticActions\Contracts\Tenancy;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

final class TeamContext implements Tenancy
{
    public function run(?Model $tenant, ?Authenticatable $actor, Closure $callback): mixed
    {
        if ($tenant === null) {
            return $callback();
        }

        $previous = Context::get('team_id');
        Context::add('team_id', $tenant->getKey());

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                Context::forget('team_id');
            } else {
                Context::add('team_id', $previous);
            }
        }
    }
}
```

Set `'tenancy' => App\Tenancy\TeamContext::class` in `config/agentic-actions.php`. `$tenant` is null for a call without a tenant, and `$actor` for one without an actor.

## Queued runs

`Action::dispatch()` queues the whole pipeline to run in a worker, as the caller:

```php
ImportPosts::dispatch(['url' => $url], ActionContext::fromRequest($request))->onQueue('imports');
```

The job keeps the caller's actor and tenant, the input and the fixed input, the locale, the idempotency key and the token grants the caller had, and every check runs again in the worker: the token, membership, `authorize()` and validation. A call the job's own code makes, and an action it queues with `dispatch()`, read the same grants, whatever context that code builds; a job of your own that it queues does not ([your own jobs](recipes.md#your-own-jobs)). It gets its own request id. `dispatch()` returns Laravel's `PendingDispatch`, so `onQueue()`, `delay()` and `afterCommit()` chain as usual, and, like `run()`, it is reached by discovery alone. The input must serialize: pass scalars and arrays, and store an uploaded file first and pass its path. A job queued by an agent's tool or inside an MCP call stays model-driven in the worker, so it still reaches only Read and Write actions, and so do the calls its code makes and the actions it queues with `dispatch()`. A refusal ends the job; a crash fires `ActionFailed` and fails it, and the worker retries it per its `--tries`, so an action that may run twice calls `$context->requireIdempotencyKey()` and stores the key ([context](#context)); `$idempotent` is only a hint to MCP clients. While a Read's own code runs, `dispatch()` refuses an action that is not a Read. [Testing](testing.md#queued-runs) shows how to test a queued run.

`dispatch()` queues one call. To make many from a job you write, such as one for each line of an uploaded file, call `run()` there with a context you build: [your own jobs](recipes.md#your-own-jobs) shows what each call then checks, and what it does not.

## The change feed

A copilot turn reloads what its own rows touched. Writes made elsewhere, over MCP, by the queue, by another person in the same tenant or in another tab, reach an open page through the change feed. Each completed write keeps its `$touches` keys (`*` when it declares none) in your default cache store for `feed.window` seconds: per tenant when the write had one, so every member's open page hears of it, and otherwise per person. Each `Actions::routes()` group gains `POST …/actions/_changes`, named `_changes` within the group, which a page polls through `useActionSync({ feed: { url } })` or `createActionSync()` ([the copilot](copilot.md#writes-made-elsewhere)). It answers a signed-in session only, with keys only, never ids or values. No action may be named `_changes`.

The feed is on by default, and each completed write pays one cache write for it. An app with no polling page turns it off with `feed.enabled`. Keep it on a cache store every server shares, such as database or redis: `actions:check` warns outside local development about one that forgets its entries, keeps them per visitor or keeps them on one server.

## The copilot

When an agent streams its turn through `ActionsProtocol`, each call of an action tool shows the person a row: a label while it runs, then done, refused, failed or ended, taken from what the pipeline recorded rather than from the model's text. An action's label is its `activityLabel()`, or the package's sentence for its effect, and a hand-written tool gets a row by implementing `DescribesActivity`. A done row carries the action's `$touches`, which the page reloads once the burst of writes is over, unless an editor on the page has unsaved work. On an HTTP call a Write with `$touches = []` reloads nothing; its copilot row sends `['*']`, which reloads the whole page, because a row has no response to read. With `#[WithPageContext]` the agent also learns which page is open, by route name and component only. A Read action that implements `ShowsTable` also shows the person its rows as a table after its row, and the model reads a short copy ([tables](data.md)). See [the copilot](copilot.md).

## The facade

`AgenticActions\Facades\Actions` is the app's entry to the package outside an action class.

| Method | Use |
|---|---|
| `routes(?bool $tenant = null)` | One POST route per web-exposed action inside the calling route group: `true` mounts the tenant-scoped actions, `false` the others, null all. See [Setup](setup.md). |
| `attempt($action, $input, $context)` | Runs the pipeline in-process and returns an [`Outcome`](#the-pipeline). |
| `tools(ActionContext $context, array $toolsets, ?Agent $agent = null)` | The action tools of these toolsets for an agent that does not use `InteractsWithActions` (below). |
| `conversation($agent, $participant, $tenant = null)` | The conversation a person continues with an agent in a tenant. See [the copilot](copilot.md#one-conversation-per-tenant). |
| `exposure()` | What `actions.exposure.json` would hold now, as `actions:list --json` prints it: `version`, `actions` by name, and `agents`. |
| `refuse($exception, $map)`, `translateUsing($translator)` | See [refusals](#refusals). |
| `membershipUsing($closure)`, `scopeUsing($closure)` | See [tenants](#tenants). |
| `fake()`, `assertToolset()`, `assertAgentTools()` | See [testing](testing.md). |

`tools()` builds the tools for this context on every call, through the same checks as the trait. Pass the agent: Destructive and External actions are offered only to an agent that stores its conversations, so the person can confirm each call, and never without one; an asking action needs it to pause for the person too.

```php
<?php

namespace App\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

final class SupportAssistant implements Agent, HasTools
{
    use Promptable;

    public function __construct(public User $user) {}

    public function instructions(): string
    {
        return 'You answer questions about the author\'s posts.';
    }

    public function tools(): iterable
    {
        return Actions::tools(ActionContext::agent($this->user), ['support'], $this);
    }
}
```

## Public API

Every class in `src/` says in its docblock whether it is `@api` or `@internal`, and so do public members of an `@api` class that apps should not call. `@api` is what your app may build on, such as `Action`, `ActionContext`, `Refusal`, `Outcome`, `NamedRule`, the facade, the attributes, the events and `ActionsFake`; `grep -rln '@api' vendor/agentic-actions/laravel/src` lists the files. Before 1.0 a release may still change it, and the [changelog](../CHANGELOG.md)'s Upgrading section for that release says what to do. `@internal` code carries no such promise, even where PHP lets you reach it, such as `ActionContext::console()` or `Outcome::kind()`: it may change in any release without a note.
