<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Models\Bag\BagItem;
use App\Models\Character\Character;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\InventoryHandler;
use App\Telegram\Handlers\MenuHandler;
use App\Telegram\UpdateProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

function bagCommandUpdate(int $tgId): TelegramUpdate
{
    return new TelegramUpdate([
        'update_id' => $tgId,
        'message' => [
            'message_id' => 40,
            'text' => '/bag',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $tgId, 'type' => 'private'],
            'entities' => [[
                'offset' => 0,
                'length' => 4,
                'type' => 'bot_command',
            ]],
        ],
    ]);
}

function bagPanelPayload(): ?string
{
    $payload = null;

    foreach (Http::recorded() as $pair) {
        $request = $pair[0];

        if (! $request instanceof Request) {
            continue;
        }

        $url = $request->url();

        if (
            ! str_contains($url, '/sendPhoto')
            && ! str_contains($url, '/sendMessage')
            && ! str_contains($url, '/editMessageMedia')
            && ! str_contains($url, '/editMessageCaption')
            && ! str_contains($url, '/editMessageText')
        ) {
            continue;
        }

        $chunk = '';
        $text = $request['text'] ?? null;
        $caption = $request['caption'] ?? null;
        $media = $request['media'] ?? null;
        $markup = $request['reply_markup'] ?? null;

        if (is_string($text)) {
            $chunk = $text;
        }

        if (is_string($caption)) {
            $chunk = $caption;
        }

        if (is_string($media)) {
            $decoded = json_decode($media, true);

            if (is_array($decoded) && array_key_exists('caption', $decoded) && is_string($decoded['caption'])) {
                $chunk = $decoded['caption'];
            }
        }

        if (is_string($markup)) {
            $chunk .= "\n" . $markup;
        }

        if ($chunk === '') {
            $chunk = $request->body();
        }

        if ($chunk !== '') {
            $payload = $chunk;
        }
    }

    return $payload;
}

