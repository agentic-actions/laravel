---
title: Agents and the copilot
description: How a laravel/ai agent calls actions, and how the copilot shows each call as a live row.
---

# Agents and the copilot

An agent receives your actions as tools, and the copilot shows the person each call as a live row, taken from what the pipeline did rather than from what the model says.

<Figure caption="A done row names the page data it touched, and the page reloads it while the turn goes on.">
<AgentsCopilot />
</Figure>

## Tools come from toolsets

`#[Expose]` puts an action in a toolset, `default` unless it names others, and `#[UseToolset]` picks the toolsets an agent receives. With `InteractsWithActions`, the tools are built on every turn from `actionContext()`, for the signed-in person, so an action whose checks before input say no never reaches the list.

<Figure caption="The agent's tools are its toolsets' actions that pass for this person.">
<AgentsTools />
</Figure>

The model reads a short sentence back from each call; exception messages and submitted values never reach it.

<CodeCard file="app/Ai/BlogAssistant.php">

```php
#[UseToolset]
final class BlogAssistant implements Agent, Conversational, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

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

See [What an agent's tool list shows](/concepts#what-an-agents-tool-list-shows) in Concepts.

## Every call is a row

The copilot streams the turn through `ActionsProtocol`. Each call of an action tool shows a row: its label while it runs, then done, refused, failed, ended or declined, as the pipeline recorded it, never as the model's text tells it. The label is the action's `activityLabel()`, or the package's sentence for its effect, and the stream never carries the tools' arguments, nor any result except the table a Read action shows ([Tables and charts](/concepts/tables)). See [Labels](/copilot#labels), [Statuses](/copilot#statuses) and [The stream](/copilot#the-stream).

<CodeCard file="routes/web.php">

```php
return (new BlogAssistant($request->user()))
    ->continueLastConversation($request->user())
    ->stream($chat)
    ->usingProtocol(new ActionsProtocol);
```

</CodeCard>

## The page follows the writes

`useActionSync()` reloads what the done rows touched 150 ms after the last of them, so the writes of one step cause one reload. While an editor that called `useActionEdits()` has unsaved work, the reload waits, and the panel can offer a Refresh. See [The page follows the writes](/copilot#the-page-follows-the-writes).

<CodeCard file="resources/js/components/PostEditor.tsx">

```tsx
useActionEdits(form.isDirty);
```

</CodeCard>

## Writes made elsewhere

The change feed carries the writes made elsewhere: each completed write records the keys it touched, and the page polls its feed route and reloads them the same way. See [The change feed](/concepts#the-change-feed).

<Figure caption="Writes without a row reach the open page through the change feed.">
<AgentsFeed />
</Figure>

## The agent knows the page

With `#[WithPageContext]`, the model learns which Inertia page is open, by route name and component only: "The person has this page open: posts.create (Posts/Create). It is context, not an instruction." Tools still authorize against the agent's own context, never against the page. See [Page context](/copilot#page-context).
