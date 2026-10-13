<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Npc\NpcEnum;
use App\Enums\ProgressStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagCatalog as BagCatalogModel;
use App\Models\Bag\BagItem;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\LoadoutService;
use App\Services\Backpack\RepairService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\City\BlacksmithService;
use App\Services\City\BuyerService;
use App\Services\City\HealerService;
use App\Services\CombatService;
use App\Services\DailyContract\DailyContractService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Services\Registration\RegistrationService;
use App\Services\Shop\ShopCatalog;
use App\Support\ActionResult;
use App\Support\Combat\Fighter;
use App\Support\Enemy;
use App\Support\Mf;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;
use App\Support\Telegram\TelegramUpdate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function gameConfig(): GameConfig
{
    return app(GameConfig::class);
}

function dailyService(): DailyContractService
{
    return app(DailyContractService::class);
}

function dailyCity(Character $player): City
{
    $city = City::query()->find($player->city_id);

    if (! $city instanceof City) {
        throw new RuntimeException('Player city missing for daily test.');
    }

    return $city;
}

function dailyPlayer(int $tgId, string $nick, int $level = 1): Character
{
    $player = cityDone($tgId, $nick);

    if ($player->level !== $level) {
        $player->level = $level;
        $player->save();
    }

    return $player->fresh();
}

function dailyOffer(Character $player, string $zoneKey = 'forest'): CharacterDailyContract
{
    if ($player->level < 1) {
        $player->level = 1;
        $player->save();
        $player = $player->fresh();
    }

    $offers = dailyService()->ensureOffers($player, dailyCity($player));
    $first = $offers->first();

    if (! $first instanceof CharacterDailyContract) {
        throw new RuntimeException('Daily offer missing after ensureOffers.');
    }

    foreach ($offers as $row) {
        if ($row->type !== DailyContractTypeEnum::Hunt) {
            continue;
        }

        $rowZone = 'forest';

        if (
            array_key_exists('zone', $row->params)
            && is_string($row->params['zone'])
            && $row->params['zone'] !== ''
        ) {
            $rowZone = $row->params['zone'];
        }

        if ($rowZone === $zoneKey) {
            return $row;
        }
    }

    $catalogId = match ($zoneKey) {
        'forest' => 'forest_bandit',
        'swamp' => 'swamp_witch',
        'ruins' => 'wild_dog',
        'port' => 'rabid_rat',
        default => throw new RuntimeException('dailyOffer zone unknown: ' . $zoneKey),
    };

    $catalog = EnemyCatalog::query()->find($catalogId);

    if (! $catalog instanceof EnemyCatalog) {
        throw new RuntimeException('dailyOffer enemy missing: ' . $catalogId);
    }

    $params = $first->params;
    $params['zone'] = $zoneKey;
    $params['monster_name'] = $catalog->name;
    $params['enemy_catalog_key'] = $catalog->catalog_id;
    unset($params['item_name'], $params['bag_catalog_key']);
    $first->type = DailyContractTypeEnum::Hunt;
    $first->enemy_catalog_id = $catalog->catalog_id;
    $first->bag_catalog_id = null;
    $first->target_kills = 1;
    $first->issuer_npc = NpcEnum::Buyer;
    $first->params = $params;
    $first->save();

    return $first->fresh();
}

