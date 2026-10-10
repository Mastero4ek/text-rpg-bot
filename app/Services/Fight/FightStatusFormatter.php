<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Support\Telegram\TelegramHtml;

final class FightStatusFormatter
{
    public function __construct(
        private readonly FightService $fights,
    ) {}

    public function format(Fight $fight, Character $player): string
    {
        return $this->captionWithoutChoices($fight, $player);
    }

    public function panelCaption(Fight $fight, Character $player): string
    {
        return $this->statusCaption($fight, $player) . "\n\n" . $this->stepPrompt($fight);
    }

    public function errorCaption(Fight $fight, Character $player, string $error): string
    {
        return $this->statusCaption($fight, $player) . "\n\n" . $error;
    }

    public function statusCaption(Fight $fight, Character $player): string
    {
        return $this->captionWithChoices($fight, $player);
    }

    public function endCaption(Fight $fight, Character $player, string $suffix): string
    {
        return $this->format($fight, $player) . $suffix;
    }

    public function stepPrompt(Fight $fight): string
    {
        $prompt = __('combat.log_prompt.' . $fight->step->value, [
            'round' => max(1, $fight->turn_seq),
        ]);

        if (
            $fight->step === FightStepEnum::DEFEND
            && $fight->player_attack instanceof PlayerAttackEnum
            && $fight->player_attack->isPotion()
        ) {
            $log = $fight->log;

            if ($log === []) {
                $prompt = mb_ltrim(__('combat.potion_then_defend'));
            } else {
                $drinkLine = $log[array_key_last($log)];

                if ($drinkLine === '') {
                    $prompt = mb_ltrim(__('combat.potion_then_defend'));
                } else {
                    $prompt = $drinkLine . "\n\n" . $prompt;
                }
            }
        }

        $prefix = '';

        if ($this->shouldShowSkipNote($fight)) {
            $prefix .= __('combat.turn_skipped') . "\n\n";
        }

        return $prefix . $prompt;
    }

    private function shouldShowSkipNote(Fight $fight): bool
    {
        if ($fight->step !== FightStepEnum::STANCE) {
            return false;
        }

        if ($fight->player_stance instanceof StanceEnum) {
            return false;
        }

        $round = $this->lastRound($fight);

        if ($round === null) {
            return false;
        }

        return $round['skipped'];
    }

    /**
     * @return array{
     *     skipped: bool,
     *     player_hp_delta: int,
     *     enemy_hp_delta: int,
     *     player_stance: string|null,
     *     player_attack: string|null,
     *     player_attack_second: string|null,
     *     player_defend: string|null,
     *     player_defend_second: string|null,
     *     enemy_stance: string|null,
     *     enemy_attack: string,
     *     enemy_attack_second: string|null,
     *     enemy_defend: string,
     *     enemy_defend_second: string|null
     * }|null
     */
    private function lastRound(Fight $fight): ?array
    {
        $round = $fight->last_round;

        if ($round === null || $round === []) {
            return null;
        }

        if (
            ! array_key_exists('skipped', $round)
            || ! is_bool($round['skipped'])
            || ! array_key_exists('player_hp_delta', $round)
            || ! is_int($round['player_hp_delta'])
            || ! array_key_exists('enemy_hp_delta', $round)
            || ! is_int($round['enemy_hp_delta'])
            || ! array_key_exists('enemy_attack', $round)
            || ! is_string($round['enemy_attack'])
            || ! array_key_exists('enemy_defend', $round)
            || ! is_string($round['enemy_defend'])
        ) {
            return null;
        }

        return [
            'skipped' => $round['skipped'],
            'player_hp_delta' => $round['player_hp_delta'],
            'enemy_hp_delta' => $round['enemy_hp_delta'],
            'player_stance' => $this->nullableString($round, 'player_stance'),
            'player_attack' => $this->nullableString($round, 'player_attack'),
            'player_attack_second' => $this->nullableString($round, 'player_attack_second'),
            'player_defend' => $this->nullableString($round, 'player_defend'),
            'player_defend_second' => $this->nullableString($round, 'player_defend_second'),
            'enemy_stance' => $this->nullableString($round, 'enemy_stance'),
            'enemy_attack' => $round['enemy_attack'],
            'enemy_attack_second' => $this->nullableString($round, 'enemy_attack_second'),
            'enemy_defend' => $round['enemy_defend'],
            'enemy_defend_second' => $this->nullableString($round, 'enemy_defend_second'),
        ];
    }

