<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Enums\Npc\NpcEnum;
use App\Models\Character\Character;
use App\Models\City;
use App\Services\CityMenuService;
use App\Services\DailyContract\DailyContractService;
use App\Services\Quest\QuestNpcPlacement;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use Throwable;

final class BarkeeperHandler
{
    public function __construct(
        private readonly CityMenuService $cityMenu,
        private readonly DailyContractService $dailyContracts,
        private readonly QuestNpcPlacement $npcPlacement,
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

        if ($data === 'city:barkeeper') {
            $this->barkeeperScreen($responder, $player);
        }
    }

    private function barkeeperScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Barkeeper);

        if (! $city instanceof City || ! $city->has_barkeeper) {
            $this->editBarkeeperPanel(
                $responder,
                __('telegram.npc.barkeeper.error.no_barkeeper'),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        $hasDaily = $this->dailyContracts
            ->turnInableForIssuer($player, $city, NpcEnum::Barkeeper)
            ->isNotEmpty();

        $this->editBarkeeperPanel(
            $responder,
            __('telegram.npc.barkeeper.offer'),
            CityKeyboard::barkeeperOfferWithDaily($hasDaily, $back),
        );
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editBarkeeperPanel(TelegramResponder $responder, string $text, array $markup): void
    {
        $image = config('bot.barkeeper_tavern_image');

        if (is_string($image) && $image !== '' && is_file($image)) {
            try {
                $responder->editPhoto($image, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }
}
