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
- Глобальный CSS в `AdminPanelProvider` (`PanelsRenderHook::HEAD_END`): hint-icon `flex-grow:0`; текст всех `.fi-btn` → `#ffffff` (light/dark).
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

- `use HasBetweenFormActions`.
- `protected static bool $canCreateAnother = false`.
- Без кастомного `getFormActions()` — всё в трейте.

### Edit (`EditRecord`)

- `use HasBetweenFormActions`.
- Header **без** View: Архивировать / Восстановить / Удалить (порядок: restore перед force-delete).
- Архивировать = `DeleteAction` + labels из `admin.actions.archive.*`, `->color('warning')`, **без** иконки на header-кнопке.
- Удалить = `ForceDeleteAction` + `admin.actions.delete.*`, `->visible` только если `trashed()` и нет inventory refs.
- Восстановить = `RestoreAction` + `admin.actions.restore.*`.
- Модалки: `modalHeading` / `modalSubmitActionLabel` / `successNotificationTitle` из тех же ключей.

### View (`ViewRecord`)

- Та же Form, что create/edit (`ViewRecord` сам `->disabled()`). **Не** определять `infolist()` у write-ресурсов.
- Header: `Клонировать` (gray) → `EditAction`. Клон через trait `CanCloneToCreate` (`getCloneAction()`).
- Отдельный Infolist — только у read-only ресурсов (Characters / Fights / Inventories).

### Clone → Create

- Встроенный Filament `ReplicateAction` **не** используем: он сразу `replicate()->save()` в БД.
- Trait `App\Filament\Concerns\CanCloneToCreate`:
  - View/Edit: `getCloneAction()` — кладёт attributes в session (без PK/timestamps/`deleted_at`; override `getCloneExcludedAttributes()`), редирект на `create`.
  - Create: после `parent::mount()` → `fillFormFromClone()` (session `pull`).
- Вкл/выкл: подключить trait + вызвать методы на страницах. Media (Spatie) не копируется.

### Form actions (Create + Edit)

- Trait `App\Filament\Concerns\HasBetweenFormActions`:
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
- Identity: две `Group` в `->columns(2)` — слева id/название/тип+профиль+слот (ряд `columns(3)`)/ранг/флаги `in_shop`+`enabled`+`vip_only` (ряд `columns(3)`), справа art/description. В форме не показываем: `sort_order` (БД + defaultSort); `stars_price` / `admin_note` / `req_vitality` нет. Novice/стартовая броня/оружие тренера — хардкод в `ShopCatalog`.
- `item_type` → `live()`: фильтрует `slot` / `profile` через `forType`. Оружие: RIGHT_HAND/LEFT_HAND + LIGHT/CLEAVE/CRUSH; броня: HELMET/ARMOR/BOOTS/SHIELD + MOBILE/HEAVY/WARD; украшение: AMULET/RING + FOCUS/CHARM/VITAL; зелье: POCKET/BELT + HEAL/STIM/GUARD. Слот и профиль disabled пока тип не выбран. Украшения в экипе/персонаже пока не надеваются (`armor_id`/`weapon_id` = null).
- Характеристики (`equipment_combat`): секция `visible` только когда выбран `item_type`. Поля по типу: WEAPON → `weapon_damage`+MF; ARMOR → `armor`+`stat_bonus`+MF; JEWELRY → `stat_bonus`+MF; POTION → `effect_type`+`effect_value`. Слот набор полей не меняет. Профиль зелья HEAL → авто `effect_type=HEAL_HP`. При смене типа лишние статы обнуляются.
- Экономика: только `currency`+`price`; `GOLD` → авто `vip_only=true`, `SILVER` → `vip_only=false`.
- Прочность: `max_durability` / износ / `gem_slots` / `repair_tier` (`columns(4)`); `repairable` на следующей строке (`columnStart(1)`).
- Поля create/edit: `->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.*'))` — на view иконка скрыта (`operation === 'view'` → `null`).
- В остальных секциях поля гридить `->columns(2)` (или явную сетку секции); сами секции всегда одна колонка, не side-by-side.
