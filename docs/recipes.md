# Recipes

## Strict agent schemas (no ids)

An agent does best with the words a person would use: a post's title, not its id. `agentSchema()` gives agents their own input, and `fromAgent()` turns it into the canonical input that `schema()` describes. The canonical input is then validated again, and `authorize()` and `handle()` see only canonical input, whichever door the call came through.

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
final class ArchivePost extends Action
{
    protected string $description = 'Archive one of the author\'s posts, named by its exact title.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The canonical input, for the CLI and your own code.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['post' => $schema->integer()->required()];
    }

    /**
     * What an agent is offered instead: no id.
     */
    public function agentSchema(JsonSchema $schema): ?array
    {
        return ['title' => $schema->string()->required()->description('The exact title of the post.')];
    }

    /**
     * Find the post among the author's own, or refuse with the titles they do have.
     */
    public function fromAgent(ValidatedInput $input, ActionContext $context): array
    {
        $author = $context->actor(User::class);
        $post = $author->posts()->where('title', $input->string('title')->toString())->first();

        if ($post === null) {
            throw Refusal::make(__('You have no post with that title.'))
                ->on('title')
                ->listing($author->posts()->pluck('title')->all());
        }

        return ['post' => $post->getKey()];
    }

    /**
     * Only signed-in authors see the tool.
     */
    public function shouldRegister(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * The post must be the actor's own, whoever named it.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return $context->actor(User::class)->posts()->whereKey($input->integer('post'))->exists();
    }

    /**
     * Archive it.
     */
    public function handle(ActionContext $context, ValidatedInput $input): void
    {
        $context->find(Post::class, $input->integer('post'))->update(['status' => 'archived']);
    }
}
```

- A missing title comes back to the model as the refusal's sentence, followed by the author's own titles framed as data, so it can pick the right one.
- Under a bare `#[Expose]`, an action with an `agentSchema()` gets no generated route: its canonical input carries ids an agent never saw, not what a form sends, so write that route by hand if a form needs one. Naming `web: true` on it is an error. When agents only need to give more fields than the web, keep `schema()` alone and name them in [`requiredForAgents()`](asking.md#fields-only-a-model-must-give) instead: the route stays.
- `fromAgent()` runs before `authorize()` sees any input. When `authorize()` takes `ValidatedInput`, `actions:check` requires `shouldRegister()` too, so a caller who may not use the action never reaches `fromAgent()`, unless that `ValidatedInput` may be null: `authorize(ActionContext $context, ?ValidatedInput $input = null)` also runs before `fromAgent()`, with null, and must refuse there every caller who may not use the action.
- To keep ids away from agents everywhere, add them to the forbidden keys in `config/agentic-actions.php`. Any action that still offers one is then left out of every toolset, and `actions:check` names it:

```php
'agents' => [
    'forbidden_keys' => ['*password*', '*secret*', '*token*', 'api_key', 'id', '*_id', 'uuid'],
],
```

## Livewire and Blade controllers

Any code already inside a request calls an action in-process with `run()`. It returns what `handle()` returned, throws a `ValidationException` for invalid input, and throws a `Refusal` for anything else that stopped the call. One `catch` turns a refusal into a form error:

```php
public function store(Request $request): RedirectResponse
{
    try {
        $post = CreatePost::run($request->only('title', 'body', 'excerpt'), ActionContext::http($request->user()));
    } catch (Refusal $r) {
        throw $r->toValidationException();
    }

    return to_route('posts.show', $post);
}
```

`toValidationException()` puts the message on the refusal's field, or on `action`, in the `default` bag; pass another bag as its first argument. The same `try` works in a Livewire component's method. `run()` reaches any action class, exposed or not, so a controller like this one needs no `#[Expose]`.

A route whose controller is the action itself, `Route::post('posts', CreatePost::class)`, works too: it runs the same pipeline as a generated route, and the route you wrote is its own allowlist. Do not also expose the action on the web; `actions:check` fails an action that has both a generated and a hand-written route.

## Forms on Inertia React

`useAction()` is Inertia's `useHttp` for an action's typed definition, with `run()` and `refusal` added:

```tsx
import { type FormEvent } from 'react';
import { useAction } from '@agentic-actions/client/react';
import { createPost } from '@/agentic/actions';

export function NewPost() {
    const post = useAction(createPost(), { title: '', body: '' });

    async function save(event: FormEvent) {
        event.preventDefault();

        if ((await post.run()) !== undefined) {
            post.setData({ title: '', body: '' });
        }
    }

    return (
        <form onSubmit={save}>
            <input value={post.data.title} onChange={(event) => post.setData('title', event.target.value)} onBlur={() => post.validate('title')} />
            {post.errors.title && <p>{post.errors.title}</p>}
            <textarea value={post.data.body} onChange={(event) => post.setData('body', event.target.value)} />
            {post.refusal && <p role="alert">{post.refusal}</p>}
            <button disabled={post.processing}>Save draft</button>
        </form>
    );
}
```

- `run()` sends an `Idempotency-Key`, resolves the output, and reloads what the action's `$touches` name. It resolves `undefined` when the call did not go through: field errors land on `errors`, and any other answer below 500 (a refusal, 403, 404) lands on `refusal`.
- `validate()` is Precognition's. A 401, 403, 404, 409 or 423 lands on `refusal` there too, and the next check clears it.
- After a successful `run()`, Inertia makes the values it sent the form's new defaults, so `reset()` brings those values back instead of emptying the form. To start the next entry empty, set the fields yourself, as `save()` does above.

## File uploads

