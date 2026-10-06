# Security

This page lists what the package guarantees, one guarantee per item, and where each one stops. To report a vulnerability, follow [SECURITY.md](../SECURITY.md).

- The class is the allowlist. Nothing reaches a network or model surface without `#[Expose]` on the action's own class. Attributes are not inherited, a class without an effect exposes nothing remote, and naming a surface the rules refuse is an error, never a silent widening.

- The manifest nominates and the class decides. The cached manifest and the route cache only say which actions might exist. Every gate (the route, the agent door, the tool catalog, the MCP server, the token check) re-reads the class's own attributes on each call, so a stale cache can hide an action but never expose one the class no longer exposes.

- Generated routes need a user. A route from `Actions::routes()` refuses a guest before it reads the tenant or any input, whatever middleware the group has: a JSON caller gets 401 and a browser visit goes to your guest redirect. `actions:check` warns about a generated route mounted without `auth`. A public action gets a route you write yourself.

- Only a Read runs on a reading method. Laravel's CSRF check lets GET, HEAD and OPTIONS through, so a route you write on one of them runs a Read and answers 405 for any other effect. Generated routes are POST only.

- Route parameters come from the route. A key the route fixes takes the route's value when `schema()` declares it, and is dropped from the request body otherwise, so a body can never supply a route parameter, even one only `rules()` checks.

- Token grants fail closed and follow the authenticating guard. The abilities are read from the guard that authenticated the request. A session has the full access a session always has. A Sanctum token needs the ability for the action's effect (`actions:read`, `actions:write`, `actions:destructive`, `actions:external`, or `*`); over MCP, `*` does not count (below). Any other credential, including a third-party guard that keeps state but is not Laravel's session guard, has no abilities, so every action reads as not found for it, until you bind your own `AgenticActions\Contracts\ReadsTokenGrants`. A context with no guard is refused, except on the console and `ActionContext::system()`.

- Tenants bind by primary key. A token bound with `tenant:{key}` reaches only that tenant's tenant-scoped actions, compared by the tenant's primary key: no action outside a tenant, and no account-level action, on any door, whether mounted under the tenant's URL, called in-process or queued. The route segment is resolved by the tenant model's route key, and an unknown key and a tenant the actor does not belong to give the same 404. Middleware of your own that runs first, such as a team-membership check on the route group, answers before the package does, so a non-member then gets its answer instead. Membership runs on every surface before the action's own code reads the tenant; only a `system()` call with no actor skips it, and any other call without an actor is refused.

- `find()` only narrows your scope. Every condition your `tenant.scope` class or `scopeUsing()` closure adds is kept in one group, an `orWhere` or an `or` inside a raw condition included, so `find()`'s key narrows the scope's rows and never returns another row. Which rows are the tenant's is the scope's decision: an `orWhere('shared', true)` makes the shared rows of every tenant reachable, in Write actions too.

- Forbidden keys. An agent is never offered an input key that matches `agents.forbidden_keys` (by default `*password*`, `*secret*`, `*token*` and `api_key`, at any depth), a route parameter of the action's routes, the tenant parameter, or the tenant model's foreign key. An action that advertises one is left out of every toolset; your tests and local requests fail loudly instead. Output keys matching `agents.forbidden_output_keys` are refused the same way.

- An agent's arguments are cut to the advertised schema before anything reads them. The agent door keeps only the keys the tool offered, at every depth, before `fromAgent()`, `prepareForValidation()`, validation or `handle()` see the arguments. A key that only `rules()` names, such as a tenant's foreign key, can never arrive from a model.

- Ids sit behind an input-aware `authorize()` and a scoped `exists`. Id-shaped keys (`id`, `*_id`, `uuid`) may be offered to agents, and `actions:check` fails unless the action's `authorize()` takes `ValidatedInput`, so the record is checked before `handle()`, and unless every `exists` or `unique` rule on such a key is scoped by a `where` clause or a query callback. To offer no ids at all, add the three patterns to `agents.forbidden_keys` and follow the [strict agent schemas](recipes.md#strict-agent-schemas-no-ids) recipe.

