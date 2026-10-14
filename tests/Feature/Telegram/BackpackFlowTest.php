<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Models\Backpack\BackpackItem;
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

function backpackCommandUpdate(int $tgId): TelegramUpdate
{
    return new TelegramUpdate([
        'update_id' => $tgId,
        'message' => [
            'message_id' => 40,
            'text' => '/backpack',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $tgId, 'type' => 'private'],
            'entities' => [[
                'offset' => 0,
                'length' => 9,
                'type' => 'bot_command',
            ]],
        ],
    ]);
}

function backpackPanelPayload(): ?string
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

function runBackpackCallback(int $tgId, string $data): void
{
    $update = cityCallback($tgId, $data);

    app(InventoryHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

function runBackpackCommand(int $tgId): void
{
    $update = backpackCommandUpdate($tgId);

    app(MenuHandler::class)->handleCommand(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

it('opens empty backpack via /backpack with header empty copy and no buttons', function (): void {
    $player = cityDone(9701, 'ПустойРюкзак');

    runBackpackCommand($player->tg_id);

    $panel = backpackPanelPayload();
    $max = backpack()->maxRows($player);

    expect($panel)->toBeString()
        ->and($panel)->toContain(__('profile.backpack_header_all', [
            'current' => 0,
            'max' => $max,
        ]))
        ->and($panel)->toContain(__('profile.inventory_empty'))
        ->and($panel)->not->toContain('menu:home')
        ->and($panel)->not->toContain('inv:list:WEAPON:1')
        ->and($panel)->not->toContain('menu:bag')
        ->and($panel)->not->toContain('menu:gear');
});

it('opens the same empty panel via menu:inv callback', function (): void {
    $player = cityDone(9702, 'МенюИнв');

    runBackpackCallback($player->tg_id, 'menu:inv');

    $panel = backpackPanelPayload();

    expect($panel)->toContain(__('profile.inventory_empty'))
        ->and($panel)->not->toContain('menu:home')
        ->and($panel)->not->toContain('menu:bag')
        ->and($panel)->not->toContain('menu:gear');
});

it('opens backpack on weapon filter as primary and hides other types', function (): void {
    $player = cityDone(9703, 'Список');
    backpack()->addItem($player->tg_id, 'knife_0');
    backpack()->addItem($player->tg_id, 'worn_mail');
    backpack()->addItem($player->tg_id, 'focus_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');
    $mail = backpack()->findOwned($player->tg_id, 'worn_mail');
    $ring = backpack()->findOwned($player->tg_id, 'focus_0');

    runBackpackCallback($player->tg_id, 'menu:inv');

    $panel = backpackPanelPayload();
    $max = backpack()->maxRows($player);

    expect($panel)->toContain(__('profile.backpack_header_all', [
        'current' => 3,
        'max' => $max,
    ]))
        ->and($panel)->toContain('inv:card:' . $knife->id)
        ->and($panel)->not->toContain('inv:card:' . $mail->id)
        ->and($panel)->not->toContain('inv:card:' . $ring->id)
        ->and($panel)->toContain('inv:list:WEAPON:1')
        ->and($panel)->toContain('inv:list:ARMOR:1')
        ->and($panel)->toContain('inv:list:JEWELRY:1')
        ->and($panel)->toContain('"style":"primary"')
        ->and($panel)->not->toContain(__('telegram.btn.filter_all'))
        ->and($panel)->not->toContain(__('profile.inventory_empty'))
        ->and($panel)->not->toContain('menu:bag')
        ->and($panel)->not->toContain('menu:gear');
});

it('filters weapons and hides armor jewelry rows', function (): void {
    $player = cityDone(9704, 'ФильтрОружие');
    backpack()->addItem($player->tg_id, 'knife_0');
    backpack()->addItem($player->tg_id, 'worn_mail');
    backpack()->addItem($player->tg_id, 'focus_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');
    $mail = backpack()->findOwned($player->tg_id, 'worn_mail');
    $ring = backpack()->findOwned($player->tg_id, 'focus_0');

    runBackpackCallback($player->tg_id, 'inv:list:WEAPON:1');

    $panel = backpackPanelPayload();

    expect($panel)->toContain('inv:card:' . $knife->id)
        ->and($panel)->not->toContain('inv:card:' . $mail->id)
        ->and($panel)->not->toContain('inv:card:' . $ring->id)
        ->and($panel)->toContain('inv:list:WEAPON:1');
});

it('shows empty-type copy when filter has no rows but backpack is not empty', function (): void {
    $player = cityDone(9705, 'ПустойФильтр');
    backpack()->addItem($player->tg_id, 'knife_0');

    runBackpackCallback($player->tg_id, 'inv:list:JEWELRY:1');

    $panel = backpackPanelPayload();

    expect($panel)->toContain(__('profile.inventory_empty_type'))
        ->and($panel)->toContain('inv:list:JEWELRY:1')
        ->and($panel)->toContain('inv:list:WEAPON:1')
        ->and($panel)->not->toContain(__('telegram.btn.filter_all'))
        ->and($panel)->not->toContain('inv:list:JEWELRY:0');
});

it('paginates backpack weapon filter when more than five weapons', function (): void {
    $player = cityDone(9706, 'BpPages');
    backpack()->addItem($player->tg_id, 'knife_0');
    backpack()->addItem($player->tg_id, 'axe_0');
    backpack()->addItem($player->tg_id, 'club_0');
    backpack()->addItem($player->tg_id, 'sword_0');
    backpack()->addItem($player->tg_id, 'hammer_0');
    backpack()->addItem($player->tg_id, 'bruiser_knuckle');

    runBackpackCallback($player->tg_id, 'inv:list:WEAPON:1');

    $pageOne = backpackPanelPayload();

    expect($pageOne)->toContain('inv:list:WEAPON:0')
        ->and($pageOne)->toContain('inv:list:WEAPON:2')
        ->and($pageOne)->toContain('inv:listpage:1:2');

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]]),
    ]);

    runBackpackCallback($player->tg_id, 'inv:list:WEAPON:2');

    $pageTwo = backpackPanelPayload();
    $ids = BackpackItem::query()
        ->where('tg_id', $player->tg_id)
        ->unequipped()
        ->where('item_type', 'WEAPON')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($pageTwo)->toContain('inv:card:' . $ids[5])
        ->and($pageTwo)->not->toContain('inv:card:' . $ids[0]);
});

