<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Enums\Quest\LocationEnum;
use App\Models\Character\Character;
use App\Services\Registration\RegistrationService;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;
use Throwable;

final class LibraryHandler
{
    /**
     * @var list<string>
     */
    private const GEAR_TOPICS = [
        'wear',
        'socket',
    ];

    /**
     * @var list<string>
     */
    private const ENEMY_ORDER = [
        'wooden_soldier',
        'forest_bandit',
        'wild_dog',
        'rabid_rat',
        'swamp_witch',
        'bruiser',
    ];

    /**
     * @var list<string>
     */
    private const MINIGAME_ORDER = [
        'lucky_barrel',
    ];

    /**
     * @var list<string>
     */
    private const NPC_ORDER = [
        'overseer',
        'barkeeper',
        'healer',
        'blacksmith',
        'buyer',
        'bruiser',
    ];

    /**
     * @var list<LocationEnum>
     */
    private const LOCATION_ORDER = [
        LocationEnum::Gates,
        LocationEnum::Board,
        LocationEnum::Tavern,
        LocationEnum::Portal,
        LocationEnum::Forest,
        LocationEnum::Swamp,
        LocationEnum::Port,
        LocationEnum::Ruins,
        LocationEnum::Arena,
        LocationEnum::Lists,
        LocationEnum::Training,
    ];

    public function __construct(
        private readonly RegistrationService $registration,
        private readonly TelegramPlayerGate $gate,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if ($data === 'lib:home') {
            $this->homeScreen($responder, $player);

            return;
        }

        if ($data === 'lib:loc') {
            $this->locationsScreen($responder, $player);

            return;
        }

        if (preg_match('/^lib:loc:([a-z_]+)$/', $data, $m) === 1) {
            $this->locationCardScreen($responder, $player, $m[1]);

            return;
        }

        if ($data === 'lib:gear') {
            $this->gearScreen($responder, $player);

            return;
        }

        if (preg_match('/^lib:gear:(wear|socket)$/', $data, $m) === 1) {
            $this->gearCardScreen($responder, $player, $m[1]);

            return;
        }

        if ($data === 'lib:pot') {
            $this->potionCardScreen($responder, $player);

            return;
        }

        if ($data === 'lib:gem') {
            $this->gemCardScreen($responder, $player);

            return;
        }

        if ($data === 'lib:stat') {
            $this->statsCardScreen($responder, $player);

            return;
        }

        if ($data === 'lib:combat') {
            $this->combatCardScreen($responder, $player);

            return;
        }

        if ($data === 'lib:contract') {
            $this->contractCardScreen($responder, $player);

            return;
        }

        if ($data === 'lib:npc') {
            $this->npcsScreen($responder, $player);

            return;
        }

        if (preg_match('/^lib:npc:([a-z_]+)$/', $data, $m) === 1) {
            $this->npcCardScreen($responder, $player, $m[1]);

            return;
        }

        if ($data === 'lib:enemy') {
            $this->enemiesScreen($responder, $player);

            return;
        }

        if (preg_match('/^lib:enemy:([a-z_]+)$/', $data, $m) === 1) {
            $this->enemyCardScreen($responder, $player, $m[1]);

            return;
        }

        if ($data === 'lib:game') {
            $this->minigamesScreen($responder, $player);

            return;
        }

        if (preg_match('/^lib:game:([a-z_]+)$/', $data, $m) === 1) {
            $this->minigameCardScreen($responder, $player, $m[1]);
        }
    }

    public function sendHome(TelegramResponder $responder, Character $player): void
    {
        $this->sendPanel(
            $responder,
            $player,
            __('telegram.library.home'),
            TelegramKeyboards::libraryHome(),
            $this->libraryClosedImagePath(),
        );
    }