function dailyDeliveryOffer(Character $player, NpcEnum $issuer = NpcEnum::Buyer): CharacterDailyContract
{
    if ($player->level < 1) {
        $player->level = 1;
        $player->save();
        $player = $player->fresh();
    }

    $boardCity = dailyCity($player);
    $offers = dailyService()->ensureOffers($player, $boardCity);

    foreach ($offers as $row) {
        if (
            $row->status === DailyContractStatusEnum::Offered
            && $row->type === DailyContractTypeEnum::Delivery
            && $row->issuer_npc === $issuer
            && is_int($row->target_city_id)
            && $row->target_city_id !== $boardCity->id
        ) {
            return $row;
        }
    }

    $slot = null;

    foreach ($offers as $row) {
        if ($row->status === DailyContractStatusEnum::Offered) {
            $slot = $row;
            break;
        }
    }

    if (! $slot instanceof CharacterDailyContract) {
        throw new RuntimeException('Daily offered slot missing after ensureOffers.');
    }

    $catalog = BagCatalogModel::query()->find('daily_parcel');

    if (! $catalog instanceof BagCatalogModel) {
        throw new RuntimeException('dailyDeliveryOffer bag catalog missing: daily_parcel');
    }

    $target = City::query()
        ->where('enabled', true)
        ->where('id', '!=', $boardCity->id)
        ->orderBy('id')
        ->get()
        ->first(fn (City $city): bool => DailyContractService::cityHasDeliverySpot($city, $issuer));

    if (! $target instanceof City) {
        throw new RuntimeException('dailyDeliveryOffer: no other city for issuer ' . $issuer->value);
    }

    $params = $slot->params;
    $params['item_name'] = $catalog->name;
    $params['bag_catalog_key'] = $catalog->catalog_id;
    $params['lvl'] = $player->level;
    $params['city_name'] = $target->name;
    $params['city_key'] = $target->key;
    unset($params['zone'], $params['monster_name'], $params['enemy_catalog_key']);

    $slot->type = DailyContractTypeEnum::Delivery;
    $slot->enemy_catalog_id = null;
    $slot->bag_catalog_id = $catalog->catalog_id;
    $slot->issuer_npc = $issuer;
    $slot->target_city_id = $target->id;
    $slot->target_kills = 0;
    $slot->params = $params;
    $slot->save();

    return $slot->fresh();
}

function shopCatalog(): ShopCatalog
{
    return app(ShopCatalog::class);
}

function bagCatalog(): BagCatalog
{
    return app(BagCatalog::class);
}

function characters(): CharacterService
{
    return app(CharacterService::class);
}

function backpack(): BackpackService
{
    return app(BackpackService::class);
}

function bag(): BagService
{
    return app(BagService::class);
}

function loadout(): LoadoutService
{
    return app(LoadoutService::class);
}

function repair(): RepairService
{
    return app(RepairService::class);
}

function blacksmithService(): BlacksmithService
{
    return app(BlacksmithService::class);
}

function healerService(): HealerService
{
    return app(HealerService::class);
}

function buyerService(): BuyerService
{
    return app(BuyerService::class);
}

function fights(): FightService
{
    return app(FightService::class);
}

function registration(): RegistrationService
{
    return app(RegistrationService::class);
}

function combat(): CombatService
{
    return app(CombatService::class);
}

function enemies(): EnemyService
{
    return app(EnemyService::class);
}

function enemyFromCatalog(string $catalogId, Character $character): Enemy
{
    $catalog = EnemyCatalog::query()->findOrFail($catalogId);

    return enemies()->makeFromCatalog($catalog, $character);
}

function woodenSoldier(Character $character): Enemy
{
    return enemyFromCatalog(EnemyCatalog::TUTORIAL_CATALOG_ID, $character);
}

function placeInCity(Character $character, string $key): Character
{
    $city = City::query()->where('key', $key)->first();

    if (! $city instanceof City) {
        throw new RuntimeException('Seed city missing: ' . $key);
    }

    $character->birth_city_id = $city->id;
    $character->city_id = $city->id;
    $character->save();

    return $character;
}

function cityFlowWebhook(array $payload): void
{
    test()->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();
}

function cityFlowCallback(int $tgId, int $messageId, string $data): void
{
    cityFlowWebhook([
        'update_id' => $tgId * 10 + $messageId,
        'callback_query' => [
            'id' => 'cb-' . $tgId . '-' . $messageId . '-' . $data,
            'data' => $data,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => $messageId,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'text' => 'city',
            ],
        ],
    ]);
}

function cityFlowText(int $tgId, int $messageId, string $text): void
{
    cityFlowWebhook([
        'update_id' => $tgId * 10 + $messageId,
        'message' => [
            'message_id' => $messageId,
            'text' => $text,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $tgId, 'type' => 'private'],
        ],
    ]);
}

function cityArrived(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, $nick)->character;
    $player = registration()->setSkin($player, 'mobile')->character;

    return registration()->setLocation($player, $cityKey)->character;
}

function cityArrivedSkipped(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = cityArrived($tgId, $nick, $cityKey);
    $player->overseer_at_tavern = true;
    $player->save();

    return $player->fresh();
}

