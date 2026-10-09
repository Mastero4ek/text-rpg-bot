# Telegram

## Transport / handlers

- Entry: `UpdateProcessor` (poll + webhook share it).
- Outbound API: `TelegramClient` via Laravel `Http` (fakeable). Пакет `irazasyed/telegram-bot-sdk` в composer **не** используется кодом (legacy dep).
- Handlers map updates to Services/Actions; no domain mutations inline beyond orchestration already in Services/Quests.
- Callback prefixes: `ob:` `menu:` `inv:` `bag:` `gear:` `stat:` `smith:` `fight:` `city:` `portal:`. Legacy alias: `backpack:` → `inv:` (и `menu:backpack*` → `menu:inv*`) в `InventoryHandler`.
- Handler routing: `InventoryHandler` (`inv:`/`bag:`/`gear:`/`menu:inv|bag|gear`), `SmithHandler` (`smith:`/`menu:smith`), `BlacksmithHandler` (`city:blacksmith*`), `HealerHandler` (`city:healer*`), `BuyerHandler` (`city:buyer*`), `MenuHandler` (profile/stats/commands), остальные — как раньше.
- Handlers — плоско в `app/Telegram/Handlers/` (`RegistrationHandler`, `OnboardingHandler`, `CityHandler`, …), без подпапок по флоу. Services/Actions по-прежнему в `Registration/` / `Onboarding/` / `Fight/` при ≥2 файлах.
- Keyboards: `CityKeyboard`, `RegistrationKeyboard`, остальное — `TelegramKeyboards`; общий helper — trait `BuildsInlineKeyboard`. Fight UI: **одно** photo + inline (`fightInlineForStep`): caption = `panelCaption` (статус + «Раунд N. Выбери…»); STANCE = стойка + зелья (не tutorial), ATTACK = `fight:atk:*`, DEFEND = `fight:def:*`. Callbacks → `FightHandler::handleCallback`.
- Fight panel: `FightPanelService` — одно сообщение (`tg_message_id`): `editMessageCaption`(+inline); fail → delete+resend. Ошибки хода (зелье и т.п.) — `showError`: caption = статус + ошибка вместо `stepPrompt`, без нового сообщения. Конец боя (зал/лес): edit caption win/lose + clear inline, затем `CityHandler::returnAfterFight` шлёт **новое** сообщение локации (лес/тренировка = `telegram.location.*`), отчёт боя остаётся. Tutorial: clear inline + reply stats/intro. Legacy `tg_log_message_id` при refresh/end удаляется. `tg_reply_kind` + `last_round`.
- City-ready guard: `TelegramPlayerGate::requireCityPlayer` (не копировать `requireDone` по handlers).
- Unknown callback → `answerCallbackQuery` (без silent spinner).
- Registration: `RegistrationHandler` + `Services/Registration/` + `Actions/Registration/` (якорь `characters.tg_*`). После города → `ARRIVED` + `sendHomePanel` (первый дом = `sendPhoto` `training_attendant.png`; онбординг не авто). City hub: `CityHandler` / `CityMenuService` — photo-панель + `editMessageCaption`; [`docs/telegram/CITY.md`](../../docs/telegram/CITY.md); ворота — [`docs/telegram/GATES.md`](../../docs/telegram/GATES.md); портал — [`docs/telegram/PORTAL.md`](../../docs/telegram/PORTAL.md); лес — [`docs/telegram/FOREST.md`](../../docs/telegram/FOREST.md); арена — [`docs/telegram/ARENA.md`](../../docs/telegram/ARENA.md); зал — [`docs/telegram/TRAINING.md`](../../docs/telegram/TRAINING.md); таверна — [`docs/telegram/TAVERN.md`](../../docs/telegram/TAVERN.md); лекарь — [`docs/telegram/HEALER.md`](../../docs/telegram/HEALER.md); кузнец — [`docs/telegram/BLACKSMITH.md`](../../docs/telegram/BLACKSMITH.md). Onboarding: `OnboardingHandler` + `Services/Onboarding/`. Mid-reg side-входы (`menu`/`city`/`shop`/`fight`/чужой `ob:*`) → `showNudge`, не `reply` сухим hint.
- Prod webhook: `VerifyTelegramWebhookSecret` + `VerifyTelegramIp` (IP skipped in local/testing).
- `TELEGRAM_ASYNC=true` → `ProcessTelegramUpdateJob`; local sync.
- `telegram:poll` refuses when `TELEGRAM_WEBHOOK_URL` is set.

## Player-facing copy (обязательно)

Канон флоу / механики — `docs/telegram/` (`REGISTRATION.md`, `CITY.md`, `GATES.md`, `PORTAL.md`, `FOREST.md`, `ARENA.md`, `TRAINING.md`, `TAVERN.md`, `HEALER.md`, `ONBOARDING.md`).  
Перед правкой поведения: прочитать соответствующий файл и соседние экраны того же флоу.

Стиль сообщений:

