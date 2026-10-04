> Баланс игры. JSON в этой папке читает `App\Services\Game\GameConfig` (character/enemies/onboarding/settings). Каталог экипа — таблица `equipment` (`ShopCatalog`); initial seed — `EquipmentSeeder`. Каталог камней — таблица `gems` (`GemCatalog`); initial seed — `GemSeeder`. Глобалки: `settings.json` → `gems` / `combat` / `repair` / `wear` / `vipRepair` (см. `docs/COMBAT.md`, `docs/GEMS.md`).

## Каталог снаряжения (этап 1.0)

Source of truth — таблица / модель **`Equipment`** + Filament CRUD.

Админ в `/admin` (Resource **Equipment**) создаёт / правит / смотрит запись: тип, слот, класс оружия, цена, урон/бонусы, МФ, effect, req, прочность, gem slots, vip/shop flags. Картинки — **`spatie/laravel-medialibrary`** (коллекция `image`).

**Telegram:** art не показываем; бот берёт текстовые статы из каталога БД.

## Каталог камней

Source of truth — таблица / модель **`Gem`** + Filament CRUD (`GemResource`). Типы — `GemTypeEnum` (рубины/изумруды/сапфиры/алмазы). Инстансы с прочностью — `characters.gem_pouch` / `inventories.socketed_gems`.
