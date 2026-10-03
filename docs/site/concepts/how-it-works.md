---
title: One action, every caller
description: How one Action class answers routes, Artisan, the queue, TypeScript, agents and MCP clients.
---

# One action, every caller

Write an operation once, as an Action class, and the same class answers a route, Artisan, the queue, TypeScript, an agent and MCP clients.

<Figure caption="Every caller goes through the same pipeline before handle() runs.">
<Hub label="The callers of CreatePost" :split="4" :callers="[
  { label: 'Web route', detail: 'POST /actions/create-post', icon: 'form' },
  { label: 'TypeScript', detail: 'createPost()', icon: 'ts' },
  { label: 'Artisan', detail: 'actions:run create-post', icon: 'terminal' },
  { label: 'The queue', detail: 'CreatePost::dispatch()', icon: 'queue' },
  { label: 'A laravel/ai agent', detail: 'a tool it can call', icon: 'agent', tone: 'agent' },
  { label: 'MCP clients', detail: 'the create-post tool', icon: 'mcp', tone: 'mcp' },
]">
<HowItWorksCore />
</Hub>
</Figure>

## The action

An action is one class that extends `AgenticActions\Action`. Its properties declare facts: what it does, its effect, what a success makes stale. Its methods declare behaviour: the input, the output, who may run it, and the work.

<Figure caption="CreatePost, the example from Getting started, one member at a time.">
<HowItWorksAnatomy />
</Figure>

Without `authorize()` the action is denied everywhere, and a key `outputSchema()` does not declare never leaves the server. See [The action](/concepts#the-action) in Concepts for every member.

## What each caller gets

Each caller comes in its own way, then takes the same steps, so a rule written once in `authorize()` or `schema()` holds for a form, a worker and a model alike. What each step answers when it says no is on [the pipeline](/concepts/pipeline), and the full list in [What that one class gets](/getting-started#what-that-one-class-gets).

<Figure caption="The same class, as each caller sees it, and what the app adds for each one.">
<HowItWorksCallers />
</Figure>

## What #[Expose] opens

`#[Expose]` is the only way onto the route, agents and MCP. Artisan, the queue and your own `run()` reach an action without it. See [Effects and surfaces](/concepts/effects).
