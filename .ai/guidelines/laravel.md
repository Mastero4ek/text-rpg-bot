## General code instructions

- Don't generate code comments above the methods or code blocks if they are obvious. Don't add docblock comments when defining variables, unless instructed to. Generate comments only for something that needs extra explanation.
- For new features, you MUST generate Pest automated tests. Tests MUST cover both happy paths and failure scenarios: invalid input, unauthorized access, and boundary conditions.
- Prefer Laravel Boost docs tools for package APIs when available.

---

## Laravel instructions

- Always use PHP Enums where possible instead of hardcoded string values, if Enum class exists: in DB migrations, Pest tests and elsewhere.
- Never chain multiple migration-creating commands (e.g., `make:model -m`, `make:migration`) with `&&` or `;` - they may get identical timestamps. Run each command separately and wait for completion before running the next.
- Unreleased local alters (ещё не в shared/prod) — схлопывать в исходный `create_*` migration; отдельный `create_*` для новых таблиц оставлять. После схлопа — `migrate:fresh` локально.
- Game balance lives in `resources/configs/*.json`; UI copy in `lang/ru/`. Do not hardcode player-facing Russian strings in Services/Actions.
- Players are `characters` (PK `tg_id`). Filament operators are `users`.
- Fight state lives in the `fights` table (session columns + JSON enemy/log), not in HTTP session and not as a single `data` blob.
- Expected game-rule failures return an `ActionResult` DTO; do not throw for those.
