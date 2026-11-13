---
name: ship-module
description: Take a finished change in this repository all the way to a pull request - branch, full test run, commit, the written record, push, PR description, CI check. Use it whenever work here is ready to be committed, pushed, recorded in the strategy or changelog, or turned into a PR, and also when a pull request went red and has to be fixed on the right branch.
argument-hint: "[short name of the change]"
---

# Ship a module

A change here is called a module. It is one decision, one branch, one row in the changelog and one pull request. The operator reads the PR, not the code, and merges it (or tells the agent to).

## 1. Branch

- Start from `origin/main`, or from the last open branch when earlier pull requests are not merged yet. Stacked branches each include the ones below; merging the top one is enough.
- Name the branch after what changes, in two or three words (`sale-or-delete`, `first-item`).

## 2. Check before committing

1. `./vendor/bin/pint --dirty` — CI runs Pint first and a style issue fails the whole check.
2. The tests nearest to the change, with `--filter`.
3. The whole suite, `php artisan test`, in the background (8-15 minutes). Do not edit files of the worktree while it runs. A filtered run is not enough: older tests often expect the previous behaviour, and only the whole suite finds them.
4. `npm test` when anything under `desktop/` or `scripts/` changed, `npm run build` when anything under `resources/` changed.

One known exception: the real-curl tests of `SafeProductHttpTest` can time out while a bot runs on the same machine. Rerun them alone before calling it a failure.

## 3. Commit

- The title is a sentence about behaviour, in English, not a list of files: `A unit's number appears with its row; a supplier is picked from a list`.
- The body says why, then what changed, in plain sentences.
- State the decision and its date. Never the words of a conversation, quoted or retold: the repository is public (`AGENTS.md`).
- End with the attribution line the session provides.

Two commits per module: the change, then `Record module NN` with the documents, because the changelog row names the first commit's hash.

## 4. Record

Read `.claude/rules/records.md` for what belongs in which file. In short: the rule in `PROJECT_STRATEGY.md`, a row in `docs/changelog-2026-10-25.md` (next module number, the commit, how to roll back, why, how it was checked), what the operator needs in `docs/operator-memo.md`, a row in `docs/failure-modes.md` when a failure was closed, and `README.md` when the structure changed.

Edit these long files with exact replacements, never by rewriting them:

```bash
php ${CLAUDE_SKILL_DIR}/scripts/patch.php <definitions.php> --check   # every piece found exactly once?
php ${CLAUDE_SKILL_DIR}/scripts/patch.php <definitions.php>           # write
```

The definitions file returns a list of `[file, old text, new text]`; paths may be relative to the project root. Nothing is written unless every old text is found exactly once, and the file's own line ending is kept. Write the definitions file with the Write tool: PHP inside a shell heredoc breaks on quotes.

New admin strings need their three translations:

```bash
php ${CLAUDE_SKILL_DIR}/scripts/add-lang.php <definitions.php>
```

The definitions file returns `[russian string => [english, czech, ukrainian]]`. Only missing keys are added.

## 5. Push and open the pull request

- Push the branch; never push to `main`.
- Base the PR on `main`. Write the description in Russian for the operator, from `${CLAUDE_SKILL_DIR}/references/pr-body.md`.
- When earlier PRs of the stack are open, say in the first line which ones this one includes.

## 6. After the push

- Wait for CI (`gh pr checks <n> --watch`). Report its real state.
- If CI is red on an older test, fix that test on the lowest branch where the behaviour changed, push it, then merge that branch upward into each branch above and push those too.
- Merging is the operator's. Merge only on their direct word, with a merge commit, not a squash.
- Putting the new version on the operator's site is a separate step with its own skill (`update-bot`).

## What to tell the operator

Lead with what they can do now that they could not before, then what to press. Name the PR to merge. Say plainly what was not checked.
