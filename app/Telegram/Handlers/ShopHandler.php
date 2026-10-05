<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Inventory\InventorySellAction;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Models\Inventory;
use App\Services\Character\CharacterService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\LoadoutService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Shop\ShopCatalog;
use App\Services\Shop\ShopService;
use App\Support\Equipment\EquipmentDef;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;

final class ShopHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly InventoryService $inventory,
        private readonly LoadoutService $loadout,
        private readonly OnboardingService $onboarding,
        private readonly ShopCatalog $shop,
        private readonly ShopService $shopService,
        private readonly InventorySellAction $sellItem,
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
            $this->shopScreen($responder, $player);

            return;
        }

        if ($data === 'shop:sell') {
            $this->sellListScreen($responder, $player);

            return;
        }

        if (preg_match('/^shop:sell:(\d+)$/', $data, $m) === 1) {
            $this->sellConfirmScreen($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^shop:sell_yes:(\d+)$/', $data, $m) === 1) {
            $this->sell($responder, $player, (int) $m[1]);

            return;
        }

        if (str_starts_with($data, 'shop:w:')) {
            $res = $this->shopService->buyWeapon($player->tg_id, mb_substr($data, 7));

            if (! $res->ok) {
                $responder->reply(TelegramResponder::errorMessage($res->error), null);

                return;
            }

            if (! $res->def instanceof EquipmentDef) {
                $responder->reply(__('common.error'), null);

                return;
            }

            $responder->reply(__('shop.bought_weapon', ['name' => $res->def->itemName]), null);

            return;
        }

        if (str_starts_with($data, 'shop:g:')) {
            $res = $this->shopService->buyGear($player->tg_id, mb_substr($data, 7));

            if (! $res->ok) {
                $responder->reply(TelegramResponder::errorMessage($res->error), null);

                return;
            }

            if (! $res->def instanceof EquipmentDef) {
                $responder->reply(__('common.error'), null);

                return;
            }

            $responder->reply(__('shop.bought_gear', ['name' => $res->def->itemName]), null);

            return;
        }

        if ($data === 'shop:potion') {
            $res = $this->shopService->buyPotion($player->tg_id);

            if (! $res->ok || ! $res->character instanceof Character) {
                $responder->reply(TelegramResponder::errorMessage($res->error), null);

                return;
            }

            $responder->reply(
                __('shop.bought_potion', [
                    'potions' => $this->inventory->potionCountByProfile(
                        $res->character->tg_id,
                        ProfileEnum::HEAL,
                    ),
                ]),
                null,
            );

            return;
        }

        if ($data === 'shop:stamina_potion') {
            $res = $this->shopService->buyStaminaPotion($player->tg_id);

            if (! $res->ok || ! $res->character instanceof Character) {
                $responder->reply(TelegramResponder::errorMessage($res->error), null);

                return;
            }

            $responder->reply(
                __('shop.bought_stamina_potion', [
                    'potions' => $this->inventory->potionCountByProfile(
                        $res->character->tg_id,
                        ProfileEnum::STAMINA,
                    ),
                ]),
                null,
            );
        }
    }

    private function currencyMark(CurrencyEnum $currency): string
    {
        if ($currency === CurrencyEnum::GOLD) {
            return '🥇';
        }

        return '🪙';
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

    private function sell(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $row = Inventory::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        if (! $this->shop->hasItem($row->item_id)) {
            $responder->reply(__('errors.cannot_sell'), null);

            return;
        }

        $def = $this->shop->findItem($row->item_id);
        $payout = $this->inventory->sellPayout($row);
        $name = $row->item_name;
        $mark = $this->currencyMark($def->currency);

        $res = $this->sellItem->handle($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->reply(__('shop.sold', [
            'name' => $name,
            'price' => $payout,
            'mark' => $mark,
        ]), null);

        $this->shopScreen($responder, $res->character);
    }

    private function sellConfirmScreen(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $row = Inventory::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null || $row->isEquipped()) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        if (! $this->shop->hasItem($row->item_id)) {
            $responder->reply(__('errors.cannot_sell'), null);

            return;
        }

        $def = $this->shop->findItem($row->item_id);
        $payout = $this->inventory->sellPayout($row);
        $mark = $this->currencyMark($def->currency);

        if ($payout <= 0) {
            $text = __('shop.sell_confirm_zero', [
                'name' => $this->inventory->rowLabel($row),
                'mark' => $mark,
            ]);
        } else {
            $text = __('shop.sell_confirm', [
                'name' => $this->inventory->rowLabel($row),
                'price' => $payout,
                'mark' => $mark,
            ]);
        }

        $responder->edit($text, ['inline_keyboard' => [
            [[
                'text' => __('shop.sell_yes'),
                'callback_data' => 'shop:sell_yes:' . $row->id,
            ]],
            [[
                'text' => __('shop.sell_btn'),
                'callback_data' => 'shop:sell',
            ]],
        ]]);
    }

    private function sellListScreen(TelegramResponder $responder, Character $player): void
    {
        $rows = $this->inventory->sellableList($player->tg_id);
        $buttons = [];
        $hasSellable = false;

        foreach ($rows as $row) {
            if (! $this->shop->hasItem($row->item_id)) {
                continue;
            }

            $hasSellable = true;
            $def = $this->shop->findItem($row->item_id);
            $payout = $this->inventory->sellPayout($row);
            $mark = $this->currencyMark($def->currency);

            if ($row->max_durability !== null && $row->durability !== null) {
                $label = __('shop.sell_row', [
                    'name' => $this->inventory->rowLabel($row),
                    'price' => $payout,
                    'mark' => $mark,
                    'dur' => $row->durability . '/' . $row->max_durability,
                ]);
            } else {
                $label = __('shop.sell_row_no_dur', [
                    'name' => $this->inventory->rowLabel($row),
                    'price' => $payout,
                    'mark' => $mark,
                ]);
            }

            $buttons[] = [[
                'text' => $label,
                'callback_data' => 'shop:sell:' . $row->id,
            ]];
        }

        $buttons[] = [[
            'text' => __('menu.shop'),
            'callback_data' => 'menu:shop',
        ]];

        if ($hasSellable) {
            $text = __('shop.sell_title', [
                'current' => $this->inventory->rowCount($player->tg_id),
                'max' => $this->inventory->maxRows($player),
            ]);
        } else {
            $text = __('shop.sell_empty');
        }

        $responder->edit($text, ['inline_keyboard' => $buttons]);
    }

    private function shopScreen(TelegramResponder $responder, Character $player): void
    {
        $wearables = $this->shop->shopGear();

        foreach ($this->shop->shopJewelry() as $jewelry) {
            $wearables[] = $jewelry;
        }

        $responder->edit(
            __('shop.balance', [
                'silver' => $player->silver,
                'current' => $this->inventory->rowCount($player->tg_id),
                'max' => $this->inventory->maxRows($player),
            ]),
            TelegramKeyboards::fullShop(
                $this->shop->weaponsForMode('full'),
                $wearables,
                $this->shop->potionPrice(),
                $this->shop->staminaPotionPrice(),
            ),
        );
    }
}