- 2-е лицо («ты»), атмосфера мира; короткий системный сухой тон — только если так явно в каноне флоу.
- Одна непрерывная история на флоу: каждый экран опирается на предыдущий. Не противоречить уже сказанному (где герой, есть ли имя/город).
- Ошибки — в том же голосе, что соседний экран, плюс ясный next action.
- Строки игроку — в `lang/ru/` (`__()`), не хардкод в Services/Actions/Handlers.
- Канон-флоу из `docs/telegram/` (registration, city, …) — **только** `lang/ru/telegram.php` → `telegram.{flow}.*` (экраны + `btn_*`). В доках — ключи и поведение, **не** полные абзацы.
- Сейчас: `telegram.registration.*` (+ `registration.error.*`), `telegram.city.*` (хаб), `telegram.location.*`, `telegram.npc.{overseer,healer,blacksmith,buyer}.*` (+ `npc.*.error.*` — нарративные отказы NPC), `telegram.btn.*`, `telegram.commands.*`. Новый ТГ-флоу — новый ключ верхнего уровня в том же файле, не отдельный `lang/ru/{flow}.php`.
- Bot Menu (`setMyCommands`): `/character` `/skills` `/backpack` `/bag` — sync через `BotCommandsSync` / `telegram:commands` (и при webhook set / poll start).
- Сухие game-rule отказы (`item_not_found`, `no_blacksmith`, …) — `lang/ru/errors.php`. Нарративные отказы канон-флоу / NPC — `telegram.*.error.*`.
- Вне канон-флоу пока: `onboarding` / `menu` (инв/профиль UI) / `combat` / `shop` (sell copy) / `errors` / …

Регистрация ≠ онбординг (`docs/telegram/REGISTRATION.md` / `ONBOARDING.md`): разные handler/service; общие шаги пока в `ProgressStepEnum`.

## Formatting (HTML)

Все исходящие тексты (`TelegramClient::sendMessage` / `editMessageText` / `sendPhoto` caption / `editMessageCaption`) уходят с `parse_mode=HTML`.

- В `lang/ru/` для бота — Telegram HTML: `<b>`, `<i>`, `<u>`, `<s>`, `<code>`, `<pre>`, `<blockquote>`, `<a href="...">`, `<tg-spoiler>`. Не Markdown.
- **Переносы строк в `lang/ru/*.php`:** если в строке есть `\n`, значение **обязательно** в двойных кавычках `"..."`. В одинарных `'...'` PHP не разворачивает `\n` — в Telegram уезжает литерал `\n`. Пример: `"…мысли.\n\nВыбери…"`, не `'…мысли.\n\nВыбери…'`.
- **Нарратив игроку (обязательно):** экранный текст, ошибки, подсказки, caption — обернуть в `<i>…</i>` (курсив по умолчанию). Касается `lang/ru/telegram.php`, `onboarding.php`, `errors.php` (игрок-facing), `combat.php` / `menu.php` / … где уходит в ТГ, и `cities.description` в админке/сидере.  
  Акцент внутри курсива — `<b>…</b>` (ник, имя города и т.п.).  
  Пример: `telegram.registration.splash`, `pick_city`, `telegram.npc.overseer.first_home` (нарративные куски).
- **Речь любого NPC (обязательно):** прямая речь — только в `<blockquote>…</blockquote>` (полоска + кавычки в клиенте Telegram). Касается экранов, отказов, офферов, реплик в `telegram.*` и `errors.*`, если говорит персонаж (Смотритель, лекарь, кузнец, скупщик, …).  
  Нарратив вокруг («он кивает», «отодвигает кружку») — в `<i>…</i>` **вне** blockquote (отдельные куски `<i>` до/после цитаты — ок).  
  **Запрещено** оформлять речь NPC кавычками `«…»` / `"…"` без `<blockquote>`. Пример канона: `telegram.npc.overseer.first_home`, `telegram.npc.overseer.skip`, `telegram.npc.healer.error.already_full`.
- Динамику (`username`, города, предметы, имена в логе боя и т.п.), подставляемую в текст сообщения, **всегда** экранировать через `TelegramHtml::escape()` **до** `__()` / склейки. Иначе `&` / `<` / `>` ломают parse или дают 400 от Telegram. Текст внутри `<blockquote>` / `<i>` / `<b>` тоже экранировать, если туда попадает динамика.
- Рандомные варианты ошибки/копирайта — массив строк в lang, показ через `LangVariant::pick(...)` (registration: `telegram.registration.error.*`; не Markdown-списки в одном ключе). Каждый вариант — тоже в `<i>…</i>`.
- Кнопки (`callback` label) parse_mode не получают — HTML-теги в текстах кнопок не использовать.
- Не экранировать целиком уже собранное сообщение с тегами — только сырые значения.

## Images (`resources/images/telegram/`)

Раскладка:

| Папка | Что |
|-------|-----|
| `city/{key}.png` | хаб города (Spatie media в `CitySeeder`) |
| `location/*.png` | экраны: gates/forest/portal/tavern/arena/quest_board + registration_* (`config/bot.php`) |
| `npc/*.png` | NPC-панели (`training_attendant` и т.п.) |
| `enemy/{catalog_id}.png` | арт моба (`EnemyCatalogSeeder` media) |

Перед подключением картинки в бот (`config/bot.php`, Spatie media, `sendPhoto` / `editMessageMedia`) — **проверить файл**, не класть «как вышло из Figma».

Канон рабочих артов:

- PNG, **RGB** (не RGBA/LA; «пустая» альфа 255 тоже риск — клиент ТГ иногда показывает крест при ок API)
- размер панели как у существующих: **512×384**
- без ancillary-чанков Figma (`sRGB` / `gAMA` / `tEXt` и т.п.) — plain PNG
- IDAT-чанки умеренные (ориентир ≤64 KiB каждый); один гигантский IDAT — перекодировать
- вес порядка сотен KB (как соседние файлы в той же папке), не мегабайты

Проверка перед wiring: `file`, режим цвета / размер (`sips` / Pillow), сравнить с уже рабочим артом в той же подпапке.  
Плохой экспорт → пересохранить в RGB PNG (`convert('RGB')` / экспорт без альфы), потом уже `config('bot.*_image')` / media.