function cityHealerPhase(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = cityArrived($tgId, $nick, $cityKey);
    $player->progress_step = ProgressStepEnum::DONE;
    $player->quest_phase = QuestPhaseEnum::Healer;
    $player->overseer_trained = true;
    $player->overseer_at_tavern = true;
    $player->overseer_train_step = null;
    $player->unlock_portal = false;
    $player->unlock_ruins = true;
    $player->healer_at_tavern = false;
    $player->healer_meet_open = false;
    $player->unlock_forest = false;
    $player->exp = 30;
    $player->silver = 25;
    $player->stat_points = 0;
    $player->tg_chat_id = $tgId;
    $player->tg_message_id = 9;
    $player->save();

    return $player->fresh();
}

function cityBlacksmithPhase(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = cityHealerPhase($tgId, $nick, $cityKey);
    $player->quest_phase = QuestPhaseEnum::Blacksmith;
    $player->healer_at_tavern = true;
    $player->healer_meet_open = false;
    $player->unlock_forest = true;
    $player->blacksmith_at_tavern = false;
    $player->unlock_board = false;
    $player->exp = 70;
    $player->silver = 25;
    $player->stat_points = 1;
    $player->save();

    return $player->fresh();
}

function cityBuyerPhase(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = cityBlacksmithPhase($tgId, $nick, $cityKey);
    $player->quest_phase = QuestPhaseEnum::Buyer;
    $player->blacksmith_at_tavern = true;
    $player->unlock_board = true;
    $player->buyer_at_tavern = false;
    $player->bruiser_contract_active = false;
    $player->unlock_arena = false;
    $player->exp = 110;
    $player->silver = 35;
    $player->stat_points = 2;
    $player->save();

    backpack()->addItem($player->tg_id, 'ancient_amulet');

    return $player->fresh();
}

function cityBruiserPhase(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = cityBuyerPhase($tgId, $nick, $cityKey);
    $amulet = backpack()->findOwned($player->tg_id, 'ancient_amulet');

    backpack()->removeOne($amulet);

    $player->quest_phase = QuestPhaseEnum::Bruiser;
    $player->buyer_at_tavern = true;
    $player->bruiser_contract_active = true;
    $player->bruiser_knuckle_pending = false;
    $player->unlock_arena = true;
    $player->healer_debt_open = false;
    $player->healer_rat_loot_pending = false;
    $player->exp = 130;
    $player->silver = 66;
    $player->stat_points = 2;
    $player->save();

    return $player->fresh();
}

function cityDone(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = cityArrived($tgId, $nick, $cityKey);
    $player->progress_step = ProgressStepEnum::DONE;
    $player->overseer_trained = true;
    unlockQuestCity($player);

    return $player->fresh();
}

function citySkipped(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = cityArrivedSkipped($tgId, $nick, $cityKey);
    $player->progress_step = ProgressStepEnum::DONE;
    unlockQuestCity($player);

    return $player->fresh();
}

function cityAwaitingBuyerTurnIn(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = cityBruiserPhase($tgId, $nick, $cityKey);
    $player->bruiser_contract_active = false;
    $player->exp = 170;
    $player->stat_points = 3;
    $player->save();

    bag()->addQuestItem($player->tg_id, 'buyer_purse');

    return $player->fresh();
}

function unlockQuestCity(Character $character): Character
{
    $character->quest_phase = QuestPhaseEnum::Done;
    $character->city_unlocked_full = true;
    $character->unlock_portal = true;
    $character->unlock_ruins = true;
    $character->unlock_forest = true;
    $character->unlock_board = true;
    $character->unlock_arena = true;
    $character->overseer_at_tavern = true;
    $character->healer_at_tavern = true;
    $character->blacksmith_at_tavern = true;
    $character->buyer_at_tavern = true;
    $character->bruiser_contract_active = false;
    $character->bruiser_knuckle_pending = false;
    $character->healer_debt_open = false;
    $character->healer_rat_loot_pending = false;
    $character->save();

    return $character;
}

