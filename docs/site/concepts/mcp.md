---
title: MCP and OAuth
description: How MCP clients reach actions with a token or through OAuth.
---

# MCP and OAuth

MCP clients such as Claude Code, Cursor and Claude Desktop reach your actions through one server the package mounts, as the person whose token they hold.

<Figure caption="Every request passes the guard and the throttle, then the same pipeline an agent's call takes.">
<McpCallFlow />
</Figure>

## One server, one tool per action

The server answers at `mcp/actions`. When your actions are tenant-scoped, set `mcp.tenant_path` (such as `mcp/t/{tenant}`) and they are served there instead, one URL per tenant. Each Read or Write action whose `#[Expose]` allows MCP is one tool. Destructive and External actions are never listed, because MCP has no confirmation step. [Where it is mounted](/mcp#where-it-is-mounted).

## A token names what it reaches

A client connects with a person's token, and over MCP an ability counts only when the token names it: `actions:read`, `actions:write`, and `tenant:{id}` to bind it to one tenant. Your app mints it; the package ships no token page.

<CodeCard file="php artisan tinker">

```php
$user->createToken('Claude Code', ['actions:read', 'actions:write', 'tenant:'.$team->getKey()], now()->addDays(90));
```

</CodeCard>

<Figure caption="Sanctum's default token, ['*'], lists no tools over MCP.">
<Matrix corner="The token names" :columns="['Read actions', 'Write actions', 'Destructive, External']" :rows="[
  { label: 'actions:read', code: true, cells: ['yes', 'no', 'no'] },
  { label: 'actions:read, actions:write', code: true, cells: ['yes', 'yes', 'no'] },
  { label: '*', code: true, cells: ['no', 'no', 'no'] },
]" />
</Figure>

The client then runs as that person, and never does more than the person could in the app: membership, `authorize()` and validation run on every call. [Tokens and abilities](/mcp#tokens-and-abilities).

## One budget per person

Every MCP request counts against `mcp.per_minute`, 60 by default. All of one person's tokens and tenant URLs share that budget, and past it the answer is 429 before the server runs. [Throttle](/mcp#throttle).

## Remote clients sign in with OAuth

A Claude custom connector or ChatGPT connects from its provider's cloud and has no field for a pasted token. Add Laravel Passport and name its guard in `mcp.middleware`: that one line is the switch.

<CodeCard file="config/agentic-actions.php">

```php
'mcp' => [
    // ...
    'middleware' => ['auth:sanctum,api', 'throttle:agentic-actions-mcp'],
],
```

</CodeCard>

<Figure caption="The person approves one URL; the client's token reaches only that URL, within the abilities they allowed.">
<Columns align="start">
<McpOAuthFlow />
<McpConsentScreen />
</Columns>
</Figure>

The consent screen appears for every authorization that names one of the package's MCP URLs, even for a client approved before. [OAuth setup](/mcp#connect-claude-chatgpt-and-other-remote-clients-oauth).

## Connections can be revoked

Each approval records one connection per client and person. List them on the person's settings page, and `revoke()` ends the client's tokens at once: its next call answers 401. [Connected apps](/mcp#connected-apps), and the guarantees in [Security](/security#mcp).
