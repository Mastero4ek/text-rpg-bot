# Text MMORPG Bot

## Stack

- PHP ^8.4, Laravel, Filament `/admin` (read-only)
- SQLite, Pest (`composer check-parallel`)
- Telegram: `irazasyed/telegram-bot-sdk` + Laravel `Http`
- Dev: long poll · Prod: webhook + secret + IP + Redis queue

## Local (Herd)

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
composer check-parallel

# TELEGRAM_BOT_TOKEN=…, TELEGRAM_WEBHOOK_URL пустой
composer dev
# или: php artisan telegram:poll
```

Админка: `/` → `/admin` (`ADMIN_EMAIL` / `ADMIN_PASSWORD`).

Домен: [`docs/`](docs/README.md) — персонаж, экипировка, рюкзак, сумка, мобы.

## Prod

```bash
# QUEUE_CONNECTION=redis, CACHE_STORE=redis
# TELEGRAM_WEBHOOK_URL=https://…/telegram/webhook
# TELEGRAM_WEBHOOK_SECRET=…, TELEGRAM_ASYNC=true
php artisan migrate --force
php artisan db:seed --class=AdminUserSeeder
php artisan telegram:webhook set
php artisan queue:work redis
```
