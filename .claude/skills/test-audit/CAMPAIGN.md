# Test-pruning campaign

Campaign mode prunes one area's whole test surface: one module (`Streaming`, `OAuth`), or the whole suite in lanes. The value bar, retention bar, candidate evidence and validation in [SKILL.md](SKILL.md) apply to every lane. This file adds the order of work. Each step ends on its completion criterion; do not start the next step early.

## 1. Baseline

Record the area's test, fixture and production line counts and every test file's pass or fail state at a pinned commit, on the full `composer check` and on the cell without laravel/ai or Passport. Keep baseline failures in their own list: each is a possible product bug, not a stale test.

Done when every in-scope test file has a recorded baseline result.

## 2. Lanes and inventory

Split the surface into **lanes** along production owners, not folder names: SKILL.md's discovery lanes are the default split for the whole suite. Include the area's cases in `Seams*`, `Security`, `Integration`, `tests/Workbench`, `js/tests` and the fixtures they use.

Done when every test file and fixture the area owns belongs to exactly one lane.

## 3. Read-only ledger per lane

Give each lane to its own read-only agent. The agent reads every assigned test in full, including `with()` datasets, and the production owners with their entry points, callers, history and the CI cells that run them. Each test declaration goes into a written **ledger** with one mark. A dataset is one declaration unless its rows need different marks; then mark each row.

- `R`: retain, naming the contract and the bug it catches; a retained test that only moves to a better-named file stays `R` with the move noted;
- `F`: retain the contract but repair the assertion, such as a negative that passes when only one of several things is missing;
- `C`: consolidate, naming the owner that absorbs the assertion first: a sibling dataset row, a stronger boundary suite, or the shared owner;
- `D`: delete, naming the proof that remains, or why no contract exists.

Judge a test by its assertions, not its name.

Done when every declaration in the lane has a mark and an evidence line.

## 4. Layer plan per lane

Treat the ledger as input, not as the edit list. A second read-only pass, starting from the ledger, looks for the redundant **layer**: for example the same schema rule asserted through the `Runner`, a route, `actions:run`, an agent tool and `tools/call`, when one dataset at the shared pipeline step owns it and each surface needs one wiring case. Name the **keeper** suite for each contract. Prefer the real boundary (the HTTP kernel, a real JSON-RPC call, the workbench) over a fake collaborator. Correct any ledger errors this pass finds.

Done when each lane plan names its retired files, its keeper per contract, the assertions to carry into keepers, and the test-only seams unlocked.

## 5. Cutover

Edit lane by lane. Serialise changes to shared support (`tests/TestCase.php`, `tests/Fixtures`, the workbench) through one owner. With each lane, remove the test-only seams it unlocks. Update anything that counts tests or files (`CLAUDE.md`'s notes, a size test). Put durable test-ownership rules drawn from mistakes this campaign actually found into `CLAUDE.md`'s testing conventions.

Done when every lane plan is applied and each lane's keepers pass.

## 6. Preservation review

Before claiming completion, have independent reviewers compare deleted coverage against the keepers, one reviewer per boundary group. They look for contracts that lost their only proof, above all security decisions and upgrade notes, and for new assertions that cannot fail, such as a refusal row the production code never reaches.

For each restored contract, make one deliberate **mutation** of the production owner and confirm the keeper goes red. Then restore the source byte for byte.

Done when every reported gap is restored or rejected with source evidence, and every restored contract has a caught mutation.

## 7. Product defects

A baseline failure that survives into a keeper is a bug report. Fix it at its owner as a separate commit, with a **control** run that reverts the fix and shows the old behaviour. Record unrelated product discrepancies as follow-ups instead of fixing them in the campaign.

Done when each repaired defect has a failing control and a passing candidate.

## 8. Reconcile and hand off

Rerun `composer check`, `npm --prefix js run check`, the cell without laravel/ai or Passport, the database group when a lane touched it, and `bin/fresh-app plain`.

Hand off with the SKILL.md report, plus:

- baseline and final test and fixture line counts, with production counted separately;
- lanes, retired layers and keepers;
- preservation gaps found and their mutations;
- product defects with control and candidate proof.