function assertCityEditHas(string $needle): void
{
    Http::assertSent(function (Request $request) use ($needle): bool {
        if (str_contains($request->url(), '/editMessageMedia')) {
            $body = $request->body();

            if (str_contains($body, $needle)) {
                return true;
            }

            return str_contains($body, str_replace("\n", '\\n', $needle));
        }

        if (! str_contains($request->url(), '/editMessageText')
            && ! str_contains($request->url(), '/editMessageCaption')) {
            return false;
        }

        $text = (string) ($request['text'] ?? $request['caption'] ?? '');

        return str_contains($text, $needle);
    });
}

function assertCitySendHas(string $needle): void
{
    Http::assertSent(function (Request $request) use ($needle): bool {
        if (str_contains($request->url(), '/sendPhoto')) {
            return str_contains($request->body(), $needle);
        }

        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), $needle);
    });
}

function assertCityEditMarkupHas(string $needle): void
{
    Http::assertSent(function (Request $request) use ($needle): bool {
        if (str_contains($request->url(), '/editMessageMedia')) {
            return str_contains($request->body(), $needle);
        }

        if (! str_contains($request->url(), '/editMessageText')
            && ! str_contains($request->url(), '/editMessageCaption')) {
            return false;
        }

        $markup = $request['reply_markup'] ?? '';

        return is_string($markup) && str_contains($markup, $needle);
    });
}

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

function assertCityCallbackToast(): void
{
    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/answerCallbackQuery')) {
            return false;
        }

        $text = $request['text'] ?? null;

        return is_string($text) && $text !== '';
    });
}

function assertCityNoMessageEdit(): void
{
    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/editMessageText')
            || str_contains($request->url(), '/editMessageCaption');
    });
}

function telegramHttpFake(): void
{
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
}

function assertCitySendMarkupHas(string $needle): void
{
    Http::assertSent(function (Request $request) use ($needle): bool {
        if (str_contains($request->url(), '/sendPhoto')) {
            return str_contains($request->body(), $needle);
        }

        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        $markup = $request['reply_markup'] ?? '';

        return is_string($markup) && str_contains($markup, $needle);
    });
}

function giveStarterKnuckles(int $tgId): BackpackItem
{
    $itemId = shopCatalog()->starterKnucklesId();
    backpack()->addItem($tgId, $itemId);

    return backpack()->findOwned($tgId, $itemId);
}

function giveAndEquipStarterKnuckles(Character $character): Character
{
    $row = giveStarterKnuckles($character->tg_id);
    $equip = loadout()->equip($character, $row->id);

    if (! $equip->ok || ! $equip->character instanceof Character) {
        throw new RuntimeException('Failed to equip starter knuckles in test.');
    }

    return $equip->character;
}

function equipItemToSlot(Character $character, string $itemId, SlotEnum $slot): Character
{
    if (! backpack()->owns($character->tg_id, $itemId)) {
        backpack()->addItem($character->tg_id, $itemId);
    }

    $row = backpack()->findOwned($character->tg_id, $itemId);
    $equip = loadout()->equipToSlot($character, $row->id, $slot);

    if (! $equip->ok || ! $equip->character instanceof Character) {
        throw new RuntimeException("Failed to equip {$itemId} to {$slot->value} in test.");
    }

    return $equip->character;
}

/**
 * @param  list<float>  $values
 */
function fakeRandom(array $values): void
{
    app()->instance(RandomSourceContract::class, new FakeRandomSource($values));
}

function cityCallback(int $tgId, string $data): TelegramUpdate
{
    return new TelegramUpdate([
        'update_id' => $tgId,
        'callback_query' => [
            'id' => 'cb-' . $tgId . '-' . $data,
            'data' => $data,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 9,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'text' => 'city',
            ],
        ],
    ]);
}

function cityPhotoCallback(int $tgId, string $data): TelegramUpdate
{
    return new TelegramUpdate([
        'update_id' => $tgId,
        'callback_query' => [
            'id' => 'cb-' . $tgId . '-' . $data,
            'data' => $data,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 9,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'city-pick', 'width' => 512, 'height' => 384],
                ],
                'caption' => 'city',
            ],
        ],
    ]);
}

function fightInlineCallback(int $tgId, string $data): TelegramUpdate
{
    return new TelegramUpdate([
        'update_id' => $tgId,
        'callback_query' => [
            'id' => 'cb-' . $tgId,
            'data' => $data,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 70,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'caption' => 'fight',
            ],
        ],
    ]);
}

