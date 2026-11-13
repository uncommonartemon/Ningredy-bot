---
paths:
  - "PROJECT_STRATEGY.md"
  - "README.md"
  - "AGENTS.md"
  - "docs/**"
---

# The written record

The repository is public and these files are read by people who were not in the conversation.

## What goes where

| File | Holds |
|---|---|
| `PROJECT_STRATEGY.md` | the rules of the product, each with the date it was decided |
| `docs/changelog-2026-10-25.md` | one table row per module: what changed, its commit, how to roll back, why, how it was checked |
| `docs/operator-memo.md` | what the person running the shop needs to know, in plain Russian |
| `docs/failure-modes.md` | what goes wrong, what guards against it, what hole remains |
| `README.md` | how the system is built, for the next developer |

## How to write them

- Write the decision, not who said what. "Решение от 2026-11-11: …" is the whole source. No quoted or retold speech from any correspondence, and no names.
- Describe what the program does now. History belongs to the changelog row and the commit.
- The operator memo uses the words on the buttons and no internal names.
- When a rule replaces an older one, say which rule it replaces and remove the old text; two rules that disagree are worse than none.

## How to edit them

- These files are long and partly in Cyrillic. Replace an exact piece of text once and check it was found once; `ship-module` has `scripts/patch.php` for that.
- Never split their text with a regular expression for line ends: in UTF-8 it can cut a Cyrillic letter in half. Split on the file's own line ending.
- Look at `git diff --stat` before committing: a documentation change touches a handful of lines, never a whole file.
