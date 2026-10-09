<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\City\CityHealerBuyPotionAction;
use App\Actions\City\CityHealerHealAction;
use App\Enums\Economy\CurrencyEnum;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\Bag\BagCatalog;
use App\Services\CityMenuService;
use App\Services\GameConfig;
use App\Support\PotionDef;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use RuntimeException;
use Throwable;

final class HealerHandler
{
    public function __construct(
        private readonly BagCatalog $bagCatalog,
        private readonly CityHealerBuyPotionAction $buyPotion,
        private readonly CityHealerHealAction $healer,
        private readonly CityMenuService $cityMenu,
        private readonly CityQuery $cityQuery,
        private readonly GameConfig $config,
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

        if ($data === 'city:healer') {
            $this->healerScreen($responder, $player);

            return;
        }

        if ($data === 'city:healer:heal') {
            $this->heal($responder, $player);

            return;
        }

        if ($data === 'city:healer:potions') {
            $this->healerPotions($responder, $player);

            return;
        }

        if (preg_match('/^city:healer:potion:(.+)$/', $data, $m) === 1) {
            $this->healerBuyPotion($responder, $player, $m[1]);
        }
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editHealerPanel(TelegramResponder $responder, string $text, array $markup): void
    {
        $healerImage = config('bot.healer_tavern_image');

        if (is_string($healerImage) && $healerImage !== '' && is_file($healerImage)) {
            try {
                $responder->editPhoto($healerImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
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

        return $settings['hospital']['goldCost'];
    }

    private function currencyMark(CurrencyEnum $currency): string
    {
        if ($currency === CurrencyEnum::GOLD) {
            return '🥇';
        }

        return '🪙';
    }

    private function heal(TelegramResponder $responder, Character $player): void
    {
        $res = $this->healer->handle($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->editHealerPanel(
                $responder,
                TelegramResponder::errorMessage($res->error),
                $this->healerErrorMarkup($res->error),
            );

            return;
        }

        $this->editHealerPanel(
            $responder,
            __('telegram.npc.healer.done', ['gold' => $this->goldCost()]),
            CityKeyboard::backToHealer(),
        );
    }

    private function healerBuyPotion(TelegramResponder $responder, Character $player, string $catalogId): void
    {
        $res = $this->buyPotion->handle($player->tg_id, $catalogId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->potion instanceof PotionDef) {
            $this->editHealerPanel(
                $responder,
                TelegramResponder::errorMessage($res->error),
                $this->healerPotionErrorMarkup($player, $res->error),
            );

            return;
        }

        $this->editHealerPanel(
            $responder,
            __('telegram.npc.healer.bought_potion', [
                'name' => TelegramHtml::escape($res->potion->name),
            ]),
            CityKeyboard::healerPotions($this->healerPotionRows($res->character)),
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private function healerErrorMarkup(?string $error): array
    {
        if ($error === __('errors.city_unavailable') || $error === __('telegram.npc.healer.error.no_healer')) {
            return CityKeyboard::backToTavern();
        }

        return CityKeyboard::backToHealer();
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private function healerPotionErrorMarkup(Character $player, ?string $error): array
    {
        if ($error === __('errors.city_unavailable') || $error === __('telegram.npc.healer.error.no_healer')) {
            return CityKeyboard::backToTavern();
        }

        $potions = $this->healerPotionRows($player);

        if (empty($potions)) {
            return CityKeyboard::backToHealer();
        }

        return CityKeyboard::healerPotions($potions);
    }

    /**
     * @return list<array{text: string, catalog_id: string}>
     */
    private function healerPotionRows(Character $player): array
    {
        if ($player->city_id === null) {
            return [];
        }

        $rows = [];

        foreach ($this->cityQuery->bagPotionShopCatalogIds($player->city_id) as $catalogId) {
            $potion = $this->bagCatalog->findPotion($catalogId);
            $rows[] = [
                'text' => __('telegram.npc.healer.potion_row', [
                    'name' => $potion->name,
                    'price' => $potion->price,
                    'mark' => $this->currencyMark($potion->currency),
                ]),
                'catalog_id' => $potion->catalogId,
            ];
        }

        return $rows;
    }

    private function healerPotions(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_healer) {
            $this->editHealerPanel(
                $responder,
                __('telegram.npc.healer.error.no_healer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $potions = $this->healerPotionRows($player);

        if (empty($potions)) {
            $this->editHealerPanel(
                $responder,
                __('telegram.npc.healer.potions_empty'),
                CityKeyboard::backToHealer(),
            );

            return;
        }

        $this->editHealerPanel(
            $responder,
            __('telegram.npc.healer.potions'),
            CityKeyboard::healerPotions($potions),
        );
    }

    private function healerScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_healer) {
            $this->editHealerPanel(
                $responder,
                __('telegram.npc.healer.error.no_healer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $this->editHealerPanel(
            $responder,
            __('telegram.npc.healer.offer', ['gold' => $this->goldCost()]),
            CityKeyboard::healerOffer(),
        );
    }
}
