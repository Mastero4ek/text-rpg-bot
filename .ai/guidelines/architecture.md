# Architecture

## Application surfaces

- **Telegram bot** (`app/Telegram/`) — player-facing UI via `irazasyed/telegram-bot-sdk`.
  - Dev bot: long polling (`php artisan telegram:poll`) when `TELEGRAM_WEBHOOK_URL` is empty.
  - Prod bot: webhook `POST /telegram/webhook` when `TELEGRAM_WEBHOOK_URL` is set. `telegram:poll` must refuse to start. Secret token + Telegram IP allowlist.
  - `TELEGRAM_ASYNC=true` only in production (Redis queue → `ProcessTelegramUpdateJob`). Local: sync.
- **Filament admin** (`app/Filament/`, panel `admin` at `/admin`) — one super-admin, **view-only** characters/inventory; CRUD каталогов equipment/gems; live `fights` — List/View + force-clear (без Create/Edit). `/` redirects to `/admin`.
- No public landing, no Mini App in MVP, no platform widget API.

## Layer structure

Request flow: Telegram handler / Filament Resource -> Service -> Action -> Model

- **Telegram handlers** (`app/Telegram/Handlers/`) — map updates/callbacks to services; no business mutations inline. Poll and webhook share one Update processor.
- **HTTP controllers** (`app/Http/Controllers/`) — thin: webhook entry. No domain logic.
- **Services** (`app/Services/`) — orchestration; may call several actions.
- **Actions** (`app/Actions/`) — one class per state change (`handle()`). Game-rule failures return an `ActionResult` DTO (`ok`, `error?` via `__()`, `character?`); do not throw for expected “cannot do that”.
- **Queries** (`app/Queries/`) — read-side builders.
- **Models** (`app/Models/`) — Eloquent relationships only, no domain logic.
- **Enums** (`app/Enums/`) — onboarding steps, stats, combat zones, etc.
- **Quests** (`app/Quest/`) — one class per quest.
- **Game balance** — JSON in `resources/configs/`, not PHP arrays for now. UI strings in `lang/ru/` (no i18n codegen).

## Actions

- Every DB write, queued job dispatch, or outbound Telegram side-effect that mutates game state goes through an Action.
- One class per operation: `{Entity}{Verb}Action`, work in `handle()`, dependencies via constructor, call site: `app(SomeAction::class)->handle(...)`.
- Do **not** introduce `Action -> Command -> Handler` triples.
- Reads belong in Queries/Services; orchestration order belongs in Services.

## Directory conventions

| What | Where |
|------|-------|
| Telegram handlers / keyboards | `app/Telegram/` |
| Services | `app/Services/{Domain}/{Entity}Service.php` |
| Actions | `app/Actions/{Entity}/{Entity}{Verb}Action.php` |
| Queries | `app/Queries/{Domain}/{Entity}Query.php` |
| Quests | `app/Quest/` |
| Enums | `app/Enums/{Domain}/{Entity}Enum.php` (`Combat/`, `Fight/`, `Equipment/`, `Gem/`, `Economy/`); корневые `OnboardingStepEnum`, `StatKeyEnum` |
| Filament admin | `app/Filament/Resources/...` |
| Support / SDK glue | `app/Support/Telegram/` |
| Game config JSON | `resources/configs/` |
| UI translations | `lang/ru/` |

## Data

- `users` — Filament operators only (one seeded super-admin).
- `characters` — players, PK `tg_id`.
- `inventories` — includes `item_name` snapshot; FK `tg_id` → `characters`.
- `fights` — one active fight per character (`tg_id` PK); session columns, not a single `data` blob. Enemy snapshot and combat `log` (already-rendered RU strings) are JSON columns.
- Nickname uniqueness: stored as typed; SQLite unique index on `LOWER(username)`.
- HP regen: `last_hp_update` timestamp (Carbon), not a unix int.
- Fight state never lives in HTTP/Telegram session.

## Background processing

- Production: Redis queue + `ProcessTelegramUpdateJob` when `TELEGRAM_ASYNC=true`.
- Local/Herd: `QUEUE_CONNECTION=sync`, `CACHE_STORE=file`. No Redis required.
- No callback rate-limit in MVP.

## Developer tooling

- PHP ^8.4, Pint, Larastan (level 5), Rector, Laravel Boost, Pest (Unit + Feature), Debugbar (`require-dev`).
- **No Telescope**, no Pest browser/agent, no Playwright.
- Local: Laravel Herd. Validation before push: `composer check-parallel`.
- CI: GitHub Actions (remote not created yet). Integration branch: `develop`.

## Document map

- `.ai/guidelines/` — always-on agent rules; `.ai/rules/` — path-scoped rules.

## Out of scope

Roadmap stages 1–6 (equipment slots, gold/VIP, PvP, clans, mass fights, world, …). Do not extend the schema for them.
