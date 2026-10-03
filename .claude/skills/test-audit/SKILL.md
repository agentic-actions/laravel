---
name: test-audit
description: "Invoke whenever writing, changing, reviewing, or sweeping tests in this package (Pest under tests/, node tests under js/tests/). Authoring gate for new tests plus the audit workflow for low-value, implementation-coupled, or duplicative tests and the test-only seams they keep alive."
---

# Test Audit

Adapted from OpenClaw's `test-audit` skill (MIT, see [LICENSE.md](LICENSE.md)) for this package: Pest on Testbench, the workbench app, the npm client's node tests, and the CI cells. Read `CLAUDE.md` first; its testing conventions and rules apply throughout.

Three modes, one value bar. **Authoring mode** gates every new or changed test at write time. **Audit mode** runs focused sweeps of tests that re-assert source, duplicate stronger proof, couple behaviour to implementation, or keep test-only seams alive. **Campaign mode** prunes one whole area's test surface (every test a module, or the whole suite, owns); before starting one, read [CAMPAIGN.md](CAMPAIGN.md). Optimise for confidence, not deletion count.

## Authoring gate

Before adding any test, answer four questions; a missing answer means do not add it yet:

1. What observable behaviour, invariant, or independent contract does it protect?
2. What credible regression makes it fail?
3. Why does existing coverage not already catch that failure? Each contract has one primary test owner at the strongest boundary; another layer needs its own distinct risk, such as a transport or lifecycle failure the owner cannot reach. Prefer another row in a `with()` dataset or a shared fixture over a near-duplicate test; consolidate duplicated setup in the same change.
4. Does it need a seam (a public method, a flag, a static reset, an injection hook) that no production caller needs? If yes, move the test to the real boundary instead.