it('hides equipped gear from the backpack list', function (): void {
    $player = cityDone(9707, 'ЭкипСкрыт');
    backpack()->addItem($player->tg_id, 'worn_mail');
    $mail = backpack()->findOwned($player->tg_id, 'worn_mail');
    $player = loadout()->equip($player, $mail->id)->character;
    backpack()->addItem($player->tg_id, 'knife_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');

    runBackpackCallback($player->tg_id, 'menu:inv');

    $panel = backpackPanelPayload();

    expect($panel)->toContain('inv:card:' . $knife->id)
        ->and($panel)->not->toContain('inv:card:' . $mail->id)
        ->and($panel)->toContain(__('profile.backpack_header_all', [
            'current' => 1,
            'max' => backpack()->maxRows($player),
        ]));
});

it('opens item card with back and discard in one row without wear bag or gear', function (): void {
    $player = cityDone(9708, 'Карточка');
    backpack()->addItem($player->tg_id, 'knife_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');

    runBackpackCallback($player->tg_id, 'inv:card:' . $knife->id);

    $panel = backpackPanelPayload();

    expect($panel)->toContain($knife->item_name)
        ->and($panel)->toContain('inv:discard:' . $knife->id)
        ->and($panel)->toContain('inv:list:WEAPON:1')
        ->and($panel)->not->toContain('inv:eq:ask:')
        ->and($panel)->not->toContain(__('menu.wear_item'))
        ->and($panel)->not->toContain('menu:bag')
        ->and($panel)->not->toContain('menu:gear')
        ->and($panel)->not->toContain('gear:gem:');
});

it('shows discard confirm with Нет Да and keeps item on cancel back to card', function (): void {
    $player = cityDone(9709, 'ОтменаВыброса');
    backpack()->addItem($player->tg_id, 'axe_0');
    $axe = backpack()->findOwned($player->tg_id, 'axe_0');

    runBackpackCallback($player->tg_id, 'inv:discard:' . $axe->id);

    $confirm = backpackPanelPayload();

    expect($confirm)->toContain(__('profile.discard_confirm_plain', [
        'name' => backpack()->rowLabel($axe),
    ]))
        ->and($confirm)->toContain('inv:card:' . $axe->id)
        ->and($confirm)->toContain('inv:discard_yes:' . $axe->id)
        ->and($confirm)->toContain('"style":"danger"')
        ->and($confirm)->toContain('"style":"success"');

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]]),
    ]);

    runBackpackCallback($player->tg_id, 'inv:card:' . $axe->id);

    expect(BackpackItem::query()->whereKey($axe->id)->exists())->toBeTrue()
        ->and(backpackPanelPayload())->toContain('inv:discard:' . $axe->id);
});

