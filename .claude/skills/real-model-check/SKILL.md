---
name: real-model-check
description: Check behaviour that only the real model can show - whether the Telegram assistant picks the right tool for an operator's phrase, whether a whole product search ends with photos, whether the running bot answers a real request. Use it after changing the assistant's instructions or tools, the search pipeline, or anything whose tests fake the model, and whenever the operator asks to test the bot for real or to send it a request.
argument-hint: "[chat | search | bot]"
---

# Check on the real model

Automated tests here fake every model. They prove that the wiring holds, not that the model understands. A faked answer can keep a branch green that the live model never enters; this has happened. What the model actually does with a phrase or a product is only seen by asking it.

## Before any run

- Real calls cost money and share an API key with other bots. Run them only on the operator's direct word in the current conversation. One run at a time, never a batch they did not ask for.
- Say what you are about to run and roughly what it costs.
- Do not edit files of the worktree while a run is in progress: classes load lazily and a half-new program gives nonsense.

## Three checks, cheapest first

### 1. Phrases through the assistant (about $0.02 a phrase, seconds each)

The real assistant model, a throwaway in-memory database with two drafts and one product, Telegram and web research faked. Nothing reaches a chat or the web.

```bash
php ${CLAUDE_SKILL_DIR}/scripts/chat-phrases.php shop          # the whole set
php ${CLAUDE_SKILL_DIR}/scripts/chat-phrases.php shop 7 8 10   # only these phrases
```

Sets live in `scripts/phrases/`:

| Set | Covers |
|---|---|
| `shop` | off sale and back, a repeat, deletion, stock units, suppliers, customer groups, a look back at a search, photos from a named page |
| `drafts` | showing and correcting drafts, more photos, when a paid search starts, changing a product |

For each phrase it prints the tools the model called with their arguments, the answer, what Telegram would have received, and at the end what the database holds. Read all three: the right tool with a wrong argument, or a right action with a misleading answer, are both findings.

Add a phrase to a set when a new tool or rule appears; add a set (a file returning a function that gets the fixture and returns `[text, expectation]` pairs) for a new area. Write phrases the way a person types in a chat, not the way the tool is named.

### 2. A whole search without the bot (about $0.05-0.30, 3-15 minutes)

Real model, real browser, real web; Telegram faked; its own database and photo folder in the worktree's `storage/app/agent/`. It reads the bot's search settings once, read-only, so it searches the way the bot does.

```bash
php ${CLAUDE_SKILL_DIR}/scripts/whole-search.php "Samsung 980 PRO 1TB MZ-V8P1T0BW"
php ${CLAUDE_SKILL_DIR}/scripts/whole-search.php "WD Blue SN570 WDS500G3B0C" --no-icecat
```

Run it in the background. It prints the draft, every photo with where it came from and why it was taken, what the operator would have been told, and the journal. Then open the photo files and look at them: a photo of the wrong part looks fine in a log.

### 3. One request through the running bot (the bot's own cost and time)

The request arrives as the operator's own message, so the card comes to their Telegram. Tell them first that a test card will arrive.

```bash
php ${CLAUDE_SKILL_DIR}/scripts/one-request.php "Kingston NV2 1TB SNV2S/1000G"
```

- It checks the version the bot folder is on, which may be older than the worktree. Say which version was tested.
- It sends nothing while the bot is busy and never starts the bot.
- Pick a product the catalog does not hold and that has no waiting draft, or the bot will rightly show the existing card instead of searching.

## Judging the result

- A search passes when it ends and ends with photos. A hang or zero photos fails. Nine photos out of ten, or a card that is not fully reconciled but has photos, passes.
- A phrase passes when the action taken is what a person would have meant. Check the database dump at the end, not only the tool names.
- What fails becomes a guard in the code where possible (a default in the model, a check in the tool), not one more sentence in the instructions. Then rerun the phrases that failed.

## Reporting

Give the numbers: how many phrases, which failed and how; the search's time, photo count and cost. Name what was fixed and rerun. Say which checks were not run.