    private function gearCardScreen(
        TelegramResponder $responder,
        Character $player,
        string $topic,
    ): void {
        if (! in_array($topic, self::GEAR_TOPICS, true)) {
            $this->gearScreen($responder, $player);

            return;
        }

        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.gear.' . $topic),
            TelegramKeyboards::libraryGearCard(),
            $this->libraryOpenedImagePath(),
        );
    }

    private function gearScreen(TelegramResponder $responder, Character $player): void
    {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.gear.list'),
            TelegramKeyboards::libraryGear(),
            $this->libraryOpenedImagePath(),
        );
    }

    private function enemiesScreen(TelegramResponder $responder, Character $player): void
    {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.enemies.list'),
            TelegramKeyboards::libraryEnemies($this->enemyButtons()),
            $this->libraryOpenedImagePath(),
        );
    }

    /**
     * @return list<array{text: string, callback_data: string}>
     */
    private function enemyButtons(): array
    {
        $buttons = [];

        foreach (self::ENEMY_ORDER as $enemy) {
            $buttons[] = [
                'text' => __('telegram.library.btn.enemy_' . $enemy),
                'callback_data' => 'lib:enemy:' . $enemy,
            ];
        }

        return $buttons;
    }

    private function enemyCardScreen(
        TelegramResponder $responder,
        Character $player,
        string $key,
    ): void {
        if (! in_array($key, self::ENEMY_ORDER, true)) {
            $this->enemiesScreen($responder, $player);

            return;
        }

        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.enemies.' . $key),
            TelegramKeyboards::libraryEnemyCard(),
            $this->libraryOpenedImagePath(),
        );
    }

    private function combatCardScreen(
        TelegramResponder $responder,
        Character $player,
    ): void {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.combat.use'),
            TelegramKeyboards::libraryCombatCard(),
            $this->libraryOpenedImagePath(),
        );
    }

    private function contractCardScreen(
        TelegramResponder $responder,
        Character $player,
    ): void {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.contracts.use'),
            TelegramKeyboards::libraryContractCard(),
            $this->libraryOpenedImagePath(),
        );
    }

    private function gemCardScreen(
        TelegramResponder $responder,
        Character $player,
    ): void {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.gems.use'),
            TelegramKeyboards::libraryGemCard(),
            $this->libraryOpenedImagePath(),
        );
    }

    private function statsCardScreen(
        TelegramResponder $responder,
        Character $player,
    ): void {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.stats.use'),
            TelegramKeyboards::libraryStatsCard(),
            $this->libraryOpenedImagePath(),
        );
    }

    private function homeScreen(TelegramResponder $responder, Character $player): void
    {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.home'),
            TelegramKeyboards::libraryHome(),
            $this->libraryClosedImagePath(),
        );
    }

    private function imageFromConfig(string $configKey): ?string
    {
        $path = config($configKey);

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        return $path;
    }

    private function libraryClosedImagePath(): ?string
    {
        return $this->imageFromConfig('bot.library_image');
    }

    private function libraryOpenedImagePath(): ?string
    {
        return $this->imageFromConfig('bot.library_opened_image');
    }

    private function minigameCardScreen(
        TelegramResponder $responder,
        Character $player,
        string $key,
    ): void {
        if (! in_array($key, self::MINIGAME_ORDER, true)) {
            $this->minigamesScreen($responder, $player);

            return;
        }

        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.minigames.' . $key),
            TelegramKeyboards::libraryMinigameCard(),
            $this->libraryOpenedImagePath(),
        );
    }

    private function minigamesScreen(TelegramResponder $responder, Character $player): void
    {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.minigames.list'),
            TelegramKeyboards::libraryMinigames(),
            $this->libraryOpenedImagePath(),
        );
    }

    /**
     * @return list<array{text: string, callback_data: string}>
     */
    private function npcButtons(): array
    {
        $buttons = [];

        foreach (self::NPC_ORDER as $npc) {
            $buttons[] = [
                'text' => __('telegram.btn.' . $npc),
                'callback_data' => 'lib:npc:' . $npc,
            ];
        }

        return $buttons;
    }

    private function npcCardScreen(
        TelegramResponder $responder,
        Character $player,
        string $key,
    ): void {
        if (! in_array($key, self::NPC_ORDER, true)) {
            $this->npcsScreen($responder, $player);

            return;
        }

        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.npcs.' . $key),
            TelegramKeyboards::libraryNpcCard(),
            $this->libraryOpenedImagePath(),
        );
    }

    private function npcsScreen(TelegramResponder $responder, Character $player): void
    {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.npcs.list'),
            TelegramKeyboards::libraryNpcs($this->npcButtons()),
            $this->libraryOpenedImagePath(),
        );
    }

    private function potionCardScreen(
        TelegramResponder $responder,
        Character $player,
    ): void {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.potions.use'),
            TelegramKeyboards::libraryPotionCard(),
            $this->libraryOpenedImagePath(),
        );
    }

    /**
     * @return list<array{text: string, callback_data: string}>
     */
    private function locationButtons(): array
    {
        $buttons = [];

        foreach (self::LOCATION_ORDER as $location) {
            $buttons[] = [
                'text' => __('telegram.btn.' . $location->value),
                'callback_data' => 'lib:loc:' . $location->value,
            ];
        }

        return $buttons;
    }

    private function locationCardScreen(
        TelegramResponder $responder,
        Character $player,
        string $key,
    ): void {
        $location = LocationEnum::tryFrom($key);

        if (! $location instanceof LocationEnum || ! in_array($location, self::LOCATION_ORDER, true)) {
            $this->locationsScreen($responder, $player);

            return;
        }

        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.locations.' . $location->value),
            TelegramKeyboards::libraryLocationCard(),
            $this->libraryOpenedImagePath(),
        );
    }

    private function locationsScreen(TelegramResponder $responder, Character $player): void
    {
        $this->showPanel(
            $responder,
            $player,
            __('telegram.library.locations.list'),
            TelegramKeyboards::libraryLocations($this->locationButtons()),
            $this->libraryOpenedImagePath(),
        );
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function sendPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        array $markup,
        ?string $imagePath,
    ): void {
        if ($imagePath !== null) {
            $messageId = $responder->replyPhoto($imagePath, $text, $markup);
            $this->registration->rememberTelegramMessage(
                $player,
                $responder->chatId(),
                $messageId,
            );

            return;
        }

        $messageId = $responder->reply($text, $markup);
        $this->registration->rememberTelegramMessage($player, $responder->chatId(), $messageId);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function showPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        array $markup,
        ?string $imagePath,
    ): void {
        if ($imagePath !== null) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);
                $this->registration->rememberTelegramMessage(
                    $player,
                    $responder->chatId(),
                    $responder->messageId(),
                );

                return;
            } catch (Throwable) {
            }
        }

        if ($responder->callbackHasPhoto()) {
            try {
                $responder->edit($text, $markup);
                $this->registration->rememberTelegramMessage(
                    $player,
                    $responder->chatId(),
                    $responder->messageId(),
                );

                return;
            } catch (Throwable) {
            }

            $responder->deleteUpdateMessage();
            $this->sendPanel($responder, $player, $text, $markup, $imagePath);

            return;
        }

        $responder->edit($text, $markup);
        $this->registration->rememberTelegramMessage(
            $player,
            $responder->chatId(),
            $responder->messageId(),
        );
    }
}
