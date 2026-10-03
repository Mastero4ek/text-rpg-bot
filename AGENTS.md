<laravel-boost-guidelines>
=== .ai/architecture rules ===

# Architecture

## Application surfaces

- **Telegram bot** (`app/Telegram/`) — player-facing UI via `irazasyed/telegram-bot-sdk`.
  - Dev bot: long polling (`php artisan telegram:poll`) when `TELEGRAM_WEBHOOK_URL` is empty.
  - Prod bot: webhook `POST /telegram/webhook` when `TELEGRAM_WEBHOOK_URL` is set. `telegram:poll` must refuse to start. Secret token + Telegram IP allowlist.
  - `TELEGRAM_ASYNC=true` only in production (Redis queue → `ProcessTelegramUpdateJob`). Local: sync.
- **Filament admin** (`app/Filament/`, panel `admin` at `/admin`) — one super-admin, **view-only** characters, inventory, fights. `/` redirects to `/admin`.
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
- **Game balance** — JSON in `resources/game/`, not PHP arrays for now. UI strings in `lang/ru/` (no i18n codegen).

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
| Enums | `app/Enums/{Entity}Enum.php` or `app/Enums/{Domain}/` |
| Filament admin | `app/Filament/Resources/...` |
| Support / SDK glue | `app/Support/Telegram/` |
| Game config JSON | `resources/game/` |
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

Roadmap stages 1–6 (equipment slots, Stars, PvP, clans, mass fights, world, …). Do not extend the schema for them.

=== .ai/code-style rules ===

# Project Code Style

Rules specific to this codebase. These override generic conventions.

## Functions

1. One function per action, two at most. A function doing more than two things is split.
2. Don't add default values to function arguments - a potential source of error.
3. Don't use non-strict comparison operators like `==`. Code should be written more strictly.
4. If the expression on the left already returns bool, do not compare it with `true` or `false` via `===` / `!==`. Use `if ($flag)` or `if (! $flag)`.
5. A project function should never `return null;` - throw an exception instead. (Framework overrides that must return `null` are exempt.)
6. A function should never return nothing (a bare `return;` used as "no result") - throw an exception instead. `void` functions that simply end are fine.
7. A function should never `return "";` - throw an exception instead.
8. If a function is missing something that is required, throw an exception.
9. No boolean parameters (flag arguments) in project functions - split them into two explicit functions. Calling framework or Filament APIs that take booleans is fine.

## Actions

1. Call actions through the container: `app(SomeAction::class)->handle(...)`, never `new SomeAction()`.
2. One class per operation: the work happens in `handle()` itself, dependencies come through the constructor. Do not add a `Command` / `Handler` pair around it.

## Interfaces

1. No `app/Contracts` / `app/Interfaces` folder. An interface lives next to its implementations, suffix `Contract`.
2. Write one only when something already calls several implementations through it. Not one per class for testability.

## Naming

1. Name functions and variables in terms of this project's business logic.
2. Forbidden words in function and variable names: `resolve`, `normalize`, `build`.
3. Instead of `temporary` in variables and methods, use the abbreviation `tmp`.

## Style preferences

These apply to project code in `app/` (Services, Actions, Controllers, Models), NOT where the framework requires closures: Filament schemas/tables, migrations, config callbacks.

- Don't use callbacks in your own project logic (prefer `foreach` over `collect()->map()->filter()` chains).
- Avoid creating additional methods. Prefer linear code over premature extraction.
- Avoid the ternary operator where possible; use `if...else`.
- Avoid the `??` operator; use `if...else`.

## Laravel-specific

1. Saving anything to a session or cookie goes through a dedicated service.
2. For each table in the migration, use only one `Schema::table` section.
3. Empty array checks use `empty()`: `if (empty($list)) {}`.
4. All function names in `app/helpers.php` are `snake_case`.

## PHP-specific

1. A function that does not use the internal object state of its class must be `static`.
2. Method order in a class: `public`, `protected`, `private`; static methods precede non-static methods within the same visibility, and methods within each group are alphabetical.

=== .ai/commits rules ===

# Commit & Pull Request Guidelines

- Short imperative commit messages in English (e.g., `Add character store action`). Group related changes; avoid formatting-only noise in feature commits.
- Branch names: `fix/{slug}`, `feature/{slug}`, `refactoring/{slug}`, or `docs/{slug}`. Default integration branch: `develop` (create when the GitHub remote exists). Do not push directly to `develop` or `master`/`main`. PRs on GitHub Actions.
- Before opening a PR: sync with the target branch, run `composer check-parallel`, fix failures.
- PR description in Russian when stakeholders need it: Зачем / Что сделано / Проверка (+ Риски when relevant).
- Do not commit `.env`, `database/database.sqlite` with player data, or secrets.

=== .ai/laravel rules ===

## General code instructions

- Don't generate code comments above the methods or code blocks if they are obvious. Don't add docblock comments when defining variables, unless instructed to. Generate comments only for something that needs extra explanation.
- For new features, you MUST generate Pest automated tests. Tests MUST cover both happy paths and failure scenarios: invalid input, unauthorized access, and boundary conditions.
- Prefer Laravel Boost docs tools for package APIs when available.

---

## Laravel instructions

- Always use PHP Enums where possible instead of hardcoded string values, if Enum class exists: in DB migrations, Pest tests and elsewhere.
- Never chain multiple migration-creating commands (e.g., `make:model -m`, `make:migration`) with `&&` or `;` - they may get identical timestamps. Run each command separately and wait for completion before running the next.
- Game balance lives in `resources/game/*.json`; UI copy in `lang/ru/`. Do not hardcode player-facing Russian strings in Services/Actions.
- Players are `characters` (PK `tg_id`). Filament operators are `users`.
- Fight state lives in the `fights` table (session columns + JSON enemy/log), not in HTTP session and not as a single `data` blob.
- Expected game-rule failures return an `ActionResult` DTO; do not throw for those.

=== .ai/testing rules ===

# Testing & Formatting Guidelines

This project has **Unit + Feature only** (no Pest browser E2e / Playwright).

## Validation before completing a task

Always run `composer check-parallel` as the final validation step. Do not push or mark a task complete until it passes. It runs `rector` -> `pint` -> `pest --parallel` -> `phpstan analyse --memory-limit=2G`.

## Commands

- `composer check-parallel` / `composer check` - full pipeline
- `composer test` - Unit + Feature in parallel
- `composer format` - Pint only; `composer format-all` - Rector + Pint
- `composer analyse` - PHPStan / Larastan only

## How to write tests

1. **Suite:** `Unit` - pure logic without DB/HTTP/container where practical. `Feature` - actions, services with DB, webhook HTTP, Filament Livewire.
2. **Placement:** mirror the subject (`tests/Feature/Actions/...`, `tests/Unit/Services/Combat/...`). Basename must be unique under `tests/`.
3. **Data:** factories and (later) `App\Testing\Scenario\*`.
4. **Combat RNG:** inject a deterministic `RandomSource`; never rely on live `mt_rand` in tests.
5. Cover happy path and key failures (insufficient gold, wrong onboarding step, etc.). No coverage percentage gate.
6. **Telegram:** fake Update JSON + `Http::fake()` outbound. Never call the live Bot API in CI. Poll and webhook share one processor; do not run long polling in tests.

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
