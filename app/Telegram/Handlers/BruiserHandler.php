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

final class BruiserHandler
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

        if ($data === 'city:bruiser') {
            $this->bruiserScreen($responder, $player);
        }
    }

    private function bruiserScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);
        $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Bruiser);

        if (! $city instanceof City || ! $city->has_bruiser) {
            $this->editBruiserPanel(
                $responder,
                __('telegram.npc.bruiser.error.no_bruiser'),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        if (! $this->npcPlacement->isBruiserAtPort($player)) {
            $this->editBruiserPanel(
                $responder,
                __('telegram.npc.bruiser.error.not_here'),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        $hasDaily = $this->dailyContracts
            ->turnInableForIssuer($player, $city, NpcEnum::Bruiser)
            ->isNotEmpty();

        $this->editBruiserPanel(
            $responder,
            __('telegram.npc.bruiser.offer'),
            CityKeyboard::bruiserOfferWithDaily($hasDaily, $back),
        );
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editBruiserPanel(TelegramResponder $responder, string $text, array $markup): void
    {
        $image = config('bot.bruiser_port_image');

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
