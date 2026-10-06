---
title: The pipeline
description: The steps every call takes, in order, and what each one answers when it says no.
---

# The pipeline

A form, an agent, an MCP client, a queued job or your own code: every call to an action takes the same steps, in the same order.

<Figure caption="A step that says no ends the call with the answer beside it.">
<PipelineFlow />
</Figure>

## Where a call stops

The door, the token, `shouldRegister()` and membership answer "not found" when they say no, so an action a caller may not see reads exactly like one that does not exist. A denied `authorize()` can answer 404 too, when it returns `Response::denyAsNotFound()`. See [The pipeline](/concepts#the-pipeline) in Concepts.

## What an agent's tool list shows

Before each turn, the agent's tools are built by running every check up to an `authorize()` without input, for each action in its toolsets; an `authorize()` whose `ValidatedInput` may be null runs there too, with null. An action leaves the list when one of them says no, or when it would offer a [forbidden key](/security).

<Figure caption="An authorize() that requires input needs the model's arguments, so its tool stays listed until the model calls it.">
<PipelineToolList />
</Figure>

Checks on the caller, such as a role, go in an `authorize()` without input, which keeps the tool off the list; checks on a particular row go in `handle()` or in an `authorize()` that takes `ValidatedInput`.

<CodeCard file="app/Actions/UpdatePost.php">

```php
public function authorize(ActionContext $context): bool
{
    return $context->actor(User::class)->can('edit-posts');
}
```

</CodeCard>

When `authorize()` has to take input, let its `ValidatedInput` be null: it then runs twice, first with null before any input is read (and when the tool list is built), then with the input after validation. Check the caller on the first run and the record on the second. `shouldRegister()` can hold the check on the caller instead, as the [strict agent schemas](/recipes#strict-agent-schemas-no-ids) recipe does. See [What an agent's tool list shows](/concepts#what-an-agents-tool-list-shows) in Concepts.

## Refusals

An action says no from its own code with a `Refusal`. `on()` puts the message on a field, which answers 422 like a validation error; otherwise `status()` sets the HTTP status, 409 by default. `Actions::refuse()` turns an exception your domain already throws into a refusal on every surface.

<CodeCard file="app/Actions/CreatePost.php">

```php
throw Refusal::make(__('You already have a post with that title.'))->on('title');
```

</CodeCard>

Replacements in the message come from your own text or the person's saved rows, never from the caller's input. See [Refusals](/concepts#refusals) in Concepts for `details()` and `listing()`.