function fightPanelCallback(int $tgId, string $data): TelegramUpdate
{
    return new TelegramUpdate([
        'update_id' => $tgId,
        'callback_query' => [
            'id' => 'cb-' . $tgId,
            'data' => $data,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 9,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'forest-pick', 'width' => 512, 'height' => 384],
                ],
                'caption' => 'fight',
            ],
        ],
    ]);
}

function grantGem(Character $character, string $gemId, int $qty = 1): Character
{
    return bag()->grantGem($character, $gemId, $qty);
}

function grantGemDurability(Character $character, string $gemId, int $durability): Character
{
    $character = grantGem($character, $gemId, 1);
    $gem = null;

    foreach (bag()->looseGems($character) as $row) {
        if ($row->catalog_id === $gemId) {
            $gem = $row;
        }
    }

    if (! $gem instanceof Illuminate\Database\Eloquent\Model) {
        throw new RuntimeException("No loose gem {$gemId} for character {$character->tg_id}.");
    }

    $gem->durability = $durability;
    $gem->save();

    return $character->fresh();
}

function looseGem(Character $character, string $gemId): BagItem
{
    foreach (bag()->looseGems($character) as $gem) {
        if ($gem->catalog_id === $gemId) {
            return $gem;
        }
    }

    throw new RuntimeException("No loose gem {$gemId} for character {$character->tg_id}.");
}

function hasLooseGem(Character $character, string $gemId): bool
{
    foreach (bag()->looseGems($character) as $gem) {
        if ($gem->catalog_id === $gemId) {
            return true;
        }
    }

    return false;
}

function socketGem(Character $character, BackpackItem $item, string $gemId): ActionResult
{
    $gem = looseGem($character, $gemId);

    return bag()->socket($character, $item->id, $gem->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fighter(array $overrides = []): Fighter
{
    $name = 'A';
    if (array_key_exists('name', $overrides) && is_string($overrides['name'])) {
        $name = $overrides['name'];
    }

    $level = 0;
    if (array_key_exists('level', $overrides) && is_int($overrides['level'])) {
        $level = $overrides['level'];
    }

    $strength = 5;
    if (array_key_exists('strength', $overrides) && is_int($overrides['strength'])) {
        $strength = $overrides['strength'];
    }

    $agility = 5;
    if (array_key_exists('agility', $overrides) && is_int($overrides['agility'])) {
        $agility = $overrides['agility'];
    }

    $instinct = 5;
    if (array_key_exists('instinct', $overrides) && is_int($overrides['instinct'])) {
        $instinct = $overrides['instinct'];
    }

    $vitality = 5;
    if (array_key_exists('vitality', $overrides) && is_int($overrides['vitality'])) {
        $vitality = $overrides['vitality'];
    }

    $weaponDamage = 0;
    if (array_key_exists('weaponDamage', $overrides) && is_int($overrides['weaponDamage'])) {
        $weaponDamage = $overrides['weaponDamage'];
    }

    $weaponMf = new Mf(0, 0, 0, 0);
    if (array_key_exists('weaponMf', $overrides) && $overrides['weaponMf'] instanceof Mf) {
        $weaponMf = $overrides['weaponMf'];
    }

    $stance = StanceEnum::DEFEND;
    if (array_key_exists('stance', $overrides) && $overrides['stance'] instanceof StanceEnum) {
        $stance = $overrides['stance'];
    }

    $armorByZone = [
        'HEAD' => 0,
        'CHEST' => 0,
        'BELLY' => 0,
        'LEGS' => 0,
    ];
    if (array_key_exists('armorByZone', $overrides) && is_array($overrides['armorByZone'])) {
        $armorByZone = $overrides['armorByZone'];
    }

    $maxStamina = $strength * 6;
    if (array_key_exists('maxStamina', $overrides) && is_int($overrides['maxStamina'])) {
        $maxStamina = $overrides['maxStamina'];
    }

    $stamina = $maxStamina;
    if (array_key_exists('stamina', $overrides) && is_int($overrides['stamina'])) {
        $stamina = $overrides['stamina'];
    }

    return new Fighter(
        $name,
        $level,
        $strength,
        $agility,
        $instinct,
        $vitality,
        $weaponDamage,
        $weaponMf,
        $stance,
        $armorByZone,
        $stamina,
        $maxStamina,
    );
}
