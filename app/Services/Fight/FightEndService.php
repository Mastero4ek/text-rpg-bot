<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Actions\Backpack\BackpackApplyFightWearAction;
use App\Actions\Bag\BagGemBreakOnLoseAction;
use App\Actions\DailyContract\DailyContractMarkReadyAction;
use App\Actions\Enemy\EnemyApplyWinLootAction;
use App\Actions\Fight\FightClearAction;
use App\Enums\Fight\FightEndUiEnum;
use App\Enums\Fight\FightKindEnum;
use App\Enums\NpcEnum;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\Fight;
use App\Quests\BlacksmithQuest;
use App\Quests\BruiserQuest;
use App\Quests\HealerQuest;
use App\Quests\OverseerQuest;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Support\Telegram\TelegramHtml;

final class FightEndService
{
    public function __construct(
        private readonly BlacksmithQuest $blacksmithQuest,
        private readonly BruiserQuest $bruiserQuest,
        private readonly CharacterService $characters,
        private readonly FightService $fights,
        private readonly FightClearAction $clearFight,
        private readonly BackpackApplyFightWearAction $fightWear,
        private readonly BagGemBreakOnLoseAction $breakGems,
        private readonly EnemyApplyWinLootAction $winLoot,
        private readonly HealerQuest $healerQuest,
        private readonly OverseerQuest $overseerQuest,
        private readonly DailyContractMarkReadyAction $dailyMarkReady,
        private readonly GameConfig $config,
        private readonly FightStatusFormatter $fightStatus,
    ) {}

