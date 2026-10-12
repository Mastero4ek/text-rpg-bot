<?php

declare(strict_types=1);

namespace App\Services\DailyContract;

use App\Actions\DailyContract\DailyContractExpireStaleAction;
use App\Enums\City\PveZoneEnum;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Enums\NpcEnum;
use App\Models\Bag\BagCatalog;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Services\GameConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DailyContractService
{
    public function __construct(
        private readonly DailyContractExpireStaleAction $expireStale,
        private readonly GameConfig $config,
    ) {}

    public static function cityHasIssuer(City $city, NpcEnum $issuer): bool
    {
        return match ($issuer) {
            NpcEnum::Buyer => $city->has_buyer,
            NpcEnum::Healer => $city->has_healer,
            NpcEnum::Blacksmith => $city->has_blacksmith,
            NpcEnum::Overseer => $city->has_overseer,
            NpcEnum::Bruiser => false,
        };
    }

    /**
     * @return Collection<int, CharacterDailyContract>
     */
    public function acceptedHuntsForCity(Character $character, City $city): Collection
    {
        $this->expireStale->handle($character->tg_id);

        return CharacterDailyContract::query()
            ->where('tg_id', $character->tg_id)
            ->where('city_id', $city->id)
            ->where('type', DailyContractTypeEnum::Hunt)
            ->where('status', DailyContractStatusEnum::Accepted)
            ->orderBy('slot')
            ->get();
    }

    /**
     * @return Collection<int, CharacterDailyContract>
     */
    public function ensureOffers(Character $character, City $city): Collection
    {
        $this->materializeTodayBoard($character, $city);

        $today = \Illuminate\Support\Facades\Date::now(config('app.timezone'))->toDateString();

        return CharacterDailyContract::query()
            ->where('tg_id', $character->tg_id)
            ->where('city_id', $city->id)
            ->whereDate('board_date', $today)
            ->orderBy('slot')
            ->get();
    }

    public function findOwned(Character $character, int $contractId): ?CharacterDailyContract
    {
        $row = CharacterDailyContract::query()->find($contractId);

        if (! $row instanceof CharacterDailyContract) {
            return null;
        }

        if ($row->tg_id !== $character->tg_id) {
            return null;
        }

        return $row;
    }

    /**
     * Create today's board rows when missing. Returns true only when this call inserted offers.
     */
    public function materializeTodayBoard(Character $character, City $city): bool
    {
        $this->expireStale->handle($character->tg_id);

        $today = \Illuminate\Support\Facades\Date::now(config('app.timezone'))->toDateString();

        $exists = CharacterDailyContract::query()
            ->where('tg_id', $character->tg_id)
            ->where('city_id', $city->id)
            ->whereDate('board_date', $today)
            ->exists();

        if ($exists) {
            return false;
        }

        return DB::transaction(function () use ($character, $city, $today): bool {
            $again = CharacterDailyContract::query()
                ->where('tg_id', $character->tg_id)
                ->where('city_id', $city->id)
                ->whereDate('board_date', $today)
                ->lockForUpdate()
                ->exists();

            if ($again) {
                return false;
            }

            $picks = $this->pickBoardSlots($character->level, $city);

            foreach ($picks as $slot => $pick) {
                $row = new CharacterDailyContract;
                $row->tg_id = $character->tg_id;
                $row->city_id = $city->id;
                $row->board_date = \Illuminate\Support\Facades\Date::parse($today);
                $row->slot = $slot;
                $row->status = DailyContractStatusEnum::Offered;
                $row->reward_silver = $character->level * $this->silverMultiplier($pick['type']);
                $row->reward_exp = $character->level * $this->expMultiplier($pick['type']);

                if ($pick['type'] === DailyContractTypeEnum::Delivery) {
                    $this->fillDeliveryRow($row, $pick, $character->level);
                } else {
                    $this->fillHuntRow($row, $pick, $character->level);
                }

                $row->save();
            }

            return true;
        });
    }

    /**
     * @return Collection<int, CharacterDailyContract>
     */
    public function offeredForBoard(Character $character, City $city, string $filter): Collection
    {
        $all = $this->ensureOffers($character, $city);
        $offered = [];

        foreach ($all as $row) {
            if ($row->status !== DailyContractStatusEnum::Offered) {
                continue;
            }

            if ($filter === 'orders' && $row->type !== DailyContractTypeEnum::Hunt) {
                continue;
            }

            if ($filter === 'asks' && $row->type !== DailyContractTypeEnum::Delivery) {
                continue;
            }

            $offered[] = $row;
        }

        return collect($offered);
    }

    /**
     * Hunt ready + delivery accepted for issuer.
     *
     * @return Collection<int, CharacterDailyContract>
     */
    public function turnInableForIssuer(Character $character, City $city, NpcEnum $issuer): Collection
    {
        $out = [];

        foreach ($this->turnInableInCity($character, $city) as $row) {
            if ($row->issuer_npc !== $issuer) {
                continue;
            }

            $out[] = $row;
        }

        return collect($out);
    }

    /**
     * Issuer keys with turn-inable contracts in the current city.
     *
     * @return list<string>
     */
    public function turnInableIssuerKeys(Character $character, City $city): array
    {
        $keys = [];

        foreach ($this->turnInableInCity($character, $city) as $row) {
            $keys[$row->issuer_npc->value] = true;
        }

        return array_keys($keys);
    }

    /**
     * @return Collection<int, CharacterDailyContract>
     */
    public function acceptedForCity(Character $character, City $city): Collection
    {
        return $this->acceptedHuntsForCity($character, $city);
    }

    /**
     * @return Collection<int, CharacterDailyContract>
     */
    public function readyForIssuer(Character $character, City $city, NpcEnum $issuer): Collection
    {
        return $this->turnInableForIssuer($character, $city, $issuer);
    }

    /**
     * Hunt ready in board city + delivery accepted targeting this city.
     *
     * @return list<CharacterDailyContract>
     */
    private function turnInableInCity(Character $character, City $city): array
    {
        $this->expireStale->handle($character->tg_id);

        $rows = CharacterDailyContract::query()
            ->where('tg_id', $character->tg_id)
            ->orderBy('slot')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            if (
                $row->type === DailyContractTypeEnum::Hunt
                && $row->status === DailyContractStatusEnum::Ready
                && $row->city_id === $city->id
            ) {
                $out[] = $row;

                continue;
            }

            if (
                $row->type === DailyContractTypeEnum::Delivery
                && $row->status === DailyContractStatusEnum::Accepted
                && $row->target_city_id === $city->id
            ) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array{type: DailyContractTypeEnum, enemy_catalog_key?: string, zone_key?: string, issuer_npc: string, bag_catalog_key?: string, min_lvl?: int, max_lvl?: int, target_city_id?: int, target_city_name?: string, target_city_key?: string}  $pick
     */
    private function fillDeliveryRow(CharacterDailyContract $row, array $pick, int $level): void
    {
        $bagKey = $pick['bag_catalog_key'] ?? '';
        $catalog = BagCatalog::query()->find($bagKey);

        if (! $catalog instanceof BagCatalog) {
            throw new RuntimeException('Daily delivery bag catalog missing: ' . $bagKey);
        }

        $issuer = NpcEnum::tryFrom($pick['issuer_npc']);

        if (! $issuer instanceof NpcEnum) {
            throw new RuntimeException('Daily delivery issuer invalid: ' . $pick['issuer_npc']);
        }

        $targetCityId = $pick['target_city_id'] ?? null;

        if (! is_int($targetCityId) || $targetCityId < 1) {
            throw new RuntimeException('Daily delivery target_city_id missing.');
        }

        $cityName = $pick['target_city_name'] ?? '';
        $cityKey = $pick['target_city_key'] ?? '';

        if ($cityName === '' || $cityKey === '') {
            throw new RuntimeException('Daily delivery target city snapshot missing.');
        }

        $row->type = DailyContractTypeEnum::Delivery;
        $row->enemy_catalog_id = null;
        $row->bag_catalog_id = $catalog->catalog_id;
        $row->issuer_npc = $issuer;
        $row->target_city_id = $targetCityId;
        $row->target_kills = 0;
        $row->params = [
            'item_name' => $catalog->name,
            'bag_catalog_key' => $catalog->catalog_id,
            'lvl' => $level,
            'city_name' => $cityName,
            'city_key' => $cityKey,
        ];
    }

    /**
     * @param  array{type: DailyContractTypeEnum, enemy_catalog_key?: string, zone_key?: string, issuer_npc: string, bag_catalog_key?: string, min_lvl?: int, max_lvl?: int, target_city_id?: int, target_city_name?: string, target_city_key?: string}  $pick
     */
    private function fillHuntRow(CharacterDailyContract $row, array $pick, int $level): void
    {
        $enemyKey = $pick['enemy_catalog_key'] ?? '';
        $catalog = EnemyCatalog::query()->find($enemyKey);

        if (! $catalog instanceof EnemyCatalog) {
            throw new RuntimeException('Daily hunt pool enemy missing: ' . $enemyKey);
        }

        $issuer = NpcEnum::tryFrom($pick['issuer_npc']);

        if (! $issuer instanceof NpcEnum) {
            throw new RuntimeException('Daily hunt pool issuer invalid: ' . $pick['issuer_npc']);
        }

        $row->type = DailyContractTypeEnum::Hunt;
        $row->enemy_catalog_id = $catalog->catalog_id;
        $row->bag_catalog_id = null;
        $row->issuer_npc = $issuer;
        $row->target_city_id = null;
        $row->target_kills = 1;
        $row->params = [
            'zone' => $pick['zone_key'] ?? 'forest',
            'monster_name' => $catalog->name,
            'lvl' => $level,
            'enemy_catalog_key' => $catalog->catalog_id,
        ];
    }

    /**
     * @return list<array{type: DailyContractTypeEnum, enemy_catalog_key?: string, zone_key?: string, issuer_npc: string, bag_catalog_key?: string, min_lvl?: int, max_lvl?: int, target_city_id?: int, target_city_name?: string, target_city_key?: string}>
     */
    private function pickBoardSlots(int $level, City $city): array
    {
        $slots = [];

        for ($i = 0; $i < 3; $i++) {
            $type = $this->rollSlotType($level, $city);

            if ($type === DailyContractTypeEnum::Delivery) {
                $pick = $this->pickOneDelivery($level, $city);

                if ($pick === null) {
                    $pick = $this->pickOneHunt($level, $city);
                    $pick['type'] = DailyContractTypeEnum::Hunt;
                } else {
                    $pick['type'] = DailyContractTypeEnum::Delivery;
                }
            } else {
                $pick = $this->pickOneHunt($level, $city);
                $pick['type'] = DailyContractTypeEnum::Hunt;
            }

            $slots[] = $pick;
        }

        return $slots;
    }

    private function rollSlotType(int $level, City $city): DailyContractTypeEnum
    {
        $weights = $this->typeWeights();

        if ($this->deliveryCandidates($level, $city) === []) {
            return DailyContractTypeEnum::Hunt;
        }

        $huntW = $weights['hunt'];
        $delW = $weights['delivery'];
        $total = $huntW + $delW;

        if ($total < 1) {
            return DailyContractTypeEnum::Hunt;
        }

        $roll = random_int(1, $total);

        if ($roll <= $huntW) {
            return DailyContractTypeEnum::Hunt;
        }

        return DailyContractTypeEnum::Delivery;
    }

    /**
     * @return array{enemy_catalog_key: string, zone_key: string, issuer_npc: string, min_lvl: int, max_lvl: int}
     */
    private function pickOneHunt(int $level, City $city): array
    {
        $pool = $this->huntPoolRowsForCity($city);
        $filtered = [];

        foreach ($pool as $row) {
            if ($level < $row['min_lvl'] || $level > $row['max_lvl']) {
                continue;
            }

            $filtered[] = $row;
        }

        if ($filtered === []) {
            $filtered = $pool;
        }

        if ($filtered === []) {
            throw new RuntimeException('daily_contracts.hunt_pool is empty.');
        }

        return $filtered[array_rand($filtered)];
    }

    /**
     * @return array{bag_catalog_key: string, issuer_npc: string, min_lvl: int, max_lvl: int, target_city_id: int, target_city_name: string, target_city_key: string}|null
     */
    private function pickOneDelivery(int $level, City $boardCity): ?array
    {
        $candidates = $this->deliveryCandidates($level, $boardCity);

        if ($candidates === []) {
            return null;
        }

        return $candidates[array_rand($candidates)];
    }

    /**
     * Delivery only to another enabled city that has the issuer NPC.
     *
     * @return list<array{bag_catalog_key: string, issuer_npc: string, min_lvl: int, max_lvl: int, target_city_id: int, target_city_name: string, target_city_key: string}>
     */
    private function deliveryCandidates(int $level, City $boardCity): array
    {
        $pool = $this->deliveryPoolRows();
        $levelMatched = [];

        foreach ($pool as $row) {
            if ($level < $row['min_lvl'] || $level > $row['max_lvl']) {
                continue;
            }

            $levelMatched[] = $row;
        }

        if ($levelMatched === []) {
            $levelMatched = $pool;
        }

        if ($levelMatched === []) {
            return [];
        }

        $others = City::query()
            ->where('enabled', true)
            ->where('id', '!=', $boardCity->id)
            ->orderBy('id')
            ->get();

        $candidates = [];

        foreach ($levelMatched as $row) {
            $issuer = NpcEnum::tryFrom($row['issuer_npc']);

            if (! $issuer instanceof NpcEnum) {
                throw new RuntimeException('daily_contracts.delivery_pool.issuer_npc invalid: ' . $row['issuer_npc']);
            }

            foreach ($others as $target) {
                if (! self::cityHasIssuer($target, $issuer)) {
                    continue;
                }

                $candidates[] = [
                    'bag_catalog_key' => $row['bag_catalog_key'],
                    'issuer_npc' => $row['issuer_npc'],
                    'min_lvl' => $row['min_lvl'],
                    'max_lvl' => $row['max_lvl'],
                    'target_city_id' => $target->id,
                    'target_city_name' => $target->name,
                    'target_city_key' => $target->key,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @return array{hunt: int, delivery: int}
     */
    private function typeWeights(): array
    {
        $cfg = $this->config->dailyContracts();

        if (! array_key_exists('type_weights', $cfg) || ! is_array($cfg['type_weights'])) {
            return ['hunt' => 1, 'delivery' => 1];
        }

        $hunt = $cfg['type_weights']['hunt'] ?? 1;
        $delivery = $cfg['type_weights']['delivery'] ?? 1;

        if (! is_int($hunt) || ! is_int($delivery) || $hunt < 0 || $delivery < 0) {
            throw new RuntimeException('daily_contracts.type_weights invalid.');
        }

        return ['hunt' => $hunt, 'delivery' => $delivery];
    }

    /**
     * @return list<array{enemy_catalog_key: string, zone_key: string, issuer_npc: string, min_lvl: int, max_lvl: int}>
     */
    private function huntPoolRowsForCity(City $city): array
    {
        $rows = [];

        foreach ($this->huntPoolRows() as $row) {
            $zone = PveZoneEnum::tryFrom($row['zone_key']);

            if (! $zone instanceof PveZoneEnum) {
                throw new RuntimeException('daily_contracts.hunt_pool.zone_key invalid: ' . $row['zone_key']);
            }

            if (! $zone->cityHas($city)) {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw new RuntimeException('daily_contracts.hunt_pool empty for city ' . $city->key);
        }

        return $rows;
    }

    /**
     * @return list<array{enemy_catalog_key: string, zone_key: string, issuer_npc: string, min_lvl: int, max_lvl: int}>
     */
    private function huntPoolRows(): array
    {
        $cfg = $this->config->dailyContracts();

        if (! array_key_exists('hunt_pool', $cfg) || ! is_array($cfg['hunt_pool'])) {
            throw new RuntimeException('daily_contracts.hunt_pool missing.');
        }

        $rows = [];

        foreach ($cfg['hunt_pool'] as $raw) {
            if (! is_array($raw)) {
                throw new RuntimeException('daily_contracts.hunt_pool row invalid.');
            }

            foreach (['enemy_catalog_key', 'zone_key', 'issuer_npc', 'min_lvl', 'max_lvl'] as $key) {
                if (! array_key_exists($key, $raw)) {
                    throw new RuntimeException('daily_contracts.hunt_pool missing ' . $key);
                }
            }

            if (! is_string($raw['enemy_catalog_key']) || $raw['enemy_catalog_key'] === '') {
                throw new RuntimeException('daily_contracts.hunt_pool.enemy_catalog_key invalid.');
            }

            if (! is_string($raw['zone_key']) || $raw['zone_key'] === '') {
                throw new RuntimeException('daily_contracts.hunt_pool.zone_key invalid.');
            }

            if (! is_string($raw['issuer_npc']) || $raw['issuer_npc'] === '') {
                throw new RuntimeException('daily_contracts.hunt_pool.issuer_npc invalid.');
            }

            if (! is_int($raw['min_lvl']) || ! is_int($raw['max_lvl'])) {
                throw new RuntimeException('daily_contracts.hunt_pool level bounds invalid.');
            }

            $rows[] = [
                'enemy_catalog_key' => $raw['enemy_catalog_key'],
                'zone_key' => $raw['zone_key'],
                'issuer_npc' => $raw['issuer_npc'],
                'min_lvl' => $raw['min_lvl'],
                'max_lvl' => $raw['max_lvl'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{bag_catalog_key: string, issuer_npc: string, min_lvl: int, max_lvl: int}>
     */
    private function deliveryPoolRows(): array
    {
        $cfg = $this->config->dailyContracts();

        if (! array_key_exists('delivery_pool', $cfg) || ! is_array($cfg['delivery_pool'])) {
            return [];
        }

        $rows = [];

        foreach ($cfg['delivery_pool'] as $raw) {
            if (! is_array($raw)) {
                throw new RuntimeException('daily_contracts.delivery_pool row invalid.');
            }

            foreach (['bag_catalog_key', 'issuer_npc', 'min_lvl', 'max_lvl'] as $key) {
                if (! array_key_exists($key, $raw)) {
                    throw new RuntimeException('daily_contracts.delivery_pool missing ' . $key);
                }
            }

            if (! is_string($raw['bag_catalog_key']) || $raw['bag_catalog_key'] === '') {
                throw new RuntimeException('daily_contracts.delivery_pool.bag_catalog_key invalid.');
            }

            if (! is_string($raw['issuer_npc']) || $raw['issuer_npc'] === '') {
                throw new RuntimeException('daily_contracts.delivery_pool.issuer_npc invalid.');
            }

            if (! is_int($raw['min_lvl']) || ! is_int($raw['max_lvl'])) {
                throw new RuntimeException('daily_contracts.delivery_pool level bounds invalid.');
            }

            $rows[] = [
                'bag_catalog_key' => $raw['bag_catalog_key'],
                'issuer_npc' => $raw['issuer_npc'],
                'min_lvl' => $raw['min_lvl'],
                'max_lvl' => $raw['max_lvl'],
            ];
        }

        return $rows;
    }

    private function silverMultiplier(DailyContractTypeEnum $type): int
    {
        return $this->typeEconomyMultiplier($type, 'silver_multiplier');
    }

    private function expMultiplier(DailyContractTypeEnum $type): int
    {
        return $this->typeEconomyMultiplier($type, 'exp_multiplier');
    }

    private function typeEconomyMultiplier(DailyContractTypeEnum $type, string $key): int
    {
        $cfg = $this->config->dailyContracts();

        if (! array_key_exists('economy_balance', $cfg) || ! is_array($cfg['economy_balance'])) {
            throw new RuntimeException('daily_contracts.economy_balance missing.');
        }

        $typeKey = $type->value;

        if (! array_key_exists($typeKey, $cfg['economy_balance']) || ! is_array($cfg['economy_balance'][$typeKey])) {
            throw new RuntimeException('daily_contracts.economy_balance.' . $typeKey . ' missing.');
        }

        $bucket = $cfg['economy_balance'][$typeKey];

        if (! array_key_exists($key, $bucket) || ! is_int($bucket[$key])) {
            throw new RuntimeException('daily_contracts.economy_balance.' . $typeKey . '.' . $key . ' missing.');
        }

        if ($bucket[$key] < 1) {
            throw new RuntimeException('daily_contracts.economy_balance.' . $typeKey . '.' . $key . ' must be >= 1.');
        }

        return $bucket[$key];
    }
}