function runBagCallback(int $tgId, string $data): void
{
    $update = cityCallback($tgId, $data);

    app(InventoryHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

function runBagPhotoCallback(int $tgId, string $data): void
{
    $update = cityPhotoCallback($tgId, $data);

    app(InventoryHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

function runBagCommand(int $tgId): void
{
    $update = bagCommandUpdate($tgId);

    app(MenuHandler::class)->handleCommand(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

function loosePotion(Character $character, string $catalogId): BagItem
{
    foreach (bag()->loosePotions($character) as $row) {
        if ($row->catalog_id === $catalogId) {
            return $row;
        }
    }

    throw new RuntimeException("No loose potion {$catalogId} for character {$character->tg_id}.");
}

it('opens empty bag via /bag with header empty copy and no buttons', function (): void {
    $player = cityDone(9801, 'ПустаяСумка');

    runBagCommand($player->tg_id);

    $panel = bagPanelPayload();
    $max = bag()->bagMaxRows($player);

    expect($panel)->toBeString()
        ->and($panel)->toContain(__('profile.bag_header', [
            'current' => 0,
            'max' => $max,
        ]))
        ->and($panel)->toContain(__('profile.bag_empty'))
        ->and($panel)->not->toContain('menu:home')
        ->and($panel)->not->toContain('bag:list:POTION:1')
        ->and($panel)->not->toContain('menu:inv')
        ->and($panel)->not->toContain('smith:gems')
        ->and($panel)->not->toContain('menu:gear');
});

it('opens the same empty panel via menu:bag callback', function (): void {
    $player = cityDone(9802, 'МенюСумка');

    runBagCallback($player->tg_id, 'menu:bag');

    $panel = bagPanelPayload();

    expect($panel)->toContain(__('profile.bag_empty'))
        ->and($panel)->not->toContain('menu:home')
        ->and($panel)->not->toContain('menu:inv')
        ->and($panel)->not->toContain('smith:gems');
});

it('opens bag on potion filter as primary and hides gems quest', function (): void {
    $player = cityDone(9803, 'СписокСумка');
    bag()->addPotion($player->tg_id, 'heal_0');
    $player = grantGemDurability($player, 'ruby_0', 8);
    bag()->addQuestItem($player->tg_id, 'buyer_purse');
    $heal = loosePotion($player, 'heal_0');
    $ruby = looseGem($player, 'ruby_0');
    $purse = bag()->looseQuestItems($player)->first();

    runBagCallback($player->tg_id, 'menu:bag');

    $panel = bagPanelPayload();
    $max = bag()->bagMaxRows($player);

    expect($panel)->toContain(__('profile.bag_header', [
        'current' => 3,
        'max' => $max,
    ]))
        ->and($panel)->toContain('bag:card:' . $heal->id)
        ->and($panel)->not->toContain('bag:card:' . $ruby->id)
        ->and($panel)->not->toContain('bag:card:' . $purse->id)
        ->and($panel)->toContain('bag:list:POTION:1')
        ->and($panel)->toContain('bag:list:GEM:1')
        ->and($panel)->toContain('bag:list:QUEST:1')
        ->and($panel)->toContain('"style":"primary"')
        ->and($panel)->not->toContain(__('profile.bag_empty'))
        ->and($panel)->not->toContain('menu:inv')
        ->and($panel)->not->toContain('smith:gems');
});

it('filters gems and hides potion quest rows', function (): void {
    $player = cityDone(9804, 'ФильтрКамни');
    bag()->addPotion($player->tg_id, 'heal_0');
    $player = grantGemDurability($player, 'ruby_0', 8);
    bag()->addQuestItem($player->tg_id, 'buyer_purse');
    $heal = loosePotion($player, 'heal_0');
    $ruby = looseGem($player, 'ruby_0');
    $purse = bag()->looseQuestItems($player)->first();

    runBagCallback($player->tg_id, 'bag:list:GEM:1');

    $panel = bagPanelPayload();

    expect($panel)->toContain('bag:card:' . $ruby->id)
        ->and($panel)->not->toContain('bag:card:' . $heal->id)
        ->and($panel)->not->toContain('bag:card:' . $purse->id)
        ->and($panel)->toContain('bag:list:GEM:1');
});

it('shows empty-type copy when filter has no rows but bag is not empty', function (): void {
    $player = cityDone(9805, 'ПустойФильтрСумка');
    bag()->addPotion($player->tg_id, 'heal_0');

    runBagCallback($player->tg_id, 'bag:list:QUEST:1');

    $panel = bagPanelPayload();

    expect($panel)->toContain(__('profile.bag_empty_type'))
        ->and($panel)->toContain('bag:list:QUEST:1')
        ->and($panel)->toContain('bag:list:POTION:1')
        ->and($panel)->not->toContain('bag:list:QUEST:0');
});

it('paginates bag gem filter when more than five gems', function (): void {
    $player = cityDone(9806, 'BagPages');
    $player = grantGem($player, 'ruby_0', 6);

    runBagCallback($player->tg_id, 'bag:list:GEM:1');

    $pageOne = bagPanelPayload();

    expect($pageOne)->toContain('bag:list:GEM:0')
        ->and($pageOne)->toContain('bag:list:GEM:2')
        ->and($pageOne)->toContain('bag:listpage:1:2');

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]]),
    ]);

    runBagCallback($player->tg_id, 'bag:list:GEM:2');

    $pageTwo = bagPanelPayload();
    $ids = BagItem::query()
        ->where('tg_id', $player->tg_id)
        ->where('kind', 'GEM')
        ->whereNull('backpack_item_id')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($pageTwo)->toContain('bag:card:' . $ids[5])
        ->and($pageTwo)->not->toContain('bag:card:' . $ids[0]);
});

it('opens potion card with name description effect and back discard row', function (): void {
    $player = cityDone(9807, 'КарточкаЗелья');
    bag()->addPotion($player->tg_id, 'heal_0');
    $heal = loosePotion($player, 'heal_0');
    $def = bagCatalog()->findPotion('heal_0');

    runBagCallback($player->tg_id, 'bag:card:' . $heal->id);

    $panel = bagPanelPayload();

    expect($panel)->toContain($def->name)
        ->and($panel)->toContain($def->description)
        ->and($panel)->toContain('❤️ +' . $def->effectValue)
        ->and($panel)->not->toContain('Количество:')
        ->and($panel)->toContain('bag:discard:' . $heal->id)
        ->and($panel)->toContain('bag:list:POTION:1')
        ->and($panel)->not->toContain('smith:gems')
        ->and($panel)->not->toContain('menu:inv')
        ->and($panel)->not->toContain('menu:bag');
});

it('opens quest card with discard and gem card without smith', function (): void {
    $player = cityDone(9808, 'КарточкаКвестГем');
    bag()->addQuestItem($player->tg_id, 'buyer_purse');
    $player = grantGemDurability($player, 'emerald_0', 4);
    $purse = bag()->looseQuestItems($player)->first();
    $gem = looseGem($player, 'emerald_0');

    runBagCallback($player->tg_id, 'bag:card:' . $purse->id);

    $questPanel = bagPanelPayload();

    expect($questPanel)->toContain('Кошель Скупщика')
        ->and($questPanel)->toContain('bag:discard:' . $purse->id)
        ->and($questPanel)->toContain('bag:list:QUEST:1');

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]]),
    ]);

    runBagCallback($player->tg_id, 'bag:card:' . $gem->id);

    $gemPanel = bagPanelPayload();

    expect($gemPanel)->toContain('bag:discard:' . $gem->id)
        ->and($gemPanel)->toContain('bag:list:GEM:1')
        ->and($gemPanel)->not->toContain('smith:gems');
});

