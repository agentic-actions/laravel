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
