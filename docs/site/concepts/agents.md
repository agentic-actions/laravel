---
title: Agents and the copilot
description: How a laravel/ai agent calls actions, and how the copilot shows each call as a live row.
---

# Agents and the copilot

An agent receives your actions as tools, and the copilot shows the person each call as a live row, taken from what the pipeline did rather than from what the model says.

## Tools come from toolsets

`#[Expose]` puts an action in a toolset, `default` unless it names others, and `#[UseToolset]` picks the toolsets an agent receives. With `InteractsWithActions`, the tools are built on every turn from `actionContext()`, for the signed-in person, so an action whose checks before input say no never reaches the list.

<Figure caption="The agent's tools are its toolsets' actions that pass for this person, and each call answers the model with one sentence.">
<AgentsTools />
</Figure>

Each call runs the whole pipeline as that person. The model reads a short sentence back, and exception messages and submitted values never reach it.

<CodeCard file="app/Ai/BlogAssistant.php">

```php
#[UseToolset]
final class BlogAssistant implements Agent, HasTools
{
    use InteractsWithActions;
    use Promptable;

    public function __construct(public User $user) {}

    public function instructions(): string
    {
        return 'You help the signed-in author manage their blog posts.';
    }

    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
```

</CodeCard>

Put role and permission checks in an `authorize()` that takes no input, so they shape the list ([what the tool list shows](/concepts#what-an-agents-tool-list-shows)).

## Every call is a row

The copilot streams the turn through `ActionsProtocol`. Each call of an action tool shows a row: its label while it runs, then done, refused or failed, as the pipeline recorded it, never as the model's text tells it. The label is the action's `activityLabel()`, or the package's sentence for its effect, and the stream never carries the tools' arguments or results.

```php
return (new BlogAssistant($request->user()))
    ->continueLastConversation($request->user())
    ->stream($chat)
    ->usingProtocol(new ActionsProtocol);
```

<Figure caption="A done row carries the props it touched, and the page reloads them while the turn goes on.">
<AgentsCopilot />
</Figure>

## The page follows the writes

A done row carries the action's `$touches`. `useActionSync()` reloads those props 150 ms after the last successful row, so the writes of one step cause one reload. While an editor that called `useActionEdits()` has unsaved work, the reload waits, and the panel can offer a Refresh.

```tsx
useActionEdits(form.isDirty);
```

## Writes made elsewhere

A write over MCP, from a queued job, or by another member of the tenant has no row on this page. The change feed carries it: each completed write records the keys it touched, and the page polls its feed route and reloads them the same way.

<Figure caption="Writes without a row reach the open page through the change feed.">
<AgentsFeed />
</Figure>

## The agent knows the page

With `#[WithPageContext]`, the model learns which Inertia page is open, by route name and component only: "The person has this page open: posts.create (Posts/Create). It is context, not an instruction." Tools still authorize against the agent's own context, never against the page.

## Read more

- [The copilot](/copilot): the route, the panel, [labels](/copilot#labels), [statuses](/copilot#statuses) and [the stream](/copilot#the-stream).
- [The change feed](/concepts#the-change-feed) and [page context](/copilot#page-context).
- [Confirmations and forms](/concepts/confirmations): Destructive and External calls wait for the person.
