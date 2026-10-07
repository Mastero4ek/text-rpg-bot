<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Backpack\BackpackApplyFightWearAction;
use App\Actions\Bag\BagGemBreakOnLoseAction;
use App\Actions\Enemy\EnemyApplyWinLootAction;
use App\Actions\Fight\FightClearAction;
use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\Enemy\EnemyCatalog;
use App\Models\Fight;
use App\Services\CharacterService;
use App\Services\Fight\FightRoundService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Services\Onboarding\OnboardingService;
use App\Support\Telegram\FightStatusFormatter;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramHtml;
use App\Telegram\Keyboards\TelegramKeyboards;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

final class ResolveFightTurnTimeoutJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tgId,
        public int $turnSeq,
    ) {}

    public function handle(
        FightRoundService $rounds,
        FightService $fights,
        FightStatusFormatter $fightStatus,
        TelegramClient $telegram,
        CharacterService $characters,
        EnemyApplyWinLootAction $winLoot,
        BackpackApplyFightWearAction $fightWear,
        BagGemBreakOnLoseAction $breakGems,
        OnboardingService $onboarding,
        GameConfig $config,
        FightClearAction $clearFight,
    ): void {
        if (! $fights->exists($this->tgId)) {
            return;
        }

        $fight = $fights->findByTgId($this->tgId);

        if ($fight->turn_seq !== $this->turnSeq) {
            return;
        }

        if (! $fights->turnTimedOut($fight)) {
            return;
        }

        $player = Character::query()->find($this->tgId);

        if ($player === null) {
            return;
        }

        $outcome = $rounds->resolveSkip($player);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return;
        }

        $chatId = $outcome->fight->tg_chat_id;
        $messageId = $outcome->fight->tg_message_id;
        $text = $fightStatus->format($outcome->fight, $outcome->character->username);

        if ($outcome->kind === 'continue') {
            if ($chatId !== null && $messageId !== null) {
                $telegram->editMessageText(
                    $chatId,
                    $messageId,
                    $text . __('combat.pick_stance'),
                    TelegramKeyboards::stance(),
                );
            }

            return;
        }

        if ($outcome->kind === 'win') {
            $this->finishWin(
                $telegram,
                $fights,
                $clearFight,
                $characters,
                $winLoot,
                $fightWear,
                $onboarding,
                $config,
                $outcome->character,
                $outcome->fight,
                $text,
                $chatId,
                $messageId,
            );

            return;
        }

        $this->finishLose(
            $telegram,
            $fights,
            $clearFight,
            $characters,
            $fightWear,
            $breakGems,
            $onboarding,
            $outcome->character,
            $outcome->fight,
            $text,
            $chatId,
            $messageId,
        );
    }

    private function finishWin(
        TelegramClient $telegram,
        FightService $fights,
        FightClearAction $clearFight,
        CharacterService $characters,
        EnemyApplyWinLootAction $winLoot,
        BackpackApplyFightWearAction $fightWear,
        OnboardingService $onboarding,
        GameConfig $config,
        Character $player,
        Fight $fight,
        string $text,
        ?int $chatId,
        ?int $messageId,
    ): void {
        if ($fight->tutorial) {
            $player = $onboarding->onTutorialWin($player);
            $clearFight->handle($player->tg_id);
            $reward = $this->tutorialReward($config);

            if ($chatId !== null && $messageId !== null) {
                $telegram->editMessageText(
                    $chatId,
                    $messageId,
                    $text . __('onboarding.tutorial_win', [
                        'exp' => $reward['exp'],
                        'silver' => $reward['silver'],
                    ]),
                    null,
                );
                $telegram->sendMessage(
                    $chatId,
                    $onboarding->statsQuestText($player),
                    TelegramKeyboards::statsQuest($player),
                );
            }

            return;
        }

        if ($this->isHallFight($fights, $fight)) {
            $this->finishHallWin(
                $telegram,
                $clearFight,
                $characters,
                $config,
                $player,
                $fight,
                $text,
                $chatId,
                $messageId,
            );

            return;
        }

        $broken = $fightWear->handleAfterWin($player, $fight->pierce_count);
        $player = $characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken);
        $enemy = $fights->enemy($fight);
        $loot = $winLoot->handle($player, $enemy);
        $player = $characters->findByTgId($player->tg_id);
        $player->current_hp = max(1, min($fight->player_hp, $characters->maxHp($player)));
        $player->current_stamina = $characters->clampStamina(
            $fight->player_stamina,
            $characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $clearFight->handle($player->tg_id);

        if ($chatId !== null && $messageId !== null) {
            $telegram->editMessageText(
                $chatId,
                $messageId,
                $text . __('combat.win', [
                    'exp' => $loot['exp'],
                    'silver' => $loot['silver'],
                ]) . $this->dropSuffix($loot['drop_names']) . $brokeSuffix,
                TelegramKeyboards::mainMenu(),
            );
        }
    }

    private function finishLose(
        TelegramClient $telegram,
        FightService $fights,
        FightClearAction $clearFight,
        CharacterService $characters,
        BackpackApplyFightWearAction $fightWear,
        BagGemBreakOnLoseAction $breakGems,
        OnboardingService $onboarding,
        Character $player,
        Fight $fight,
        string $text,
        ?int $chatId,
        ?int $messageId,
    ): void {
        if ($fight->tutorial) {
            $player = $onboarding->onTutorialLose($player);
            $clearFight->handle($player->tg_id);

            if ($chatId !== null && $messageId !== null) {
                $telegram->editMessageText(
                    $chatId,
                    $messageId,
                    $text . __('onboarding.tutorial_lose'),
                    null,
                );
                $telegram->sendMessage(
                    $chatId,
                    $onboarding->introText(),
                    TelegramKeyboards::intro(),
                );
            }

            $player->progress_step = ProgressStepEnum::INTRO;
            $player->save();

            return;
        }

        if ($this->isHallFight($fights, $fight)) {
            $this->finishHallLose(
                $telegram,
                $clearFight,
                $player,
                $text,
                $chatId,
                $messageId,
            );

            return;
        }

        $broken = $fightWear->handleAfterLose($player, $fight->pierce_count);
        $gemBroken = $breakGems->handle($player);
        $player = $characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken) . $this->brokenGemsSuffix($gemBroken);
        $player->current_hp = 0;
        $player->last_hp_update = now();
        $player->current_stamina = 0;
        $player->last_stamina_update = now();
        $player->save();
        $clearFight->handle($player->tg_id);

        if ($chatId !== null && $messageId !== null) {
            $telegram->editMessageText(
                $chatId,
                $messageId,
                $text . __('combat.lose') . $brokeSuffix,
                TelegramKeyboards::mainMenu(),
            );
        }
    }

    private function finishHallWin(
        TelegramClient $telegram,
        FightClearAction $clearFight,
        CharacterService $characters,
        GameConfig $config,
        Character $player,
        Fight $fight,
        string $text,
        ?int $chatId,
        ?int $messageId,
    ): void {
        $reward = $config->trainingReward();
        $characters->addExpSilver($player, $reward['exp'], $reward['silver']);
        $player = $characters->findByTgId($player->tg_id);
        $player->current_hp = max(1, min($fight->player_hp, $characters->maxHp($player)));
        $player->current_stamina = $characters->clampStamina(
            $fight->player_stamina,
            $characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $clearFight->handle($player->tg_id);

        if ($chatId !== null && $messageId !== null) {
            $telegram->editMessageText(
                $chatId,
                $messageId,
                $text . __('combat.win', [
                    'exp' => $reward['exp'],
                    'silver' => $reward['silver'],
                ]),
                TelegramKeyboards::mainMenu(),
            );
        }
    }

    private function finishHallLose(
        TelegramClient $telegram,
        FightClearAction $clearFight,
        Character $player,
        string $text,
        ?int $chatId,
        ?int $messageId,
    ): void {
        $player->current_hp = 0;
        $player->last_hp_update = now();
        $player->current_stamina = 0;
        $player->last_stamina_update = now();
        $player->save();
        $clearFight->handle($player->tg_id);

        if ($chatId !== null && $messageId !== null) {
            $telegram->editMessageText(
                $chatId,
                $messageId,
                $text . __('combat.lose'),
                TelegramKeyboards::mainMenu(),
            );
        }
    }

    private function isHallFight(FightService $fights, Fight $fight): bool
    {
        if ($fight->tutorial) {
            return false;
        }

        return $fights->enemy($fight)->catalogId === EnemyCatalog::TUTORIAL_CATALOG_ID;
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
     * @return array{exp: int, silver: int}
     */
    private function tutorialReward(GameConfig $config): array
    {
        $onboarding = $config->onboarding();

        if (! array_key_exists('rewards', $onboarding) || ! is_array($onboarding['rewards'])) {
            throw new RuntimeException('onboarding.rewards missing.');
        }

        $row = $onboarding['rewards']['tutorialQuest'] ?? null;

        if (! is_array($row) || ! is_int($row['exp']) || ! is_int($row['silver'])) {
            throw new RuntimeException('tutorialQuest reward missing.');
        }

        return ['exp' => $row['exp'], 'silver' => $row['silver']];
    }
}
