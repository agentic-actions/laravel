---
title: Tenants
description: How a call runs inside one tenant, and what a stranger sees.
---

# Tenants

Multi-tenancy, set up once: every tenant-scoped call runs inside one team. The URL names the team, the package checks the person belongs, and `$context->find()` finds only that team's rows.

<Figure caption="An unknown team, a stranger and another team's post all answer 404.">
<Flow vertical label="A call inside a team">
<Step title="POST teams/&#8203;acme/&#8203;actions/&#8203;update-post" code icon="globe" note="the team is in the URL" />
<Step title="Find the team" note="by the model's route key: a slug or a UUID" branch="no such team: 404" />
<Step title="Tenant membership" tone="tenant" note="your tenant.membership class" branch="not a member: 404" />
<Step title="authorize(), validation" code note="your own rules" branch="denied: 403" />
<Step title="$context&#8203;-&#8288;>find()" code tone="tenant" note="finds the post in Acme only" branch="other team's post: 404" />
</Flow>
</Figure>

## Turn it on

Set `tenant.model`, `tenant.parameter` (the route segment, `team`), `tenant.membership`, a class that answers whether a person may enter a tenant, and `tenant.scope`, a class that narrows `find()` to it. From then on every action is tenant-scoped, so mount them under the parameter:

<CodeCard file="routes/web.php">

```php
Route::middleware('auth')
    ->prefix('teams/{team}')
    ->name('teams.')
    ->group(fn () => Actions::routes(tenant: true));
```

</CodeCard>

An action that belongs to the account rather than a team, such as editing a profile, sets `$tenantScoped = false` and goes in a group without the prefix. See [Tenants](/concepts#tenants) in Concepts for every key.

## A stranger sees a 404

<Figure caption="Globex answers exactly as a team that does not exist." center>
<TenantsTeams />
</Figure>

The package checks membership itself, on every surface, before your code reads the tenant. Keep that by leaving membership to the package: a group whose own middleware checks it first answers with that middleware's response instead, often a 403. See [Tenants](/concepts#tenants) in Concepts.

## `find()` stays inside the team

`$context->find(Post::class, $id)` looks the row up through your `tenant.scope`, so another team's post is simply not found. Your scope decides which rows are the team's: its conditions stay in one group, and `find()`'s key only narrows them, so an `orWhere` you add to the scope, such as shared posts, makes those rows reachable from every team, in Write actions too. Your own queries, such as `Post::query()` in `handle()`, are yours to scope. See [Security](/security) for the guarantees.

## Tokens and MCP

<Figure caption="A token bound to Acme's primary key reaches Acme's path and nothing else, even for a person in both teams.">
<TenantsToken />
</Figure>

A token binds to a tenant with the ability `tenant:{key}`, by primary key. Over MCP, tenant-scoped actions live on the tenant path, such as `mcp/t/{team}`, and the rest on the base path, so a client that needs both connects both URLs. See [Tenants](/mcp#tenants) in the MCP guide.

## The copilot, the queue and the feed

- **One conversation per team.** `Actions::conversation($agent, $user, $team)` keeps one copilot conversation per person, tenant and agent. See [One conversation per tenant](/copilot#one-conversation-per-tenant).
- **Queued runs keep the tenant.** A dispatched action carries its person and tenant, and the worker checks membership again. See [Queued runs](/concepts#queued-runs).
- **The change feed is per tenant.** A write in a team reaches every member's open page within one poll. See [The change feed](/concepts#the-change-feed).
