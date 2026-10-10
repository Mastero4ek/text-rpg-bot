<?php

declare(strict_types=1);

use App\Enums\Gem\GemTypeEnum;
use App\Models\Bag\BagCatalog;
use App\Models\City;
use App\Telegram\Keyboards\PaginatedListKeyboard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    telegramHttpFake();
});

/**
 * @param  callable(string): bool  $bodyCheck
 */
function assertTelegramMarkupBody(callable $bodyCheck): void
{
    Http::assertSent(function (Request $request) use ($bodyCheck): bool {
        $url = $request->url();

        if (
            ! str_contains($url, '/editMessageMedia')
            && ! str_contains($url, '/editMessageCaption')
            && ! str_contains($url, '/editMessageText')
            && ! str_contains($url, '/sendPhoto')
            && ! str_contains($url, '/sendMessage')
        ) {
            return false;
        }

        return $bodyCheck(urldecode($request->body()));
    });
}

describe('buyer chest', function (): void {
    it('opens chest with filters pagination and danger back', function (): void {
        $player = cityDone(10101, 'ListChestChrome');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.chest')));
        assertCityEditMarkupHas('city:buyer:chest:list:all:1');
        assertCityEditMarkupHas('city:buyer:chest:list:gems:1');
        assertCityEditMarkupHas('city:buyer:chest:list:charms:1');
        assertCityEditMarkupHas('city:buyer:chest:list:all:0');
        assertCityEditMarkupHas('city:buyer:chest:list:all:2');
        assertCityEditMarkupHas('"style":"primary"');
        assertCityEditMarkupHas('"style":"danger"');
        assertCityEditMarkupHas('city:buyer:buy:ruby_0');
    });

    it('filters chest to gems and keeps charms empty', function (): void {
        $player = cityDone(10102, 'ListChestGems');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest:list:gems:1');

        assertCityEditMarkupHas('city:buyer:buy:ruby_0');
        assertCityEditMarkupHas('city:buyer:chest:list:gems:1');

        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:buyer:chest:list:charms:1');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.empty')));
        assertCityEditMarkupHas('city:buyer:chest:list:charms:1');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:buyer:buy:'));
    });

    it('keeps filters and pagination on empty chest', function (): void {
        $player = cityDone(10103, 'ListChestEmpty', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->bagCatalog()->sync([]);
        bagCatalog()->forgetCache();

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.empty')));
        assertCityEditMarkupHas('city:buyer:chest:list:all:1');
        assertCityEditMarkupHas('city:buyer:chest:list:all:0');
        assertCityEditMarkupHas('city:buyer:chest:list:all:2');
        assertCityEditMarkupHas('"style":"danger"');
    });

    it('edits chest panel with page edge copy and keeps list chrome', function (): void {
        $player = cityDone(10104, 'ListChestEdge');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest');
        telegramHttpFake();

        cityFlowCallback($player->tg_id, 10, 'city:buyer:chest:list:all:0');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.empty')));
        assertCityEditMarkupHas('city:buyer:chest:list:all:1');
        assertCityEditMarkupHas('"style":"danger"');
    });

    it('falls back to all gems for an unknown chest filter', function (): void {
        $player = cityDone(10105, 'ListChestBadFilter');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest:list:nope:1');

        assertCityEditMarkupHas('city:buyer:buy:ruby_0');
        assertCityEditMarkupHas('city:buyer:buy:emerald_0');
        assertCityEditMarkupHas('city:buyer:chest:list:all:1');
    });

    it('paginates chest to the second page when there are more than five gems', function (): void {
        $player = cityDone(10106, 'ListChestPage2', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $ids = ['ruby_0', 'emerald_0', 'sapphire_0', 'diamond_0'];

        for ($i = 0; $i < 2; $i++) {
            $gem = BagCatalog::factory()->create([
                'name' => 'Камень пагинации ' . $i,
                'type' => GemTypeEnum::RUBY,
                'enabled' => true,
                'sort_order' => 100 + $i,
            ]);
            $ids[] = $gem->catalog_id;
        }

        $city->bagCatalog()->sync($ids);
        bagCatalog()->forgetCache();

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest:list:all:2');

        assertTelegramMarkupBody(function (string $body) use ($ids): bool {
            $buyCount = 0;

            foreach ($ids as $id) {
                if (str_contains($body, 'city:buyer:buy:' . $id)) {
                    $buyCount++;
                }
            }

            return $buyCount === 1
                && str_contains($body, 'city:buyer:chest:list:all:1')
                && str_contains($body, 'city:buyer:chest:list:all:3');
        });
    });

    it('builds filter pagination and danger back rows', function (): void {
        $items = [];

        for ($i = 1; $i <= 6; $i++) {
            $items[] = [
                'text' => 'Item ' . $i,
                'callback_data' => 'item:' . $i,
            ];
        }

        $markup = PaginatedListKeyboard::markup(
            $items,
            [
                ['id' => 'all', 'label' => 'Все'],
                ['id' => 'bag', 'label' => 'Сумка'],
            ],
            'bag',
            2,
            'city:buyer:sell',
            'city:buyer',
            null,
        );

        $rows = $markup['inline_keyboard'];

        expect($rows[0][0]['callback_data'])->toBe('city:buyer:sell:list:all:1')
            ->and($rows[0][1]['callback_data'])->toBe('city:buyer:sell:list:bag:1')
            ->and($rows[0][1]['style'])->toBe('primary')
            ->and($rows[1][0]['callback_data'])->toBe('item:6')
            ->and($rows[2][0]['callback_data'])->toBe('city:buyer:sell:list:bag:1')
            ->and($rows[2][1]['callback_data'])->toBe('city:buyer:sell:list:bag:3')
            ->and($rows[3][0]['style'])->toBe('danger')
            ->and($rows[3][0]['callback_data'])->toBe('city:buyer');
    });
});

