---
title: The pipeline
description: The steps every call takes, in order, and what each one answers when it says no.
---

# The pipeline

A form, an agent, an MCP client, a queued job or your own code: every call to an action takes the same steps, in the same order.

<Figure caption="The steps in order. A step that says no ends the call, and the caller gets the answer beside it.">
<PipelineFlow />
</Figure>

## Where a call stops

The first four steps answer "not found" when they say no, so an action a caller may not see reads exactly like one that does not exist. A denied `authorize()` answers 403, or 404 when it returns `Response::denyAsNotFound()`. Validation answers 422. Whatever the answer, the call ends with one event, which carries no input values.

The full list, with what step 7 converts, is in [Concepts](/concepts#the-pipeline).

## One authorize(), two moments

An action has one `authorize()`. When it takes no input, it runs at step 5, before the input is read. When it takes `ValidatedInput`, it runs at step 9, on the validated input. Put checks on the caller, such as a role, in the first kind, and checks on a particular row in `handle()` or in the second kind.

## What an agent's tool list shows

Before each turn, the agent's tools are built by running steps 1 to 5 for every action in its toolsets. An action leaves the list only when one of them says no.

<Figure caption="An authorize() that takes input needs the model's arguments, so its tool stays listed until the model calls it.">
<PipelineToolList />
</Figure>

So a person who may never run an action still sees its tool when its `authorize()` takes input, and is refused when the model calls it. A check on the caller in an `authorize()` without input keeps the tool off the list:

<CodeCard file="app/Actions/UpdatePost.php">

```php
public function authorize(ActionContext $context): bool
{
    return $context->actor(User::class)->can('edit-posts');
}
```

</CodeCard>

When `authorize()` has to take input, put the check on the caller in `shouldRegister()`, as the [strict agent schemas](/recipes#strict-agent-schemas-no-ids) recipe does.

## Refusals

An action says no from its own code with a `Refusal`. `on()` puts the message on a field, and `status()` sets the HTTP status, 409 by default. `Actions::refuse()` turns an exception your domain already throws into a refusal on every surface.

<CodeCard file="app/Actions/CreatePost.php">

```php
throw Refusal::make(__('You already have a post with that title.'))->on('title');
```

</CodeCard>

Replacements in the message come from your own text or the actor's saved rows, never from the caller's input. See [Refusals](/concepts#refusals) for `details()` and `listing()`.
