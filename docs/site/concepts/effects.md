---
title: Effects and surfaces
description: How an action's effect decides which surfaces may reach it.
---

# Effects and surfaces

An action says what it does to the world, a call says where it comes from, and the two together decide whether the call runs.

<Figure>
<Matrix corner="Effect" label="Which caller reaches which effect" :columns="['Route', 'CLI', 'Agents', 'MCP']" :rows="[
  { label: 'Read', cells: ['yes', 'yes', 'yes', 'yes'] },
  { label: 'Write', cells: ['yes', 'yes', 'yes', 'yes'] },
  { label: 'Destructive', cells: ['yes', 'yes', 'confirm', 'no'] },
  { label: 'External', cells: ['yes', 'yes', 'confirm', 'no'] },
]" />
<template #caption>Which caller reaches which effect, once #[Expose] has opened its surface; what the person sees while a call waits is in <a href="/how-it-works/confirmations">Confirmations and forms</a>.</template>
</Figure>

## Four effects

Every action declares one `$effect`. It picks the token ability a call needs, and whether a model may call the action at all. An action without one opens no remote surface and fails `actions:check`.

<Figure caption="The line above each pair says what a model may do with it.">
<EffectsScale />
</Figure>

<CodeCard file="app/Actions/DeletePost.php">

```php
protected ?Effect $effect = Effect::Destructive;
```

</CodeCard>

See [Effects](/concepts#effects) in Concepts for when to use which, and [Security](/security) for the abilities.

## Six surfaces

A surface is where a call comes from. Agent and Mcp are model-driven, and so is any call made inside a laravel/ai tool call or an MCP request; a queued run keeps the answer of the call that queued it. A model-driven call reaches only Read and Write, except an agent's call the person confirms, and needs a signed-in person unless the action sets `$guests`, as a public support bot would. See [Surfaces](/concepts#surfaces) in Concepts.

<Figure caption="The six surfaces, and when a call on each is model-driven.">
<EffectsChips label="The six surfaces" :groups="[
  { label: 'Always model-driven', chips: [
    { name: 'Agent', detail: 'a laravel/ai tool call', icon: 'agent', tone: 'agent' },
    { name: 'Mcp', detail: 'an MCP client', icon: 'mcp', tone: 'mcp' },
  ] },
  { label: 'Only inside a tool call', chips: [
    { name: 'Http', detail: 'a route', icon: 'globe' },
    { name: 'Console', detail: 'actions:run', icon: 'terminal' },
    { name: 'Queue', detail: 'a queued run', icon: 'queue' },
    { name: 'System', detail: 'webhooks, schedules', icon: 'clock' },
  ] },
]" />
</Figure>

## Exposure

`#[Expose]` is the only way onto a network or model surface; in-process calls and routes you write never read it, see [Doors](/concepts#doors). Bare, it opens every surface the action can use and quietly skips the rest: agents and MCP for a Destructive or External action, agents while laravel/ai is not installed, MCP for an action without a description, and the route for an action with an `agentSchema()`. Naming a surface the rules refuse is an error instead, except a toolset named for a Destructive or External action.

<Figure caption="The forms of #[Expose], and the surfaces each one opens.">
<EffectsExpose />
</Figure>

`actions:list` and `actions:check` show every decision with its reason, and the switches `surfaces.web`, `surfaces.agents` and `surfaces.mcp` close a surface for the whole app. See [Exposure](/concepts#exposure) in Concepts for the rules in full.
