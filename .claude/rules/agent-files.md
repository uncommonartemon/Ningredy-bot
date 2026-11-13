---
paths:
  - ".claude/**"
  - "AGENTS.md"
---

# The agent's own files

`AGENTS.md`, the rules in `.claude/rules/` and the skills in `.claude/skills/` are how the next session knows what this one learned. They are in a public repository and are read by every agent that opens it.

## Which file for what

- `AGENTS.md`: what holds for every task. Keep it under 200 lines; it loads into every session.
- A rule: what holds while working in one part of the code. It names its paths in the frontmatter and loads only when a matching file is opened. A rule without paths costs context in every session; the guard test refuses one.
- A skill: a procedure with steps, done on request or when its description fits. Its scripts carry the repeated work, so each session does not write them again.
- Something true only on one machine, or about one person, belongs to none of these. It stays in the private memory.

## Writing them

- Rules and skills are written in English, like the code comments; `AGENTS.md` is in Russian, like the documents the operator reads.
- Say why, not only what. A reader who knows the reason handles the case the text did not foresee.
- A skill's description decides whether it gets used: say what it does and name the situations and phrases that should bring it up. Keep `SKILL.md` under 500 lines and put long material into `references/`.
- When a mistake repeats, write down the rule that would have prevented it, where the next session will meet it: next to the code as a rule, or in the skill that covers the procedure.

## Scripts in skills

- A script finds the project from its own location (`dirname(__DIR__, 4)`), and the bot folder as the main worktree of the repository. No path of anybody's computer.
- Logins, passwords, chat ids and addresses come from the environment or arguments, never from the file.
- Scratch files go to `storage/app/agent/` of the worktree, which git ignores. Never copy the bot's database anywhere.
- A script that reads says so in its first line and writes nothing. A script that could reach the bot or a paid model refuses in the cases it must not run.
- Put `use` lines at the top of a PHP script, before any code. An import applies only to the code below it, and the formatter shortens class names above.
- Run a new script once in a mode that costs nothing before relying on it.

## The guard

`tests/Feature/RepositoryHygieneTest.php` runs in CI and checks these files: no quoted correspondence, nothing private to a machine or person, every skill well formed with the scripts it names present, every rule scoped to paths that exist. Extend it when a new kind of thing must stay out.
