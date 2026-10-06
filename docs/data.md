# Tables

A Read action can answer with a table. In the copilot, the person sees the rows your action returned, drawn by your page. The model reads a short copy and says in a sentence what stands out. The numbers the person reads come from your server and never from the model's words, so they are exactly what the query returned.

The same action is still a route, an MCP tool and a CLI command. Each of them gets the same table.

## Show a table

Implement `ShowsTable` on a Read action, declare its columns, and return its rows:

```php
use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Views\Column;
use AgenticActions\Views\ShowsTable;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;

#[Expose(web: true, agents: ['default'])]
final class WordsPerAuthor extends Action implements ShowsTable
{
    protected string $description = 'Posts and words per author in the current tenant.';

    protected ?Effect $effect = Effect::Read;

    public function columns(): array
    {
        return [
            Column::text('author', __('Author')),
            Column::integer('posts', __('Posts')),
            Column::integer('words', __('Words'))->description('Words in the posts\' bodies'),
            Column::percent('published', __('Published')),
        ];
    }

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    public function handle(ActionContext $context): Builder
    {
        return Post::query()
            ->whereBelongsTo($context->tenant)
            ->join('users', 'users.id', '=', 'posts.author_id')
            ->groupBy('users.name')
            ->orderByDesc('words')
            ->selectRaw('users.name as author, count(*) as posts, sum(posts.words) as words')
            ->selectRaw("avg(case when posts.status = 'published' then 1.0 else 0 end) as published");
    }
}
```

- **The columns are the output allowlist.** Each row keeps only the declared keys, whatever the query selected, on every surface. A key the rows carry but the columns do not declare never leaves the server.
- **Rows can be:**
  - an array, a collection, a lazy collection or a generator, of arrays or objects;
  - a query builder or a relation, which the package limits to `views.max_rows + 1` rows before it runs. A lower limit of your own is kept.
- **Reading rows:** they are read the way output is read everywhere else: array keys, object properties, model attributes. A declared column is read even when the model lists it in `$hidden`: the columns decide.
- **Read only:** only a Read action shows a table. `actions:check` fails a `ShowsTable` action with any other effect, and that action runs as an ordinary one.
- **What `columns()` must be:** pure and cheap, like `schema()`. It is read in the call's locale, so labels can use `__()`.

## Column types

| Constructor | Holds | A value becomes |
|---|---|---|
| `Column::text($key, $label)` | text | a string of at most 1,000 characters, in valid UTF-8: a scalar, a backed enum's value, or a `Stringable` that is not a whole record (a model or a collection gives null, never its JSON) |
| `Column::integer($key, $label)` | whole numbers | an integer, rounded when given a fraction |
| `Column::number($key, $label, $decimals = 2)` | numbers | a number, shown with at most `$decimals` digits after the point |
| `Column::money($key, $label, 'USD')` | amounts in one currency | a number, shown in the currency (ISO 4217) |
| `Column::percent($key, $label)` | fractions | a number: `0.25` shows as 25% |
| `Column::date($key, $label)` | dates | `Y-m-d`, from a date, or a string that is a real date, alone or with a time |
| `Column::datetime($key, $label)` | dates with a time | ISO 8601, from a date, or a string that is a date with a time in ISO 8601 or the database's form |
| `Column::boolean($key, $label)` | yes or no | a boolean |

- **Nulls:** every value may be null, and a value of the wrong kind becomes null.
- **Keys** are lower-case snake case of up to 64 characters.
- **Descriptions:** `description()` is optional. It tells the model what the column holds, and the page shows it as the header's title.

## What each surface gets

A table action's output has one shape on every surface: the generated route's JSON, `Actions::attempt()->output()`, MCP and `actions:run`. For example:

```json
{
  "columns": [
    {"key": "author", "label": "Author", "type": "text"},
    {"key": "posts", "label": "Posts", "type": "integer"},
    {"key": "words", "label": "Words", "type": "integer", "description": "Words in the posts' bodies"},
    {"key": "published", "label": "Published", "type": "percent"}
  ],
  "rows": [{"author": "Ada", "posts": 12, "words": 9120, "published": 0.75}],
  "truncated": false,
  "chart": {"type": "bar", "x": "author", "y": ["posts", "words"]},
  "caption": null
}
```