- A Read cannot write through Laravel's database connection. While a Read action's own code runs, from `authorize()` through `fromAgent()`, `prepareForValidation()`, `rules()`, validation, `handle()`, the output projection and `modelReply()`, every statement that would write is refused before it executes and the call fails. Tables your cache, session and queue use on the database driver stay writable, on the connection each one uses, and `reads.writable_tables` adds others. A table named with its schema or database (`other.cache`) is writable only when `reads.writable_tables` lists it by that name. The guarantee does not cover functions called inside a `SELECT`, raw PDO, jobs of your own that a Read queues (the package's `dispatch()` refuses a non-Read action while the Read runs), or code outside the action: the package's events and their listeners, and the response with its session and flash data. Transactions and savepoints that Laravel opens itself work inside a Read; a raw `ROLLBACK` statement does not.

- The Read guard reads a statement as its connection's driver does. On SQLite and SQL Server a backslash inside a string is a literal. On Postgres a statement is read two ways: with a backslash as a literal except in an `E'...'` string, and with a backslash escaping in every single-quoted string, since the guard cannot tell whether the server has `standard_conforming_strings` on. A string of one backslash does not close in the second reading, so the guard refuses it as a statement it could not check: on Postgres, write a `LIKE` escape with another character, such as `like ? escape '!'`. On MySQL and MariaDB a statement is read with and without backslash escapes, and with backslash escapes only in single-quoted strings, since `NO_BACKSLASH_ESCAPES` or `ANSI_QUOTES` may be on. A driver the guard does not know gets every one of those readings. On SQL Server, which runs a second statement that follows the first with no semicolon between them, the guard refuses a statement followed by a second one that could write, run a procedure or other code, or change the session or a transaction, and a statement whose first word SQL Server would run as a procedure, such as `savepoint`. A driver the guard does not know gets SQL Server's reading and the first of those checks too. Every other quoting and comment convention the supported databases use (dollar quotes, nested comments, `#` comments, bracketed names, `--` without a space, a line comment that a carriage return ends as well as a line feed) is read both ways on every driver. A statement is allowed only when every reading allows it. When a quote or comment does not close in some reading, the statement holds an executable comment (`/*! ... */`), or, in SQL Server's reading, a letter follows a number with no space between them (`0x1truncate`), a word outside quotes holds a character past ASCII, or a `--` comment holds a line break other than a carriage return or a line feed, the guard cannot check it. It refuses the statement with a message that says so and quotes it, and does not report it as a write. SQL Server does not document which Unicode characters it reads as white space, so on `sqlsrv` and on a driver the guard does not know, put a name that holds a character past ASCII in brackets or double quotes, as Laravel's grammar does. Between tokens the guard reads ASCII white space only, whatever the locale.

- A Read sends no `SET` but a transaction's. While a Read action's own code runs, a `SET` statement is refused unless it is `SET TRANSACTION` or `SET LOCAL TRANSACTION` naming only an isolation level, an access mode (`READ ONLY`, `READ WRITE`), `DEFERRABLE` or a snapshot. Every other `SET` fails the call as a write does, among them `autocommit`, `foreign_key_checks`, `sql_log_bin`, `sql_mode`, `NAMES`, user variables, `SET ROLE`, `SET SESSION AUTHORIZATION` and `search_path`, since each would change how the connection behaves for the code that runs after the Read. A SQLite `PRAGMA` runs only when it is one of the read-only pragmas the guard names, so `pragma foreign_keys = 0` is refused too. The statements Laravel sends while it opens a connection (character set, time zone, SQL mode, search path and the SQLite pragmas in `config/database.php`) go to PDO directly, so a Read can still open a connection. How long an allowed `SET TRANSACTION` lasts is your database's rule: Postgres applies it to the current transaction, MySQL to the next one, and SQL Server to the rest of the session.

- A model reads only authored sentences. An agent receives "Done.", your `modelReply()`, a Read's projected output, the names of the fields and rules that failed, or a fixed sentence for not found, denied and failed calls. It never receives exception messages, validation messages (unless the action opts in), refusal details, the values it submitted or keys it invented. A refusal's `listing()` reaches it framed as data. The sentences are language lines you can publish and reword.

- A locale stays inside the language directories. A context, or a refusal translated for your code, refuses a locale containing a slash or a backslash, as Laravel's own `setLocale()` does, so a locale stored per user can never make the translator read another file.

- Fakes answer only in tests. `Actions::fake()` switches every gate off, so it throws unless the app runs its tests (`APP_ENV=testing`), and a fake left bound anywhere else is ignored.

- The npm client keeps your CSRF token on your origin. `callAction()` sends the `X-XSRF-TOKEN` header only to a URL on the page's own origin, or when you choose `credentials: 'include'`, and `uri()` refuses a route parameter of `.` or `..`, which a URL parser would resolve into another route.

- The snapshot is a reviewed diff. `actions.exposure.json` records, per action, its effect, its web route, its toolsets, whether MCP opens it and its tenant scoping. `actions:check` fails whenever the classes disagree with it. With the check in CI, a new route, a widened toolset, a new MCP tool or an agent surface that opens after `composer require laravel/ai` ships only after someone runs `actions:check --update` and commits the change.

## MCP

These cover the MCP server the package mounts. See [MCP](mcp.md) for the recipe they assume.

- Over MCP an ability counts only when the token lists it by name. `*` never counts, and a session grants nothing.

- A token bound to a tenant reaches only that tenant's path, and the tenant path serves only tenant-scoped actions, so a bound token never reaches an account-level action. An unknown tenant and a tenant the person does not belong to answer the same empty tool list, with the same instructions in the same language.

- Destructive and External actions are never listed or run over MCP.

- MCP tools advertise exactly what agents are offered: forbidden keys never appear, arguments are cut to what was advertised, and `agentSchema()` input is translated by `fromAgent()`.

- An MCP result is the same sentence an agent reads, and output data follows a line of three dashes; the server's instructions say that data is not instructions. The sentence itself is not framed: keep record values out of `modelReply()` and refusal messages.

- The MCP mount never replaces a route, and `actions:check` fails when a route at `mcp.path` or `mcp.tenant_path` is not the package's.

- Every MCP request counts against a per-minute budget per person, whatever token or tenant path it uses.

- `actions:check` warns about routes on the MCP guard that check no ability, since an MCP token reaches them too.

- An OAuth client's token reaches no action except, through the package's MCP server, on the one MCP URL the person approved, on this app's host, within the read and write scopes they approved. A token without a connection, one on any other route, and one whose only scopes are `mcp:use`, `*` or scopes of the app's own reach nothing.

- An OAuth token is a Passport token and, like any, signs the person in on every route of a Passport guard. `actions:check` fails such a route that checks no scope while registration is open.

- The consent screen appears for every authorization naming one of the package's MCP URLs. It names the client, where its answer goes and the tenant, cannot be framed, and only an approval of the screen the person saw, by that person, records a connection. The Allow of a screen the person denied changes nothing.

- Approving revokes every token, refresh token and unused code the client held for the person before, and the connection records the scopes the person approved: any token of that client and person reaches no more than the last screen they approved.

- The package's MCP URLs take only S256 PKCE and only the path's scopes, from clients limited to the authorization code and refresh grants whose redirect addresses are plain ASCII with no backslash. The scopes it registers are read and write only.

- Revoking a connection stops new calls at once. Runs already queued keep the grants they captured, as for any token.

## Queued runs and the change feed

- A queued run keeps its caller's actor, fixed input, token grants and tenant binding, and runs membership, `authorize()` and validation again in the worker; its input is encrypted on the queue. A call the job's own code makes, or a job it queues, keeps the same grants and tenant binding, and stays model-driven when a model queued the first job, whatever context that code builds, as inside the request that queued it. A token revoked or expired after dispatch does not stop a job already queued. A job whose person or tenant was deleted, soft-deleted included, is dropped without running. Turning `surfaces.mcp` or `surfaces.agents` off also refuses jobs those surfaces queued earlier.

- An agent turn that laravel/ai queues (`queue()`, `broadcastOnQueue()`) keeps the grants of the token that queued it, as a queued run does: the job carries them, and its tools run with those limits and that tenant binding in the worker. A turn queued from a queued run or turn keeps that run's grants. A turn a session, the console or a guest queued keeps none and runs as the person.

- While a Read's own code runs, `dispatch()` refuses a non-Read action. Jobs of your own that a Read queues run outside the guard, and with `reads.guard` off the rule is off too.

- The change feed sends touched keys only, never ids or values, answers only a signed-in session, and only after membership; members of a tenant see the keys other members' writes touched. Each poll passes through the group's middleware like any request, session included. The client stops polling after 10 minutes with no input, so an idle open tab does not keep a session alive.

## The copilot

These cover the chat stream, its rows and the page context. See [the copilot](copilot.md) for the recipe they assume.

- The chat stream is an allowlist. `ActionsProtocol` rebuilds every part from the keys its type may carry. Outside the words the model writes in its reply, no part carries a tool's arguments or result, reasoning, usage, a model or provider name, or an exception's or provider's text. A failed turn ends with one fixed sentence, which you reword by publishing the language files. The stream ends with `[DONE]` even when your exception reporter throws. A part type the allowlist does not name is dropped, and your own parts must be `data-*` parts other than the package's four (`data-action`, `data-approval`, `data-elicitation` and `data-view`), narrowed to `type`, `id` and `data`. The one result that reaches the browser is a table a Read action shows ([below](#tables)).

- A row's labels take no arguments and are read before their own call runs, so nothing the model sends can write one. One instance can serve several calls of a turn, so a label returns a fixed sentence and never reads state an earlier call left behind.

- A row's link is a same-host URL with no `.` or `..` segment, no backslash and no control character, checked on the server, and the browser checks again that it is a string URL of the page's own origin, over http or https, before it shows or follows it. A link is followed only when the action sets `$followLink`, which is for a `redirectTo()` built from the saved record, never for an endpoint that redirects. When the model chooses part of that URL, such as a slug, keep it a slug with no `/`, so the link can only name the record's own page.

- `ChatRequest` keeps only the text parts of the last message, in UTF-8, up to `agents.max_message_length` characters, or the person's answers to calls their conversation is waiting on (below). A client never supplies history: request data never becomes a message the model reads as said before, and files never arrive through the chat request.

- History and transcripts come only from a conversation the server chose for this user, and with tenants, for this user, agent and tenant. `Transcript` returns nothing when the conversation store says the conversation belongs to someone else. The store keeps no tenant. `Actions::conversation()` keys each conversation by the user, the tenant and the agent class your route passes, and reads no id from the request; a mapping of your own checks the tenant itself.

- The page context sends the model a route name and a page component, never parameter values. A page under another tenant's route, or one no request can be built from, is dropped, and the turn carries on without it. The page is never stored and never authorizes anything: tools authorize against the agent's own context.

- Output a tool echoes, prints or dumps goes into the stream body as is, past the allowlist. Tools return their text and never echo.

- A closed tab never cuts a turn short. Its tools still run, and the turn is stored.

- The `/ai-sdk` preset sends the `X-XSRF-TOKEN` header only to an endpoint on the page's own origin, or when you choose `credentials: 'include'`.

## Tables

These cover [tables](data.md), the one result that reaches the browser.

- A table shows only what its columns declare. A Read action that implements `ShowsTable` sends its declared columns, and nothing else of its rows, to the browser, the kept table and the model's copy. A cell holds a scalar or null: a record in a text column gives null, never its JSON, and text is valid UTF-8 of at most 1,000 characters. A declared column is read even when the model lists it in `$hidden`: declare only what may be shown. The `data-view` part is rebuilt from an allowlist of its keys, for the stream and for a reload alike, and every other action's result still never reaches the browser.

- `agents.forbidden_output_keys` reads a table's column keys. A column that matches leaves the tool out, as an output key does; the table's own keys (`columns`, `label`, `chart`…) are the package's and are never read.

- A reload shows a kept table only while the action is still offered. It comes back only to the person it was shown to, through an agent acting for that person in that conversation and tenant, while the agent's own tools still offer its action, under the columns the action declares now, and only the newest twenty on a page. A table of a person who has since lost the action, or of a column you removed, does not come back. A reload does not run an input-aware `authorize()` again: the rows are what the person was shown then, and a refresh runs every check.

- A refresh is a new call through the web door. It runs the stored input as the person, through exposure, membership, `authorize()` and validation, only for an action exposed on the web and served by that route group, and only for a signed-in session. A call that ran on fixed input (a preset or a route parameter) and an action whose class declares middleware of its own are never refreshed, since the refresh route cannot check again what chose that input or apply that middleware. A table of another person, another tenant, a deleted conversation, a token, or an unknown id gets the same 404, and so does every refresh while the tables are not migrated. A refresh never changes the kept table.

- Kept tables are stored as the conversation is. `agentic_views` holds the rows shown and the input of the call, unencrypted, as laravel/ai's messages hold the model's copy. Use your database's encryption at rest where you need it, and delete a conversation's tables with it.

- The model reads a compact copy. While the person sees a table, the model reads at most `views.model_rows` rows, each text cut to 80 characters, after "---" as data. Text in your rows is data from your users: the copy is marked as data, and the model's reply is still words it wrote.

## Datasets

These cover [datasets](data.md#datasets), where a model chooses the question and the package writes the query.

- A model never writes SQL. A dataset's call is generated from its declarations: the only names it takes are the measures, dimensions, grains and sort keys the dataset declares, and a call naming anything else is refused before any query runs. Every identifier in the query comes from a declaration, checked as a plain column name or `relation.column` before it is quoted, and every value the model sends is a binding.

- Every measure reaches every dimension and filter. A model can ask for any declared measure grouped or filtered by any declared dimension, so a `sum`, `min` or `max` grouped by a dimension that names one person or record reveals that row's value. `authorize()` must allow every value a measure can reveal this way; a measure that needs another permission belongs in another dataset.

- Inside a tenant, a dataset counts only the tenant's rows. The package applies your tenant scope to the dataset's model as `find()` does whenever the call runs in a tenant, even for a dataset with `$tenantScoped = false`, and the model's global scopes apply as always. Outside a tenant, such as an MCP client on the account's URL, an account-level dataset counts every row its `scope()` allows, and `actions:check` warns about one that declares no `scope()`. The conditions `scope()` adds come from a query of its own and join in parentheses, so an `orWhere` there narrows the tenant's rows and cannot widen them. A `scope()` that removes a scope, or adds a join, union, group, having, order, limit, offset or columns, fails the call. A removal inside a nested `where()` is refused on Laravel 13, which carries it up to the query; Laravel 12 leaves it on the nested builder, where it never reaches the query. A `scope()` that writes is refused by the Read guard, as any Read's code is.

- A dimension reads one relation at most, and only a `BelongsTo`: a public method of the model declared to return exactly `BelongsTo`, found by reflection before it is called, to the related model's primary key and with no conditions of its own. The related row is read through its own model, with that model's global scopes, so a row outside them reads as null. Inside a tenant, a related row is read in the tenant's scope too, as `find()` would read it: the tenant itself by its key, any other model through your tenant scope. A row that points at another tenant's row, through a foreign key nobody checked the tenant of, shows no value. A relation named in `$shared` is read without the tenant's scope, for rows every tenant shares, and `actions:check` fails a relation your tenant scope refuses. `actions:check` also reads each column a dimension or measure reads, a measure's `where()` column included, by its last segment, against `agents.forbidden_output_keys`, and the agents' tools leave out a dataset that reads one, whatever name shows it.

- A question's size is capped: five measures, two dimensions to group by, five filters of up to 20 values of at most 255 characters, dates between 1900 and 2999, and at most `views.max_rows` rows or buckets. Past a cap, the call is refused. On every action, a list its rules cap (`array` or `list` with `max:N`) is cut to one item past the cap before validation reads it, so an oversized list is refused as fast as one item too many.

- A caption repeats the call's filter values, which the model chose. A text value is plain text, as a card's (no control, separator or direction character), its quotation marks become apostrophes so it cannot close its quotes, and it is quoted and cut to 40 characters. The model reads the caption as data after `---`, never as one of the package's own lines.

- A dataset with an error of its own, such as another effect than Read, has every remote surface closed, in production too, so no door runs its `scope()` or query.

- The package sets no time limit on a question: your database does. MySQL enforces `max_execution_time` on `SELECT` statements, MariaDB `max_statement_time`, and Postgres `statement_timeout`; SQLite has none. Set one on the connection datasets use (`datasets.connection`, such as a read replica), and `actions:check` warns while it has none. A timeout the connection raises answers with a fixed sentence and is not reported. A call runs at most two queries (with `compare`), and your throttle and the MCP budget bound how often it runs.

## Confirmations

These cover an agent's Destructive and External calls. See [confirmations](copilot.md#confirmations) for the recipe they assume.

- An agent runs a Destructive or External action only after a person confirms that one call, and only an agent whose conversations laravel/ai stores is ever offered one. MCP clients are never offered one.

- An agent cannot switch the confirmation off.

- The card is built on the server by the action's own `approvalReason()` and `approvalSummary()`, after `authorize()` allowed the call, from the validated input that will run and the records it names. The browser receives only that sentence and those rows, as plain text, never the raw arguments, a reason or the model's prose; a character that would break a line or reorder the text around it becomes a space. An input value a summary shows is exactly what will run.
- The card is built again from the input that will run, just before `handle()`, and the call runs only when it reads exactly as the card the person confirmed and `approvalBinding()`'s values are the same. A record that changed in between, or an input that now names another record, runs nothing, and a reload shows no card for it. What the card cannot show (a long body, a full recipient list) is covered only when the action returns it from `approvalBinding()`.
- One step of the model asks about at most eight calls; any further Destructive or External call of that step is refused.

- While the card is built, and before a person's confirmation is checked, database writes and queued actions are refused. Keep `authorize()`, `prepareForValidation()`, `rules()`, `approvalSummary()` and `approvalBinding()` free of other effects.

- The confirmation route keeps Laravel's CSRF protection and is never listed as a CSRF exception. Only a JSON boolean `approved` counts as an answer.

- An answer counts only from the session of the person the conversation belongs to, as approve or decline for a call that conversation is still waiting on. The package never runs arguments a person or a browser edited. A decline reason reaches the model as one quoted string inside the package's sentence.

- A confirmation runs its call at most once, as the person who was asked, in the tenant they were asked in, with the input and the card the person saw, within `approvals.ttl` seconds. A replayed answer, a second answer sent at once, another person's answer and a late one run nothing. Of two answers sent at once, or an answer and a new message, one goes on with the turn and the other gets 409 before any agent work, so the stored conversation holds the result of the call that ran.

- Confirmations and forms are kept in the app's default cache store, which must be a database, redis, memcached or dynamodb store every server shares. `actions:check` warns on any other driver.

- An action a person confirmed cannot, from its own code, run or queue another Destructive or External action.

## Asking the person

These cover forms for the fields a model's call left out. See [asking the person](asking.md) for the recipe they assume.

- A form is built on the server from the action's own schema and `ask()`. Its sentence and titles are never the model's words, and it names the app that asks. A field opens on the model's value only when validation accepted it and it holds no control character, line separator or direction control. A field whose key, title or description reads as a secret, a password, a token or a payment credential, or whose rules check a password, is never asked: such a call is refused instead. The person's values in a form are for your action, not for a model.

- In the app, the person's values never enter the conversation store or anything the package hands a model, including validation messages, whether your rules or your `handle()` raise them: the model reads which fields were filled. What your action returns through `modelReply()`, a `Refusal`'s replacements or `listing()`, or a Read's output is yours, so do not echo the values there.

- An answer counts only from the session of the person the conversation belongs to, for a form their conversation is still waiting on, within `approvals.ttl` seconds, once. It is checked against the action's own rules before any agent work, and the action runs every check again when it runs, on the arguments the form was built from. Any other answer to a form's call declines it, and a body that answers one call several times is read for its first answer only, so it rebuilds the form once. The checks ride your chat route's `throttle`.

- Over MCP, a form goes only to a client on protocol 2026-07-28 that declares form elicitation on the request; every other client gets the refusal naming the fields. Its answer counts only on a retry carrying a request state that your app's key encrypted and authenticated, made within `approvals.ttl` seconds for the same bearer token (the person alone for a guard that reads no bearer token), person, tenant, tool, arguments and form, and it runs through every check a call runs. A state that does not verify gets error -32602; a stale or foreign one gets a fresh form. The state is not single use: within its time a retry runs again, as the token could by sending those values itself. Everything in the form reaches the client, and the model reads which fields were filled, never the values, from the package.

- Destructive and External actions never ask. Their calls are confirmed on the card, once complete.

- A free-text field goes to your action as the person wrote it: never ask for a secret in one, and never put a URL in a form's sentence, a title or a description.

- For sensitive data, send the person to a page of your app, the pattern MCP's URL mode standardizes. The package does not build it.
