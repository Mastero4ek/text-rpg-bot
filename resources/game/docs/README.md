> Баланс игры. JSON в этой папке читает `App\Services\Game\GameConfig` (character/combat/enemies/onboarding). Каталог экипа — таблица `equipment` (`ShopCatalog`); initial seed — `EquipmentSeeder`.

## Каталог снаряжения (этап 1.0)

Source of truth — таблица / модель **`Equipment`** + Filament CRUD.

Админ в `/admin` (Resource **Equipment**) создаёт / правит / смотрит запись: тип, слот, класс оружия, цена, урон/бонусы, МФ, effect, req, прочность, gem slots, vip/shop flags. Картинки — **`spatie/laravel-medialibrary`** (коллекция `image`).

**Telegram:** art не показываем; бот берёт текстовые статы из каталога БД.
