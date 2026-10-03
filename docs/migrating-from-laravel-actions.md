# Migrating from laravel-actions

[lorisleiva/laravel-actions](https://github.com/lorisleiva/laravel-actions) and this package share the idea of one class per operation. They differ in how an action receives its caller: here every call carries an `ActionContext` (actor, tenant, locale, surface) and a validated input, and the class declares its own effect and exposure.

## Mapping

| laravel-actions | Agentic Actions |
|---|---|
| `rules()` | `schema(JsonSchema $schema)` for the input every caller sees and TypeScript types, plus `rules(ActionContext $context)` for server-only rules such as closures and scoped `exists` |
| `asController()` and a route you register | a route whose controller is the action, or `#[Expose]` and `Actions::routes()` |
| `authorize(ActionRequest $request)` | `authorize(ActionContext $context)`, or `authorize(ActionContext $context, ValidatedInput $input)` when it must see the input |
| `handle(...)` with your own parameters | `handle(ActionContext $context, ValidatedInput $input, ...$services)` |
| `MyAction::run(...$arguments)` | `MyAction::run(array $input, ActionContext $context)` |
| `AsCommand` and `asCommand()` | `php artisan actions:run my-action key=value --as=1` |
| `AsFake`, `MyAction::shouldRun()` | `Actions::fake([...])` and `$fake->assertRan(...)` |
| `AsJob`, `MyAction::dispatch(...)` | `MyAction::dispatch(array $input, ActionContext $context)`, which queues the whole pipeline as the caller |
| `AsListener` | a listener that calls `MyAction::run($input, ActionContext::system())` |

A few habits change on the way:

- Read the actor and the tenant from the context (`$context->actor(User::class)`, `$context->tenant()`), never from `auth()` or `request()`, so the same action works for a route, the CLI and an agent.
- Declare `$effect`. Without it the action is exposed nowhere and `actions:check` fails.
- Say no with `throw Refusal::make(__('Sentence.'))->on('field')` instead of aborting. Each surface renders a refusal in its own way.

## Wrap, don't mix

`run()`, `__invoke()` and `dispatch()` are final on `AgenticActions\Action`. A class that also uses `AsAction`, which defines all three, or `AsJob`, which defines `dispatch()`, fails to load. Keep the two apart: write the new action, and let the old class delegate to it while its other roles (job, listener, command) still have callers.

```php
use AgenticActions\ActionContext;
use App\Actions\PublishPost;
use App\Models\Post;
use Lorisleiva\Actions\Concerns\AsAction;

final class PublishScheduledPost
{
    use AsAction;

    public function handle(Post $post): void
    {
        PublishPost::run(['post' => $post->id], ActionContext::system($post->team));
    }
}
```

`ActionContext::system()` is for work no person started: there is no actor and no token check, and the action's `authorize()` still decides. Once nothing calls the old class, delete it.
