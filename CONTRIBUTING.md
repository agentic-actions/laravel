# Contributing

Bug reports, fixes and docs improvements are welcome. Report a bug or ask for a feature in [GitHub Issues](https://github.com/agentic-actions/laravel/issues/new/choose), and report a security problem privately, as [SECURITY.md](SECURITY.md) describes. Pull requests go against `main`, from a fork.

## Local setup

You need PHP 8.3 or later, Composer 2 and Node 22.3 or later.

```bash
composer install
npm --prefix js ci
```

The package is tested with [Orchestra Testbench](https://packages.tools/testbench) against the real framework, Sanctum and laravel/ai 1.0. `workbench/` holds a small blog application that the Workbench test suite and `vendor/bin/testbench` use.

## Checks

Run both before you open a pull request:

```bash
composer check             # pint --test, phpstan, pest
npm --prefix js run check  # typecheck, type tests, runtime tests
```

`composer lint` applies Pint's fixes and runs PHPStan.

Run one file, or the tests whose names match:

```bash
vendor/bin/pest tests/Feature/Streaming/ConversationSlotTest.php
vendor/bin/pest --filter=ConversationSlot
```

The suite runs serially. Several tests share the Testbench skeleton's `bootstrap/cache`, so `--parallel` fails tests that pass on their own.

The documentation site, agentic-actions.com, is built from `docs/*.md`, the README, the changelog and this file. A change to any of them also passes the site's build, which fails on a dead link, a missing `#fragment` or an include it cannot find:

```bash
npm --prefix docs ci
npm --prefix docs run docs:build   # or docs:dev to preview
```

`bin/fresh-app plain` and `bin/fresh-app livewire` create a brand-new Laravel application under `build/`, install the package from this checkout, and walk the whole setup path: the route lines, `make:agentic-action`, `actions:check --update`, the Blade form, a Sanctum token, `actions:run`, the TypeScript file and laravel/ai. Run them before a release.

## Making a change

1. **Start with a failing test.** A bug fix starts as a test that fails on the code before the fix, for the reason the bug gives, and passes after it. A security fix keeps its attack as a test in `tests/Feature/Security/` (the `*AttacksTest.php` files). A new or changed test answers the four questions of the [authoring gate](.claude/skills/test-audit/SKILL.md#authoring-gate).
2. **Keep the checks green.** Both checks above pass before every commit. A change that touches laravel/ai or Passport also passes [the cell without them](#without-laravelai-and-passport), one that touches SQL passes [the database group](#the-database-group), and one that touches the setup path passes the `bin/fresh-app` walks ([Releases](#releases) says why).
3. **Update the docs and the changelog with the code.** A change a user can see updates `docs/` and adds a line under `## Unreleased` at the top of `CHANGELOG.md`, in the section that fits (`### Added`, `### Changed`, `### Fixed` or `### Documentation`). Create the heading when it is missing. A change an app must act on also gets a line under `### Upgrading`.
4. **Commit in the conventional format**, such as `fix(security): …`, `feat(console): …`, `docs: …` or `test: …`, with a body that says what changed and why.
5. **Open the pull request against `main`.** The template lists what to check.

A pull request that changes `js/src` commits the `js/dist` that the npm client's check rebuilt, because an app that installs the client from `vendor/agentic-actions/laravel/js` reads `dist` from the branch it installs. Version numbers, tags and the release notes are the maintainers' part: see [Releases](#releases).

## Tests

`tests/Unit` and `tests/Feature` extend `Tests\TestCase`, which runs on Testbench with SQLite in memory. `tests/Feature` is grouped by module, as `src/` is; `tests/Feature/Security` holds the attacks of each security review, `Integration` the cases that cross modules, and `Seams04` and `Seams05` the contracts that confirmations and asking the person rely on. `tests/Workbench` runs end to end on the workbench app (`WorkbenchTestCase`). `tests/Fixtures/<Module>` holds the small real classes that tests use in place of mocks.

### Without laravel/ai and Passport

Every push and pull request runs the tests on PHP 8.3 and 8.5 twice: every suite with laravel/ai and laravel/passport, then Unit and Feature with both removed. A test that needs laravel/ai calls `$this->skipUnlessAi()` first, and one that needs Passport calls `$this->skipUnlessPassport()`, or `$this->useOAuth()`, which skips too. Workbench tests always need laravel/ai, which is why that cell leaves them out.

To run that cell, clone into `build/` (git-ignored), so your own `vendor/` stays as it is. The clone takes `HEAD`, so commit first, and delete `build/cells` afterwards:

```bash
git clone --quiet . build/cells/no-ai && cd build/cells/no-ai
composer update --prefer-stable --prefer-dist --no-interaction --no-progress --with="laravel/framework:^13.15"
composer remove --dev laravel/ai laravel/passport --no-interaction --no-progress
vendor/bin/pint --test && vendor/bin/pest --exclude-group=database --testsuite=Unit,Feature
```

### The database group

The cases that behave differently on a database server, such as the Read guard, datasets, conversation slots and OAuth connections, are in the `database` group. A tag runs the group on MySQL 8, Postgres 16 and MariaDB 11; a pull request does not. When your change touches SQL, create an empty `agentic_actions_testing` database on each server and run the group against it, with `DB_PORT` set to where each one listens:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=agentic_actions_testing DB_USERNAME=root DB_PASSWORD= vendor/bin/pest --group=database
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=agentic_actions_testing DB_USERNAME=postgres DB_PASSWORD= vendor/bin/pest --group=database
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=agentic_actions_testing DB_USERNAME=root DB_PASSWORD= vendor/bin/pest --group=database
```

### Trying a change by hand

The workbench app has users, teams routed by slug, posts and the actions in `workbench/app/Actions`. `workbench:build` creates its SQLite database, migrates it and seeds one user (id 1) who belongs to the team `acme`:

```bash
vendor/bin/testbench workbench:build
vendor/bin/testbench actions:list
vendor/bin/testbench actions:run create-post --as=1 title=Hello body=World
```

The app has no sign-in screen, so call it over HTTP with a token:

```bash
vendor/bin/testbench tinker --execute 'echo Workbench\App\Models\User::find(1)->createToken("dev", ["actions:write"])->plainTextToken;'
vendor/bin/testbench serve
curl -X POST http://127.0.0.1:8000/api/actions/create-post \
  -H 'Authorization: Bearer <token>' -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"title":"Hello","body":"World"}'
```

These commands leave a `.env`, a database and caches in the Testbench skeleton (`vendor/orchestra/testbench-core/laravel`), and the tests then read them: several fail for reasons that have nothing to do with your change. Purge the skeleton before you run the tests again:

```bash
vendor/bin/testbench package:purge-skeleton   # composer dump-autoload runs it too
```

## Docs pages

`docs/*.md` are the reference pages. They render on GitHub as well as on agentic-actions.com, so they hold plain Markdown only, with no Vue components or site-only syntax, and link to each other as relative `.md` paths. A new reference page also needs its sidebar entry in `docs/.vitepress/config.mts`, whose label is the page's H1, and a line in the README's Documentation list.

`docs/site/` holds the pages only the site has, which render empty on GitHub: the home page, and the pages that include a file from the repository's root. Getting started includes the README's `getting-started` region, and the Changelog and Contributing pages include `CHANGELOG.md` and this file. Relative links in an included file resolve from the repository root, as they do on GitHub. `docs/site/concepts/` holds the How it works pages, served at `/how-it-works/<page>` and drawn with the components in `docs/.vitepress/theme/components`.

The site's build, under [Checks](#checks), fails on a dead link, a missing `#fragment` or an include it cannot find.

## Releases

The maintainers release. A `chore(release): prepare X` commit turns `## Unreleased` into the version and moves the version lines, then an annotated `vX.Y.Z` tag publishes it, and Packagist and npm follow the tag. A pull request does not bump a version, edit those lines or push a tag.

A tag runs cells that a pull request does not: the Laravel 12 floor (`--prefer-lowest` on PHP 8.3), Laravel 12 latest, the database group, the npm client without React, and both `bin/fresh-app` walks. So when your change touches the setup path (`actions:install`, `make:agentic-action` and its stub, `actions:check`, `actions:typescript`, or the README's Getting started), run `bin/fresh-app plain` and `bin/fresh-app livewire` yourself, and when it touches SQL, run [the database group](#the-database-group).

## Conventions

- PHP follows laravel/ai's style: a one-sentence docblock on every method, array shapes in `@param` and `@return`, curly braces always, typed parameters and returns, constructor promotion, and inline comments only where the logic is not obvious. Classes are `final` unless they are meant to be extended.
- Tests use Pest, Testbench, the real HTTP kernel and laravel/ai's own fakes. Do not mock the package's own classes: when a test needs another implementation of a package contract, write a small real class under `tests/Fixtures`. Tests that need laravel/ai call `$this->skipUnlessAi()`, so Unit and Feature also run without it ([Tests](#tests) has the cell that checks it).
- Examples, fixtures and docs stay generic: posts, teams, a blog assistant, `__()` for sentences, and "tenant" for whatever an app calls its tenants.
- `js/dist` is committed, because apps can also install the npm client from `vendor/agentic-actions/laravel/js`; npm publishes the same `dist`. Rebuild it with `npm --prefix js run build` before a release commit.
- Stage files by path. Do not use `git add -A` or `git add .`.
- Code that exists only because of how another package behaves today carries one docblock line, `@upstream {reason}`, where the reason is a single neutral sentence about what this package does (for example `@upstream A blank tool-call id is treated as absent.`), never how the other package behaves wrongly. Each marker cites the pull request to that package that would make it unnecessary, once one is open.
- Docs, docblocks, language lines, tests, fixtures and commit messages state this package's own guarantees, never another package's shortcomings.
- No new Composer or npm dependency, runtime or dev, without a maintainer's agreement. The npm client has no runtime dependencies and its root entry imports nothing but its own modules (a test checks it), and laravel/passport stays in `require-dev` and `suggest`.
- Keep it simple: use what Laravel, laravel/ai and laravel/mcp offer before building a mechanism. `@agentic-actions/client/react` stays under 4,608 bytes gzipped, and a test checks it.
- No absolute local paths, such as a home or temporary directory, in tracked files.
- PHPStan runs at level 6 with no baseline and no `@phpstan-ignore`: fix the type instead.
- Every language line is in both `lang/en` and `lang/ar`, and a test checks they match.
- `WorkbenchTest` keeps the committed `workbench/actions.exposure.json` and `workbench/resources/js/agentic/actions.ts` current. When it fails because it rewrote one, review the diff and commit it.