describe('buyer sell', function (): void {
    it('filters sell list to backpack only', function (): void {
        $player = cityDone(10111, 'ListSellBp');
        backpack()->addItem($player->tg_id, 'knife_0');
        bag()->addPotion($player->tg_id, 'heal_0');
        $player = grantGem($player, 'ruby_0', 1);
        $knife = backpack()->findOwned($player->tg_id, 'knife_0');
        $potion = bag()->loosePotions($player)->first();
        $gem = looseGem($player, 'ruby_0');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell:list:bp:1');

        assertTelegramMarkupBody(function (string $body) use ($knife, $potion, $gem): bool {
            return str_contains($body, 'city:buyer:sell:bp:' . $knife->id)
                && ! str_contains($body, 'city:buyer:sell:bag:' . $potion->id)
                && ! str_contains($body, 'city:buyer:sell:bag:' . $gem->id)
                && str_contains($body, 'city:buyer:sell:list:bp:1');
        });
    });

    it('filters sell list to bag only', function (): void {
        $player = cityDone(10112, 'ListSellBag');
        backpack()->addItem($player->tg_id, 'knife_0');
        bag()->addPotion($player->tg_id, 'heal_0');
        $knife = backpack()->findOwned($player->tg_id, 'knife_0');
        $potion = bag()->loosePotions($player)->first();

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell:list:bag:1');

        assertTelegramMarkupBody(function (string $body) use ($knife, $potion): bool {
            return str_contains($body, 'city:buyer:sell:bag:' . $potion->id)
                && ! str_contains($body, 'city:buyer:sell:bp:' . $knife->id);
        });
    });

    it('shows sell empty copy with chrome when filter has no rows', function (): void {
        $player = cityDone(10113, 'ListSellEmptyBp');
        bag()->addPotion($player->tg_id, 'heal_0');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell:list:bp:1');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.sell_empty')));
        assertCityEditMarkupHas('city:buyer:sell:list:bp:1');
        assertCityEditMarkupHas('city:buyer:sell:list:bp:0');
        assertCityEditMarkupHas('city:buyer:sell:list:bp:2');
    });
});

describe('blacksmith', function (): void {
    it('filters gear stand to weapons', function (): void {
        $player = cityDone(10121, 'ListSmithWpn');

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:gear:list:wpn:1');

        assertTelegramMarkupBody(function (string $body): bool {
            return str_contains($body, 'city:blacksmith:buy:sword_0')
                && str_contains($body, 'city:blacksmith:gear:list:wpn:1')
                && ! str_contains($body, 'city:blacksmith:buy:heavy_0');
        });
    });

    it('filters repair list by gear type', function (): void {
        $player = cityDone(10122, 'ListSmithRepair');
        backpack()->addItem($player->tg_id, 'knife_0');
        backpack()->addItem($player->tg_id, 'heavy_0');
        $knife = backpack()->findOwned($player->tg_id, 'knife_0');
        $armor = backpack()->findOwned($player->tg_id, 'heavy_0');
        $knife->durability = 1;
        $knife->save();
        $armor->durability = 1;
        $armor->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair:list:wpn:1');

        assertTelegramMarkupBody(function (string $body) use ($knife, $armor): bool {
            return str_contains($body, 'city:blacksmith:repair:' . $knife->id)
                && ! str_contains($body, 'city:blacksmith:repair:' . $armor->id)
                && str_contains($body, 'city:blacksmith:repair_all');
        });
    });

    it('edits gear panel with page edge copy and keeps list chrome', function (): void {
        $player = cityDone(10123, 'ListSmithEdge');

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:gear');
        telegramHttpFake();

        cityFlowCallback($player->tg_id, 10, 'city:blacksmith:gear:list:wpn:99');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.gear_empty')));
        assertCityEditMarkupHas('city:blacksmith:gear:list:wpn:1');
        assertCityEditMarkupHas('"style":"danger"');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:blacksmith:gear:list:all:'));
    });
});