    /**
     * @param  array<string, mixed>  $round
     */
    private function nullableString(array $round, string $key): ?string
    {
        if (! array_key_exists($key, $round) || $round[$key] === null) {
            return null;
        }

        if (! is_string($round[$key])) {
            return null;
        }

        return $round[$key];
    }

    private function captionWithChoices(Fight $fight, Character $player): string
    {
        $enemy = $this->fights->enemy($fight);

        if ($player->username === null) {
            $playerName = __('common.you');
        } else {
            $playerName = TelegramHtml::escape($player->username);
        }

        $blocks = [
            $this->fighterBlock(
                $playerName,
                $player->level,
                $fight->player_hp,
                $fight->player_max_hp,
                $fight->player_stamina,
                $fight->player_max_stamina,
                $this->playerStanceLabel($fight),
                $this->playerAttackLabel($fight),
                $this->playerDefendLabel($fight),
            ),
            '',
            $this->fighterBlock(
                TelegramHtml::escape($enemy->name),
                $enemy->level,
                $enemy->currentHp,
                $enemy->maxHp,
                $enemy->stamina,
                $enemy->maxStamina,
                $this->enemyStanceLabel($fight),
                $this->enemyAttackLabel($fight),
                $this->enemyDefendLabel($fight),
            ),
        ];

        return implode("\n", $blocks);
    }

    private function captionWithoutChoices(Fight $fight, Character $player): string
    {
        $enemy = $this->fights->enemy($fight);

        if ($player->username === null) {
            $playerName = __('common.you');
        } else {
            $playerName = TelegramHtml::escape($player->username);
        }

        $blocks = [
            $this->fighterBlock(
                $playerName,
                $player->level,
                $fight->player_hp,
                $fight->player_max_hp,
                $fight->player_stamina,
                $fight->player_max_stamina,
                null,
                null,
                null,
            ),
            '',
            $this->fighterBlock(
                TelegramHtml::escape($enemy->name),
                $enemy->level,
                $enemy->currentHp,
                $enemy->maxHp,
                $enemy->stamina,
                $enemy->maxStamina,
                null,
                null,
                null,
            ),
        ];

        return implode("\n", $blocks);
    }

    private function fighterBlock(
        string $name,
        int $level,
        int $hp,
        int $maxHp,
        int $stamina,
        int $maxStamina,
        ?string $stanceLabel,
        ?string $attackLabel,
        ?string $defendLabel,
    ): string {
        $lines = [
            __('combat.status_name', [
                'name' => $name,
                'level' => $level,
            ]),
            __('combat.status_hp', [
                'hp' => $hp,
                'maxHp' => $maxHp,
            ]),
            __('combat.status_stamina', [
                'stamina' => $stamina,
                'maxStamina' => $maxStamina,
            ]),
        ];

        if ($stanceLabel !== null || $attackLabel !== null || $defendLabel !== null) {
            $lines[] = '';
        }

        if ($stanceLabel !== null) {
            $lines[] = __('combat.status_stance', ['stance' => $stanceLabel]);
        }

        if ($attackLabel !== null) {
            $lines[] = __('combat.status_attack', ['zones' => $attackLabel]);
        }

        if ($defendLabel !== null) {
            $lines[] = __('combat.status_defend', ['zones' => $defendLabel]);
        }

        return implode("\n", $lines);
    }

