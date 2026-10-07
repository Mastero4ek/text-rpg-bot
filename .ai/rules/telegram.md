# Telegram

## Transport / handlers

- Entry: `UpdateProcessor` (poll + webhook share it).
- Outbound API: `TelegramClient` via Laravel `Http` (fakeable).
- Handlers map updates to Services/Actions; no domain mutations inline beyond orchestration already in Services/Quests.
- Callback prefixes: `ob:` `menu:` `inv:` `bag:` `gear:` `stat:` `shop:` `smith:` `fight:` `city:` `portal:`.
- Handlers — плоско в `app/Telegram/Handlers/` (`RegistrationHandler`, `OnboardingHandler`, `CityHandler`, …), без подпапок по флоу. Services/Actions по-прежнему в `Registration/` / `Onboarding/` при ≥2 файлах.
- Keyboards: `CityKeyboard`, `RegistrationKeyboard`, остальное — `TelegramKeyboards`; общий helper — trait `BuildsInlineKeyboard`.
- Registration: `RegistrationHandler` + `Services/Registration/` + `Actions/Registration/` (якорь `characters.tg_*`). После города → `ARRIVED` + `sendHomePanel` (первый дом; онбординг не авто). City hub: `CityHandler` / `CityMenuService` — [`docs/telegram/CITY.md`](../../docs/telegram/CITY.md). Onboarding: `OnboardingHandler` + `Services/Onboarding/`. Mid-reg side-входы (`menu`/`city`/`shop`/`fight`/чужой `ob:*`) → `showNudge`, не `reply` сухим hint.
- Prod webhook: `VerifyTelegramWebhookSecret` + `VerifyTelegramIp` (IP skipped in local/testing).
- `TELEGRAM_ASYNC=true` → `ProcessTelegramUpdateJob`; local sync.
- `telegram:poll` refuses when `TELEGRAM_WEBHOOK_URL` is set.

## Player-facing copy (обязательно)

Канон флоу / механики — `docs/telegram/` (`REGISTRATION.md`, `CITY.md`, `ONBOARDING.md`).  
Перед правкой поведения: прочитать соответствующий файл и соседние экраны того же флоу.

Стиль сообщений:

- 2-е лицо («ты»), атмосфера мира; короткий системный сухой тон — только если так явно в каноне флоу.
- Одна непрерывная история на флоу: каждый экран опирается на предыдущий. Не противоречить уже сказанному (где герой, есть ли имя/город).
- Ошибки — в том же голосе, что соседний экран, плюс ясный next action.
- Строки игроку — в `lang/ru/` (`__()`), не хардкод в Services/Actions/Handlers.
- Канон-флоу из `docs/telegram/` (registration, city, …) — **только** `lang/ru/telegram.php` → `telegram.{flow}.*` (экраны + `btn_*`). В доках — ключи и поведение, **не** полные абзацы.
- Сейчас: `telegram.registration.*`, `telegram.city.*`, `telegram.commands.*` (описания Bot Menu). Новый ТГ-флоу — новый ключ верхнего уровня в том же файле, не отдельный `lang/ru/{flow}.php`.
- Bot Menu (`setMyCommands`): `/character` `/skills` `/backpack` `/bag` — sync через `BotCommandsSync` / `telegram:commands` (и при webhook set / poll start).
- Вне канон-флоу пока: `onboarding` / `menu` (инв/профиль UI) / `combat` / `shop` / `errors` / …

Регистрация ≠ онбординг (`docs/telegram/REGISTRATION.md` / `ONBOARDING.md`): разные handler/service; общие шаги пока в `ProgressStepEnum`.

## Formatting (HTML)

Все исходящие тексты (`TelegramClient::sendMessage` / `editMessageText`) уходят с `parse_mode=HTML`.

- В `lang/ru/` для бота — Telegram HTML: `<b>`, `<i>`, `<u>`, `<s>`, `<code>`, `<pre>`, `<blockquote>`, `<a href="...">`, `<tg-spoiler>`. Не Markdown. Речь NPC — в `<blockquote>` (полоска + кавычки в клиенте).
- Динамику (`username`, города, предметы, имена в логе боя и т.п.), подставляемую в текст сообщения, **всегда** экранировать через `TelegramHtml::escape()` **до** `__()` / склейки. Иначе `&` / `<` / `>` ломают parse или дают 400 от Telegram.
- Рандомные варианты ошибки/копирайта — массив строк в lang, показ через `LangVariant::pick(...)` (registration: `telegram.registration.errors.*`; не Markdown-списки в одном ключе).
- Кнопки (`callback` label) parse_mode не получают — HTML-теги в текстах кнопок не использовать.
- Не экранировать целиком уже собранное сообщение с тегами — только сырые значения.
