<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Services\Character\CharacterService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Shop\ShopCatalog;
use App\Services\Shop\ShopService;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;

final class ShopHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly OnboardingService $onboarding,
        private readonly ShopCatalog $shop,
        private readonly ShopService $shopService,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();
        $player = $this->requireDone($update, $responder);

        if (! $player instanceof Character) {
            return;
        }

        if ($data === 'menu:shop') {
            $responder->edit(
                __('shop.balance', ['gold' => $player->gold]),
                TelegramKeyboards::fullShop(
                    $this->shop->weaponsForMode('full'),
                    $this->shop->potionPrice(),
                ),
            );

            return;
        }

        if (str_starts_with($data, 'shop:w:')) {
            $res = $this->shopService->buyWeapon($player->tg_id, mb_substr($data, 7));

            if (! $res->ok) {
                $responder->reply(TelegramResponder::errorMessage($res->error), null);

                return;
            }

            if (! $res->def instanceof \App\Support\Game\ItemDef) {
                $responder->reply(__('common.error'), null);

                return;
            }

            $responder->reply(__('shop.bought_weapon', ['name' => $res->def->itemName]), null);

            return;
        }

        if ($data === 'shop:potion') {
            $res = $this->shopService->buyPotion($player->tg_id);

            if (! $res->ok || ! $res->character instanceof Character) {
                $responder->reply(TelegramResponder::errorMessage($res->error), null);

                return;
            }

            $responder->reply(
                __('shop.bought_potion', ['potions' => $res->character->potions]),
                null,
            );
        }
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

        if ($player->onboarding_step !== OnboardingStepEnum::DONE) {
            $responder->reply($this->onboarding->stepHint($player->onboarding_step->value), null);

            return null;
        }

        return $player;
    }
}
