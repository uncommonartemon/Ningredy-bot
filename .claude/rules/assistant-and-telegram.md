---
paths:
  - "app/Ai/**"
  - "app/Services/DatabaseOperator/**"
  - "app/Services/Telegram/**"
---

# The assistant in Telegram

The operator runs the shop from the chat. The bot must be able to do anything with business data on request and must never send them to the admin panel for something a tool could do.

## A new capability is three things at once

1. The data: table or setting in `DatabaseOperatorService::catalog()` with its writable fields and validation.
2. The words: a short paragraph in `ServerAssistantAgent::instructions()` saying what the table means and which phrases map to which tool.
3. The guard: anything that must hold whoever writes (a form, a button, the chat) lives in the model or its service, not in the instructions. The model forgets; a `saving` hook does not. Example: a unit marked sold without a date is sold today (`StockUnit::saving`).

## Tools

- A dedicated tool beats the generic database tool when the action has a meaning of its own (take off sale, prepare deletion): it records an operation for the audit and returns what the bot should say.
- A tool returns facts the answer needs (`on_sale`, `in_stock`), so the model does not guess.
- Reversible actions run at once. Deletion always goes through a confirmation the application builds (`TelegramCatalogDeletion::confirmation()`), and the confirmation offers the reversible alternative.

## Texts

- A message names the real cause. A shared reason code gets a neutral, true text.
- Nothing is reported done before the tool returned `ok`.
- No questions or notes to the operator while a search runs; only "a restart is needed" may interrupt.
- A card of a product that is off sale says so and carries no link to a page that would not open.

## Buttons

- One keyboard is built in one place and reused; two copies drift apart.
- A handled press answers the callback and closes its update (`markCommandProcessed`), otherwise the update counts as hung.
- A product that no longer exists is answered in plain words.

## Checking

- Automated tests fake the model; they prove the wiring, not the understanding.
- Whether the real model picks the right tool for a phrase is checked with the `real-model-check` skill, on the operator's word. A faked answer can keep dead code green.
