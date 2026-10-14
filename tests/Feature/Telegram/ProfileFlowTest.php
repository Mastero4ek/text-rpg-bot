<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Enums\StatKeyEnum;
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

function profileCommandUpdate(int $tgId, string $command = 'profile'): TelegramUpdate
{
    $text = '/' . $command;

    return new TelegramUpdate([
        'update_id' => $tgId,
        'message' => [
            'message_id' => 40,
            'text' => $text,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $tgId, 'type' => 'private'],
            'entities' => [[
                'offset' => 0,
                'length' => mb_strlen($text),
                'type' => 'bot_command',
            ]],
        ],
    ]);
}

function profilePanelPayload(): ?string
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

function runMenuCallback(int $tgId, string $data): void
{
    $update = cityCallback($tgId, $data);

    app(MenuHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

function runInventoryCallback(int $tgId, string $data): void
{
    $update = cityCallback($tgId, $data);

    app(InventoryHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

it('opens profile via /profile with combat icons and gear button', function (): void {
    $player = cityDone(9801, 'ПрофильГерой');
    $update = profileCommandUpdate($player->tg_id);

    app(MenuHandler::class)->handleCommand(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $panel = profilePanelPayload();

    expect($panel)->toBeString()
        ->and($panel)->toContain('ПрофильГерой')
        ->and($panel)->toContain('❤️')
        ->and($panel)->toContain('⚔️')
        ->and($panel)->toContain('🛡')
        ->and($panel)->toContain('menu:gear');
});

it('opens the same profile via legacy /character', function (): void {
    $player = cityDone(9802, 'Легаси');
    $update = profileCommandUpdate($player->tg_id, 'character');

    app(MenuHandler::class)->handleCommand(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $panel = profilePanelPayload();

    expect($panel)->toContain('Легаси')
        ->and($panel)->toContain('menu:gear');
});

it('opens profile via UpdateProcessor /profile', function (): void {
    $player = cityDone(9803, 'Процессор');

    app(UpdateProcessor::class)->handle([
        'update_id' => 98031,
        'message' => [
            'message_id' => 41,
            'text' => '/profile',
            'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $player->tg_id, 'type' => 'private'],
            'entities' => [['offset' => 0, 'length' => 8, 'type' => 'bot_command']],
        ],
    ]);

    $panel = profilePanelPayload();

    expect($panel)->toContain('Процессор')
        ->and($panel)->toContain('menu:gear');
});

it('edits profile panel on menu:profile callback', function (): void {
    $player = cityDone(9804, 'Колбэк');

    runMenuCallback($player->tg_id, 'menu:profile');

    $panel = profilePanelPayload();

    expect($panel)->toContain('Колбэк')
        ->and($panel)->toContain('menu:gear');
});

it('opens gear doll from profile and returns back to profile', function (): void {
    $player = cityDone(9805, 'Кукла');

    runInventoryCallback($player->tg_id, 'menu:gear');

    expect(profilePanelPayload())->toContain('menu:profile')
        ->and(profilePanelPayload())->toContain(__('menu.back'));

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]]),
    ]);

    runMenuCallback($player->tg_id, 'menu:profile');

    $panel = profilePanelPayload();

    expect($panel)->toContain('Кукла')
        ->and($panel)->toContain('menu:gear');
});

it('shows equip confirm with Нет/Да and icon deltas then equips', function (): void {
    $player = cityDone(9806, 'Надеть');
    backpack()->addItem($player->tg_id, 'worn_mail');
    $mail = backpack()->findOwned($player->tg_id, 'worn_mail');

    runInventoryCallback($player->tg_id, 'inv:eq:ask:' . $mail->id);

    $confirm = profilePanelPayload();

    expect($confirm)->toContain('Надеть «' . $mail->item_name . '»')
        ->and($confirm)->toContain(__('telegram.btn.no'))
        ->and($confirm)->toContain(__('telegram.btn.yes'))
        ->and($confirm)->toContain('inv:eq:yes:' . $mail->id)
        ->and($confirm)->toContain(__('menu.stat_max_hp'))
        ->and($confirm)->toContain(__('menu.stat_armor'));

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 3]]),
    ]);

    runInventoryCallback($player->tg_id, 'inv:eq:yes:' . $mail->id);

    expect($mail->fresh()->isEquipped())->toBeTrue();
});

it('shows unequip confirm with Нет/Да and icon deltas then unequips', function (): void {
    $player = cityDone(9807, 'Снять');
    backpack()->addItem($player->tg_id, 'worn_mail');
    $mail = backpack()->findOwned($player->tg_id, 'worn_mail');
    $player = loadout()->equip($player, $mail->id)->character;

    runInventoryCallback($player->tg_id, 'inv:uneq:ask:' . $mail->id);

    $confirm = profilePanelPayload();

    expect($confirm)->toContain('Снять «' . $mail->item_name . '»')
        ->and($confirm)->toContain(__('telegram.btn.no'))
        ->and($confirm)->toContain(__('telegram.btn.yes'))
        ->and($confirm)->toContain('inv:uneq:yes:' . $mail->id)
        ->and($confirm)->toContain(__('menu.stat_max_hp'))
        ->and($confirm)->toContain(__('menu.stat_armor'));

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4]]),
    ]);

    runInventoryCallback($player->tg_id, 'inv:uneq:yes:' . $mail->id);

    expect($mail->fresh()->isEquipped())->toBeFalse();
});

