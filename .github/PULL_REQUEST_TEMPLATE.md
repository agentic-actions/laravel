<!-- What does this change, and why? Link the issue it fixes. -->

## Checklist

See [CONTRIBUTING.md](https://github.com/agentic-actions/laravel/blob/main/CONTRIBUTING.md) for each step.

- [ ] A test that fails before the change and passes after it (a security fix keeps its attack in `tests/Feature/Security`)
- [ ] `composer check` passes
- [ ] The npm client's check passes, and the rebuilt `js/dist` is committed (when `js/` changed)
- [ ] The cell without laravel/ai and laravel/passport passes (when the change touches either)
- [ ] The `database` group passes on MySQL, Postgres and MariaDB (when the change touches SQL)
- [ ] `bin/fresh-app plain` and `bin/fresh-app livewire` pass (when the change touches the setup path)
- [ ] `docs/` is updated, and the site builds (when users can see the change)
- [ ] A line under `## Unreleased` in `CHANGELOG.md`, with an Upgrading note when apps must act