Then check the test against every [junk pattern](#junk-patterns); a match fails the gate unless the [retention bar](#retention-bar) names the contract it independently guards. A test that would break under a behaviour-preserving refactor is asserting implementation, not behaviour; rewrite it at the owning boundary before landing it.

A bug regression test must fail on the pre-fix code for the intended reason and pass after the fix at the owner. A regression test that never demonstrably failed proves the fake, not the fix. One regression at the owner boundary covers the bug; do not replay the same scenario at every surface it crosses (web, CLI, agent, MCP, queue) unless a surface has its own path for it.

## Junk patterns

The shared checklist for both modes: the authoring gate rejects a new test that matches one, and audits hunt for existing tests that do.

- assertion-free coverage probes (a test that only runs code, or asserts `true`);
- self-comparisons and identity copies;
- copied fixtures, inventories, manifests or export lists (a hand-written copy of what `actions:list`, the manifest or the generated TypeScript already produces);
- exact source, import or string greps;
- private predicate or call-shape tests duplicated at a real boundary (the `Runner`, a route, `tools/call`, `actions:run`);
- the same contract asserted once per surface when the surfaces share one pipeline step;
- expected values produced by the helper or renderer under test;
- fakes that implement the asserted behaviour, or one fake standing in for different APIs;
- fixtures that supply the ordering, claim or row the owner should produce, or persistence asserted against a store the path never writes;
- tests that restate a declared attribute, config default or enum case instead of exercising what it promises;
- negative controls that pass for an unrelated reason, such as a refusal from a different guard (a missing token ability instead of the tenant check under test) or a rejection the production path never reaches;
- names or datasets that promise more than the input exercises;
- tests whose only purpose is keeping a test-only seam, static or wrapper alive, and dead production code whose only callers are tests.

## Value bar

Tests justify their maintenance cost by protecting behaviour, a credible regression, or an independently meaningful contract. In an audit, an existing test that must change for a behaviour-preserving reorganisation is suspect, not automatically deletable; the authoring gate still rejects new ones.

Before judging a candidate, read the complete test and its production owner, the entry point, callers, callees, sibling implementations, overlapping tests, which CI cell runs it (with or without laravel/ai and Passport, the database group, the workbench), and its history (`git log -L` or `git log --follow`). When the test claims dependency-backed behaviour, read the dependency's source under `vendor/` directly.

## Discovery

Keep discovery read-only and report evidence before editing. For a broad scope, run parallel read-only lanes along the package's owners:

- the pipeline and its rules: `Runner`, `Pipeline`, `Schema`, `Security`, `Tenancy`, `Exposure`, `Discovery` (`tests/Unit`, `tests/Feature/Core`, `Discovery`, `Http`);
- the surfaces: `Console`, `TypeScript`, `Testing`, `Queue`, `Feed`;
- agents and the copilot: `Ai`, `Streaming`, `Approvals`, `Elicitation`;
- MCP and OAuth: `Mcp`, `OAuth`;
- cross-cutting: `Security/*AttacksTest.php`, `Seams*`, `Integration`, `tests/Workbench`, `js/tests`, and `tests/Fixtures`.

Outside campaign mode, prefer a few high-confidence candidates over a large speculative inventory.

## Retention bar

Keep a test when it independently enforces a public API, a config key or default, a migration, a storage shape, a security guarantee, the wire format (the copilot stream, MCP's JSON-RPC, the generated TypeScript), a documented upgrade note, or a dependency contract. In this package that always includes:

- the attacks under `tests/Feature/Security/` and `tests/Workbench/*Attacks*`: each is the proof of a security decision; consolidate them only into a stronger attack that fails on the same mutation;
- the pins in `LaravelAiContractTest` and `StreamingContractTest`: each guards one laravel/ai member the package calls or overrides;
- one test per documented upgrade note and per `actions:check` row;
- the cases that need the MySQL and Postgres cells (`database` group), and the tests that prove behaviour without laravel/ai or Passport.

Also keep:

- call ordering when order is observable behaviour (authorize before validation, a claim before the tool runs);
- regressions with a credible failure mode;
- source inspection when it is the cheapest independent guard: it fails when the contract changes (the user-facing key, byte or path) and survives an identifier-only refactor;
- a retained test that fails on the baseline: treat it as a possible product bug, reproduce it, and fix the owner rather than deleting it.

Static or slow is not a deletion reason. A test that resembles implementation may still be the independent contract; prove otherwise before removing it.

## Candidate evidence

Record every field below before editing. A missing field means the candidate is not ready for deletion:

- exact test name and location;
- what failure it can actually detect;
- non-test callers of the covered production code or test-support seam;
- the stronger proof that remains at the owner boundary, or why no proof is needed;
- relevant history and the reason the test or seam exists (a commit, a review finding, an attack);
- production or test-support code the deletion unlocks;
- risk, and the focused command that validates it.

## Edit shape

Choose one coherent owner-boundary batch. Delete obsolete test-only seams and dead production paths instead of keeping aliases. Move retained regressions to their canonical owners. Consolidate repeated per-surface assertions into one dataset at the shared boundary.

Prefer net-negative lines. Do not add replacement tests that restate the same implementation, and do not turn uncertain candidates into cleanup to raise the deletion count.

## Validation

Never edit source or tests while Pest is running in the same checkout.

1. Run the smallest owner and sibling tests: `vendor/bin/pest <path>` or `vendor/bin/pest --filter=<name>`; for the client, `npm --prefix js run check`.
2. For a removed grep or snapshot assertion, run the command that owns the real contract (`actions:check`, `actions:typescript --check`, `bin/fresh-app`).
3. `composer check`, `npm --prefix js run check`, and the cell without laravel/ai or Passport when a touched test skips there (`CLAUDE.md`, Commands). Then `git diff --check`.
4. For each deleted test that guarded a decision, make one deliberate mutation of the production owner, confirm the keeper goes red, and restore the source byte for byte.
5. Inspect `git diff --numstat`; report production separately from tests and test support.

## Landing

Commit by path, one coherent batch per commit. Never push; the maintainers do.

## Handoff

Report:

- the removed low-value categories and why they existed;
- production simplifications;
- retained false positives and why they remain valuable;
- focused and full proof actually run, and the mutations caught;
- production versus test lines;
- named follow-ups.
