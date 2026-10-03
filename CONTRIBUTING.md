# Contributing

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

The documentation site, agentic-actions.com, is built from `docs/*.md`, the README and the changelog. A change to any of them also passes the site's build, which fails on a dead link, a missing `#fragment` or an include it cannot find:

```bash
npm --prefix docs ci
npm --prefix docs run docs:build   # or docs:dev to preview
```

`bin/fresh-app plain` and `bin/fresh-app livewire` create a brand-new Laravel application under `build/`, install the package from this checkout, and walk the whole setup path: the route lines, `make:agentic-action`, `actions:check --update`, the Blade form, a Sanctum token, `actions:run`, the TypeScript file and laravel/ai. Run them before a release.

## Conventions

- PHP follows laravel/ai's style: a one-sentence docblock on every method, array shapes in `@param` and `@return`, curly braces always, typed parameters and returns, constructor promotion, and inline comments only where the logic is not obvious. Classes are `final` unless they are meant to be extended.
- Tests use Pest, Testbench, the real HTTP kernel and laravel/ai's own fakes. Do not mock the package's own classes: when a test needs another implementation of a package contract, write a small real class under `tests/Fixtures`. Tests that need laravel/ai call `$this->skipUnlessAi()`, so the suite also runs without it.
- Examples, fixtures and docs stay generic: posts, teams, a blog assistant, `__()` for sentences, and "tenant" for whatever an app calls its tenants.
- `js/dist` is committed, because apps can also install the npm client from `vendor/agentic-actions/laravel/js`; npm publishes the same `dist`. Rebuild it with `npm --prefix js run build` before a release commit.
- Stage files by path. Do not use `git add -A` or `git add .`.
- Code that exists only because of how another package behaves today carries one docblock line, `@upstream {reason}`, where the reason is a single neutral sentence about what this package does (for example `@upstream A blank tool-call id is treated as absent.`), never how the other package behaves wrongly. Each marker cites the pull request to that package that would make it unnecessary, once one is open.