A file is a `string()` field with the `binary` format. It arrives as `multipart/form-data`, and `handle()` reads it as an `Illuminate\Http\UploadedFile`. This action takes a CSV of posts for a team, stores it, and queues [a job of your own](#your-own-jobs) to import it:

```php
<?php

namespace App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use App\Jobs\ImportPostsFromCsv;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

#[Expose(web: true)]
final class UploadPosts extends Action
{
    protected string $description = 'Import draft posts from a CSV file: one post per line, its title, then its body.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The file.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'file' => $schema->string()->format('binary')->required(),
        ];
    }

    /**
     * What schema() does not say about a file: its type, and its size in kilobytes.
     */
    public function rules(ActionContext $context): array
    {
        return ['file' => ['mimes:csv,txt', 'max:1024']];
    }

    /**
     * Any member of the team: membership is checked before this runs.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * Keep the file, and import it in a worker.
     */
    public function handle(ActionContext $context, ValidatedInput $input): void
    {
        $path = $input->input('file')->store('imports');

        ImportPostsFromCsv::dispatch(
            $context->actor(User::class)->getKey(),
            $context->tenant(Team::class)->getKey(),
            $path,
            $context->locale,
        );
    }
}
```

In the tenant group of [mounting the routes](concepts.md#mounting-the-routes), the action answers `POST /teams/{team}/actions/upload-posts`, named `teams.actions.upload-posts`. Every caller sends the file as `multipart/form-data`:

- A Blade form sets `enctype`. A file the rules refuse comes back as an error on `file`:

```blade
<form method="POST" action="{{ route('teams.actions.upload-posts', $team) }}" enctype="multipart/form-data">
    @csrf
    <input type="file" name="file" accept=".csv">
    @error('file') <p>{{ $message }}</p> @enderror
    <button>Import</button>
</form>
```

- The TypeScript client types the field `File | Blob`. Pass the `File` itself, not a `FormData` of your own: `callAction()` builds the multipart body once the input holds a file, and `useAction()` sends one as multipart too, through Inertia's `useHttp`. For a file the rules refuse, `callAction()` rejects with an `ActionValidationError` whose `errors.file` holds the message, while `useAction()` puts the message on its `errors.file` and its `run()` resolves `undefined`.

```ts
import { callAction } from '@agentic-actions/client';
import { uploadPosts } from '@/agentic/actions';

export async function uploadCsv(file: File): Promise<void> {
    await callAction(uploadPosts({ team: 'acme' }), { file });
}
```

- A token client posts the same form to the `routes/api.php` group, with a token that can write:

```bash
curl https://example.com/api/teams/acme/actions/upload-posts \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F file=@posts.csv
```

A JSON body cannot carry a file, so the field fails its `file` rule there. `handle()` runs while the request is open, so it only stores the file, on the default disk under a name Laravel picks, and queues the import with the path: a job's input must serialize, and an uploaded file does not.

An action whose `schema()` holds a file is not offered to agents or MCP clients. A model's tool call carries JSON arguments, never a file, and every call a model makes is validated against `schema()` too, so an `agentSchema()` without the file changes nothing. The package leaves the action out of every agent's tools and the MCP server's (your tests and local requests throw instead), and `actions:check` fails it while its `#[Expose]` opens agents or MCP, as a bare `#[Expose]` would. `web: true` keeps it to the route. To let an agent import posts, give it another action that takes what a model can send: `CreatePost` once for each post, or an action that takes a list of titles and bodies.

Only a call on the `Http` surface takes a file: a route, or your own code with `ActionContext::http()`. A call from the CLI, a queued run or `ActionContext::system()` that reaches validation fails with `UnsupportedSchema`, so `actions:run` exits with 4. Under `system()`, `UploadPosts` refuses before that, since its `authorize()` wants a person.

## Your own jobs

A job you write runs actions with `run()`, as a controller does. Build the context for the person the work belongs to, `ActionContext::http($user, $team, $locale)`, and each call goes through the whole pipeline as theirs. This job imports the file [the upload](#file-uploads) stored, one post per line, with `CreatePost` made tenant-scoped as in [a tenant-scoped action](concepts.md#a-tenant-scoped-action):

```php
<?php

namespace App\Jobs;

use AgenticActions\ActionContext;
use AgenticActions\Refusal;
use App\Actions\CreatePost;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ImportPostsFromCsv implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $authorId,
        public int $teamId,
        public string $path,
        public string $locale,
    ) {}

    /**
     * Draft one post per line, as the author in the team, and keep the reason for each line it skips.
     */
    public function handle(): void
    {
        $author = User::find($this->authorId);
        $team = Team::find($this->teamId);

        // Deleted since the upload, soft-deleted included: import nothing.
        if ($author === null || $team === null) {
            Storage::delete($this->path);

            return;
        }

        $context = ActionContext::http($author, $team, $this->locale);
        $csv = Storage::readStream($this->path);
        $skipped = [];
        $line = 0;

        while (($row = fgetcsv($csv, escape: '')) !== false) {
            $line++;

            try {
                CreatePost::run(['title' => $row[0] ?? null, 'body' => $row[1] ?? null], $context);
            } catch (ValidationException $e) {
                $skipped[$line] = $e->getMessage();
            } catch (Refusal $e) {
                $skipped[$line] = $e->translate($context->locale);
            }
        }

        fclose($csv);
        Storage::delete($this->path);

        // Tell the author which lines were skipped and why, such as in a notification.
    }

    /**
     * The last try crashed: remove the file all the same.
     */
    public function failed(): void
    {
        Storage::delete($this->path);
    }
}
```

The job takes keys rather than models and finds the author and the team when it runs: a person or team deleted since the upload, soft-deleted included, imports nothing, and the file goes either way, by `failed()` once the last try has crashed. That is where it differs from the job `dispatch()` queues, which the package drops when its person or tenant is gone: a job of your own that took the models would run for a soft-deleted author, since Laravel restores one and `run()` does not refuse it, and would fail on one deleted for good, leaving the file behind.

The loop is yours, so the job can also count progress, stop early or send a summary. Each `run()` is a whole call, as the author in the team:

- `shouldRegister()`, membership in the team (asked again for every line), `authorize()` and validation run, then `handle()`. Each call fires its own [event](concepts.md#events), on the `Http` surface its context names, and its write reaches the team's open pages through the [change feed](concepts.md#the-change-feed).
- `run()` returns what `handle()` returned. Invalid input throws a `ValidationException`, and a refusal, a denied `authorize()` or a person no longer in the team throws a `Refusal`, so the job skips that line and goes on. Anything else is a crash: it fires `ActionFailed` and fails the job, and a retry starts again at the first line, so keep each call safe to repeat (`CreatePost` refuses a title the author already has). `Actions::attempt()` makes the same call and returns an [`Outcome`](concepts.md#the-pipeline) instead, for a loop that should report a crash and go on.
- A worker runs in `app.locale`, so the job takes the person's locale from the context that queued it, and validation messages and refusals come back in that language.

What each call is not held to:

- `#[Expose]`: `run()` reaches any action ([doors](concepts.md#doors)).
- A token's limits. `http()` takes the default guard when you build the context, which in a worker is `auth.defaults.guard`: under the session guard (`web` in a new app) the calls have the person's full access, and under a token guard every call reads as not found.
- A model's limits. In a worker, the calls are not model-driven, even when an agent's tool or an MCP client led to the job, directly or from a queued run.

So whoever may start the job gets every call it makes. Queue it from code whose own checks cover that work, as `UploadPosts`, a Write, queues a job that only writes. For one call, or for items that stand alone, queue the action itself with `CreatePost::dispatch($input, $context)` ([queued runs](concepts.md#queued-runs)): that job keeps the caller's token limits and model origin and checks them again in the worker, each call retries on its own, and a person or team deleted before the run drops it. Write a job of your own when the work needs one place: a file to read, lines in order, progress, a summary.

With no person behind the work, such as a nightly import, build the context with `ActionContext::system($team)`: there is no actor, no token check and no membership, and `authorize()` decides, so the action must let `$context->isSystem()` through. Never build one with `ActionContext::queued()`: the package builds it for its own queued runs, and it is `@internal`.

## State created the first time it is read

A team's inbound address, made the first time anyone opens the page that shows it, in an app whose tenant is a team, set up as in [tenants](concepts.md#tenants). When every team should have one from the start, create it with the team instead and backfill the teams that exist once ([a Read that creates its own state](concepts.md#a-read-that-creates-its-own-state)); this recipe is for state that should exist only once someone looks.

The table holds one row per team, and the unique key on `team_id` is what keeps it at one when two first reads arrive at once:

```php
Schema::create('team_inboxes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
    $table->string('address')->unique();
    $table->timestamps();
});
```

`Team` gains `public function inbox(): HasOne { return $this->hasOne(TeamInbox::class); }`, and `TeamInbox` lists `address` in `$fillable`. The action adds the row in `initialize()`, which may only add rows to the tables `$initializes` lists, and `handle()` only reads it:

```php
<?php

namespace App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;

#[Expose]
final class ShowTeamInbox extends Action
{
    protected string $description = 'The address that forwards email into this team.';

    protected ?Effect $effect = Effect::Read;

    protected array $initializes = ['team_inboxes'];

    public function outputSchema(JsonSchema $schema): array
    {
        return ['address' => $schema->string()->required()];
    }

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Add the team's inbox when it has none. The address comes from the server, never from input.
     */
    public function initialize(ActionContext $context): void
    {
        $context->tenant(Team::class)->inbox()->firstOrCreate([], fn (): array => ['address' => Str::lower(Str::random(24))]);
    }

    /**
     * @return array{address: string}
     */
    public function handle(ActionContext $context): array
    {
        return ['address' => $context->tenant(Team::class)->inbox()->sole()->address.'@in.example.com'];
    }
}
```

`firstOrCreate()` reads first and adds the row only when it finds none, so every later call sends nothing but reads. When two first reads arrive at once, both find none and the unique key refuses the second insert: `createOrFirst()`, where `firstOrCreate()` ends, then reads the row the first one added, and both calls answer the same address. Inside a transaction it inserts in a savepoint, so the transaction goes on. Without the unique key the team would get two inboxes.

`initialize()` runs after every check, membership and `authorize()` included, so a person outside the team never adds one. Whoever may read the inbox may cause it to exist, a token with only `actions:read` included, and the action stays a Read for every caller: the copilot's row reads "Looking it up…", MCP clients read it as read-only, and nothing reaches the change feed. Its other rules, and when to use a Write instead, are in [a Read that creates its own state](concepts.md#a-read-that-creates-its-own-state).

A test runs it twice and finds one row:

```php
use AgenticActions\ActionContext;
use App\Actions\ShowTeamInbox;
use App\Models\Team;
use App\Models\User;

it('gives a team one inbox, made the first time it is read', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team);

    $first = ShowTeamInbox::run([], ActionContext::http($user, $team));
    $second = ShowTeamInbox::run([], ActionContext::http($user, $team));

    expect($second)->toBe($first)
        ->and($team->inbox()->count())->toBe(1);
});
```

## laravel-data

The validated input is a plain array, so a data object is one line inside `handle()`: `PostData::from($input->all())`. `schema()` stays the one place the input is described and validated.

## Discovery layouts

Actions are found under `app` by default. Set `discovery.paths` in `config/agentic-actions.php` for other layouts; globs and absolute paths both work:

- `Modules/*/app` for nwidart/laravel-modules
- `app-modules/*/src` for internachi/modular
- `src/Domain` for domain folders outside `app`

The scanner only reads files that mention `AgenticActions\`. An action that extends your own base class and never names the package itself goes in `discovery.classes`. `php artisan actions:list` prints the directories it walked when it finds nothing.

## A token reader for a JWT or API-key guard

The default reader knows three credentials: Laravel's session (full access), Sanctum tokens (their abilities) and Passport tokens (their read and write scopes, on the MCP URL of their [OAuth connection](mcp.md#what-a-connection-reaches) only). Any other guard reads as having no abilities, so every action is not found for it. Bind your own `AgenticActions\Contracts\ReadsTokenGrants` to read its grants. A reader of your own replaces the one that keeps each OAuth connection to its URL and tenant, so `actions:check` fails while it is bound beside a Passport guard in `mcp.middleware`:

```php
<?php

namespace App\Auth;

use AgenticActions\Contracts\ReadsTokenGrants;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

final class AppTokenGrants implements ReadsTokenGrants
{
    /**
     * Null for a session, the credential's abilities for a token, nothing for anything else.
     *
     * @return list<string>|null
     */
    public function grants(?Authenticatable $actor, Guard $guard): ?array
    {
        if ($guard instanceof SessionGuard) {
            return null;
        }

        if ($guard instanceof ApiKeyGuard) {
            return $guard->key()?->abilities ?? [];
        }

        $token = $actor !== null && method_exists($actor, 'currentAccessToken') ? $actor->currentAccessToken() : null;

        return match (true) {
            $token instanceof TransientToken => null,
            $token instanceof PersonalAccessToken => is_array($token->abilities) ? array_values($token->abilities) : [],
            default => [],
        };
    }
}
```

`ApiKeyGuard` stands for your own guard class. Register the reader in a service provider's `register()`:

```php
$this->app->singleton(ReadsTokenGrants::class, AppTokenGrants::class);
```

The rules for what you return:

- `null` means a session: full access. Return it only for a credential that really is one.
- `[]` means nothing: every action reads as not found.
- Otherwise, a list of abilities from `config('agentic-actions.abilities')`: `actions:read`, `actions:write`, `actions:destructive`, `actions:external`, `*` for all of them over HTTP, and `tenant:{key}` to bind the credential to one tenant's primary key.

Your reader replaces the default one, so keep the session and Sanctum branches if the app still uses them. This one reads a Sanctum token's stored abilities only, so test Sanctum calls with real tokens from `createToken()` rather than `Sanctum::actingAs()`, whose token has none stored. `php artisan actions:list` then shows "custom reader" with your class.

## TypeScript and Wayfinder

A generated route is an ordinary route whose controller is the action class, so Laravel Wayfinder sees it as it sees any other controller route. `php artisan actions:typescript` adds what Wayfinder does not know: the input and output types from `schema()` and `outputSchema()`, the description, and the `touches` keys. The two can live side by side. Whatever regenerates Wayfinder's files in your dev loop can run `php artisan actions:typescript` too, and `php artisan actions:typescript --check` in CI fails when the committed file is stale.
