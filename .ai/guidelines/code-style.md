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
4. Backed enum string values are `UPPERCASE` (e.g. `case HEAD = 'HEAD'`). `StatKeyEnum` values are uppercase too; use `column()` for DB attribute names (`strength`, …).

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
