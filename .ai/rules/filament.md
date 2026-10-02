---
paths:
  - "app/Filament/**"
---

# Filament (admin)

- Panel: `/admin`, single User from `AdminUserSeeder` (`.env` ADMIN_*).
- MVP resources are **read-only**: `canCreate` / `canEdit` / `canDelete` → `false`. No Create/Edit pages.
- Structure: `Resources/{Plural}/{Entity}Resource.php` + `Pages/` + `Schemas/*Infolist.php` + `Tables/`.
- Labels: `lang/ru/admin.php` (`labels.*`, `models.*`, `navigation.*`).
- Tests: `tests/Feature/Filament/*` via `pest-plugin-livewire` (`livewire(...)`).
