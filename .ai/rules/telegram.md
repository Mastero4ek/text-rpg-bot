# Telegram handlers

- Entry: `UpdateProcessor` (poll + webhook share it).
- Outbound API: `TelegramClient` via Laravel `Http` (fakeable).
- Handlers map updates to Services/Actions; no domain mutations inline beyond orchestration already in Services/Quests.
- Callback prefixes: `ob:` `menu:` `inv:` `stat:` `shop:` `fight:`.
- Prod webhook: `VerifyTelegramWebhookSecret` + `VerifyTelegramIp` (IP skipped in local/testing).
- `TELEGRAM_ASYNC=true` → `ProcessTelegramUpdateJob`; local sync.
- `telegram:poll` refuses when `TELEGRAM_WEBHOOK_URL` is set.
