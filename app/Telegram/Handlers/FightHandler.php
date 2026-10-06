<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Backpack\BackpackApplyFightWearAction;
use App\Actions\Bag\BagGemBreakOnLoseAction;
use App\Actions\Enemy\EnemyApplyWinLootAction;
use App\Actions\Fight\FightClearAction;
use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Models\Fight;
use App\Queries\City\CityQuery;
use App\Services\Backpack\LoadoutService;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\EnemyService;
use App\Services\Fight\FightRoundService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Services\OnboardingService;
use App\Support\Telegram\FightStatusFormatter;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FightHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly EnemyService $enemies,
        private readonly FightClearAction $clearFight,
        private readonly FightService $fights,
        private readonly FightRoundService $rounds,
        private readonly BagService $bag,
        private readonly BackpackApplyFightWearAction $fightWear,
        private readonly BagGemBreakOnLoseAction $breakGems,
        private readonly EnemyApplyWinLootAction $winLoot,
        private readonly LoadoutService $loadout,
        private readonly OnboardingService $onboarding,
        private readonly GameConfig $config,
        private readonly FightStatusFormatter $fightStatus,
        private readonly CityQuery $cityQuery,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();

        if (
            preg_match('/^fight:(stance|atk|def):/', $data) === 1
            && $this->handleTimedOutTurn($update, $responder)
        ) {
            return;
        }

        if (preg_match('/^fight:stance:(ATTACK|DEFEND)$/', $data, $m) === 1) {
            $this->stance($update, $responder, StanceEnum::from($m[1]));

            return;
        }

        if (preg_match('/^fight:atk:(HEAD|CHEST|BELLY|LEGS|POTION|STAMINA_POTION)$/', $data, $m) === 1) {
            $this->attack($update, $responder, $m[1]);

            return;
        }

        if (preg_match('/^fight:def:(HEAD|CHEST|BELLY|LEGS)$/', $data, $m) === 1) {
            $this->defend($update, $responder, ZoneEnum::from($m[1]));

            return;
        }

        if (preg_match('/^fight:start:(.+)$/', $data, $m) === 1) {
            $this->startFight($update, $responder, $m[1]);
        }
    }

    private function handleTimedOutTurn(TelegramUpdate $update, TelegramResponder $responder): bool
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || ! $this->fights->exists($player->tg_id)) {
            return false;
        }

        $fight = $this->fights->findByTgId($player->tg_id);
        $this->fights->rememberTelegramMessage($fight, $update->chatId(), $update->messageId());
        $fight = $this->fights->findByTgId($player->tg_id);

        if (! $this->fights->turnTimedOut($fight)) {
            return false;
        }

        $outcome = $this->rounds->resolveSkip($player);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return true;
        }

        if ($outcome->kind === 'win') {
            $this->endFight($responder, $outcome->character, $outcome->fight, true);

            return true;
        }

        if ($outcome->kind === 'lose') {
            $this->endFight($responder, $outcome->character, $outcome->fight, false);

            return true;
        }

        $responder->edit(
            $this->fightStatus->format($outcome->fight, $outcome->character->username) . __('combat.pick_stance'),
            TelegramKeyboards::stance(),
        );

        return true;
    }

    private function persistFightMessage(TelegramUpdate $update, Fight $fight): void
    {
        $this->fights->rememberTelegramMessage($fight, $update->chatId(), $update->messageId());
    }

    private function stance(TelegramUpdate $update, TelegramResponder $responder, StanceEnum $stance): void
    {
        $saved = DB::transaction(function () use ($update, $stance): ?array {
            $player = Character::query()->find($update->userId());

            if ($player === null || ! $this->fights->exists($player->tg_id)) {
                return null;
            }

            $fight = $this->fights->findByTgId($player->tg_id);

            if ($fight->step !== FightStepEnum::STANCE) {
                return null;
            }

            $fight->use_potion = false;
            $fight->player_stance = $stance;
            $fight->step = FightStepEnum::ATTACK;
            $this->fights->save($fight);

            return ['player' => $player, 'fight' => $fight];
        });

        if ($saved === null) {
            return;
        }

        /** @var Character $player */
        $player = $saved['player'];
        /** @var Fight $fight */
        $fight = $saved['fight'];

        $keyboard = TelegramKeyboards::attack($this->availablePotionAttacks($player, $fight));

        $this->persistFightMessage($update, $fight);
        $responder->edit(
            $this->fightStatus->format($fight, $player->username) . __('combat.pick_attack'),
            $keyboard,
        );
    }

    private function attack(TelegramUpdate $update, TelegramResponder $responder, string $choice): void
    {
        $saved = DB::transaction(function () use ($update, $choice): ?array {
            $player = Character::query()->find($update->userId());

            if ($player === null || ! $this->fights->exists($player->tg_id)) {
                return null;
            }

            $fight = $this->fights->findByTgId($player->tg_id);

            if ($fight->step === FightStepEnum::ATTACK_SECOND) {
                if ($choice === 'POTION' || $choice === 'STAMINA_POTION') {
                    return null;
                }

                $fight->player_attack_second = PlayerAttackEnum::from($choice);
                $fight->step = FightStepEnum::DEFEND;
                $this->fights->save($fight);

                return ['kind' => 'defend', 'player' => $player, 'fight' => $fight];
            }

            if ($fight->step !== FightStepEnum::ATTACK) {
                return null;
            }

            if ($choice === 'POTION' || $choice === 'STAMINA_POTION') {
                $attack = PlayerAttackEnum::from($choice);

                if ($fight->tutorial || ! $this->canUsePotionAttack($player, $attack)) {
                    return ['kind' => 'potion_denied'];
                }

                $fight->use_potion = true;
                $fight->player_attack = $attack;
                $fight->player_attack_second = null;
                $fight->step = FightStepEnum::DEFEND;
                $this->fights->save($fight);

                return ['kind' => 'defend', 'player' => $player, 'fight' => $fight];
            }

            $fight->use_potion = false;
            $fight->player_attack = PlayerAttackEnum::from($choice);
            $fight->player_attack_second = null;

            $loadout = $this->loadout->forCharacter($player);

            if ($loadout->attackSlots >= 2) {
                $fight->step = FightStepEnum::ATTACK_SECOND;
                $this->fights->save($fight);

                return ['kind' => 'second', 'player' => $player, 'fight' => $fight];
            }

            $fight->step = FightStepEnum::DEFEND;
            $this->fights->save($fight);

            return ['kind' => 'defend', 'player' => $player, 'fight' => $fight];
        });

        if ($saved === null) {
            return;
        }

        if ($saved['kind'] === 'potion_denied') {
            $responder->reply(__('errors.potion_unavailable'), null);

            return;
        }

        /** @var Character $player */
        $player = $saved['player'];
        /** @var Fight $fight */
        $fight = $saved['fight'];

        if ($saved['kind'] === 'second') {
            $this->persistFightMessage($update, $fight);
            $responder->edit(
                $this->fightStatus->format($fight, $player->username) . __('combat.pick_attack_second'),
                TelegramKeyboards::attackWithoutPotion(),
            );

            return;
        }

        if ($fight->use_potion) {
            $prompt = __('combat.potion_then_defend');
        } else {
            $prompt = __('combat.pick_defend');
        }

        $this->persistFightMessage($update, $fight);
        $responder->edit(
            $this->fightStatus->format($fight, $player->username) . $prompt,
            TelegramKeyboards::defend(),
        );
    }

    private function defend(TelegramUpdate $update, TelegramResponder $responder, ZoneEnum $zone): void
    {
        $saved = DB::transaction(function () use ($update, $zone): ?array {
            $player = Character::query()->find($update->userId());

            if ($player === null || ! $this->fights->exists($player->tg_id)) {
                return null;
            }

            $fight = $this->fights->findByTgId($player->tg_id);

            if ($fight->step === FightStepEnum::DEFEND) {
                $fight->player_defend = $zone;
                $fight->player_defend_second = null;

                $loadout = $this->loadout->forCharacter($player);

                if ($loadout->blockSlots >= 2) {
                    $fight->step = FightStepEnum::DEFEND_SECOND;
                    $this->fights->save($fight);

                    return [
                        'kind' => 'second',
                        'player' => $player,
                        'fight' => $fight,
                        'first' => $zone,
                    ];
                }

                $this->fights->save($fight);

                return ['kind' => 'resolve', 'player' => $player];
            }

            if ($fight->step !== FightStepEnum::DEFEND_SECOND) {
                return null;
            }

            if ($fight->player_defend === $zone) {
                return null;
            }

            $fight->player_defend_second = $zone;
            $this->fights->save($fight);

            return ['kind' => 'resolve', 'player' => $player];
        });

        if ($saved === null) {
            return;
        }

        if ($saved['kind'] === 'second') {
            /** @var Character $player */
            $player = $saved['player'];
            /** @var Fight $fight */
            $fight = $saved['fight'];
            /** @var ZoneEnum $first */
            $first = $saved['first'];

            $this->persistFightMessage($update, $fight);
            $responder->edit(
                $this->fightStatus->format($fight, $player->username) . __('combat.pick_defend_second'),
                TelegramKeyboards::defendExcluding($first),
            );

            return;
        }

        /** @var Character $player */
        $player = $saved['player'];
        $this->persistFightMessage($update, $this->fights->findByTgId($player->tg_id));
        $outcome = $this->rounds->resolve($player);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return;
        }

        if ($outcome->kind === 'win') {
            $this->endFight($responder, $outcome->character, $outcome->fight, true);

            return;
        }

        if ($outcome->kind === 'lose') {
            $this->endFight($responder, $outcome->character, $outcome->fight, false);

            return;
        }

        $responder->edit(
            $this->fightStatus->format($outcome->fight, $outcome->character->username) . __('combat.pick_stance'),
            TelegramKeyboards::stance(),
        );
    }

    private function startFight(TelegramUpdate $update, TelegramResponder $responder, string $catalogId): void
    {
        $player = $this->requireDone($update, $responder);

        if (! $player instanceof Character) {
            return;
        }

        if ($player->current_hp <= 0) {
            $responder->reply(__('errors.no_hp'), null);

            return;
        }

        if ($player->city_id === null) {
            $responder->reply(__('errors.no_forest'), null);

            return;
        }

        $city = City::query()->find($player->city_id);

        if (! $city instanceof City || ! $city->has_forest) {
            $responder->reply(__('errors.no_forest'), null);

            return;
        }

        $catalog = null;

        foreach ($this->cityQuery->forestCatalogs($city->id) as $row) {
            if ($row->catalog_id === $catalogId) {
                $catalog = $row;
            }
        }

        if (! $catalog instanceof EnemyCatalog) {
            $responder->reply(__('errors.enemy_not_found'), null);

            return;
        }

        $enemy = $this->enemies->makeFromCatalog($catalog, $player);
        $fight = $this->fights->createTraining($player, $enemy);
        $messageId = $responder->reply(
            $this->fightStatus->format($fight, $player->username) . __('combat.pick_stance'),
            TelegramKeyboards::stance(),
        );
        $this->fights->rememberTelegramMessage($fight, $update->chatId(), $messageId);
    }

    private function endFight(
        TelegramResponder $responder,
        Character $player,
        Fight $fight,
        bool $won,
    ): void {
        $text = $this->fightStatus->format($fight, $player->username);

        if ($fight->tutorial) {
            if ($won) {
                $player = $this->onboarding->onTutorialWin($player);
                $this->clearFight->handle($player->tg_id);
                $reward = $this->tutorialReward();
                $responder->edit(
                    $text . __('onboarding.tutorial_win', [
                        'exp' => $reward['exp'],
                        'silver' => $reward['silver'],
                    ]),
                    null,
                );
                $responder->reply(
                    $this->onboarding->statsQuestText($player),
                    TelegramKeyboards::statsQuest($player),
                );

                return;
            }

            $player = $this->onboarding->onTutorialLose($player);
            $this->clearFight->handle($player->tg_id);
            $responder->edit($text . __('onboarding.tutorial_lose'), null);
            $responder->reply($this->onboarding->introText(), TelegramKeyboards::intro());
            $player->onboarding_step = OnboardingStepEnum::INTRO;
            $player->save();

            return;
        }

        if ($this->isHallFight($fight)) {
            $this->endHallFight($responder, $player, $fight, $won, $text);

            return;
        }

        $pierceCount = $fight->pierce_count;

        if ($won) {
            $broken = $this->fightWear->handleAfterWin($player, $pierceCount);
            $player = $this->characters->findByTgId($player->tg_id);
            $brokeSuffix = $this->brokenGearSuffix($broken);
            $enemy = $this->fights->enemy($fight);
            $loot = $this->winLoot->handle($player, $enemy);
            $player = $this->characters->findByTgId($player->tg_id);
            $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
            $player->current_stamina = $this->characters->clampStamina(
                $fight->player_stamina,
                $this->characters->maxStamina($player),
            );
            $player->last_stamina_update = now();
            $player->save();
            $this->clearFight->handle($player->tg_id);
            $responder->edit(
                $text . __('combat.win', [
                    'exp' => $loot['exp'],
                    'silver' => $loot['silver'],
                ]) . $this->dropSuffix($loot['drop_names']) . $brokeSuffix,
                TelegramKeyboards::mainMenu(),
            );

            return;
        }

        $broken = $this->fightWear->handleAfterLose($player, $pierceCount);
        $gemBroken = $this->breakGems->handle($player);
        $player = $this->characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken) . $this->brokenGemsSuffix($gemBroken);
        $player->current_hp = 0;
        $player->last_hp_update = now();
        $player->current_stamina = 0;
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);
        $responder->edit($text . __('combat.lose') . $brokeSuffix, TelegramKeyboards::mainMenu());
    }

    /**
     * @return list<PlayerAttackEnum>
     */
    private function availablePotionAttacks(Character $player, Fight $fight): array
    {
        if ($fight->tutorial) {
            return [];
        }

        $attacks = [];

        if ($this->bag->potionCountByProfile($player->tg_id, ProfileEnum::HEAL) > 0) {
            $attacks[] = PlayerAttackEnum::POTION;
        }

        if ($this->bag->potionCountByProfile($player->tg_id, ProfileEnum::STAMINA) > 0) {
            $attacks[] = PlayerAttackEnum::STAMINA_POTION;
        }

        return $attacks;
    }

    private function canUsePotionAttack(Character $player, PlayerAttackEnum $attack): bool
    {
        if ($attack === PlayerAttackEnum::POTION) {
            return $this->bag->potionCountByProfile($player->tg_id, ProfileEnum::HEAL) > 0;
        }

        if ($attack === PlayerAttackEnum::STAMINA_POTION) {
            return $this->bag->potionCountByProfile($player->tg_id, ProfileEnum::STAMINA) > 0;
        }

        return false;
    }

    /**
     * @param  list<string>  $broken
     */
    private function brokenGearSuffix(array $broken): string
    {
        if ($broken === []) {
            return '';
        }

        return __('combat.gear_broke', ['names' => implode(', ', $broken)]);
    }

    /**
     * @param  list<string>  $names
     */
    private function dropSuffix(array $names): string
    {
        if ($names === []) {
            return '';
        }

        return __('combat.drop', ['names' => implode(', ', $names)]);
    }

    /**
     * @param  list<string>  $broken
     */
    private function brokenGemsSuffix(array $broken): string
    {
        if ($broken === []) {
            return '';
        }

        return __('combat.gems_broke', ['names' => implode(', ', $broken)]);
    }

    private function requireDone(TelegramUpdate $update, TelegramResponder $responder): ?Character
    {
        if (! $update->hasFrom()) {
            return null;
        }

        $player = Character::query()->find($update->userId());

        if ($player === null) {
            $responder->reply(__('common.press_start'), null);

            return null;
        }

        $player = $this->characters->applyRegen($player);
        $player = $this->loadout->dropUnmetEquipped($player);

        if ($player->onboarding_step !== OnboardingStepEnum::DONE) {
            $responder->reply($this->onboarding->stepHint($player->onboarding_step->value), null);

            return null;
        }

        return $player;
    }

    /**
     * @return array{exp: int, silver: int}
     */
    private function tutorialReward(): array
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('rewards', $onboarding) || ! is_array($onboarding['rewards'])) {
            throw new RuntimeException('onboarding.rewards missing.');
        }

        $row = $onboarding['rewards']['tutorialWin'] ?? null;

        if (! is_array($row) || ! is_int($row['exp']) || ! is_int($row['silver'])) {
            throw new RuntimeException('tutorialWin reward missing.');
        }

        return ['exp' => $row['exp'], 'silver' => $row['silver']];
    }

    private function endHallFight(
        TelegramResponder $responder,
        Character $player,
        Fight $fight,
        bool $won,
        string $text,
    ): void {
        if ($won) {
            $reward = $this->config->trainingReward();
            $this->characters->addExpSilver($player, $reward['exp'], $reward['silver']);
            $player = $this->characters->findByTgId($player->tg_id);
            $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
            $player->current_stamina = $this->characters->clampStamina(
                $fight->player_stamina,
                $this->characters->maxStamina($player),
            );
            $player->last_stamina_update = now();
            $player->save();
            $this->clearFight->handle($player->tg_id);
            $responder->edit(
                $text . __('combat.win', [
                    'exp' => $reward['exp'],
                    'silver' => $reward['silver'],
                ]),
                TelegramKeyboards::backToCity(),
            );

            return;
        }

        $player->current_hp = 0;
        $player->last_hp_update = now();
        $player->current_stamina = 0;
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);
        $responder->edit($text . __('combat.lose'), TelegramKeyboards::backToCity());
    }

    private function isHallFight(Fight $fight): bool
    {
        if ($fight->tutorial) {
            return false;
        }

        return $this->fights->enemy($fight)->catalogId === EnemyCatalog::TUTORIAL_CATALOG_ID;
    }
}