it('shows discard confirm with Нет Да and keeps item on cancel back to card', function (): void {
    $player = cityDone(9809, 'ОтменаВыбросаСумка');
    bag()->addPotion($player->tg_id, 'stamina_0');
    $potion = loosePotion($player, 'stamina_0');

    runBagCallback($player->tg_id, 'bag:discard:' . $potion->id);

    $confirm = bagPanelPayload();

    expect($confirm)->toContain(__('profile.discard_gem_confirm', [
        'name' => 'Зелье выносливости',
    ]))
        ->and($confirm)->toContain('bag:card:' . $potion->id)
        ->and($confirm)->toContain('bag:discard_yes:' . $potion->id)
        ->and($confirm)->toContain('"style":"danger"')
        ->and($confirm)->toContain('"style":"success"');

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]]),
    ]);

    runBagCallback($player->tg_id, 'bag:card:' . $potion->id);

    expect(BagItem::query()->whereKey($potion->id)->exists())->toBeTrue()
        ->and(bagPanelPayload())->toContain('bag:discard:' . $potion->id);
});

it('discards gem on Да and edits bag panel with notice under header', function (): void {
    $player = cityDone(9810, 'ВыбросилГем');
    $player = grantGemDurability($player, 'ruby_0', 9);
    $ruby = looseGem($player, 'ruby_0');

    runBagCallback($player->tg_id, 'bag:discard_yes:' . $ruby->id);

    expect(BagItem::query()->whereKey($ruby->id)->exists())->toBeFalse();

    $panel = bagPanelPayload();
    $max = bag()->bagMaxRows($player);

    expect($panel)->toContain(__('profile.bag_header', [
        'current' => 0,
        'max' => $max,
    ]))
        ->and($panel)->toContain(__('profile.discarded', [
            'name' => 'Рубин ученика',
        ]))
        ->and($panel)->toContain(__('profile.bag_empty'))
        ->and($panel)->not->toContain('menu:home');
});