    private function playerStanceLabel(Fight $fight): ?string
    {
        if ($fight->player_stance instanceof StanceEnum) {
            return __('combat.stances.' . $fight->player_stance->value);
        }

        $round = $this->lastRound($fight);

        if ($round === null || $round['skipped'] || $round['player_stance'] === null) {
            return null;
        }

        return __('combat.stances.' . $round['player_stance']);
    }

    private function enemyStanceLabel(Fight $fight): ?string
    {
        if ($this->hasPendingChoices($fight)) {
            return null;
        }

        $round = $this->lastRound($fight);

        if ($round === null) {
            return null;
        }

        if ($round['enemy_stance'] !== null) {
            return __('combat.stances.' . $round['enemy_stance']);
        }

        return __('combat.stances.' . $this->fights->enemy($fight)->stance->value);
    }

    private function playerAttackLabel(Fight $fight): ?string
    {
        if ($this->hasPendingChoices($fight)) {
            if (! $fight->player_attack instanceof PlayerAttackEnum) {
                return null;
            }

            $attack = $fight->player_attack->value;

            if ($attack === PlayerAttackEnum::POTION->value || $attack === PlayerAttackEnum::STAMINA_POTION->value) {
                return __('combat.attack_choice.' . $attack);
            }

            $second = null;

            if ($fight->player_attack_second instanceof PlayerAttackEnum) {
                $second = $fight->player_attack_second->value;
            }

            return $this->zonesLabel([$attack, $second]);
        }

        $round = $this->lastRound($fight);

        if ($round === null || $round['skipped']) {
            return null;
        }

        $attack = $round['player_attack'];

        if ($attack === null) {
            return null;
        }

        if ($attack === PlayerAttackEnum::POTION->value || $attack === PlayerAttackEnum::STAMINA_POTION->value) {
            return __('combat.attack_choice.' . $attack);
        }

        return $this->zonesLabel([$attack, $round['player_attack_second']]);
    }

    private function playerDefendLabel(Fight $fight): ?string
    {
        if ($this->hasPendingChoices($fight)) {
            if (! $fight->player_defend instanceof ZoneEnum) {
                return null;
            }

            $second = null;

            if ($fight->player_defend_second instanceof ZoneEnum) {
                $second = $fight->player_defend_second->value;
            }

            return $this->zonesLabel([$fight->player_defend->value, $second]);
        }

        $round = $this->lastRound($fight);

        if ($round === null || $round['skipped']) {
            return null;
        }

        if ($round['player_defend'] === null) {
            return null;
        }

        return $this->zonesLabel([$round['player_defend'], $round['player_defend_second']]);
    }

    private function hasPendingChoices(Fight $fight): bool
    {
        if ($fight->player_stance instanceof StanceEnum) {
            return true;
        }

        if ($fight->player_attack instanceof PlayerAttackEnum) {
            return true;
        }

        return $fight->player_defend instanceof ZoneEnum;
    }

    private function enemyAttackLabel(Fight $fight): ?string
    {
        if ($this->hasPendingChoices($fight)) {
            return null;
        }

        $round = $this->lastRound($fight);

        if ($round === null) {
            return null;
        }

        return $this->zonesLabel([$round['enemy_attack'], $round['enemy_attack_second']]);
    }

    private function enemyDefendLabel(Fight $fight): ?string
    {
        if ($this->hasPendingChoices($fight)) {
            return null;
        }

        $round = $this->lastRound($fight);

        if ($round === null) {
            return null;
        }

        return $this->zonesLabel([$round['enemy_defend'], $round['enemy_defend_second']]);
    }

    /**
     * @param  list<string|null>  $zones
     */
    private function zonesLabel(array $zones): string
    {
        $labels = [];

        foreach ($zones as $zone) {
            if ($zone === null || $zone === '') {
                continue;
            }

            if (
                $zone === PlayerAttackEnum::POTION->value
                || $zone === PlayerAttackEnum::STAMINA_POTION->value
            ) {
                continue;
            }

            $labels[] = __('combat.zone_label.' . ZoneEnum::from($zone)->value);
        }

        return implode(', ', $labels);
    }
}
