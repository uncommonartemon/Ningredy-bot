---
paths:
  - "app/Services/Products/**"
  - "app/Services/ProductSearch/**"
  - "app/Jobs/**"
  - "scripts/*.mjs"
---

# Product and photo search

This is the oldest and most tuned part of the code. Read `docs/failure-modes.md` before changing it: most rules here exist because of a named live failure.

## How to change it

- A fix is general. No special case by host or domain name; if one site needs it, find the class of sites.
- Prefer giving an agent a tool and letting it decide over a hand-written heuristic. Rules learned from examples are data (`image_rendition_rules`), not code.
- When the operator accepts what a rule forbade, delete the rule and all its code. No fallback branch around it.
- A strict rule needs a last resort. Zero photos is a failure; a card with photos that is not fully reconciled is not.

## How to judge a change

- Success: the search ends, and it ends with photos. A hang or zero photos is the only failure.
- Judge a rule about a model's verdict by the model's raw answers next to the server's verdict, not by the final card. Replay real answers from `ai_runs`; a test that fakes the answer can keep a branch green that the live model never enters.
- When a call fails on one machine only, compare every database-derived input of the request first (schema enums built from categories, instructions built from tables), then the prompt, key and code.
- After a live run, open every page the search visited and compare with the journal. A mismatch is a fix candidate.

## Time

- The floor of a search is research plus card assembly, about two minutes. A slow search is many shops in a row.
- Do not add a model call per page without measuring what it costs across a search; `read-a-search` has the script that sums time per stage.

## Identity of parts

- A part is proven by the code on its own label, read by a reader that was not told the wanted code.
- A long code read with two neighbouring characters swapped is the same code (`ReadingSlip`); a code with or without a short tail is the same part at page level (`PartNumberSuffix`). Both hold only for long codes, so `i5-13400F` never becomes `i5-12400F`.
- A box is an ordinary photo. A series name on a box is not a marking.

## Live runs

- No real-model searches without the operator's word: bots may share one API key.
- A request for a product that already has a pending draft only shows that draft; a rerun must ask to search again.