describe('healer potions', function (): void {
    it('filters potions to heal profile', function (): void {
        $player = cityDone(10131, 'ListHealerHeal');

        cityFlowCallback($player->tg_id, 9, 'city:healer:potions:list:heal:1');

        assertTelegramMarkupBody(function (string $body): bool {
            return str_contains($body, 'city:healer:potion:heal_0')
                && ! str_contains($body, 'city:healer:potion:stamina_0')
                && str_contains($body, 'city:healer:potions:list:heal:1');
        });
    });

    it('keeps filters on empty potion shelf', function (): void {
        $player = cityDone(10132, 'ListHealerEmpty', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->bagCatalog()->sync([]);
        bagCatalog()->forgetCache();

        cityFlowCallback($player->tg_id, 9, 'city:healer:potions');

        assertCityEditHas(mb_trim(__('telegram.npc.healer.potions_empty')));
        assertCityEditMarkupHas('city:healer:potions:list:all:1');
        assertCityEditMarkupHas('city:healer:potions:list:heal:1');
        assertCityEditMarkupHas('"style":"danger"');
    });
});

describe('portal', function (): void {
    it('filters portal targets to free cities', function (): void {
        $player = cityDone(10141, 'ListPortalFree');
        $free = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $free->portal_cost_silver = 0;
        $free->save();

        cityFlowCallback($player->tg_id, 9, 'portal:list:free:1');

        assertTelegramMarkupBody(function (string $body) use ($free): bool {
            return str_contains($body, 'portal:' . $free->id)
                && str_contains($body, 'portal:list:free:1')
                && ! str_contains($body, '10🪙');
        });
    });

    it('shows portal empty copy when free filter has no targets', function (): void {
        $player = cityDone(10142, 'ListPortalEmptyFree');

        cityFlowCallback($player->tg_id, 9, 'portal:list:free:1');

        assertCityEditHas(mb_trim(__('telegram.location.portal_empty')));
        assertCityEditMarkupHas('portal:list:free:1');
        assertCityEditMarkupHas('portal:list:free:0');
        assertCityEditMarkupHas('portal:list:free:2');
    });
});

describe('forest and training', function (): void {
    it('opens forest with pagination and without kind filters', function (): void {
        $player = cityDone(10151, 'ListForestNoFilters');

        cityFlowCallback($player->tg_id, 9, 'city:forest');

        assertCityEditMarkupHas('city:forest:list:all:0');
        assertCityEditMarkupHas('city:forest:list:all:2');
        assertCityEditMarkupHas('"style":"danger"');
        assertTelegramMarkupBody(function (string $body): bool {
            return ! str_contains($body, 'city:forest:list:fixed:')
                && ! str_contains($body, 'city:forest:list:mirror:')
                && ! str_contains($body, mb_trim(__('telegram.btn.filter_enemy_fixed')))
                && ! str_contains($body, mb_trim(__('telegram.btn.filter_enemy_mirror')));
        });
    });

    it('opens training with pagination and without kind filters', function (): void {
        $player = cityDone(10152, 'ListTrainNoFilters');

        cityFlowCallback($player->tg_id, 9, 'city:training');

        assertCityEditMarkupHas('city:training:list:all:0');
        assertCityEditMarkupHas('city:training:list:all:2');
        assertCityEditMarkupHas('"style":"danger"');
        assertTelegramMarkupBody(function (string $body): bool {
            return ! str_contains($body, 'city:training:list:fixed:')
                && ! str_contains($body, 'city:training:list:mirror:')
                && ! str_contains($body, mb_trim(__('telegram.btn.filter_enemy_fixed')))
                && ! str_contains($body, mb_trim(__('telegram.btn.filter_enemy_mirror')));
        });
    });

    it('edits forest panel with page edge copy and keeps list chrome', function (): void {
        $player = cityDone(10153, 'ListForestEdge');

        cityFlowCallback($player->tg_id, 9, 'city:forest');
        telegramHttpFake();

        cityFlowCallback($player->tg_id, 10, 'city:forest:list:all:0');

        assertCityEditHas(mb_trim(__('telegram.location.forest_empty')));
        assertCityEditMarkupHas('city:forest:list:all:0');
        assertCityEditMarkupHas('city:forest:list:all:2');
        assertCityEditMarkupHas('"style":"danger"');
    });
});