- **`truncated`** is true when the rows went past `views.max_rows` (500 by default).
- **`actions:run`** prints the output as a table.
- **`php artisan actions:typescript`** writes the exact type.
- **The model's copy.** In a streamed copilot turn, the person sees the table, and the model reads:
  - that the person sees a table of so many rows;
  - the first `views.model_rows` rows (20 by default), with each text cut to 80 characters;
  - the columns' keys, labels and descriptions;
  - an instruction to say what stands out instead of repeating the rows.

  Later turns replay that same short copy. Everywhere else, including MCP and a `prompt()` call, the model reads the whole output as JSON, because no person sees a table there.

## The chart

The package draws no chart and ships no chart library. `chart` tells your page which chart the rows' shape calls for:

- **A metric:** one row of numbers only, with `y` naming them.
- **A line:** one date or datetime column first, then numbers, with `x` naming the date column.
- **A bar:** one text or yes-or-no column first, then numbers, up to 50 rows.
- **None:** anything else.

`y` takes the number columns of the first one's type, so one chart shows one unit: a percentage is never drawn beside a count. Draw it with your own components. With [shadcn/ui's chart](https://ui.shadcn.com/docs/components/chart) (Recharts):

```tsx
import { Bar, BarChart, CartesianGrid, XAxis } from 'recharts';
import type { TableData } from '@agentic-actions/client';

export function TableChart({ table }: { table: TableData }) {
    const { type, x, y } = table.chart;

    if (type !== 'bar' || x === undefined) {
        return null;
    }

    return (
        <BarChart data={table.rows} width={480} height={240}>
            <CartesianGrid vertical={false} />
            <XAxis dataKey={x} />
            {y.map((key) => <Bar key={key} dataKey={key} />)}
        </BarChart>
    );
}
```

Labels and cells are data from your rows. A chart library that renders its labels as HTML must escape them.

## Datasets

A table action writes its own query. A dataset lets the model ask the question instead, within names you declare: you list the ways rows can be grouped and filtered (dimensions) and the numbers that can be computed (measures) over one model, and the package writes a scoped, bounded query for each call. The model never writes SQL. It chooses among your names, and every value it sends is a binding.

A dataset is a Read action like any other. `#[Expose]`, `authorize()`, `shouldRegister()`, `$tenantScoped`, token abilities, the Read guard, MCP, the route, `actions:run` and TypeScript all apply as they do to a table action, and its answer is a table.

### Declare a dataset

```php
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use App\Enums\PostStatus;
use App\Models\Post;

#[Expose(web: true, agents: ['default'])]
final class PostActivity extends Dataset
{
    protected string $description = 'Posts of the current tenant: how many, how many featured, and by how many authors.';

    protected string $model = Post::class;

    protected string $timezone = 'Europe/Berlin';   // '' reads app.timezone

    protected array $shared = ['author'];   // users belong to no one tenant

    public function dimensions(): array
    {
        return [
            Dimension::time('published', __('Published'), 'published_at'),
            Dimension::text('author', __('Author'), 'author.name'),   // through the author() BelongsTo
            Dimension::enum('status', __('Status'), 'status', PostStatus::class),
        ];
    }

    public function measures(): array
    {
        return [
            Measure::count('posts', __('Posts'))->description('The number of posts'),
            Measure::count('featured', __('Featured'))->where('featured', true),
            Measure::countDistinct('authors', __('Authors'), 'author_id'),
            Measure::sum('words', __('Words'), 'word_count'),
            Measure::ratio('featured_share', __('Featured share'), 'featured', 'posts'),
        ];
    }

    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }
}
```

- **`$model`** is required. With tenants, the package scopes the model's query to the tenant as `$context->find()` does whenever the call runs in a tenant, even with `$tenantScoped = false`, and the model's global scopes apply as always. Outside a tenant, an account-level dataset counts every row its `scope()` allows, and `actions:check` warns about one that declares no `scope()`.
- **`dimensions()` and `measures()`** are pure, like `columns()`, and read in the call's locale, so labels can use `__()`. A declaration the package cannot answer throws when it is read, and `actions:check` reports it with its message.
- **What you do not write:** `schema()`, `rules()`, `columns()` and `handle()` are generated, and a dataset declares neither `agentSchema()` nor `outputSchema()`: discovery refuses one that does. It never asks the person for a missing field, whatever `$askForMissing` says.

### Dimensions and measures

| Constructor | What it gives |
|---|---|
| `Dimension::time($name, $label, $column)` | the time the rows are counted in: a call groups it by day, week (from Monday), month, quarter or year, and limits it to a range. One per dataset |
| `Dimension::text($name, $label, $column)` | a column of text to group or filter by |
| `Dimension::enum($name, $label, $column, Status::class)` | a column of a backed enum's values: a filter takes only those, and a row shows the case's `label()` when the enum has one |
| `Measure::count($name, $label)` | the number of rows |
| `Measure::countDistinct($name, $label, $column)` | the number of distinct values |
| `Measure::sum()`, `avg()`, `min()`, `max()` | the sum, average, smallest or largest value of a column; `->money('EUR')` shows it as money |
| `->where($column, $value)`, `->where($column, $operator, $value)` | on a count or a sum: only the rows whose column compares with the value, as Laravel's `where()` reads it: two arguments mean equals, three name the operator (`=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`); null means "is null" with `=` and "is not null" with `!=` |
| `Measure::ratio($name, $label, $numerator, $denominator)` | one measure divided by another, as a fraction the table shows as a percentage; none when the denominator is 0 |

- **Names** are lower-case snake case of up to 41 characters, and each is used once across the dataset. They are the table's column keys.
- **Columns** are the model's own (`published_at`) or one relation away (`author.name`). A relation is a public method of the model declared to return `BelongsTo`, to the related model's primary key, with no conditions of its own. Anything else, a `MorphTo` included, is a declaration error, found by reflection before the method is called. The related row is read through its own model, with that model's global scopes, so a related row outside them reads as null. Inside a tenant, the related row is read in the tenant's scope too: the tenant itself by its key, any other model through your tenant scope, so a link to another tenant's row reads as null. A relation whose rows every tenant shares, such as countries or the users who write, is named in `$shared` and read without it. `actions:check` fails a relation your tenant scope refuses.
- **Descriptions** are optional. The model reads them where it chooses a name, and the table's column carries them.

### The call a model makes

"Which authors published the most this month, compared with last month?" becomes:

```json
{"measures": ["posts"], "by": ["author"], "since": "-1m", "compare": true}
```

| Key | What it takes |
|---|---|
| `measures` | one to five measure names; required |
| `by` | one or two dimension names to group by, not the time |
| `grain` | `day`, `week`, `month`, `quarter` or `year`: one row per bucket of the time, every bucket of the range, never with `by` |
| `since` | the first day: `-30d`, `-8w`, `-6m`, `-2q` or `-1y`, or a date as `YYYY-MM-DD`; by default `$range` (`-30d`) |
| `until` | the last day, included: `today` (the default) or a date |
| `filters` | up to five of `{dimension, values, exclude}`: the rows whose dimension is one of up to 20 values, or with `exclude`, none of them. `exclude` keeps the rows with no value |
| `compare` | adds each measure in the previous period of the same length, and its change |
| `sort`, `ascending` | a chosen measure or dimension, or the time with `grain`; by default the time from the earliest with `grain`, else the first measure from the highest. Rows with no value come last either way |
| `limit` | 1 to `views.max_rows`; by default 50. With `grain`, every bucket comes back |

- **Only declared names.** The generated schema lists your measures, dimensions, the five grains and your sort keys, and nothing else. A call naming anything else is refused as invalid, naming the key, before any query runs. So are a filter value outside an enum dimension's values, a value over 255 characters, a date that does not exist or lies outside 1900 to 2999, a first day after the last, a sort the call did not choose, a dimension filtered twice, and `grain` with `by`.
- **Relative bounds start their unit.** `-8w` is the Monday seven weeks before this week's, so it covers eight whole weeks, this one included. `-1m` is the first of this month.
- **A series fits one table.** With `grain`, a range of more buckets than `views.max_rows` is refused, and the model reads the fix: `coarser_grain_or_shorter_range`.
- **The previous period** is as long as the range and comes right before it. A range of whole months compares with the months before it, so this month compares with last month. With `grain`, it is moved back by as many buckets as the range has, so each bucket compares with the one that many buckets earlier.

### What a dataset answers

The same table output as any table action, with the columns the call chose, in the order `columns()` declares them: the time as a date, the other dimensions as text, the measures, then with `compare` each measure's `{name}_previous` and `{name}_change` (a fraction: `0.5` is 50% more), none when the previous value is 0. A ratio gets no comparison.

- **Gaps are filled.** A bucket no row fell in reads 0 for a count or a sum, and no value for the rest.
- **The caption says what was asked**, in the person's language, with a text filter's values quoted and cut to 40 characters: "Posts by Author · 1 Sep 2026 – 30 Sep 2026 · compared with 1 Aug 2026 – 31 Aug 2026 · the 50 highest by Posts". The model reads it with its copy.
- **The chart** follows the shape as for any table: a series is a line, one dimension up to 50 rows a bar. A change, a percentage, is never drawn beside a count.
- **A refresh asks the same question.** The kept input holds the dates `since` and `until` resolved to, so refreshing next month still answers for this month.

### Your own conditions: scope()

```php
public function scope(Builder $query, ActionContext $context): void
{
    $query->where('author_id', $context->actor()->getKey())->orWhere('visibility', 'public');
}
```

`scope()` receives a query of its own. Only its conditions join the call's query, in parentheses, after the tenant's scope and the model's global scopes, so an `orWhere` narrows the tenant's rows and never widens them. A `scope()` that removes a scope (`withoutGlobalScopes()`) or adds a join, a union, a group, a having, an order, a limit, an offset or columns fails the call, naming the dataset.

### Time zones

Days, weeks and months are counted in `$timezone`, or `app.timezone` when it is empty. Stored times are read in `app.timezone`, which Laravel writes them in; UTC is the default, and the one to keep.

A time the model casts to `date` or `immutable_date`, with or without a format, is a day, not an instant: it is compared with the range's own days and never moved by an offset. Cast a `DATE` column that way. Read as an instant, it would count each day's rows on the day before in a zone west of storage.

A time is moved into the dataset's zone by the offset that zone had at that moment, so a post written at 00:30 in Berlin on the night the clocks change counts on the right day, with no time zone tables in your database. With a storage zone other than UTC, the move is the difference of the two zones' offsets, and a time stored inside the storage zone's own repeated hour, when its clocks go back, can be read either way.

### Give the datasets connection a time limit

A question can ask for a lot of rows. The package sets no time limit of its own: it answers a statement timeout that the connection raised with a sentence the model can act on ("The question took too long: ask for a shorter range, a coarser grain or fewer groups."), a 409 on the web, which is not reported. `actions:check` warns about a dataset connection with no limit.

Set `agentic-actions.datasets.connection` (`AGENTIC_ACTIONS_DATASETS_CONNECTION`) to run datasets on a connection of their own, such as a read replica, and give that connection a limit:

- **MySQL:** in the connection's `options`, `PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION max_execution_time=10000'` (milliseconds, `SELECT` statements only).
- **MariaDB:** `PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION max_statement_time=10'` (seconds).
- **Postgres:** `ALTER ROLE reports SET statement_timeout = '10s';` on the role the connection signs in as.
- **SQLite:** has none. The caps still bound the answer's size.

Without `datasets.connection`, a dataset runs on its model's connection, and Laravel's read and write split already sends its queries to the read connection.

### Testing a dataset with actions:run

```bash
php artisan actions:run post-activity --as=1 --input='{"measures": ["posts"], "by": ["author"], "since": "-1m"}'
```

It prints the caption, with the dates the call resolved to, and then the table, so you can try each question a person might ask before any model does. In a test, `Actions::attempt(PostActivity::class, $input, $context)->output()` returns the same table.

## On the page

A table arrives in the chat as a `data-view` part after its row. With React:

```tsx
import { messageSegments } from '@agentic-actions/client';
import { ActionTable, viewsOf } from '@agentic-actions/client/views';

// Inside the panel of docs/copilot.md, for each message:
{viewsOf(message).map((view) => (
    <ActionTable key={view.id} view={view} refreshUrl="/actions/_views" onRefresh={(fresh) => setChart(fresh.table)} />
))}
```

- **`viewsOf(message)`** returns the message's tables in order.
- **`<ActionTable>` renders one unstyled:**
  - a `<table>` with the caption;
  - `<th scope="col">` headers;
  - cells written by `Intl` in the page's language, with number cells aligned to the end, so a right-to-left page reads correctly;
  - a note when the rows were cut;
  - the time the rows were read;
  - a Refresh button when the table can be refreshed.

  Pass `classNames` to style its parts, and `locale` on a server-rendered page. Datetimes show in the viewer's time zone, so render the table on the client there.
- **Without React,** `viewsOf()`, `formatCell()` and `refreshView()` come from `@agentic-actions/client` itself.

## Kept with the conversation

A table the person saw in a conversation laravel/ai stores is kept with that conversation. After a reload it comes back in its message, with the rows the model described. This needs:

- **An agent whose conversations are stored,** as for [confirmations](copilot.md#the-agent): `Conversational` and `RemembersConversations`. With any other agent, the table shows live only.
- **The `agentic_views` table.** `php artisan actions:install` publishes it with a copilot. It lives on laravel/ai's conversation connection.
- **The agent passed to the transcript,** as for a waiting card:

  ```php
  $agent = (new BlogAssistant($user))->continueLastConversation($user);
  $conversationId = $agent->currentConversation();

  'transcript' => $conversationId === null ? [] : Transcript::forUseChat($conversationId, $user, agent: $agent),
  ```

A reload shows a table only while the agent's own tools still offer its action to this person in this tenant, with the rows shown under the columns the action declares today. When a column is removed, it disappears from old tables too. A reload shows the newest twenty tables of the page at most. It checks that the action is still offered, not that an `authorize()` taking `ValidatedInput` would still pass for its input: a refresh checks that.

A table is stored as the rest of the conversation is: unencrypted, like laravel/ai's messages, which hold the model's copy. When you delete a conversation, delete its tables too:

```php
AgenticView::where('conversation_id', $conversationId)->delete();
```

Or let `model:prune` delete the tables whose conversation is gone, once they are a day old:

```php
// routes/console.php
Schedule::command('model:prune', ['--model' => [\AgenticActions\Streaming\AgenticView::class]])->daily();
```

## Refresh

A kept table of an action exposed on the web can be refreshed. The page posts to the `_views` route of the group that serves your routes (`{prefix}/actions/_views/{ref}`, with `Actions::routes()`). The action runs again as the person, with the input it ran with, through every check a call to its route gets: exposure, membership, `authorize()` and validation.

A table is refreshable only when a refresh can check everything its first call did. So there is no Refresh for:
- a call that ran on fixed input, such as a preset your agent's context carries (`withFixed()`) or a parameter of the chat route, since the refresh route cannot check again what chose it;
- an action whose class declares middleware of its own (`HasMiddleware`, or the `#[Middleware]` and `#[Authorize]` attributes), which only its generated route applies.

- **Who can refresh:** only the person the table was shown to, in a signed-in session, in the same tenant, while the conversation exists. Anything else gets 404.
- **What changes:** the page shows the fresh rows and their time, and `onRefresh` hands them to your chart. The kept table does not change, so a reload still shows the rows the model described.
- **Actions not on the web:** an action exposed to agents only shows no Refresh.

## Limits

- **One kind of action:** tables are shown only by Read actions.
- **Ten per turn:** a turn shows at most ten tables. Past that, the model reads the rows and the person sees none.
- **Two sizes to tune:** `views.max_rows` (500) caps a table on every surface, and `views.model_rows` (20) caps the model's copy.
- **The chart:** its type follows the rows' shape, and an action cannot choose one.
- **MCP:** clients get the output as JSON text. Structured results and a chart inside the client are not built yet.
