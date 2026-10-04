---
paths:
    - "app/Filament/**"
---

# Filament (admin)

- Panel: `/admin`, single User from `AdminUserSeeder` (`.env` ADMIN\_\*).
- MVP resources are **read-only**: `canCreate` / `canEdit` / `canDelete` → `false`. No Create/Edit pages.
- Exception (roadmap **1.0**): full **CRUD каталога снаряжения** (`Equipment` resource) — Create / Edit / View / Archive / Delete, все поля баланса + art через **`spatie/laravel-medialibrary`** (`HasMedia`, коллекция `image`). Telegram без отправки art до отдельного подэтапа.
- Structure: `Resources/{Plural}/{Entity}Resource.php` + `Pages/` + `Schemas/*Form.php` + `Tables/`. Shared: `app/Filament/Concerns/`.
- Labels / actions / hints: `lang/ru/admin.php` (`labels.*`, `models.*`, `navigation.*`, `actions.*`, `hints.*`, `sections.*`). Не хардкодить RU-строки в Table/Form/Pages.
- Глобальный CSS в `AdminPanelProvider` (`PanelsRenderHook::HEAD_END`): hint-icon `flex-grow:0`; текст всех `.fi-btn` → `#ffffff` (light/dark); `.fi-ta-appearance-placeholder` (empty appearance circle).
- Tests: `tests/Feature/Filament/*` via `pest-plugin-livewire` (`livewire(...)`).

## Soft delete = архив

- Soft delete → UI **Архивировать**; ForceDelete → **Удалить**; Restore → **Восстановить**.
- ForceDelete только на уже архивной записи и если нет ссылок в inventory (`$record->trashed() && ! $record->isReferencedByInventory()` / `EquipmentResource::canForceDelete`).
- Copy: `admin.actions.archive|delete|restore|archive_bulk|trashed_filter|view|edit|clone`.

## Pages — канон

Эталон: `Equipment/Pages/{List,Create,Edit,View}Equipment.php`.

### List (`ListRecords`)

- Header: `CreateAction::make()` (у write-ресурсов).

### Create (`CreateRecord`)

- `use HasFormActionsBetween`.
- `protected static bool $canCreateAnother = false`.
- Без кастомного `getFormActions()` — всё в трейте.

### Edit (`EditRecord`)

- `use HasFormActionsBetween`.
- Header **без** View: Архивировать / Восстановить / Удалить (порядок: restore перед force-delete).
- Архивировать = `DeleteAction` + labels из `admin.actions.archive.*`, `->color('warning')`, **без** иконки на header-кнопке.
- Удалить = `ForceDeleteAction` + `admin.actions.delete.*`, `->visible` только если `trashed()` и нет inventory refs.
- Восстановить = `RestoreAction` + `admin.actions.restore.*`.
- Модалки: `modalHeading` / `modalSubmitActionLabel` / `successNotificationTitle` из тех же ключей.

### View (`ViewRecord`)

- Та же Form, что create/edit (`ViewRecord` сам `->disabled()`). **Не** определять `infolist()` у write-ресурсов.
- Header: `Клонировать` (gray) → `EditAction`. Клон через trait `HasCloneToCreate` (`getCloneAction()`).
- Отдельный Infolist — только у read-only ресурсов (Characters / Fights / Inventories).

### Clone → Create

- Встроенный Filament `ReplicateAction` **не** используем: он сразу `replicate()->save()` в БД.
- Trait `App\Filament\Concerns\HasCloneToCreate`:
  - View/Edit: `getCloneAction()` — кладёт attributes в session (без PK/timestamps/`deleted_at`; override `getCloneExcludedAttributes()`), редирект на `create`.
  - Create: после `parent::mount()` → `fillFormFromClone()` (session `pull`).
- Вкл/выкл: подключить trait + вызвать методы на страницах. Media (Spatie) не копируется.

### Form actions (Create + Edit)

- Trait `App\Filament\Concerns\HasFormActionsBetween`:
  - `getFormActionsAlignment()` → `Alignment::Between`
  - порядок: Cancel слева, Create/Save справа

## Tables — канон

Эталон: `app/Filament/Resources/Equipment/Tables/EquipmentTable.php`.
Новые/правки List-таблиц копируют этот паттерн (не Characters/Fights/Inventories, пока их не подтянут).

### Каркас

- Class `final`, `public static function configure(Table $table): Table`.
- `defaultSort(...)` на осмысленной колонке.
- Eager-load через `modifyQueryUsing` (`with(...)`), без N+1.
- Labels только `__('admin.labels.*')`.

### Колонки

- Первая колонка Equipment: trait `HasAppearanceColumn` → `AppearanceImageColumn` / `appearanceColumn()` (label `admin.labels.appearance` = «Вид»; `collection('image')`, circular, `imageSize(40)`; empty → `.fi-ta-appearance-placeholder` в `AdminPanelProvider` CSS: light `rgb(249 250 251)`, dark `#2e2e31` + camera icon; `sortable` по `exists` media collection `image`). Eager `with('media')`.
- Длинный текст: `limit(...)` + `tooltip(fn (Model $record): string => ...)` + `placeholder('-')`.
- Nullable / пустые: всегда `placeholder('-')`.
- Числа / флаги / даты / enum-badge: `alignCenter()` где уместно.
- Enum / статус: `->badge()`; цвета **не** через `->color()` в таблице — enum реализует `Filament\Support\Contracts\HasColor` (`getColor()` → `danger|warning|success|info|gray|primary` или `Color::*` palette).
- Bool: `IconColumn::make(...)->boolean()` (+ `alignCenter()`, `sortable()`).
- Даты в списке: `dateTime('d.m.Y')` + `sinceTooltip()` (не полный datetime в ячейке).
- `searchable()` / `sortable()` — на колонках, по которым реально ищут/сортируют.

