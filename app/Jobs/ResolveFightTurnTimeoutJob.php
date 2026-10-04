<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Fight\FightClearAction;
use App\Actions\Gem\GemBreakOnLoseAction;
use App\Actions\Inventory\InventoryApplyFightWearAction;
use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\Character\CharacterService;
use App\Services\Combat\CombatService;
use App\Services\Fight\FightRoundService;
use App\Services\Fight\FightService;
use App\Services\Game\GameConfig;
use App\Services\Onboarding\OnboardingService;
use App\Support\Telegram\FightStatusFormatter;
use App\Support\Telegram\TelegramClient;
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
        CombatService $combat,
        InventoryApplyFightWearAction $fightWear,
        GemBreakOnLoseAction $breakGems,
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
                $combat,
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
        CombatService $combat,
        InventoryApplyFightWearAction $fightWear,
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

        $broken = $fightWear->handleAfterWin($player, $fight->pierce_count);
        $player = $characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken);
        $enemy = $fights->enemy($fight);
        $reward = $combat->pveRewards($enemy->level);
        $characters->addExpSilver($player, $reward['exp'], $reward['silver']);
        $player = $characters->findByTgId($player->tg_id);
        $player->current_hp = max(1, min($fight->player_hp, $characters->maxHp($player)));
        $player->save();
        $clearFight->handle($player->tg_id);

        if ($chatId !== null && $messageId !== null) {
            $telegram->editMessageText(
                $chatId,
                $messageId,
                $text . __('combat.win', [
                    'exp' => $reward['exp'],
                    'silver' => $reward['silver'],
                ]) . $brokeSuffix,
                TelegramKeyboards::mainMenu(),
            );
        }
    }

    private function finishLose(
        TelegramClient $telegram,
        FightClearAction $clearFight,
        CharacterService $characters,
        InventoryApplyFightWearAction $fightWear,
        GemBreakOnLoseAction $breakGems,
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

            $player->onboarding_step = OnboardingStepEnum::INTRO;
            $player->save();

            return;
        }

        $broken = $fightWear->handleAfterLose($player, $fight->pierce_count);
        $gemBroken = $breakGems->handle($player);
        $player = $characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken) . $this->brokenGemsSuffix($gemBroken);
        $player->current_hp = 0;
        $player->last_hp_update = now();
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
     * @param  list<string>  $broken
     */
    private function brokenGemsSuffix(array $broken): string
    {
        if ($broken === []) {
            return '';
        }

        return __('combat.gems_broke', ['names' => implode(', ', $broken)]);
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

        $row = $onboarding['rewards']['tutorialWin'] ?? null;

        if (! is_array($row) || ! is_int($row['exp']) || ! is_int($row['silver'])) {
            throw new RuntimeException('tutorialWin reward missing.');
        }

        return ['exp' => $row['exp'], 'silver' => $row['silver']];
    }
}
