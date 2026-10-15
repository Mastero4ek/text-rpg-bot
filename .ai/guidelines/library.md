# Library sync (always-on)

`/library` (`LibraryHandler`, `lib:*`, `telegram.library.*`, `TelegramKeyboards::library*`) — player encyclopedia. It must stay aligned with the live game.

## When

After any feature / fix / copy or UX change that touches player-facing game knowledge, before marking the task done, run the checklist below.

Skip only when the change cannot affect encyclopedia content (pure refactor with same behavior, formatter-only, admin-only, tests-only, infra).

## Trigger surface (non-exhaustive)

- Locations, NPCs, enemies/creatures, minigames
- Combat / fight / PvP rules or turn UX
- Equipment, bag, potions, gems, sockets, stats
- Daily contracts / board / journal
- New player systems or removed/renamed ones
- Button labels, commands, or flows mentioned in library cards

## Checklist

1. **Drift** — Does any `telegram.library.*` card still describe old rules, wrong place, wrong `/command`, or dead UI?
2. **Missing section** — Is there a new player system that needs a home button + card (or list) under `/library`?
3. **Stale section** — Should a section/button/card be removed or renamed because the feature is gone or renamed?
4. **List entries** — Locations / enemies / NPCs / minigames orders and keys still match code + catalogs + arts?
5. **Canon** — Copy matches docs/settings/handlers; no balance-table spam; same voice as sibling library cards (`Как` / `Где` / `Важно` where used).
6. **Wiring** — `libraryHome` buttons, `LibraryHandler` callbacks, keyboards, `lang/ru/telegram.php`, and `.ai/rules/telegram.md` Library bullet stay in sync.

## How to act

- Clear drift → update library in the **same** change (handler/keyboard/lang + telegram.md bullet if structure changed).
- New section needed but UX unclear → implement only if the pattern is obvious (single card like Бой/Контракты, or list like Существа); otherwise state the gap in the reply and wait.
- Do not invent lore that contradicts quests/docs; encyclopedia is read-only teaching, not a second quest line.

## Canonical touchpoints

| What | Where |
|------|--------|
| Routing / screens | `app/Telegram/Handlers/LibraryHandler.php` |
| Keyboards | `TelegramKeyboards::library*` |
| Copy | `lang/ru/telegram.php` → `telegram.library.*` |
| Structure note | `.ai/rules/telegram.md` (Library UI) |
| Images | `config/bot.php` + `resources/images/telegram/` |
