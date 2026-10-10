<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\ProgressStepEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\Character;
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
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Services\Onboarding\OnboardingService;
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

function onboarding(): OnboardingService
{
    return app(OnboardingService::class);
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

    return registration()->setLocation($player, $cityKey)->character;
}

function cityDone(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = cityArrived($tgId, $nick, $cityKey);
    $player->progress_step = ProgressStepEnum::DONE;
    $player->onboarding_skipped = false;
    $player->save();

    return $player->fresh();
}

function citySkipped(int $tgId, string $nick, string $cityKey = City::KEY_ANKRAT): Character
{
    $player = cityArrived($tgId, $nick, $cityKey);
    $player->progress_step = ProgressStepEnum::DONE;
    $player->onboarding_skipped = true;
    $player->save();

    return $player->fresh();
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
