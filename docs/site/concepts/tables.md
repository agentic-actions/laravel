---
title: Tables and charts
description: How a Read action's rows reach the person as a table, with the chart their shape calls for, while the model reads a short copy.
---

# Tables and charts

A Read action can answer with a table: the person reads your query's rows, and the model says what stands out.

<Figure caption="A copilot answer that shows a table. The numbers come from the query, and the sentence comes from the model.">
<TablesAnswer />
</Figure>

## Declare columns, return rows

Implement `ShowsTable` on a Read action. `columns()` says what each row may show, and `handle()` returns the rows: a query, an array or a generator. Nothing outside the declared columns leaves the server, on any surface.

<CodeCard file="app/Actions/PostsPerAuthor.php">

```php
final class PostsPerAuthor extends Action implements ShowsTable
{
    protected ?Effect $effect = Effect::Read;

    public function columns(): array
    {
        return [
            Column::text('author', __('Author')),
            Column::integer('posts', __('Posts')),
            Column::percent('published', __('Published')),
        ];
    }
}
```

</CodeCard>

## One table, two readers

In a copilot turn, the rows split. The person gets every row, up to 500. The model gets a short copy: the first 20 rows and an instruction to comment, not repeat. So the numbers on screen are the query's own and are never retyped by the model.

<Figure caption="The same rows, cut two ways after the columns allowlist.">
<TablesSplit />
</Figure>

Everywhere else, such as a route, MCP or `actions:run`, the caller gets the same table as JSON. See [what each surface gets](/data#what-each-surface-gets).

## The chart follows the shape

The package draws no chart. It names one in `table.chart`, from the columns alone: one row of numbers is a <Pill>metric</Pill>, a date column then numbers is a <Pill>line</Pill>, and a text column then numbers is a <Pill>bar</Pill> of up to 50 rows. One chart shows one unit, so the percentage above stays out of the bar chart of posts. Your page draws it with your own components ([the chart](/data#the-chart)).

## Kept and refreshed

A table is kept with a stored conversation, so a reload shows the rows the model described. When the action is exposed on the web, Refresh runs it again as the person, with the same input, through every check its route gets. Another person or another tenant gets a 404 ([refresh](/data#refresh)).

## Datasets: the agent asks

A dataset lets the model ask its own question within names you declare: dimensions to group and filter by, and measures to compute. The package writes the query, scoped to the tenant the call runs in. The model never writes SQL.

<Figure caption="A dataset call: the model chooses among your names, and the package writes a scoped query.">
<Flow numbered label="A dataset call">
<Step title="The model picks names" tone="agent" note="measures, by, since" />
<Step title="Only declared names" note="checked before any query" branch="unknown: refused" />
<Step title="A scoped query" tone="tenant" note="the tenant, global scopes, then scope()" />
<Step title="A table" tone="pass" note="with a caption of what was asked" />
</Flow>
</Figure>

```json
{"measures": ["posts"], "by": ["author"], "since": "-1m", "compare": true}
```

Read the [datasets reference](/data#datasets) and the security notes on [tables](/security#tables) and [datasets](/security#datasets).
