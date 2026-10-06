<?php

declare(strict_types=1);

namespace App\Actions\City;

use App\Models\Character;
use App\Models\City;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CityHospitalHealAction
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            if ($character->city_id === null) {
                return ActionResult::fail(__('errors.city_unavailable'));
            }

            $city = City::query()->find($character->city_id);

            if (! $city instanceof City || ! $city->enabled || ! $city->has_hospital) {
                return ActionResult::fail(__('errors.no_hospital'));
            }

            $character = $this->characters->applyRegen($character);
            $maxHp = $this->characters->maxHp($character);
            $maxStamina = $this->characters->maxStamina($character);

            if ($character->current_hp >= $maxHp && $character->current_stamina >= $maxStamina) {
                return ActionResult::fail(__('errors.hospital_already_full'));
            }

            $cost = $this->goldCost();
            $paid = Character::query()
                ->where('tg_id', $character->tg_id)
                ->where('gold', '>=', $cost)
                ->decrement('gold', $cost);

            if ($paid <= 0) {
                return ActionResult::fail(__('errors.not_enough_gold'));
            }

            $character = $this->characters->findByTgId($character->tg_id);
            $character->current_hp = $maxHp;
            $character->current_stamina = $maxStamina;
            $character->last_hp_update = now();
            $character->last_stamina_update = now();
            $character->save();

            return ActionResult::ok($character);
        });
    }

    private function goldCost(): int
    {
        $settings = $this->config->settings();

        if (! array_key_exists('hospital', $settings) || ! is_array($settings['hospital'])) {
            throw new RuntimeException('settings.hospital missing.');
        }

        if (! array_key_exists('goldCost', $settings['hospital']) || ! is_int($settings['hospital']['goldCost'])) {
            throw new RuntimeException('settings.hospital.goldCost missing.');
        }

        if ($settings['hospital']['goldCost'] < 1) {
            throw new RuntimeException('settings.hospital.goldCost must be >= 1.');
        }

        return $settings['hospital']['goldCost'];
    }
}
