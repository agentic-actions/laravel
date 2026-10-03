---
title: One action, every caller
description: How one Action class answers routes, Artisan, the queue, TypeScript, agents and MCP clients.
---

# One action, every caller

Write an operation once, as an Action class, and the same class answers a route, Artisan, the queue, TypeScript, an agent and MCP clients.

<Figure caption="Six callers, one class. Every call goes through the same pipeline before handle() runs.">
<Hub label="The callers of CreatePost" :split="4" :callers="[
  { label: 'Web route', detail: 'POST /actions/create-post', icon: 'form' },
  { label: 'TypeScript', detail: 'createPost()', icon: 'ts' },
  { label: 'Artisan', detail: 'actions:run create-post', icon: 'terminal' },
  { label: 'The queue', detail: 'CreatePost::dispatch()', icon: 'queue' },
  { label: 'A laravel/ai agent', detail: 'a tool in the default toolset', icon: 'agent', tone: 'agent' },
  { label: 'MCP clients', detail: 'the create-post tool', icon: 'mcp', tone: 'mcp' },
]">
<HowItWorksCore />
</Hub>
</Figure>

## The action

An action is one class that extends `AgenticActions\Action`. Its properties declare facts: what it does, its effect, what a success makes stale. Its methods declare behaviour: the input, the output, who may run it, and the work.

<Figure caption="CreatePost, the README's example, one member at a time.">
<HowItWorksAnatomy />
</Figure>

Without `authorize()` the action is denied everywhere, and a key `outputSchema()` does not declare never leaves the server. Every member is in [Concepts](/concepts#the-action).

## What each caller gets

Each caller enters through its own door, then takes the same steps, so a rule written once in `authorize()` or `schema()` holds for a form, a worker and a model alike. What each step answers when it says no is on [the pipeline](/concepts/pipeline).

<Figure caption="The same class, as each caller sees it, and what the app adds for each one.">
<HowItWorksCallers />
</Figure>

## What #[Expose] opens

`#[Expose]` is the only way onto the route, agents and MCP. A bare one opens every surface the action's effect allows; arguments narrow it.

<CodeCard file="app/Actions/CreatePost.php">

```php
#[Expose]                       // every surface the effect and shape allow
#[Expose(web: true)]            // the generated route only
#[Expose(agents: ['support'])]  // the "support" toolset only
```

</CodeCard>

Artisan, the queue and your own `run()` reach any discovered action, exposed or not. A Destructive or External action gets the route and the CLI; MCP never serves it, and an agent reaches it only through a named toolset, after the person confirms. See [Effects and surfaces](/concepts/effects) and [Exposure](/concepts#exposure).

`php artisan actions:list` shows each decision with its reason, and `actions:check` fails until a changed exposure is reviewed into `actions.exposure.json`.
