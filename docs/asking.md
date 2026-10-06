# Asking the person

When a model calls one of your Read or Write actions and leaves out something the action needs, the call is refused, naming the fields, and the model asks in words. With `$askForMissing`, the person gets a form in the chat instead, under the model's reply and marked as your app's. They submit it, decline it or close it. Only a submit runs the action: once, as that person, with their values, and with every check the action always runs.

The form follows MCP's form-mode elicitation: what the page receives is MCP's `{mode, message, requestedSchema}`, plus one hint for a textarea that a standard renderer ignores, so any renderer of MCP forms can draw it. It works in [the copilot](copilot.md), for the agents that can [confirm](copilot.md#confirmations) a call: those whose conversations laravel/ai stores, and for [MCP clients](#mcp) that show forms. The tool's description tells the model it may call the action with what it knows, so your instructions need nothing new.

## Opt in

```php
use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

#[Expose(web: true, agents: ['default'])]
final class DraftPost extends Action
{
    protected string $description = 'Draft a post for the current tenant.';

    protected ?Effect $effect = Effect::Write;

    protected bool $askForMissing = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title(__('Title'))->min(1)->max(120)->required(),
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

The person writes "Draft a post called Launch notes". The model calls `draft-post` with the title alone, and the chat shows a form with the title already filled, a body to write and a status to pick. The rest is the copilot you already have:

- The agent stores its conversations: it implements laravel/ai's `Conversational` and uses its `RemembersConversations` trait, as for confirmations ([the agent](copilot.md#the-agent)). An agent that does not never asks: the call is refused, naming the fields, as before, and `actions:check` warns about it (the Approvals row).
- The chat route passes the agent to `ChatRequest::from()` and returns through `respond()`, as for confirmations ([the route](copilot.md#the-route)). Keep its `throttle` middleware: the answers and their checks go through the route too.

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

`ask()` is optional. Without it, the form says "A few details are needed before this can go ahead." and shows each field under its schema title.

## What the form holds

The fields the model left out or got wrong, plus the ones `confirm()` names, in the order of your schema. A field opens on the model's value when validation accepted it, else on your `default()`, else on the schema's default. A value validation refused is never shown, nor a text holding a control character, a line separator or a direction control, which could make it read otherwise than it runs.

| Your schema | In the form |
|---|---|
| a string | a text field with its length limits. The formats `email`, `uri` (or `url`), `date` and `date-time` get the matching input. Other formats and `pattern()` are still checked by your action, not by the form |
| a string with `enum()` or `choices()` | one choice |
| an integer or a number | a number field with its bounds; with `enum()` or `choices()`, one choice |
| a boolean | a checkbox, which always answers yes or no |
| a list whose items are an `enum()` of strings, or a list with `choices()` | several choices, with the list's minimum and maximum |
| an object, a list of objects, a list of free values, a union, a file | never asked |
| a field that reads as a secret ([below](#never-ask-for-secrets)) | never asked |

A nullable field is optional in the form. A call that also leaves out a field the form cannot hold is refused whole, naming the fields, as before, so the model asks in words: the person is never asked half of what the call needs. A complete call runs at once; `confirm()` adds fields to a form the call already needs and never makes a complete call wait.

Nobody is asked for an action they may not reach. The tenant, the token and your input-free `authorize()` are checked before any form is built, as is an `authorize()` whose `ValidatedInput` may be null, called with null; an `authorize()` that takes `ValidatedInput` sees the input only when the action runs. With an `authorize()` that takes `ValidatedInput`, the form, and the choices and defaults `ask()` puts in it, is therefore shown before it checks the input, to a person it may still refuse: build `ask()` only from what every person who can reach the action may see.

A field's label is its schema `title()`, read in the person's locale. Without one, the form shows the key as a headline (`due_on` becomes "Due On"), so give every field a title in an app that is not in English.

`ask()` runs on a fresh instance of the action, which never saw the model's arguments, with the context alone. Read only what the person and the tenant may see, such as the person's own profile or the tenant's members, and keep it free of effects: while it runs, as while a confirmation card is built, database writes and queued actions are refused. An `ask()` that writes gives no form, and the call is refused as before.

`ask()` naming a field agents are not offered, confirming a field a form cannot hold, `textarea()` on a field that is not a string, or a choice outside the field's `enum()` is a mistake in the action: it is reported to your exception handler, and the call gets no form and is refused. The first test that drives such a call shows it.

### The `Ask` builder

| Method | What it does |
|---|---|
| `message($sentence)` | the one sentence above the form: why these fields are needed. Plain text, no URL, up to 200 characters shown |
| `confirm(...$fields)` | fields every form of this action shows for review, opening on the model's value when validation accepted it |
| `choices($field, [$value => $label])` | the field's choices, in order: one pick for a single value, several for a list. With an `enum()`, every value must be one of it; without one, the choices close the form's select while your rules still decide |
| `default($field, $value)` | the value a field opens on when the model gave none that validation accepted |
| `textarea(...$fields)` | shows these text fields as several lines |

A later `choices()` or `default()` for the same field replaces the earlier one; `confirm()` and `textarea()` add. Build choices and defaults from what the context may read, such as the tenant's members for a choice of owner, or a default from the person's own profile: `->default('city', $context->actor(User::class)->city)`.

## Fields only a model must give

Your own form may leave out a field the copilot should always settle: a post's publish date, say, which a person sets later on the page, but a model should give when it drafts the post. Keep the field optional in `schema()` and name it in `requiredForAgents()`:

```php
public function schema(JsonSchema $schema): array
{
    return [
        'title' => $schema->string()->title(__('Title'))->max(120)->required(),
        'publish_on' => $schema->string()->title(__('Publish on'))->format('date')->nullable(),
        'status' => $schema->string()->title(__('Status'))->enum(['draft', 'published']),
    ];
}

public function requiredForAgents(): array
{
    return ['publish_on', 'status'];
}
```

- Agents and MCP clients are offered these fields as required and not nullable. A model's call that leaves one out, or sends null, is refused naming it, as for any required field; with `$askForMissing`, the form asks for it and marks it required.
- The generated route, `run()`, `dispatch()`, `actions:run` and the TypeScript types read `schema()` as written, so those callers may still leave the fields out.
- It names fields agents are offered: `agentSchema()`'s when the class overrides it, otherwise `schema()`'s.
- `rules()` may keep `sometimes` on these fields for the web, in any form Laravel reads, `Rule::when()` included: a model's call must give them all the same.

Prefer it to an `agentSchema()` that repeats `schema()` with more fields required. An action with an `agentSchema()` gets no generated route: its `schema()` is then read as what `fromAgent()` builds, such as ids an agent never saw, and not as what a form sends ([exposure](concepts.md#exposure)).

## When the person answers

- **Submit.** The page first checks the answer against your action's own rules, with a Precognition request to the chat route. Errors show under their fields and nothing runs; the form waits for the person to fix them. Then the answer goes out, the turn resumes, and the action runs with the person's values over the model's arguments. Every check runs again then: the tenant, both `authorize()` steps and validation.
- Keys the form does not hold are dropped. A field the person leaves empty is left out, never filled from the model's value. A checkbox always answers `true` or `false`, and a multi-select with nothing chosen answers an empty list, so a person's "no" is never replaced by your default.
- **Decline** and **Not now** run nothing, and the row reads "Declined".
- An answer counts only from the session of the person the conversation belongs to, once, for a form their conversation is still waiting on, within `approvals.ttl` seconds of the pause (1800 by default). A second press, a replay, a stale tab, another person, a token and a late answer run nothing and get 409.
- A reload shows a waiting form again, built anew, so what the person typed before the reload is not kept. A form whose choices or defaults changed while it waited, such as a member added to the tenant, still takes the answer, checked against the form as it reads now.
- A call that no longer needs a form, because its arguments now pass your rules or its time ran out, shows none after a reload, and its answer gets 409. The person's next message settles it.
- Several forms, and confirmation cards, can wait in one step: at most eight calls in all. The page shows one at a time, and the answers go out together once each has one.

## What the model reads

"The person filled in: body, status.", then your action's usual sentence ("Done.", your `modelReply()`, or a Read's output). "The person declined to fill in …" or "The person closed the form without answering …" when they did not submit. Never the values: they are not stored in the conversation either, and the stored call keeps the model's own arguments. When your rules, or a `ValidationException` your `handle()` throws, refuse one of the person's values at the run, the model reads the field and its rules, never the message, even with `$validationMessagesToModel`.

What your action returns is yours. `modelReply()`, a `Refusal`'s replacements and `listing()`, and a Read's output all reach the model, so put a person's value there only when the model must know it.

## Destructive and External actions never ask

`$askForMissing` changes nothing on them. A call that leaves fields out is refused, naming them, as before, and a complete call shows the [confirmation card](copilot.md#confirmations), built from the record. The person confirms a deletion or a send only on that card.

## The page

```tsx
import { Chat, useChat } from '@ai-sdk/react';
import { useState } from 'react';
import { approvalCard, elicitation } from '@agentic-actions/client';
import { actionsChat, answerElicitation, type ActionMessage } from '@agentic-actions/client/ai-sdk';
import { ApprovalCard, ElicitationForm } from '@agentic-actions/client/react';

// Inside the panel of the copilot recipe, with its options kept for answerElicitation():
const [options] = useState(() => ({ api: '/assistant', messages: transcript }));
const [chat] = useState(() => new Chat<ActionMessage>(actionsChat(options)));
const { messages, addToolApprovalResponse } = useChat({ chat });
const approval = approvalCard(messages.at(-1));
const form = elicitation(messages.at(-1));

{approval && <ApprovalCard approval={approval} onAnswer={(approved) => addToolApprovalResponse({ id: approval.id, approved })} />}
{form && (
    <ElicitationForm
        key={form.id}
        params={form.params}
        labels={form.labels}
        onAnswer={(result) => answerElicitation(chat, options, form.id, result)}
    />
)}
```

- `elicitation(message)` returns the first form the message is still waiting on, or null. Once the person answers, it is gone.
- `<ElicitationForm>` renders any MCP form with native controls: the line naming your app (from `app.name`), the sentence, one labelled field per property with its description, required marker and errors, then Submit, Decline and Not now. The browser checks what it can (required fields, lengths, bounds, types) in the person's language. `key={form.id}` gives each form its own state.
- `answerElicitation(chat, options, id, result)` checks a submit against the server first. When your rules refuse it, it resolves to the errors by field, the form shows them, and nothing is sent. Otherwise it hands the answer to the chat, which sends it with the step's other answers. Decline and Not now go straight to the chat. Pass the options you gave `actionsChat()`.
- Style the form through `classNames` (for example your input and button classes) or its `data-agentic-elicitation` and `data-field` attributes. A page that draws its own controls, such as a searchable select, reads `form.params`, MCP's own shape, and answers with `answerElicitation()` the same way.

## MCP

The same form goes to an MCP client that can show one: a client on protocol 2026-07-28 that declares form elicitation. It gets the form in MCP's standard keys, answers by calling the tool again, and the action runs with the answer, through every check. Every other client gets the refusal naming the fields, as before. Over MCP the whole form reaches the client, defaults and choices included, and the package cannot tell whether a person or the client filled it in. [Asking over MCP](mcp.md#asking-over-mcp) has the exchange, the request state and the clients that show forms. Destructive and External actions stay unavailable over MCP.

## Never ask for secrets

A form is for facts the person gives your app: a date, a choice, a phone number. MCP's rule is the package's: never passwords, API keys, access tokens or payment credentials. The package builds no form holding a field whose key, title or description reads as one (password, passcode, secret, token, API key, access key, PIN, OTP, verification code, backup code, recovery phrase, card number, CVV, IBAN and their kin, singular or plural, whether written `api_key`, `apiKey`, `APIKey` or `api-key`), whose rules check a password (`current_password`, `Password`, also in an array or behind `Rule::when()`), or whose key matches your `agents.forbidden_keys`. Such a call is refused instead, naming the fields.

- The check reads words, so a field it catches by mistake, such as `pin_to_top`, is renamed (`pinned`).
- It cannot see through `fromAgent()`: never map an agent key to a secret there.
- A free-text field goes to your action as written, so never invite a secret into one, such as "Notes".
- Never put a URL in `ask()`'s message, a title or a description.

## Sensitive data: send the person to a page

For a card number, a password, a sign-in to another service or an OAuth connection, the value should never pass through the model or the chat. MCP's URL mode standardizes the pattern: the person follows a link to a page of your app and enters the value there. The package builds neither URL mode nor the page; an app builds the pattern itself today. Give the page a signed route behind your own sign-in, and let the action refuse with a sentence that hands the model the link:

```php
// routes/web.php: the link is not a sign-in, and the page checks who opened it
Route::middleware(['auth', 'signed'])->get('/billing/card/{user}', function (Request $request, User $user) {
    abort_unless($request->user()->is($user), 403);

    return Inertia::render('Billing/AddCard');
})->name('billing.card');

// In the action's handle()
$url = URL::temporarySignedRoute('billing.card', now()->addMinutes(30), ['user' => $context->actor()->getKey()]);

throw Refusal::make(__('Cards are added on a page of the app, never in the chat. Give the person this link: :url'), replace: ['url' => $url]);
```

The link passes through the model and the client, so it follows MCP's rules for URLs: it carries no personal data beyond an opaque key, it signs nobody in (the page needs the person's own session), and the page checks that the person who opened it is the one who was asked. The page stores the value and never sends it back to the chat.

## Testing

Script the model on the provider, as for a confirmation: see [testing](testing.md#asking-the-person).
