# AGENTS.md

The instructions for coding agents in this repository are in [CLAUDE.md](CLAUDE.md). Read it in full before you change anything. It is the only copy: this file just points to it, so the two cannot drift apart.

These rules hold even before you open it:

- Never push, create a remote, push a tag or publish anything. The maintainers do that.
- Docs, docblocks, tests and commit messages state only this package's own guarantees, never another package's shortcomings. Code that exists because of how another package behaves carries one neutral `@upstream` docblock line.
- Add no Composer or npm dependency without a maintainer's agreement.
- Stage files by path, never `git add -A` or `git add .`.
- `composer check` and `npm --prefix js run check` pass before every commit.
- A new or changed test passes the authoring gate in `.claude/skills/test-audit/SKILL.md`.
