---
title: Effects and surfaces
description: How an action's effect decides which surfaces may reach it.
---

# Effects and surfaces

An action says what it does to the world, a call says where it comes from, and the two together decide whether the call runs.

<Figure caption="Which caller reaches which effect, once #[Expose] has opened its surface.">
<Matrix corner="Effect" label="Which caller reaches which effect" :columns="['Web', 'CLI', 'Agents', 'MCP']" :rows="[
  { label: 'Read', cells: ['yes', 'yes', 'yes', 'yes'] },
  { label: 'Write', cells: ['yes', 'yes', 'yes', 'yes'] },
  { label: 'Destructive', cells: ['yes', 'yes', 'confirm', 'no'] },
  { label: 'External', cells: ['yes', 'yes', 'confirm', 'no'] },
]" />
</Figure>

## Four effects

Every action declares one `$effect`. It picks the token ability a call needs, and whether a model may call the action at all. An action without one opens no remote surface and fails `actions:check`.

<Figure caption="From changing nothing to reaching outside. The line above each pair says what a model may do with it.">
<EffectsScale />
</Figure>

<CodeCard file="app/Actions/DeletePost.php">

```php
protected ?Effect $effect = Effect::Destructive;
```

</CodeCard>

The table of when to use which is in [Concepts](/concepts#effects), and the abilities in [Security](/security).

## Six surfaces

A surface is where a call comes from. Agent and Mcp are model-driven, and so is any call made inside a laravel/ai tool call or an MCP request: the call stack decides, not the context a caller built. A queued run keeps the answer of the call that queued it.

<EffectsChips label="The six surfaces" :groups="[
  { label: 'Person or app', chips: [
    { name: 'Http', detail: 'a route', icon: 'globe' },
    { name: 'Console', detail: 'actions:run', icon: 'terminal' },
    { name: 'Queue', detail: 'a queued run', icon: 'queue' },
    { name: 'System', detail: 'webhooks, schedules', icon: 'clock' },
  ] },
  { label: 'Model-driven', chips: [
    { name: 'Agent', detail: 'a laravel/ai tool call', icon: 'agent', tone: 'agent' },
    { name: 'Mcp', detail: 'an MCP client', icon: 'mcp', tone: 'mcp' },
  ] },
]" />

## Who reaches what

A model-driven call reaches only Read and Write, and needs an actor unless the action sets `$guests`. The one exception is an agent offered a Destructive or External action through a named toolset: each of its calls runs only after the person confirms it. MCP has no confirmation step, so it never reaches them.

What the person sees while a call waits is in [Confirmations and forms](/concepts/confirmations).

## Doors

Every caller enters through one of [five doors](/concepts#doors); three of them read `#[Expose]`.

<EffectsChips label="The five doors" :groups="[
  { label: 'Reads #[Expose]', chips: [
    { name: 'Generated route', detail: 'Actions::routes()', icon: 'form' },
    { name: 'Agent', detail: 'a shared toolset', icon: 'agent', tone: 'agent' },
    { name: 'MCP', detail: 'a tool call', icon: 'mcp', tone: 'mcp' },
  ] },
  { label: 'Discovery alone', chips: [
    { name: 'In-process', detail: 'run(), dispatch(), attempt(), actions:run', icon: 'code' },
    { name: 'Route', detail: 'a route you wrote', icon: 'globe' },
  ] },
]" />

## Exposure

`#[Expose]` is the only way onto a network or model surface. Bare, it opens every surface the effect and shape allow and quietly skips the rest: agents and MCP for a Destructive or External action, MCP for an action without a description. Naming a surface the rules refuse is an error instead, except a toolset named for a Destructive or External action.

<Figure caption="The forms of #[Expose], and the surfaces each one opens.">
<EffectsExpose />
</Figure>

`actions:list` and `actions:check` show every decision with its reason, and the switches `surfaces.web`, `surfaces.agents` and `surfaces.mcp` close a surface for the whole app. The rules in full are in [Exposure](/concepts#exposure).
