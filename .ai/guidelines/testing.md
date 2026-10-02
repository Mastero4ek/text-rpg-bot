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