it('shows gem warning on discard confirm when item has socketed gems', function (): void {
    $player = cityDone(9710, 'КамниВСокете');
    backpack()->addItem($player->tg_id, 'knife_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');
    $player = grantGemDurability($player, 'ruby_0', 8);
    $socket = socketGem($player, $knife, 'ruby_0');
    expect($socket->ok)->toBeTrue();
    $knife->refresh();

    runBackpackCallback($player->tg_id, 'inv:discard:' . $knife->id);

    expect(backpackPanelPayload())->toContain(__('profile.discard_confirm', [
        'name' => backpack()->rowLabel($knife),
    ]));
});

it('discards item on Да and edits backpack panel with notice under header', function (): void {
    $player = cityDone(9711, 'Выбросил');
    backpack()->addItem($player->tg_id, 'axe_0');
    $axe = backpack()->findOwned($player->tg_id, 'axe_0');
    $name = $axe->item_name;

    runBackpackCallback($player->tg_id, 'inv:discard_yes:' . $axe->id);

    expect(BackpackItem::query()->whereKey($axe->id)->exists())->toBeFalse();

    $panel = backpackPanelPayload();
    $max = backpack()->maxRows($player);

    expect($panel)->toContain(__('profile.backpack_header_all', [
        'current' => 0,
        'max' => $max,
    ]))
        ->and($panel)->toContain(__('profile.discarded', ['name' => $name]))
        ->and($panel)->toContain(__('profile.inventory_empty'))
        ->and($panel)->not->toContain('menu:home');

    Http::assertNotSent(function (Request $request) use ($name): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('profile.discarded', ['name' => $name]);
    });
});

it('removes photo panel when returning to backpack list from a card', function (): void {
    $player = cityDone(9726, 'НазадРюкзакФото');
    backpack()->addItem($player->tg_id, 'knife_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');
    $max = backpack()->maxRows($player);

    $update = cityPhotoCallback($player->tg_id, 'inv:list:WEAPON:1');

    app(InventoryHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deleteMessage'));
    Http::assertSent(function (Request $request) use ($knife, $max): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), __('profile.backpack_header_all', [
                'current' => 1,
                'max' => $max,
            ]))
            && str_contains((string) ($request['reply_markup'] ?? ''), 'inv:card:' . $knife->id);
    });
});

it('removes photo panel when discarding from an item card with art', function (): void {
    $player = cityDone(9725, 'DropNoPhoto');
    backpack()->addItem($player->tg_id, 'worn_mail');
    $mail = backpack()->findOwned($player->tg_id, 'worn_mail');
    $max = backpack()->maxRows($player);

    $update = new TelegramUpdate([
        'update_id' => 9725,
        'callback_query' => [
            'id' => 'cb-discard-photo',
            'data' => 'inv:discard_yes:' . $mail->id,
            'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 55,
                'chat' => ['id' => $player->tg_id, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'mail-art', 'width' => 512, 'height' => 512],
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
    Http::assertSent(function (Request $request) use ($mail, $max): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), __('profile.discarded', [
                'name' => $mail->item_name,
            ]))
            && str_contains((string) ($request['text'] ?? ''), __('profile.backpack_header_all', [
                'current' => 0,
                'max' => $max,
            ]));
    });
});

it('accepts legacy inv:filter callbacks as filter page one', function (): void {
    $player = cityDone(9712, 'ЛегасиФильтр');
    backpack()->addItem($player->tg_id, 'knife_0');
    backpack()->addItem($player->tg_id, 'focus_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');
    $ring = backpack()->findOwned($player->tg_id, 'focus_0');

    runBackpackCallback($player->tg_id, 'inv:filter:WEAPON');

    $panel = backpackPanelPayload();

    expect($panel)->toContain('inv:card:' . $knife->id)
        ->and($panel)->not->toContain('inv:card:' . $ring->id);
});

