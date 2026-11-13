---
name: look-at-pages
description: See pages of this project with your own eyes in a real browser - screenshots of the admin panel or the storefront, a walk through every admin page, a check that a button or block is really where it should be. Use it after any change to the admin or the storefront, before telling the operator where to find something, when the operator says they do not see a feature, and whenever asked to run, open, look at or screenshot the app.
---

# Look at the pages

Tests prove that code runs. They do not show that a block is visible, that a heading is stale or that a button is where a person looks for it. Looking does. Every screen change in this project is looked at before it is reported.

There are two places to look. Pick by what you need to know.

| | The operator's site | Your own copy |
|---|---|---|
| What it is | the running bot's site | this worktree's code on another port with a scratch database |
| Answers | "what does the operator see right now?" | "does my unfinished change look right?" |
| You may | open pages, switch tabs | anything: press, save, delete |
| Needs | the bot running, the admin's login | nothing from the operator |

## The operator's site: read only

It is their working catalog. Open pages and take screenshots; do not press anything that writes. Buttons such as taking a product off sale act at once, without a confirmation.

```bash
LOOK_USER=<admin login> LOOK_PASS=<admin password> \
  node ${CLAUDE_SKILL_DIR}/scripts/look.mjs http://127.0.0.1:8000 /admin/products /admin/products/144/edit
```

- The login and password come from the operator in the conversation. Pass them in the environment for that one command; never write them into a file, a commit or a memory.
- `LOOK_FIND="text one|text two"` reports whether each text is on each page: the quick answer to "is the block there?".
- If nothing listens on the port, the bot is stopped. Say so; do not start it.

## Your own copy: for your eyes only

Use it to look at a change before it is anywhere else. It never touches the bot folder, its port or its database, and it is refused when started from the bot folder itself.

```bash
php ${CLAUDE_SKILL_DIR}/scripts/seed-copy.php        # a fresh scratch database with demo data; prints the sign-in
bash ${CLAUDE_SKILL_DIR}/scripts/serve-copy.sh       # run in the background; serves on 127.0.0.1:8123
LOOK_USER=admin LOOK_PASS=<printed password> LOOK_ALLOW_CLICKS=1 \
  node ${CLAUDE_SKILL_DIR}/scripts/look.mjs http://127.0.0.1:8123 /admin /admin/stock-units
```

- `LOOK_CLICK="text"` presses the first element with that text before the screenshot. It works only with `LOOK_ALLOW_CLICKS=1`, which is for the copy.
- Stop the server when the looking is done.
- Do not send the operator to the copy. It has a different password and demo data, and it is not their site; that has confused them before. What they should see goes to their own site with the `update-bot` skill. If a picture helps them, name the screenshot file.

## Walk the whole admin

Opens every page of the menu, every tab, the create page and the first record's edit page of every list. Reports pages that failed to open, server errors behind a page and errors in the browser's console. It only navigates, so it is safe on the operator's site too.

```bash
LOOK_USER=... LOOK_PASS=... node ${CLAUDE_SKILL_DIR}/scripts/walk-admin.mjs http://127.0.0.1:8123
```

A crashed tab of the test browser on a busy machine is retried once; a page that fails twice is a finding.

## Reading the result

- Screenshots land in `storage/app/agent/shots/` of the worktree, which git ignores. Open them with the Read tool and actually look: is the block there, is the number right, is anything cut off.
- A page can be correct in the test and wrong on the screen. Known kinds here: a count in a section heading that does not change after a button inside the section; a block hidden while a value is zero; a folded section nobody opens.
- Report what you saw, with the address. If you could not look, say that you did not.