it('cancels equip confirm without wearing the item', function (): void {
    $player = cityDone(9808, 'Отмена');
    backpack()->addItem($player->tg_id, 'worn_mail');
    $mail = backpack()->findOwned($player->tg_id, 'worn_mail');

    runInventoryCallback($player->tg_id, 'inv:eq:ask:' . $mail->id);
    runInventoryCallback($player->tg_id, 'inv:card:' . $mail->id);

    expect($mail->fresh()->isEquipped())->toBeFalse();
});

it('spends a free stat point from skills screen', function (): void {
    $player = cityDone(9809, 'Статы');
    $player->stat_points = 1;
    $before = $player->strength;
    $player->save();

    runMenuCallback($player->tg_id, 'menu:stats');
    expect(profilePanelPayload())->toContain('stat:' . StatKeyEnum::STRENGTH->value);

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]]),
    ]);

    runMenuCallback($player->tg_id, 'stat:' . StatKeyEnum::STRENGTH->value);

    $fresh = $player->fresh();

    expect($fresh->strength)->toBe($before + 1)
        ->and($fresh->stat_points)->toBe(0)
        ->and(profilePanelPayload())->toContain('💚')
        ->and(profilePanelPayload())->toContain('❤️');
});

it('resets stats for gold after confirm', function (): void {
    $player = cityDone(9810, 'Сброс');
    $cost = characters()->statResetGoldCost();
    $player->gold = $cost;
    $player->strength += 3;
    $player->stat_points = 0;
    $player->save();

    runMenuCallback($player->tg_id, 'stat:reset');
    expect(profilePanelPayload())->toContain('stat:reset_yes');

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 6]]),
    ]);

    runMenuCallback($player->tg_id, 'stat:reset_yes');

    $fresh = $player->fresh();

    expect($fresh->gold)->toBe(0)
        ->and($fresh->strength)->toBe(gameConfig()->character()['start']['strength'])
        ->and(profilePanelPayload())->toContain('Статы сброшены');
});

it('replies press_start when /profile comes from unknown user', function (): void {
    app(UpdateProcessor::class)->handle([
        'update_id' => 98111,
        'message' => [
            'message_id' => 50,
            'text' => '/profile',
            'from' => ['id' => 98111, 'is_bot' => false, 'first_name' => 'Ghost'],
            'chat' => ['id' => 98111, 'type' => 'private'],
            'entities' => [['offset' => 0, 'length' => 8, 'type' => 'bot_command']],
        ],
    ]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        return ($request['text'] ?? null) === __('common.press_start');
    });
});

it('nudges registration instead of profile while mid-registration', function (): void {
    $player = characters()->createDraft(9812);
    $player->progress_step = ProgressStepEnum::SET_NICK;
    $player->save();

    runMenuCallback($player->tg_id, 'menu:profile');

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->body(), 'menu:gear');
    });
});

it('ignores /profile during an active fight', function (): void {
    $player = cityDone(9813, 'ВБою');
    fights()->createHall($player, woodenSoldier($player));

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
    ]);

    $update = profileCommandUpdate($player->tg_id);

    app(MenuHandler::class)->handleCommand(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(Http::recorded())->toBeEmpty();
});

it('rejects equip confirm for missing item', function (): void {
    $player = cityDone(9814, 'НетВещи');

    runInventoryCallback($player->tg_id, 'inv:eq:ask:999999');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('rejects unequip confirm when item is not equipped', function (): void {
    $player = cityDone(9815, 'НеНадето');
    backpack()->addItem($player->tg_id, 'worn_mail');
    $mail = backpack()->findOwned($player->tg_id, 'worn_mail');

    runInventoryCallback($player->tg_id, 'inv:uneq:ask:' . $mail->id);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.item_not_found');
    });
});

it('rejects spending a stat point with none left', function (): void {
    $player = cityDone(9816, 'БезОчков');
    $player->stat_points = 0;
    $player->save();

    runMenuCallback($player->tg_id, 'stat:' . StatKeyEnum::STRENGTH->value);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.no_stat_points');
    });
});

it('rejects stats reset without enough gold', function (): void {
    $player = cityDone(9817, 'БезЗолота');
    $player->gold = 0;
    $player->strength += 1;
    $player->save();

    runMenuCallback($player->tg_id, 'stat:reset_yes');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('errors.not_enough_gold');
    });
});

it('rejects equip when requirements are not met', function (): void {
    $player = cityDone(9818, 'Слабый');
    $player->level = 0;
    $player->strength = 0;
    $player->agility = 0;
    $player->instinct = 0;
    $player->save();

    backpack()->addItem($player->tg_id, 'sword_0');
    $sword = backpack()->findOwned($player->tg_id, 'sword_0');

    runInventoryCallback($player->tg_id, 'inv:eq:yes:' . $sword->id);

    expect($sword->fresh()->isEquipped())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        $text = $request['text'] ?? '';

        return is_string($text)
            && (
                str_contains($text, 'уровень')
                || str_contains($text, 'сила')
                || str_contains($text, 'ловкость')
            );
    });
});