it('falls back unknown list filter to weapon', function (): void {
    $player = cityDone(9713, 'СтранныйФильтр');
    backpack()->addItem($player->tg_id, 'knife_0');
    backpack()->addItem($player->tg_id, 'focus_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');
    $ring = backpack()->findOwned($player->tg_id, 'focus_0');

    runBackpackCallback($player->tg_id, 'inv:list:nope:1');

    $panel = backpackPanelPayload();

    expect($panel)->toContain('inv:card:' . $knife->id)
        ->and($panel)->not->toContain('inv:card:' . $ring->id)
        ->and($panel)->toContain('inv:list:WEAPON:1')
        ->and($panel)->toContain('"style":"primary"');
});

it('opens backpack via UpdateProcessor /backpack', function (): void {
    $player = cityDone(9714, 'ПроцессорРюкзак');

    app(UpdateProcessor::class)->handle([
        'update_id' => 97141,
        'message' => [
            'message_id' => 41,
            'text' => '/backpack',
            'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $player->tg_id, 'type' => 'private'],
            'entities' => [['offset' => 0, 'length' => 9, 'type' => 'bot_command']],
        ],
    ]);

    expect(backpackPanelPayload())->toContain(__('profile.inventory_empty'));
});

it('rejects item card for missing row', function (): void {
    $player = cityDone(9715, 'НетКарточки');

    runBackpackCallback($player->tg_id, 'inv:card:999999');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('rejects discard confirm for missing row', function (): void {
    $player = cityDone(9716, 'НетВыброса');

    runBackpackCallback($player->tg_id, 'inv:discard:999999');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('rejects discard confirm while item is equipped', function (): void {
    $player = cityDone(9717, 'НадетоНеВыбросить');
    backpack()->addItem($player->tg_id, 'worn_mail');
    $mail = backpack()->findOwned($player->tg_id, 'worn_mail');
    $player = loadout()->equip($player, $mail->id)->character;

    runBackpackCallback($player->tg_id, 'inv:discard:' . $mail->id);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.unequip_first');
    });
});

it('rejects discard_yes for missing row', function (): void {
    $player = cityDone(9718, 'НетДаВыброса');

    runBackpackCallback($player->tg_id, 'inv:discard_yes:999999');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('rejects card access to another players item', function (): void {
    $owner = cityDone(9719, 'Владелец');
    $intruder = cityDone(9720, 'Чужой');
    backpack()->addItem($owner->tg_id, 'knife_0');
    $knife = backpack()->findOwned($owner->tg_id, 'knife_0');

    runBackpackCallback($intruder->tg_id, 'inv:card:' . $knife->id);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('nudges registration instead of backpack while mid-registration', function (): void {
    $player = characters()->createDraft(9721);
    $player->progress_step = ProgressStepEnum::SET_NICK;
    $player->save();

    runBackpackCallback($player->tg_id, 'menu:inv');

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->body(), __('profile.inventory_empty'))
            || str_contains($request->body(), 'inv:list:');
    });
});

it('replies press start for unknown telegram user on backpack callback', function (): void {
    runBackpackCallback(9722, 'menu:inv');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('common.press_start');
    });
});

it('ignores /backpack during an active fight', function (): void {
    $player = cityDone(9723, 'ВБоюРюкзак');
    fights()->createHall($player, woodenSoldier($player));

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
    ]);

    runBackpackCommand($player->tg_id);

    expect(Http::recorded())->toBeEmpty();
});

it('flashes empty-type copy on out-of-range backpack page then keeps clamped markup', function (): void {
    $player = cityDone(9724, 'КрайСтраницы');
    backpack()->addItem($player->tg_id, 'knife_0');
    backpack()->addItem($player->tg_id, 'axe_0');
    backpack()->addItem($player->tg_id, 'club_0');
    backpack()->addItem($player->tg_id, 'sword_0');
    backpack()->addItem($player->tg_id, 'hammer_0');
    backpack()->addItem($player->tg_id, 'bruiser_knuckle');

    runBackpackCallback($player->tg_id, 'inv:list:WEAPON:99');

    assertCityEditHas(__('profile.inventory_empty_type'));
    assertTelegramMarkupBody(fn (string $body): bool => str_contains($body, 'inv:list:WEAPON:1')
        && str_contains($body, 'inv:listpage:2:2'));
});