    public function finishWin(Character $player, Fight $fight): FightEndResult
    {
        $text = $this->fightStatus->format($fight, $player);

        if ($fight->kind === FightKindEnum::QUEST_OVERSEER) {
            return $this->overseerWin($player, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_BLACKSMITH) {
            return $this->blacksmithWin($player, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_BRUISER) {
            return $this->bruiserWin($player, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_HEALER) {
            return $this->healerRatWin($player, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_DAILY) {
            return $this->dailyWin($player, $fight, $text);
        }

        if ($fight->hall) {
            return $this->hallWin($player, $fight, $text);
        }

        return $this->trainingWin($player, $fight, $text);
    }

    public function finishFlee(Character $player, Fight $fight): FightEndResult
    {
        $text = $this->fightStatus->format($fight, $player);

        if ($fight->kind === FightKindEnum::QUEST_BLACKSMITH) {
            return $this->blacksmithFlee($player, $fight, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_BRUISER) {
            return $this->bruiserFlee($player, $fight, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_HEALER) {
            return $this->healerRatFlee($player, $fight, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_DAILY) {
            return $this->trainingFlee($player, $fight, $text);
        }

        if ($fight->hall) {
            return $this->hallFlee($player, $fight, $text);
        }

        return $this->trainingFlee($player, $fight, $text);
    }

    public function finishLose(Character $player, Fight $fight): FightEndResult
    {
        $text = $this->fightStatus->format($fight, $player);

        if ($fight->kind === FightKindEnum::QUEST_OVERSEER) {
            return $this->overseerLose($player, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_BLACKSMITH) {
            return $this->blacksmithLose($player, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_BRUISER) {
            return $this->bruiserLose($player, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_HEALER) {
            return $this->healerRatLose($player, $text);
        }

        if ($fight->kind === FightKindEnum::QUEST_DAILY) {
            return $this->trainingLose($player, $fight, $text);
        }

        if ($fight->hall) {
            return $this->hallLose($player, $fight, $text);
        }

        return $this->trainingLose($player, $fight, $text);
    }

    private function dailyWin(Character $player, Fight $fight, string $text): FightEndResult
    {
        $logText = $this->fightLogText($fight);
        $broken = $this->fightWear->handleAfterWin($player, $fight->pierce_count);
        $player = $this->characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken);
        $this->dailyMarkReady->handle($player, $fight);
        $player = $this->characters->findByTgId($player->tg_id);
        $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
        $player->current_stamina = $this->characters->clampStamina(
            $fight->player_stamina,
            $this->characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();

        $issuer = NpcEnum::Buyer;
        $fight->loadMissing('dailyContract');

        if ($fight->dailyContract instanceof CharacterDailyContract) {
            $issuer = $fight->dailyContract->issuer_npc;
        }

        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . "\n\n" . __('telegram.daily.win_ready', [
                'npc' => __('telegram.daily.npc.' . $issuer->value),
            ]) . $brokeSuffix,
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
            $logText,
        );
    }

    private function bruiserFlee(Character $player, Fight $fight, string $text): FightEndResult
    {
        $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
        $player->current_stamina = $this->characters->clampStamina(
            $fight->player_stamina,
            $this->characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            __('combat.flee') . "\n\n" . $this->bruiserQuest->meetSpeech($player),
            FightEndUiEnum::BruiserArrive,
            null,
        );
    }

    private function bruiserLose(Character $player, string $text): FightEndResult
    {
        $res = $this->bruiserQuest->openDebt($player);
        $this->clearFight->handle($player->tg_id);

        if (! $res->ok || ! $res->character instanceof Character) {
            return new FightEndResult(
                $player,
                $text . $this->bruiserQuest->fightLoseSpeech($player),
                FightEndUiEnum::None,
                null,
                FightEndUiEnum::None,
                null,
            );
        }

        $player = $res->character;

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            $this->bruiserQuest->fightLoseSpeech($player),
            FightEndUiEnum::BruiserKnockout,
            null,
        );
    }

    private function bruiserWin(Character $player, string $text): FightEndResult
    {
        $res = $this->bruiserQuest->complete($player);
        $this->clearFight->handle($player->tg_id);

        if (! $res->ok || ! $res->character instanceof Character) {
            $error = $res->error;

            if ($error === null) {
                $error = __('telegram.quest.bruiser.error.wrong_phase');
            }

            return new FightEndResult(
                $player,
                $text,
                FightEndUiEnum::None,
                $error . "\n\n" . $this->bruiserQuest->meetSpeech($player),
                FightEndUiEnum::BruiserArrive,
                null,
            );
        }

        $player = $res->character;

        if ($player->bruiser_knuckle_pending) {
            return new FightEndResult(
                $player,
                $text,
                FightEndUiEnum::None,
                $this->bruiserQuest->knucklePendingSpeech($player),
                FightEndUiEnum::BruiserKnucklePending,
                null,
            );
        }

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            $this->bruiserQuest->completeSpeech($player),
            FightEndUiEnum::BruiserComplete,
            null,
        );
    }

    private function healerRatFlee(Character $player, Fight $fight, string $text): FightEndResult
    {
        $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
        $player->current_stamina = $this->characters->clampStamina(
            $fight->player_stamina,
            $this->characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            __('combat.flee') . "\n\n" . $this->healerQuest->debtSpeech($player),
            FightEndUiEnum::HealerDebt,
            null,
        );
    }

    private function healerRatLose(Character $player, string $text): FightEndResult
    {
        $maxHp = $this->characters->maxHp($player);
        $maxStamina = $this->characters->maxStamina($player);
        $player->current_hp = $maxHp;
        $player->current_stamina = $maxStamina;
        $player->last_hp_update = now();
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            __('telegram.quest.healer.rat_lose') . "\n\n" . $this->healerQuest->debtSpeech($player),
            FightEndUiEnum::HealerDebt,
            null,
        );
    }

    private function healerRatWin(Character $player, string $text): FightEndResult
    {
        $res = $this->healerQuest->markRatWin($player);
        $this->clearFight->handle($player->tg_id);

        if (! $res->ok || ! $res->character instanceof Character) {
            $error = $res->error;

            if ($error === null) {
                $error = __('telegram.quest.healer.error.debt_wrong_phase');
            }

            return new FightEndResult(
                $player,
                $text,
                FightEndUiEnum::None,
                $error . "\n\n" . $this->healerQuest->debtSpeech($player),
                FightEndUiEnum::HealerDebt,
                null,
            );
        }

        $player = $res->character;

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            $this->healerQuest->ratLootSpeech($player),
            FightEndUiEnum::HealerRatLoot,
            null,
        );
    }

    private function blacksmithFlee(Character $player, Fight $fight, string $text): FightEndResult
    {
        $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
        $player->current_stamina = $this->characters->clampStamina(
            $fight->player_stamina,
            $this->characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            __('combat.flee') . "\n\n" . $this->blacksmithQuest->meetSpeech($player),
            FightEndUiEnum::BlacksmithMeet,
            null,
        );
    }

    private function blacksmithLose(Character $player, string $text): FightEndResult
    {
        $res = $this->blacksmithQuest->resetAfterLose($player);
        $this->clearFight->handle($player->tg_id);

        if (! $res->ok || ! $res->character instanceof Character) {
            return new FightEndResult(
                $player,
                $text . __('telegram.quest.blacksmith.fight_lose'),
                FightEndUiEnum::None,
                null,
                FightEndUiEnum::None,
                null,
            );
        }

        $player = $res->character;

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            __('telegram.quest.blacksmith.fight_lose') . "\n\n" . $this->blacksmithQuest->retrySpeech($player),
            FightEndUiEnum::BlacksmithMeet,
            null,
        );
    }

    private function blacksmithWin(Character $player, string $text): FightEndResult
    {
        $res = $this->blacksmithQuest->complete($player);
        $this->clearFight->handle($player->tg_id);

        if (! $res->ok || ! $res->character instanceof Character) {
            $reset = $this->blacksmithQuest->resetAfterLose($player);
            $player = $reset->character instanceof Character ? $reset->character : $player;
            $error = $res->error;

            if ($error === null) {
                $error = __('telegram.quest.blacksmith.error.wrong_phase');
            }

            return new FightEndResult(
                $player,
                $text,
                FightEndUiEnum::None,
                $error . "\n\n" . $this->blacksmithQuest->meetSpeech($player),
                FightEndUiEnum::BlacksmithMeet,
                null,
            );
        }

        $player = $res->character;

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            $this->blacksmithQuest->completeSpeech($player),
            FightEndUiEnum::BlacksmithComplete,
            null,
        );
    }

    private function overseerWin(Character $player, string $text): FightEndResult
    {
        $res = $this->overseerQuest->advanceAfterWin($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->clearFight->handle($player->tg_id);

            return new FightEndResult(
                $player,
                $text,
                FightEndUiEnum::None,
                null,
                FightEndUiEnum::None,
                null,
            );
        }

        $player = $res->character;
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            $this->overseerQuest->statsSpeech($player),
            FightEndUiEnum::OverseerStats,
            null,
        );
    }

    private function overseerLose(Character $player, string $text): FightEndResult
    {
        $res = $this->overseerQuest->resetAfterLose($player);
        $this->clearFight->handle($player->tg_id);

        if (! $res->ok || ! $res->character instanceof Character) {
            return new FightEndResult(
                $player,
                $text . __('telegram.quest.overseer.fight_lose'),
                FightEndUiEnum::None,
                null,
                FightEndUiEnum::None,
                null,
            );
        }

        $player = $res->character;

        return new FightEndResult(
            $player,
            $text,
            FightEndUiEnum::None,
            __('telegram.quest.overseer.fight_lose') . "\n\n" . $this->overseerQuest->introSpeech(),
            FightEndUiEnum::Intro,
            null,
        );
    }

    private function hallWin(Character $player, Fight $fight, string $text): FightEndResult
    {
        $logText = $this->fightLogText($fight);
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

        return new FightEndResult(
            $player,
            $text . __('combat.win', [
                'exp' => $reward['exp'],
                'silver' => $reward['silver'],
            ]),
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
            $logText,
        );
    }

    private function hallFlee(Character $player, Fight $fight, string $text): FightEndResult
    {
        $logText = $this->fightLogText($fight);
        $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
        $player->current_stamina = $this->characters->clampStamina(
            $fight->player_stamina,
            $this->characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . __('combat.flee'),
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
            $logText,
        );
    }

    private function hallLose(Character $player, Fight $fight, string $text): FightEndResult
    {
        $logText = $this->fightLogText($fight);
        $player->current_hp = 0;
        $player->last_hp_update = now();
        $player->current_stamina = 0;
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . __('combat.lose'),
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
            $logText,
        );
    }

    private function trainingFlee(Character $player, Fight $fight, string $text): FightEndResult
    {
        $logText = $this->fightLogText($fight);
        $broken = $this->fightWear->handleAfterLose($player, $fight->pierce_count);
        $player = $this->characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken);
        $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
        $player->current_stamina = $this->characters->clampStamina(
            $fight->player_stamina,
            $this->characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . __('combat.flee') . $brokeSuffix,
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
            $logText,
        );
    }