it('labels potion stack rows with multiplication mark and quantity', function (): void {
    $player = cityDone(9820, 'СтакЛейбл');
    bag()->addPotion($player->tg_id, 'heal_0');
    bag()->addPotion($player->tg_id, 'heal_0');
    $heal = loosePotion($player, 'heal_0');
    $def = bagCatalog()->findPotion('heal_0');

    expect($heal->quantity)->toBe(2);

    runBagCallback($player->tg_id, 'bag:list:POTION:1');

    $label = __('profile.bag_potion_row', [
        'name' => $def->name,
        'quantity' => 2,
    ]);

    expect(bagPanelPayload())
        ->toContain('bag:card:' . $heal->id)
        ->and(bagPanelPayload())->toContain(mb_trim(json_encode($label), '"'));
});

it('decrements potion stack on discard and keeps row when quantity remains', function (): void {
    $player = cityDone(9811, 'СтекЗелья');
    bag()->addPotion($player->tg_id, 'heal_0');
    bag()->addPotion($player->tg_id, 'heal_0');
    $heal = loosePotion($player, 'heal_0');

    expect($heal->quantity)->toBe(2);

    runBagCallback($player->tg_id, 'bag:discard_yes:' . $heal->id);

    $heal->refresh();

    expect($heal->quantity)->toBe(1);

    $panel = bagPanelPayload();

    expect($panel)->toContain(__('profile.discarded', [
        'name' => 'Зелье лечения',
    ]))
        ->and($panel)->toContain('bag:card:' . $heal->id)
        ->and($panel)->toContain(__('profile.bag_header', [
            'current' => 1,
            'max' => bag()->bagMaxRows($player),
        ]));
});

it('removes photo panel when returning to bag list from a card', function (): void {
    $player = cityDone(9812, 'НазадБезФото');
    bag()->addPotion($player->tg_id, 'heal_0');
    $heal = loosePotion($player, 'heal_0');
    $max = bag()->bagMaxRows($player);

    runBagPhotoCallback($player->tg_id, 'bag:list:POTION:1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deleteMessage'));
    Http::assertSent(function (Request $request) use ($heal, $max): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), __('profile.bag_header', [
                'current' => 1,
                'max' => $max,
            ]))
            && str_contains((string) ($request['reply_markup'] ?? ''), 'bag:card:' . $heal->id);
    });
});

it('removes photo panel when discarding bag item from a card with art', function (): void {
    $player = cityDone(9813, 'DropBagPhoto');
    bag()->addPotion($player->tg_id, 'heal_0');
    $heal = loosePotion($player, 'heal_0');
    $max = bag()->bagMaxRows($player);

    $update = new TelegramUpdate([
        'update_id' => 9813,
        'callback_query' => [
            'id' => 'cb-bag-discard-photo',
            'data' => 'bag:discard_yes:' . $heal->id,
            'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 55,
                'chat' => ['id' => $player->tg_id, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'heal-art', 'width' => 512, 'height' => 512],
                ],
                'caption' => 'card',
            ],
        ],
    ]);

    app(InventoryHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deleteMessage'));
    Http::assertSent(function (Request $request) use ($max): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), __('profile.discarded', [
                'name' => 'Зелье лечения',
            ]))
            && str_contains((string) ($request['text'] ?? ''), __('profile.bag_header', [
                'current' => 0,
                'max' => $max,
            ]));
    });
});

it('accepts legacy bag:potion and bag:filter callbacks', function (): void {
    $player = cityDone(9814, 'ЛегасиСумка');
    bag()->addPotion($player->tg_id, 'heal_0');
    $player = grantGemDurability($player, 'sapphire_0', 3);
    $heal = loosePotion($player, 'heal_0');
    $gem = looseGem($player, 'sapphire_0');

    runBagCallback($player->tg_id, 'bag:potion:' . $heal->id);

    expect(bagPanelPayload())->toContain('bag:discard:' . $heal->id);

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]]),
    ]);

    runBagCallback($player->tg_id, 'bag:filter:gems');

    $panel = bagPanelPayload();

    expect($panel)->toContain('bag:card:' . $gem->id)
        ->and($panel)->not->toContain('bag:card:' . $heal->id);
});