### Фильтры

- Enum: `SelectFilter::make(...)->options(SomeEnum::class)`.
- Bool: `TernaryFilter`.
- SoftDeletes: `TrashedFilter` с copy архива:
  - `->label(__('admin.actions.trashed_filter.label'))`
  - `->placeholder(...without)` / `->trueLabel(...with)` / `->falseLabel(...only)`
- Все select/ternary/trashed: `->native(false)`.
- Label обычных фильтров = тот же `__('admin.labels.*')`, что у колонки.

### Row actions

- Иконки без текста: `->label('')` + **обязательный** `->tooltip(__('admin.actions.*.label'))`.
- Цвета через `Filament\Support\Colors\Color` (не строковые `'primary'`):
    - View → `Color::Teal` + `heroicon-o-eye` + tooltip `admin.actions.view`
    - Edit → `Color::Sky` + `heroicon-o-pencil` + tooltip `admin.actions.edit`
    - Archive (soft delete) → `Color::Amber` + `Heroicon::OutlinedArchiveBox` + tooltip/modals `admin.actions.archive`
    - Restore → `Color::Green` + `heroicon-o-arrow-uturn-left` + `admin.actions.restore`
    - Delete (force) → `Color::Red` + `heroicon-o-trash` + `admin.actions.delete`; `visible` если `trashed()` и нет inventory refs
- Bulk: `BulkActionGroup` + `DeleteBulkAction` как **архив** (`admin.actions.archive_bulk`, amber, archive icon) — только у write-ресурсов.

### Enums для UI

- Badge/filter enums: `HasColor` + `HasLabel` (RU через `lang/ru/…`, не сырой `WEAPON`).
- Не дублировать color/label-map в Table — Filament подхватывает с enum при cast на модели.

## Form — канон

Эталон: `EquipmentForm` (create / edit / view).

- Schema: `->columns(1)`.
- Каждая `Section`: `->columnSpanFull()` + collapsible + `->icon(Heroicon::Outlined…)`. Первая ключевая (identity) — `->collapsible()` открыта; остальные — `->collapsed()`.
- Identity: две `Group` в `->columns(2)` — слева название/тип+профиль+слот (ряд `columns(3)`)/флаги `in_shop`+`enabled` (ряд `columns(2)`), справа art/description. `item_id` — `Hidden`, автоген по profile на create (`Equipment::nextItemIdForProfile`); не в таблице и не в UI формы. В форме не показываем: `sort_order` (БД + defaultSort); нет `vip_only` / `effect_type` / `allowed_gem_types` / `admin_note` / `tier` (VIP-витрина = `currency=GOLD`; лечение = profile `HEAL` + `effect_value`; камни — любой type в `gem_slots`). Novice/стартовая броня/оружие тренера — хардкод в `ShopCatalog`. Поиск по name в List также матчит `item_id`.
- Requirements: `req_level` / `req_strength` / `req_agility` / `req_instinct` / `req_vitality` (`columns(5)`).
- `item_type` → `live()`: фильтрует options `slot` / `profile` через `forType`; при смене типа **сбрасывает** profile+slot (без автоподстановки дефолтов). Оружие: каталожный слот только `RIGHT_HAND` + 6 классов `KNUCKLES`/`KNIFE`/`AXE`/`HAMMER`/`CLUB`/`SWORD` (**без** `SPEAR`/`TWO_HAND`; LH для ножа/кастета — runtime через `equipToSlot`); броня: HELMET/ARMOR/PANTS/BOOTS/GLOVES/SHIELD + MOBILE/HEAVY/WARD; украшение: AMULET/RING_1/RING_2 + FOCUS/CHARM/VITAL; зелье: POCKET + `HEAL`. Слот и профиль disabled пока тип не выбран.
- Зонная `armor` только HELMET/ARMOR/PANTS/BOOTS (GLOVES/SHIELD → 0, поле скрыто).
- Характеристики (`equipment_combat`): секция `visible` только когда выбран `item_type`. Поля по типу: WEAPON → `weapon_damage_min`+`weapon_damage_max`+MF; ARMOR → `stat_bonus`+MF (+`armor` на зонных слотах); JEWELRY → `stat_bonus`+MF; POTION → `effect_value` (профиль HEAL). При смене типа лишние статы **сбрасываются в пусто** (`null`), не в `0`.
- Create: **без** `->default(...)` на полях формы (пусто до ввода админа). NOT NULL инты при save → `0` в `Equipment::saving`.
- Экономика: только `currency`+`price` (без default).
- Прочность: `max_durability` / износ / `repair_tier`; `gem_slots` только WEAPON/ARMOR; `repairable` на следующей строке (без default).
- Поля create/edit: `->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.*'))` — на view иконка скрыта (`operation === 'view'` → `null`).
- В остальных секциях поля гридить `->columns(2)` (или явную сетку секции); сами секции всегда одна колонка, не side-by-side.
