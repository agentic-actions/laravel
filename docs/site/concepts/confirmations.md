---
title: Confirmations and forms
description: How an agent's risky call waits for the person, and how a form asks for missing fields.
---

# Confirmations and forms

An agent's call can stop and wait for the person: for their confirmation before something risky, or for the fields it left out.

<Figure caption="DeletePost's card in the chat, and the row each answer leaves.">
<ConfirmationsCard />
</Figure>

## Confirm a risky call

A Destructive or External action, such as deleting a post or sending an email, reaches an agent only when its `#[Expose]` names a toolset. When the agent calls it, the call pauses, and the server builds a card from the action's own `approvalReason()` and `approvalSummary()`. Nothing has run yet. See [Offer the action](/copilot#offer-the-action).

<Figure caption="The call runs only after the person confirms it, and only if the card still reads the same.">
<ConfirmationsRoundTrip />
</Figure>

## The card

The browser receives the sentence and the rows as plain text, never the model's arguments or words. The person answers from their own session, once, within `approvals.ttl` seconds (30 minutes by default). A decline runs nothing, and neither does a second press, a replay or another person.

The rows come from the record the input names, found the way `authorize()` finds it:

<CodeCard file="app/Actions/DeletePost.php">

```php
public function approvalSummary(
    ActionContext $context,
    ValidatedInput $input,
): array {
    $post = $context->find(Post::class, $input->integer('post'));

    return [__('Post') => $post->title, __('Status') => $post->status];
}
```

</CodeCard>

See [What the person can rely on](/copilot#what-the-person-can-rely-on) and [Security](/security#confirmations).

## Ask for what is missing

A Read or Write action can ask instead of refusing a call that left something out. The form is built on the server from the action's schema and its optional `ask()`, in the shape of MCP's form elicitation, so an MCP client that shows forms gets the same one. Only a submit runs the action, with every check again.

<Figure caption="The model gives a title; the person fills in the rest, and the model learns only which fields they filled.">
<ConfirmationsAsk />
</Figure>

<CodeCard file="app/Actions/DraftPost.php">

```php
protected bool $askForMissing = true;
```

</CodeCard>

See [What the form holds](/asking#what-the-form-holds) for which schema types become which fields, and [Asking over MCP](/mcp#asking-over-mcp).

## The model never reads the values

The action runs with the person's values over the model's arguments. The values are not stored in the conversation either, so they reach a model only if your action returns them. A form never asks for a password, a token or a card number: such a call is refused. See [Never ask for secrets](/asking#never-ask-for-secrets).

## Destructive and External actions never ask

`$askForMissing` changes nothing on them. An incomplete call is refused, naming the fields, and a complete one gets the card. The person confirms a deletion or a send only there. See [Asking the person](/asking) and [Testing confirmations](/testing#confirmations).
