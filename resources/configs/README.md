# Game configs

Читает `App\Services\GameConfig`.

| Файл | Что |
|------|-----|
| `settings.json` | глобальный баланс (ниже) |
| `onboarding.json` | награды квестов онбординга (`rewards.*`: `exp` / `silver`) |

Каталоги предметов/мобов — таблицы БД + сидеры, не JSON. Канон домена: корневой `docs/`.

---

## `settings.json`

### `backpack`

| Поле | Смысл |
|------|--------|
| `maxRows` | default `characters.backpack_max_rows` — строк unequipped в рюкзаке |

### `bag`

| Поле | Смысл |
|------|--------|
| `maxRows` | default `characters.bag_max_rows` — loose `bag_items` |
| `potionMaxStack` | макс. стек зелья одной строки |

### `shop`

| Поле | Смысл |
|------|--------|
| `sellRatioPermille` | доля цены при продаже (‰). `500` = 50% |

### `repair`

| Поле | Смысл |
|------|--------|
| `silverPerMissingPoint` | серебро за 1 недостающую прочность |
| `goldRepairAllDivisor` | делитель для цены «починить всё» за золото |

### `vipRepair`

| Поле | Смысл |
|------|--------|
| `silverMultiplier` | множитель серебряной починки для VIP-пути |
| `goldPass` | золото за VIP-починку |

### `wear`

| Поле | Смысл |
|------|--------|
| `extraLossOnLose` | доп. износ шмота при поражении |
| `extraLossPerPierce` | доп. износ за каждое пробитие блока в бою |

### `hospital`

| Поле | Смысл |
|------|--------|
| `goldCost` | золото за фулл HP+stamina в госпитале (≥ 1) |

### `training`

| Поле | Смысл |
|------|--------|
| `exp` | награда тренировочного зала (победа) |
| `silver` | награда тренировочного зала (победа) |

### `gems`

| Поле | Смысл |
|------|--------|
| `breakChanceOnLose` | шанс порчи камня при поражении, % (0…100) |
| `premiumBreakChanceReduce` | снижение этого шанса для premium, п.п. (0…100) |

### `combat`

| Поле | Смысл |
|------|--------|
| `aiDefendChance` | вероятность AI выбрать стойку DEFEND (0…1) |
| `dualWieldMinLevel` | мин. уровень для 2 ударов (нож/кастет в LH) |
| `turnTimeoutSeconds` | таймаут хода боя |
| `mfPerStat` | МФ с 1 очка стата (ловкость→уворот, инстинкт→крит, …) |

#### `combat.stamina`

| Поле | Смысл |
|------|--------|
| `maxPerStrength` | max stamina = strength × это |
| `drainDefend` | расход за ход в DEFEND |
| `drainAttack` | расход за ход в ATTACK |
| `drainExtraOnCrit` | доп. расход при крите |
| `drainExtraOnDodge` | доп. расход при увороте |

#### `combat.damage`

| Поле | Смысл |
|------|--------|
| `base` | база урона до статов/оружия |
| `statMultiplier` | множитель силы в формуле |
| `varianceMin` | нижняя граница random variance |
| `varianceRange` | ширина variance (`min + rand×range`) |

#### `combat.stances.ATTACK` / `DEFEND`

| Поле | Смысл |
|------|--------|
| `damageMult` | множитель урона до variance / pierce |
| `dodge` | ± МФ уворота на ход |
| `antiCrit` | ± МФ антикрита |
| `crit` | ± МФ крита (open + pierce) |
| `antiDodge` | ± МФ антиуворота |

#### `combat.pierce` / `crit` / `dodge`

Шанс: `clamp(chanceMin…chanceMax, chanceBase + ΔMF × chanceScale)`.

| Поле | Смысл |
|------|--------|
| `chanceMin` / `chanceMax` | clamp шанса, % |
| `chanceBase` | база шанса, % |
| `chanceScale` | вес разницы МФ |
| `multMin` / `multRange` | только pierce: множитель урона при пробитии |
| `mult` | только crit: множитель урона при крите |

#### `combat.pveRewards`

База XP для PvE (не серебро — оно с шаблона моба).

| Поле | Смысл |
|------|--------|
| `expBase` | база XP |
| `expPerLevel` | +XP за уровень игрока → `exp_base = expBase + level × expPerLevel` |

### `character`

#### `character.start`

Стартовые значения драфта (`CharacterService::createDraft`).

| Поле | Смысл |
|------|--------|
| `silver` / `gold` | валюта |
| `strength` / `agility` / `instinct` / `vitality` | базовые статы |
| `statPoints` | свободные очки |
| `level` / `exp` | уровень и опыт |

#### `character.maxHp`

| Поле | Смысл |
|------|--------|
| `perVitality` | max HP = vitality × это |

#### `character.regen` (HP)

| Поле | Смысл |
|------|--------|
| `tickSeconds` | интервал тика |
| `vitalityDivisor` | HP/тик ≈ `floor(vit / divisor) + bonus` |
| `vitalityBonus` | константа к тику |

#### `character.staminaRegen`

| Поле | Смысл |
|------|--------|
| `tickSeconds` | интервал тика |
| `strengthDivisor` | stamina/тик ≈ `floor(str / divisor) + bonus` |
| `strengthBonus` | константа к тику |

#### `character.level`

| Поле | Смысл |
|------|--------|
| `max` | потолок уровня |
| `upsPerLevel` | сколько `up`-порогов внутри уровня (для UI/логики) |
| `statPointsOnUp` | очки за порог `kind=up` |
| `statPointsOnLevel` | очки за `kind=level`, если новый level ≤ `levelAboveThreshold` |
| `statPointsOnLevelAbove` | очки за `kind=level`, если новый level > порога |
| `levelAboveThreshold` | граница «обычный / повышенный» набор очков |

#### `character.statReset`

| Поле | Смысл |
|------|--------|
| `goldCost` | золото за сброс статов |

#### `character.experience[]`

Таблица порогов. Строки по возрастанию `exp`. При наборе опыта срабатывают все пройденные пороги.

| Поле | Смысл |
|------|--------|
| `level` | уровень после этого порога |
| `exp` | накопленный exp, при котором срабатывает |
| `kind` | `start` — якорь; `up` — промежуточный; `level` — смена уровня |
| `silverGain` | серебро при срабатывании порога |