it('falls back unknown list filter to potion', function (): void {
    $player = cityDone(9815, 'СтранныйФильтрСумка');
    bag()->addPotion($player->tg_id, 'heal_0');
    $player = grantGemDurability($player, 'ruby_0', 2);
    $heal = loosePotion($player, 'heal_0');
    $ruby = looseGem($player, 'ruby_0');

    runBagCallback($player->tg_id, 'bag:list:nope:1');

    $panel = bagPanelPayload();

    expect($panel)->toContain('bag:card:' . $heal->id)
        ->and($panel)->not->toContain('bag:card:' . $ruby->id)
        ->and($panel)->toContain('bag:list:POTION:1')
        ->and($panel)->toContain('"style":"primary"');
});

it('opens bag via UpdateProcessor /bag', function (): void {
    $player = cityDone(9816, 'ПроцессорСумка');

    app(UpdateProcessor::class)->handle([
        'update_id' => 98161,
        'message' => [
            'message_id' => 41,
            'text' => '/bag',
            'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $player->tg_id, 'type' => 'private'],
            'entities' => [['offset' => 0, 'length' => 4, 'type' => 'bot_command']],
        ],
    ]);

    expect(bagPanelPayload())->toContain(__('profile.bag_empty'));
});

it('rejects item card for missing bag row', function (): void {
    $player = cityDone(9817, 'НетКарточкиСумка');

    runBagCallback($player->tg_id, 'bag:card:999999');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('rejects discard confirm for missing bag row', function (): void {
    $player = cityDone(9818, 'НетВыбросаСумка');

    runBagCallback($player->tg_id, 'bag:discard:999999');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('rejects discard_yes for missing bag row', function (): void {
    $player = cityDone(9819, 'НетДаВыбросаСумка');

    runBagCallback($player->tg_id, 'bag:discard_yes:999999');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('rejects card access to another players bag item', function (): void {
    $owner = cityDone(9820, 'ВладелецСумки');
    $intruder = cityDone(9821, 'ЧужаяСумка');
    bag()->addPotion($owner->tg_id, 'heal_0');
    $heal = loosePotion($owner, 'heal_0');

    runBagCallback($intruder->tg_id, 'bag:card:' . $heal->id);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('rejects card for socketed gem that is not loose', function (): void {
    $player = cityDone(9822, 'СокетНеВСумке');
    backpack()->addItem($player->tg_id, 'knife_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');
    $player = grantGemDurability($player, 'ruby_0', 8);
    $socket = socketGem($player, $knife, 'ruby_0');
    expect($socket->ok)->toBeTrue();
    $gem = BagItem::query()->where('tg_id', $player->tg_id)->where('catalog_id', 'ruby_0')->first();

    runBagCallback($player->tg_id, 'bag:card:' . $gem->id);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('nudges registration instead of bag while mid-registration', function (): void {
    $player = characters()->createDraft(9823);
    $player->progress_step = ProgressStepEnum::SET_NICK;
    $player->save();

    runBagCallback($player->tg_id, 'menu:bag');

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->body(), __('profile.bag_empty'))
            || str_contains($request->body(), 'bag:list:');
    });
});

it('replies press start for unknown telegram user on bag callback', function (): void {
    runBagCallback(9824, 'menu:bag');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('common.press_start');
    });
});

it('ignores /bag during an active fight', function (): void {
    $player = cityDone(9825, 'ВБоюСумка');
    fights()->createHall($player, woodenSoldier($player));

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
    ]);

    runBagCommand($player->tg_id);

    expect(Http::recorded())->toBeEmpty();
});

it('flashes empty-type copy on out-of-range bag page then keeps clamped markup', function (): void {
    $player = cityDone(9826, 'КрайСтраницыСумка');
    $player = grantGem($player, 'ruby_0', 6);

    runBagCallback($player->tg_id, 'bag:list:GEM:99');

    assertCityEditHas(__('profile.bag_empty_type'));
    assertTelegramMarkupBody(fn (string $body): bool => str_contains($body, 'bag:list:GEM:1')
        && str_contains($body, 'bag:listpage:2:2'));
});
