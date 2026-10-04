# Agentic Actions for Laravel: notes for agents and reviewers

Read this before any change. It is written so a new session, another agent or a reviewer can pick the work up cold. `AGENTS.md` points here. `CONTRIBUTING.md` gives people the same workflow and rules (making a change, the CI cells and how to run them, the database group, docs pages, releases); this file adds the map of the code, the testing conventions in full and the gotchas.

## What it is

`agentic-actions/laravel` (PHP) and `@agentic-actions/client` (npm, in `js/`). An app writes each operation once as an `Action` class, and `#[Expose]` makes that class a web and JSON route (with Precognition), an Artisan command, a laravel/ai agent tool, an MCP tool, a queued job and a typed TypeScript function. Every caller goes through one pipeline in `Runner`: exposure, token abilities, tenant membership, `authorize()`, validation, `handle()`, and the output schema as an allowlist. On top sit the copilot wire (live rows while an agent works), a change feed, person-confirmed agent calls for Destructive and External actions, asking the person for missing fields, and OAuth for remote MCP clients on Passport. PHP 8.3+, Laravel 12.62+ or 13.15+; laravel/mcp 1.x is the one package it installs, and laravel/ai 1.x is optional (`docs/setup.md`). It is a beta: `CHANGELOG.md` has each version and its upgrade notes.

## Where things are

