<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Character;
use App\Models\Fight;
use App\Services\Combat\CombatService;
use App\Services\Fight\FightService;
use App\Support\Game\Enemy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

final class FightSeeder extends Seeder
{
    private const LEGACY_FIGHTER_TG_IDS = [900101, 900102, 900103, 900104, 900105];

    public function run(): void
    {
        Bus::fake();

        $this->purgeLegacyFighters();

        $fights = app(FightService::class);
        $combat = app(CombatService::class);

        foreach ($this->rows() as $row) {
            $character = Character::query()->find($row['tg_id']);

            if (! $character instanceof Character) {
                throw new RuntimeException(
                    'CharacterSeeder must run before FightSeeder (missing tg_id ' . $row['tg_id'] . ').',
                );
            }

            $enemy = $this->enemy($combat, $row['enemy']);
            $fight = $fights->createTraining($character, $enemy);
            $fight->log = $this->logLines(
                (string) $character->username,
                $enemy->name,
                $row['logVariant'],
            );
            $fights->save($fight);
        }
    }

    /**
     * @return list<array{tg_id: int, enemy: string, logVariant: int}>
     */
    private function rows(): array
    {
        return [
            [
                'tg_id' => 900001,
                'enemy' => 'woodenSoldier',
                'logVariant' => 0,
            ],
            [
                'tg_id' => 900002,
                'enemy' => 'wanderer:1',
                'logVariant' => 1,
            ],
            [
                'tg_id' => 900003,
                'enemy' => 'wanderer:2',
                'logVariant' => 2,
            ],
        ];
    }

    private function enemy(CombatService $combat, string $key): Enemy
    {
        if ($key === 'woodenSoldier') {
            return $combat->makeWoodenSoldier();
        }

        if (str_starts_with($key, 'wanderer:')) {
            $level = (int) mb_substr($key, mb_strlen('wanderer:'));

            return $combat->makeMob($level);
        }

        throw new RuntimeException('Unknown seed enemy key: ' . $key);
    }

    private function purgeLegacyFighters(): void
    {
        Fight::query()->whereIn('tg_id', self::LEGACY_FIGHTER_TG_IDS)->delete();

        Character::query()
            ->withTrashed()
            ->whereIn('tg_id', self::LEGACY_FIGHTER_TG_IDS)
            ->forceDelete();
    }

    /**
     * @return list<string>
     */
    private function logLines(string $player, string $enemy, int $variant): array
    {
        $head = __('combat.zone_acc.HEAD');
        $chest = __('combat.zone_acc.CHEST');
        $belly = __('combat.zone_acc.BELLY');
        $legs = __('combat.zone_acc.LEGS');

        return match ($variant) {
            0 => [
                __('combat.hit', ['attacker' => $player, 'zone' => $chest, 'dmg' => 4]),
                __('combat.block', ['defender' => $player, 'zone' => $head]),
                __('combat.hit', ['attacker' => $enemy, 'zone' => $legs, 'dmg' => 2]),
            ],
            1 => [
                __('combat.crit', ['attacker' => $player, 'zone' => $head, 'dmg' => 9]),
                __('combat.dodge', ['defender' => $player, 'zone' => $chest]),
                __('combat.hit', ['attacker' => $enemy, 'zone' => $belly, 'dmg' => 3]),
            ],
            default => [
                __('combat.block', ['defender' => $enemy, 'zone' => $chest]),
                __('combat.pierce', ['attacker' => $player, 'zone' => $chest, 'dmg' => 5]),
                __('combat.hit', ['attacker' => $enemy, 'zone' => $head, 'dmg' => 2]),
                __('combat.hit', ['attacker' => $player, 'zone' => $legs, 'dmg' => 3]),
            ],
        };
    }
}