    private function trainingWin(Character $player, Fight $fight, string $text): FightEndResult
    {
        $logText = $this->fightLogText($fight);
        $broken = $this->fightWear->handleAfterWin($player, $fight->pierce_count);
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

        return new FightEndResult(
            $player,
            $text . __('combat.win', [
                'exp' => $loot['exp'],
                'silver' => $loot['silver'],
            ]) . $this->dropSuffix($loot['drop_names']) . $brokeSuffix,
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
            $logText,
        );
    }

    private function trainingLose(Character $player, Fight $fight, string $text): FightEndResult
    {
        $logText = $this->fightLogText($fight);
        $broken = $this->fightWear->handleAfterLose($player, $fight->pierce_count);
        $gemBroken = $this->breakGems->handle($player);
        $player = $this->characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken) . $this->brokenGemsSuffix($gemBroken);
        $player->current_hp = 0;
        $player->last_hp_update = now();
        $player->current_stamina = 0;
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . __('combat.lose') . $brokeSuffix,
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
            $logText,
        );
    }

    /**
     * @param  list<string>  $broken
     */
    private function brokenGearSuffix(array $broken): string
    {
        if ($broken === []) {
            return '';
        }

        return __('combat.gear_broke', ['names' => TelegramHtml::escapeJoin($broken, ', ')]);
    }

    /**
     * @param  list<string>  $broken
     */
    private function brokenGemsSuffix(array $broken): string
    {
        if ($broken === []) {
            return '';
        }

        return __('combat.gems_broke', ['names' => TelegramHtml::escapeJoin($broken, ', ')]);
    }

    /**
     * @param  list<string>  $names
     */
    private function dropSuffix(array $names): string
    {
        if ($names === []) {
            return '';
        }

        return __('combat.drop', ['names' => TelegramHtml::escapeJoin($names, ', ')]);
    }

    private function fightLogText(Fight $fight): string
    {
        if (empty($fight->log)) {
            return __('combat.report_log_empty');
        }

        return implode("\n", $fight->log);
    }
}