- `src/` (`AgenticActions\`), one module per folder, each with its own provider when it registers anything:
  - root: `Action`, `Ask` (the form builder an action's `ask()` returns), `ActionContext`, `Effect`, `Surface`, `Outcome`, `Refusal`, `Runner` (the pipeline), `ActionsManager` (behind the `Actions` facade), the providers.
  - core: `Pipeline` (doors, authorize timing, exception mapping), `Schema` (schema to rules, coercion, output projection), `Security` (token grants, forbidden keys, the Read guard and its SQL reader), `Tenancy`, `Exposure`, `Discovery` (scanner, registry, manifest, snapshot), `Attributes`, `Contracts`, `Events`, `Exceptions`, `Support` (`Packages`, `Paths`, `Migrations`).
  - surfaces: `Http` (`Actions::routes()`, the FormRequest bridge, the responder), `Console` (the `actions:*` commands and `make:agentic-action`; every `actions:check` row is in `Checks`), `TypeScript`, `Testing` (`Actions::fake()`, assertions, Pest expectations), `Ai` (`ActionTool`, the agent door, toolsets, `ConfirmingAgents`, `PendingCards`), `Streaming` (`ActionsProtocol`, the relay, `ChatRequest`, `Transcript`, page context, the conversation store), `Approvals` (ticket, card, claims), `Elicitation` (`Form`, built on the server from an action's schema, and `FormSchema`, which says whether a form can hold a field), `Mcp` (`CallAction` answers `tools/call`, and asks the clients that show forms; `McpMount` also holds the OAuth switch, `oauth()`, and matches a URL to a package path, `path()`), `OAuth` (on Passport, only while the switch is on: `Discovery`, the 401 challenge and the metadata's scopes; `BindConsent`, on Passport's two authorization routes; `Consent`, the screen's data and default page; `McpConnection`, one row per client and person naming the approved URL), `Queue` (`RunAction`), `Feed`.
- `js/src/`: `index.ts` (the root, zero dependencies), `inertia.ts`, `react.ts`, `ai-sdk.ts`, and their helpers. `js/dist/` is committed, since apps can also install the client from `vendor/agentic-actions/laravel/js`; npm publishes the same `dist/`. `js/tests/runtime` runs on the built `dist/`, `js/tests/types` holds the type tests, and `js/tests/fixtures/streams/*.sse` are recorded by `tests/Workbench/StreamFixturesTest.php`.
- `tests/`: `Unit`; `Feature` by module, with the attacks of each security review in `Security/*AttacksTest.php`, the cases that cross modules in `Integration`, and the contracts the confirmation and asking features rely on in `Seams04` and `Seams05`; `Workbench`, end to end on the workbench app (attacks, a real MCP client, route caching); `Fixtures/<Module>` for real classes the tests use.
- `workbench/`: a small blog app (users, teams routed by slug, posts; `BlogAssistant`, `TeamAssistant`; five actions) with its committed `actions.exposure.json` and `resources/js/agentic/actions.ts`.
- `config/agentic-actions.php`; `lang/{en,ar}` (a parity test covers both; a group of lines such as `oauth.abilities` is checked flattened); `resources/boost/` (the guideline, capped at 50 lines by a test, and the skill); `resources/views/consent.blade.php` (the OAuth consent page, loaded only while OAuth is on); `stubs/`; `database/migrations/` (published only on request, file by file: the conversations table under `agentic-actions-migrations`, the OAuth connections table under `agentic-actions-oauth-migrations`; never loaded).
- `bin/fresh-app plain|livewire` builds a new Laravel app under `build/` and walks the whole setup path against a copy of this working tree. `bin/stream-probe` (with `stream-probe-browser.mjs` and `stream-probe-stubs/`) checks streaming over Laravel Herd's nginx and PHP-FPM with headless Chrome; its header has the usage, and it runs `herd link` and `herd secure`.
- `docs/*.md` are the user docs, and also build agentic-actions.com (VitePress, `docs/.vitepress/config.mts`). `docs/site/` holds the pages only the site has, which render empty on GitHub: the home page, and Getting started, the Changelog and Contributing, which include the README's `getting-started` region, `CHANGELOG.md` and `CONTRIBUTING.md`; relative links in what they include resolve from the repository root, as on GitHub. `docs/site/concepts/` holds the visual concept pages (served at `/how-it-works/<page>`, so no folder shares a name with the reference page `/concepts`), whose diagrams come from the Vue components in `docs/.vitepress/theme/components`. A new docs page needs its sidebar entry in the config, and the nav's version is read from `js/package.json`.

## Commands

Run them from the repository root, with PHP 8.3+, Composer and Node 22 on the PATH.

```bash
composer install && npm ci               # setup; the client's toolchain is in the root package.json
composer check                           # Pint --test, PHPStan, Pest (serial)
npm --prefix js run check                # typecheck, type tests, build, runtime tests
npm --prefix docs ci && npm --prefix docs run docs:build   # the site; a dead link, #fragment or include fails it
composer lint                            # applies Pint's fixes, then PHPStan
vendor/bin/pest tests/Feature/Streaming/ConversationSlotTest.php   # one file
vendor/bin/pest --filter=ConversationSlot                          # by name
UPDATE_STREAM_FIXTURES=1 vendor/bin/pest tests/Workbench/StreamFixturesTest.php   # re-record the .sse fixtures
bin/fresh-app plain                      # about 45 seconds each; run both before a release
bin/fresh-app livewire
```

CI: `.github/workflows/push.yml` runs on every push and pull request (PHP 8.3 and 8.5 on Laravel 13, with laravel/ai and laravel/passport, and without either, and the npm client's check); `tags.yml` on a `v*` tag or by hand (the Laravel 12 floor on PHP 8.3 with `--prefer-lowest`, Laravel 12 latest and Laravel 13 on PHP 8.4, MySQL 8, Postgres 16 and MariaDB 11 running `--group=database`, both fresh-app modes, the npm client with and without React); `docs.yml` builds the site on a pull request or push that touches `docs/`, the README, the CHANGELOG, CONTRIBUTING or `js/package.json`, and deploys main to GitHub Pages. To run a cell locally, run it in a clone under `build/` (git-ignored), never in your checkout, whose `vendor/` it would change. A clone takes HEAD, so commit first, copy the cell's install line from the workflow, and delete `build/cells` afterwards. An npm cell is the exception: run it in a clone outside the checkout, such as `git clone --quiet . "$(mktemp -d)/no-react"`, because Node and TypeScript look for packages in every parent folder's `node_modules`, so under `build/` a clone still finds the checkout's React and a cell without React passes when it should fail:

```bash
git clone --quiet . build/cells/no-ai && cd build/cells/no-ai
composer update --prefer-stable --prefer-dist --no-interaction --no-progress --with="laravel/framework:^13.15"
composer remove --dev laravel/ai laravel/passport --no-interaction --no-progress
vendor/bin/pint --test && vendor/bin/pest --exclude-group=database --testsuite=Unit,Feature
```

The floor cell runs on PHP 8.3: `composer update --prefer-lowest --prefer-stable --prefer-dist --no-interaction --no-progress`, then `vendor/bin/pint --test && vendor/bin/pest --exclude-group=database`. The database cells run in the checkout, against a local MySQL 8, Postgres 16 and MariaDB 11. Create the database once on each (MariaDB listens on 3307 here, as in CI, so it runs beside MySQL), then run the group on each:

```bash
php -r 'new PDO("mysql:host=127.0.0.1", "root", "")->exec("create database if not exists agentic_actions_testing");'
php -r 'new PDO("mysql:host=127.0.0.1;port=3307", "root", "")->exec("create database if not exists agentic_actions_testing");'
php -r 'new PDO("pgsql:host=127.0.0.1;dbname=postgres", "postgres", "")->exec("create database agentic_actions_testing");'
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=agentic_actions_testing DB_USERNAME=root DB_PASSWORD= vendor/bin/pest --group=database
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=agentic_actions_testing DB_USERNAME=postgres DB_PASSWORD= vendor/bin/pest --group=database
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=agentic_actions_testing DB_USERNAME=root DB_PASSWORD= vendor/bin/pest --group=database
```

## Hard rules

- **Never push, create a remote, push a tag or publish** to Packagist or npm. The maintainers do that.
- **Own guarantees only.** Docs, docblocks, language lines, tests, fixtures, CI names and commit messages state this package's own guarantees, never another package's shortcomings. Code that exists only because of how another package behaves today carries one docblock line, `@upstream <one neutral sentence on what this package does>`, for example `@upstream A blank tool-call id is treated as absent.` `grep -rn '@upstream' src js/src` lists them, and each cites the pull request to that package once one is open.
- **No new dependencies**, Composer or npm, runtime or dev, without a maintainer's agreement. The npm root keeps zero dependencies (`builds.test.mjs`). laravel/passport is in `require-dev` and `suggest`, never in `require`.
- **Generic by default.** Every default fits a fresh `laravel new` app. Examples, fixtures, docs and the workbench use posts, teams, a blog assistant, `__()` and the word "tenant", never a real product's names or values.
- **Keep it simple; Laravel conventions first.** Use what Laravel, laravel/ai and laravel/mcp offer before building a mechanism, and add code only where it supports a feature. `/react` stays under 4,608 bytes gzipped (`size.test.mjs`). Count PHP lines with `find src -name '*.php' -exec cat {} + | grep -cvE '^\s*($|//|/\*|\*)'`, and `js/src` the same way with `'*.ts'`.
- **No absolute local paths** in tracked files, such as a home or temporary directory: name a sibling checkout relatively and a scratch directory as `<scratch>`.
- **Stage by path**, never `git add -A` or `git add .`. Other agents may work in the same checkout; if `git commit` fails on `index.lock`, wait and retry.
- **Commits** are conventional (`feat(console): …`, `fix(security): …`, `docs: …`, `test: …`, `chore(release): …`), with a body that says what changed and why. A commit an agent wrote ends with its `Co-Authored-By:` trailer.

## How a change is made

1. **Test first.** A behaviour change starts as a failing test; a security fix keeps its attack as a test under `tests/Feature/Security/`.
2. **Checks green.** `composer check` and `npm --prefix js run check` pass before every commit. A change to `js/src` commits the `js/dist` that check rebuilt, and a change that touches optional packages also passes the cell without them. A change to `docs/`, the README or the CHANGELOG also passes the site's build.
3. **Docs and CHANGELOG with the code.** A user-visible change updates `docs/` and adds a line under the CHANGELOG's Unreleased section; a change an app must act on also gets an Upgrading note.
4. **Release.** `chore(release): prepare X`: the CHANGELOG's Unreleased section becomes the version with its upgrade notes, `js/package.json` and its `js` entry in the root `package-lock.json` take the version without the "v", the README's pre-release note and install line follow, `composer.json`'s branch alias and SECURITY.md's supported line move with a new minor, and `js/dist` is rebuilt. Then the annotated tag (`vX.Y.Z-beta.N` for a beta), after `tags.yml` has passed on the commit.

## Testing conventions

Every new or changed test passes the test-audit skill's authoring gate (`.claude/skills/test-audit/SKILL.md`), and a sweep or pruning of tests follows its audit or campaign mode.

- Pest on Testbench, **serial**: several tests share the Testbench skeleton's `bootstrap/cache`, so `--parallel` collides.
- `Unit` and `Feature` extend `Tests\TestCase`; `Workbench` extends `WorkbenchTestCase`, which boots the workbench app. By default the tests run on Testbench's `testing` connection, SQLite in memory without foreign keys, so a cascade is asserted from the schema, or on a second in-memory SQLite connection with `foreign_key_constraints` on, migrated inside the test (`ConversationSlotTest` does both). The `database` group is what the MySQL, Postgres and MariaDB cells run; a case in it that needs foreign keys skips on SQLite.
- Two requests at once are simulated in one process: the second one runs from a `DB::listen()` callback at the moment it would interleave (after the first one's query, outside its savepoint), as `ConversationSlotTest`'s `openAgainstAnotherTurn()` does. A model `creating` listener runs inside the first one's savepoint, whose rollback also removes the second one's row.
- A test that needs laravel/ai calls `$this->skipUnlessAi()` first: the push cells run Unit and Feature without laravel/ai. Workbench tests always need it. A test that needs Passport calls `$this->skipUnlessPassport()`, or `$this->useOAuth()`, which skips too.
- `useOAuth($config, $packages, $routes)` reloads the application with OAuth on as an app turns it on: Passport's keys as PEM strings made once per process, an `api` guard on the `passport` driver named after Sanctum's in `mcp.middleware`, `Mcp::oauthRoutes()` and a `login` route (`tests/Fixtures/OAuth/OAuthRoutes`). Pass config through it, never set before it: the reload drops it. `Flow::teams()` is the OAuth tests' environment, and `tests/Fixtures/OAuth/Flow` runs a client's way through the flow (register, authorize, approve, token, refresh, MCP calls).
- Passport keeps state in statics that outlive a test's application: `TestCase::setUp()` resets `Passport::$scopes`, the token lifetimes and `Passport::$keyPath`. An `afterEach` never names a Passport class: it runs after a skip too, in the cell without Passport.
- Optional packages are read through `AgenticActions\Support\Packages` (`Missing`, `DevOnly`, `Installed`; `laravelAi()` also checks the Tool contract loads), never through `InstalledVersions` or `class_exists` directly. laravel/ai and inertia-laravel are dev requirements here, so Composer reports them `DevOnly`; `TestCase` binds them as `Installed`, and `$this->usePackages([...])` sets Missing or DevOnly for one test.
- Real classes, not mocks: never Mockery a package class; write a small class under `tests/Fixtures/<Module>`. laravel/ai's own fakes are fine where they fit (`BlogAssistant::fake()`, `FakeTextGateway`).
- A confirmation spans two requests. Tests that pause and resume install `tests/Fixtures/Approvals/ScriptedGateway` on the provider (`ScriptedGateway::install()`), never an agent `fake()`: laravel/ai resumes approvals against the provider's gateway, so an agent fake tests the pause only.
- Each laravel/ai member the package newly calls or overrides gets a pin in `tests/Feature/Ai/LaravelAiContractTest.php` or `tests/Feature/Streaming/StreamingContractTest.php`.
- The Testbench skeleton (`vendor/orchestra/testbench-core/laravel`) is shared. A test never leaves a route cache, a `.env` or a generated file in it: `route:cache` cases run in a child process with scratch cache paths, and `TestCase` never loads a `.env`. `vendor/bin/testbench` commands can leave a `.env` and caches there and do not match the test setup; `composer dump-autoload` purges the skeleton.
- `->not->toContain('a', 'b')` passes when either needle is missing, so it proves nothing: negate one needle per call. (`->not->toHaveKeys([...])` checks each key and is fine.)
- A negative test pins the refusal it names. Give the caller everything except the one thing under test, and assert that guard's own answer, so a different guard cannot refuse first and keep the test green.
- A surface that enters the pipeline through its own path keeps its own proof: the HTTP bridge admits through `Runner::admit()`, a queued run through the worker, so a test through `Runner::run()` does not cover them.
- PHPStan level 6, no baseline and no `@phpstan-ignore` in the tree: fix the type.
- `WorkbenchTest` holds the committed `workbench/actions.exposure.json` and TypeScript file current; a stale TypeScript file is rewritten and the test fails, so review the diff and commit it.

## Gotchas found so far

- **Scratch copies need their own `composer install`.** With `vendor/` symlinked from another checkout, the autoloader loads that checkout's `src/`, and Pest works out test class names from the wrong root: under a folder whose name starts with a digit it stops with `InvalidTestClassName`. A copy with its own install runs anywhere.
- **Database cells** need MySQL 8, Postgres 16 and MariaDB 11 running; nothing else in the suite needs a server.
- **Octane.** Per-request state is a `scoped()` binding, and a listener that reads it resolves it with `app()` when the event fires, so it reads the request's container under Octane too. `ActionsProtocol` echoes and flushes each streamed part itself.
- **Streaming** relies on `ActionsProtocol` sending `X-Accel-Buffering: no` and calling `ignore_user_abort(true)`, so rows arrive one tool at a time behind nginx and a closed tab still stores the turn (`bin/stream-probe` checks it).
- **Route names.** Two `Actions::routes()` groups under one name prefix share the feed route's name, which `route:cache` refuses; give each group its own prefix.
- **`bin/fresh-app` copies the working tree**, uncommitted changes included, while a cell's clone takes HEAD. It exports `PAO_DISABLE=true`, because laravel/pao in the new skeleton compacts console output when it detects an agent.
- **`composer archive` from a working copy** packs `vendor/` and `node_modules/`; build a dist from a clean clone.
- **Passport's authorization controller keeps the guard it was built with**, and Laravel caches a route's controller, so in a test that signs in several people on one application the controller answers for the first. `Flow::authorize()` calls `flushController()` on the route first.
- **A GET or HEAD with a JSON body.** Laravel's `input()` reads the JSON body of a GET or HEAD (Laravel answers HEAD on every GET route), while Passport's authorization server reads the query; `BindConsent` reads every method's parameters but a POST's from the query, and merges the forced `prompt` into the input, which is where Passport reads it.
- **A query parameter sent as a list** (`scope[]=…`) is an array: `(string)` on it raises a warning that answers 500. Read request strings with `is_string()`.
- **Two requests at once on one connection.** A second approval run from a `DB::listen()` callback lands inside the first one's transaction, so the first one's rollback takes the second's rows too (`OAuthAttacksTest`); only two database connections show the lock serialising them.
- **Long runs** (`composer check`, the fresh-app walk) can pass Composer's 300-second process timeout on a slow machine: set `COMPOSER_PROCESS_TIMEOUT=0`.
