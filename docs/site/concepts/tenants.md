---
title: Tenants
description: How a call runs inside one tenant, and what a stranger sees.
---

# Tenants

Name a tenant model once, and every call runs inside one team: the URL names it, the package checks the person belongs, and queries see only its rows.

<Figure caption="One call under a team's URL. An unknown team, a stranger and another team's post all answer 404.">
<Flow vertical label="A call inside a team">
<Step title="POST teams/&#8203;acme/&#8203;actions/&#8203;update-post" code icon="globe" note="the team is in the URL" />
<Step title="Find the team" note="by the model's route key: a slug or a UUID" branch="no such team: 404" />
<Step title="Tenant membership" tone="tenant" note="your tenant.membership class" branch="not a member: 404" />
<Step title="authorize(), validation" code note="your own rules" branch="denied: 403" />
<Step title="$context&#8203;-&#8288;>find()" code tone="tenant" note="finds the post in Acme only" branch="other team's post: 404" />
</Flow>
</Figure>

## Turn it on

Set `tenant.model`, `tenant.parameter` (the route segment, `team`) and `tenant.membership`, a class that answers whether a person may enter a tenant. From then on every action is tenant-scoped, so mount them under the parameter:

```php
Route::middleware('auth')
    ->prefix('teams/{team}')
    ->name('teams.')
    ->group(fn () => Actions::routes(tenant: true));
```

An action that belongs to the account rather than a team, such as editing a profile, sets `$tenantScoped = false` and goes in a group without the prefix. [All the keys](/concepts#tenants).

## A stranger sees a 404

<Figure caption="Sam belongs to Acme only. Globex answers exactly as a team that does not exist." center>
<TenantsTeams />
</Figure>

The package checks membership itself, on every surface, before your code reads the tenant. A team you are not in gets the same 404 as one that does not exist, so a stranger cannot tell which teams exist. Keep that by leaving membership to the package: a group whose own middleware checks it first answers with that middleware's response instead, often a 403.

## Queries stay inside the team

`$context->find(Post::class, $id)` looks the row up through your `tenant.scope`, so another team's post is simply not found. Its conditions stay in one group, so an `orWhere` in your scope cannot widen what `find()` reaches. [Security](/security) lists the guarantees.

## Tokens and MCP

<Figure caption="A token bound to Acme's primary key reaches Acme's path and nothing else, even for a person in both teams.">
<TenantsToken />
</Figure>

A token binds to a tenant with the ability `tenant:{key}`, by primary key. Over MCP, tenant-scoped actions live on the tenant path, such as `mcp/t/{team}`, and the rest on the base path, so a client that needs both connects both URLs. [MCP and tenants](/mcp#tenants).

## The copilot, the queue and the feed

- **One conversation per team.** `Actions::conversation($agent, $user, $team)` keeps one copilot conversation per person, tenant and agent. [The copilot](/copilot#one-conversation-per-tenant).
- **Queued runs keep the tenant.** A dispatched action carries its actor and tenant, and the worker checks membership again. [Queued runs](/concepts#queued-runs).
- **The change feed is per tenant.** A write in a team reaches every member's open page within one poll. [The change feed](/concepts#the-change-feed).
