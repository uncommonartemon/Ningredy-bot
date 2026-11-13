---
name: read-a-search
description: Find out what the bot actually did in a product search and where it lost time or photos - model time by stage, the journal step by step, why each photo was taken or refused, a summary across the last searches. Use it when the operator says a search was slow, hung, found nothing, took a wrong photo or stopped early, when a bot on another machine failed, and before changing anything in the search pipeline.
argument-hint: "[request number | last]"
---

# Read a search

A search leaves a complete trace: every step in the journal, every model call with its answer, every photo with the reason it was kept. The answer to "why" is in that trace, not in the final card and not in a guess. Read it before forming a theory; first theories about a failed search have been wrong here more than once.

## The search on this machine

```bash
php ${CLAUDE_SKILL_DIR}/scripts/search-story.php last        # the last request
php ${CLAUDE_SKILL_DIR}/scripts/search-story.php 423         # a request by number
php ${CLAUDE_SKILL_DIR}/scripts/search-story.php --slow 20   # the last 20: model time by stage, summed
php ${CLAUDE_SKILL_DIR}/scripts/search-story.php 12 --scratch # a run of whole-search.php in the worktree
```

It reads the bot's database read-only and prints:

- the request, its status and how long it took;
- model time by stage: calls, seconds, the longest call;
- the journal: the most frequent steps, the longest silences and what followed them, then every step in order;
- the draft and each photo with its source and the reason it was kept.

## Reading it

Work down this list; stop at the first that explains the complaint.

1. **Did it end?** A request that is not finished and has no running call is a hang. A sudden gap in the journal with no closing lines usually means the bot was stopped: compare with when its processes started before calling it a hang.
2. **Where did the time go?** The floor is research plus card assembly, about two minutes. Above that, count sites: a slow search is many shops in a row, each costing an applicability check, a gallery look and a review. One stage with a long single call is a timeout; one stage with many calls is a loop.
3. **Why was a page refused?** Read the step's decision and message, then open the page yourself. Known causes: a code that differs only by a short tail, a page that showed a bot check, a quick check answered 403 before the browser tried.
4. **Why was a photo taken or refused?** Read the reason on the photo, then look at the picture. For parts, the code on the label decides; compare what the reader read with what was wanted, character by character.
5. **Why did it stop?** The stop reason on the draft and the last verdict of the watcher say whether it ran out of pages, of time or of budget, or decided there was nothing more to find.

To see a model's raw answer for a step, read `ai_runs.response` for the call of that stage and time. Judge a rule by those answers next to the server's verdict.

## The assistant can tell the same story

In the chat, the `ExplainSearch` tool answers "why are there no photos" from the same data. If the operator asks in the chat and the answer is poor, that tool is the thing to improve.

## A bot on another machine

Its database is not here. Ask for its diagnostic report: the `/report` command in its chat, or the button on the admin's state page. The archive holds the journal, the model calls with their answers, downsized photos, settings without secrets and a summary. When a call fails only there, compare what its database feeds into the request (categories, kinds, settings) before blaming the key or the network.

## After reading

- Open every page the search visited and compare with the journal. A page the journal misdescribes is a finding of its own.
- A cause that belongs to a class of sites or codes gets a general fix and a row in `docs/failure-modes.md`. One odd site gets a note, not a special case.
- Say what you found in the operator's terms: which page, which photo, how many minutes, and what will be different.
