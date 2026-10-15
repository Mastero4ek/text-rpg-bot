<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\LibraryHandler;
use App\Telegram\Handlers\MenuHandler;
use App\Telegram\UpdateProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

function libraryCommandUpdate(int $tgId): TelegramUpdate
{
    return new TelegramUpdate([
        'update_id' => $tgId,
        'message' => [
            'message_id' => 40,
            'text' => '/library',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $tgId, 'type' => 'private'],
            'entities' => [[
                'offset' => 0,
                'length' => 8,
                'type' => 'bot_command',
            ]],
        ],
    ]);
}

function libraryBody(Request $request): string
{
    return urldecode($request->body());
}

function libraryRequestLooksLikePanel(Request $request): bool
{
    $url = $request->url();

    return str_contains($url, '/sendPhoto')
        || str_contains($url, '/sendMessage')
        || str_contains($url, '/editMessageMedia')
        || str_contains($url, '/editMessageCaption')
        || str_contains($url, '/editMessageText');
}

function assertLibraryPanelHas(string $needle): void
{
    Http::assertSent(function (Request $request) use ($needle): bool {
        if (! libraryRequestLooksLikePanel($request)) {
            return false;
        }

        $body = libraryBody($request);

        return str_contains($body, $needle)
            || str_contains($body, str_replace("\n", '\\n', $needle));
    });
}

function assertLibraryPanelMissing(string $needle): void
{
    Http::assertNotSent(function (Request $request) use ($needle): bool {
        if (! libraryRequestLooksLikePanel($request)) {
            return false;
        }

        $body = libraryBody($request);

        return str_contains($body, $needle)
            || str_contains($body, str_replace("\n", '\\n', $needle));
    });
}

function runLibraryCallback(int $tgId, string $data): void
{
    $update = cityPhotoCallback($tgId, $data);

    app(LibraryHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

function runLibraryCommand(int $tgId): void
{
    $update = libraryCommandUpdate($tgId);

    app(MenuHandler::class)->handleCommand(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

describe('library home', function (): void {
    it('opens home via /library with all section buttons', function (): void {
        $player = cityDone(10101, 'L10101');

        runLibraryCommand($player->tg_id);

        assertLibraryPanelHas(__('telegram.library.home'));
        assertLibraryPanelHas('lib:loc');
        assertLibraryPanelHas('lib:gear');
        assertLibraryPanelHas('lib:pot');
        assertLibraryPanelHas('lib:gem');
        assertLibraryPanelHas('lib:stat');
        assertLibraryPanelHas('lib:combat');
        assertLibraryPanelHas('lib:enemy');
        assertLibraryPanelHas('lib:game');
        assertLibraryPanelHas('lib:npc');
        assertLibraryPanelHas('lib:contract');
    });

    it('opens home via UpdateProcessor /library', function (): void {
        $player = cityDone(10102, 'L10102');

        app(UpdateProcessor::class)->handle([
            'update_id' => 101021,
            'message' => [
                'message_id' => 41,
                'text' => '/library',
                'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
                'chat' => ['id' => $player->tg_id, 'type' => 'private'],
                'entities' => [['offset' => 0, 'length' => 8, 'type' => 'bot_command']],
            ],
        ]);

        assertLibraryPanelHas(__('telegram.library.home'));
        assertLibraryPanelHas('lib:combat');
    });

    it('returns home on lib:home', function (): void {
        $player = cityDone(10103, 'L10103');

        runLibraryCallback($player->tg_id, 'lib:home');

        assertLibraryPanelHas(__('telegram.library.home'));
        assertLibraryPanelHas('lib:contract');
    });
});

describe('library single cards', function (): void {
    it('opens direct encyclopedia card with back home', function (string $callback, string $copy, int $tgId): void {
        $player = cityDone($tgId, 'L' . $tgId);

        runLibraryCallback($player->tg_id, $callback);

        assertLibraryPanelHas(__($copy));
        assertLibraryPanelHas('lib:home');
        assertLibraryPanelMissing('lib:loc:gates');
        assertLibraryPanelMissing('lib:gear:wear');
    })->with([
        'potions' => ['lib:pot', 'telegram.library.potions.use', 10110],
        'gems' => ['lib:gem', 'telegram.library.gems.use', 10111],
        'stats' => ['lib:stat', 'telegram.library.stats.use', 10112],
        'combat' => ['lib:combat', 'telegram.library.combat.use', 10113],
        'contracts' => ['lib:contract', 'telegram.library.contracts.use', 10114],
    ]);
});

describe('library gear', function (): void {
    it('opens gear topic list', function (): void {
        $player = cityDone(10120, 'L10120');

        runLibraryCallback($player->tg_id, 'lib:gear');

        assertLibraryPanelHas(__('telegram.library.gear.list'));
        assertLibraryPanelHas('lib:gear:wear');
        assertLibraryPanelHas('lib:gear:socket');
        assertLibraryPanelHas('lib:home');
    });

    it('opens gear topic card and backs to gear list', function (string $topic, int $tgId): void {
        $player = cityDone($tgId, 'L' . $tgId);

        runLibraryCallback($player->tg_id, 'lib:gear:' . $topic);

        assertLibraryPanelHas(__('telegram.library.gear.' . $topic));
        assertLibraryPanelHas('lib:gear');
        assertLibraryPanelMissing('lib:gear:wear');
    })->with([
        'wear' => ['wear', 10121],
        'socket' => ['socket', 10122],
    ]);
});

describe('library locations', function (): void {
    it('lists locations with full-width gates board training and back home', function (): void {
        $player = cityDone(10130, 'L10130');

        runLibraryCallback($player->tg_id, 'lib:loc');

        assertLibraryPanelHas(__('telegram.library.locations.list'));
        assertLibraryPanelHas('lib:loc:gates');
        assertLibraryPanelHas('lib:loc:board');
        assertLibraryPanelHas('lib:loc:training');
        assertLibraryPanelHas('lib:loc:tavern');
        assertLibraryPanelHas('lib:loc:portal');
        assertLibraryPanelHas('lib:loc:forest');
        assertLibraryPanelHas('lib:loc:swamp');
        assertLibraryPanelHas('lib:loc:port');
        assertLibraryPanelHas('lib:loc:ruins');
        assertLibraryPanelHas('lib:loc:arena');
        assertLibraryPanelHas('lib:loc:lists');
        assertLibraryPanelHas('lib:home');
        assertLibraryPanelHas(__('telegram.btn.gates'));
    });

    it('opens location card with back to locations', function (string $key, int $tgId): void {
        $player = cityDone($tgId, 'L' . $tgId);

        runLibraryCallback($player->tg_id, 'lib:loc:' . $key);

        assertLibraryPanelHas(__('telegram.library.locations.' . $key));
        assertLibraryPanelHas('lib:loc');
        assertLibraryPanelMissing('lib:loc:gates');
    })->with([
        'gates' => ['gates', 10131],
        'board' => ['board', 10132],
        'tavern' => ['tavern', 10133],
        'portal' => ['portal', 10134],
        'forest' => ['forest', 10135],
        'swamp' => ['swamp', 10136],
        'port' => ['port', 10137],
        'ruins' => ['ruins', 10138],
        'arena' => ['arena', 10139],
        'lists' => ['lists', 10140],
        'training' => ['training', 10141],
    ]);
});

describe('library enemies', function (): void {
    it('lists creatures with catalog callbacks', function (): void {
        $player = cityDone(10150, 'L10150');

        runLibraryCallback($player->tg_id, 'lib:enemy');

        assertLibraryPanelHas(__('telegram.library.enemies.list'));
        assertLibraryPanelHas('lib:enemy:wooden_soldier');
        assertLibraryPanelHas('lib:enemy:forest_bandit');
        assertLibraryPanelHas('lib:enemy:wild_dog');
        assertLibraryPanelHas('lib:enemy:rabid_rat');
        assertLibraryPanelHas('lib:enemy:swamp_witch');
        assertLibraryPanelHas('lib:enemy:bruiser');
        assertLibraryPanelHas('lib:home');
    });

    it('opens creature card with back to list', function (string $key, int $tgId): void {
        $player = cityDone($tgId, 'L' . $tgId);

        runLibraryCallback($player->tg_id, 'lib:enemy:' . $key);

        assertLibraryPanelHas(__('telegram.library.enemies.' . $key));
        assertLibraryPanelHas('lib:enemy');
        assertLibraryPanelMissing('lib:enemy:wooden_soldier');
    })->with([
        'wooden_soldier' => ['wooden_soldier', 10151],
        'forest_bandit' => ['forest_bandit', 10152],
        'wild_dog' => ['wild_dog', 10153],
        'rabid_rat' => ['rabid_rat', 10154],
        'swamp_witch' => ['swamp_witch', 10155],
        'bruiser' => ['bruiser', 10156],
    ]);
});

describe('library npcs', function (): void {
    it('lists city residents', function (): void {
        $player = cityDone(10160, 'L10160');

        runLibraryCallback($player->tg_id, 'lib:npc');

        assertLibraryPanelHas(__('telegram.library.npcs.list'));
        assertLibraryPanelHas('lib:npc:overseer');
        assertLibraryPanelHas('lib:npc:barkeeper');
        assertLibraryPanelHas('lib:npc:healer');
        assertLibraryPanelHas('lib:npc:blacksmith');
        assertLibraryPanelHas('lib:npc:buyer');
        assertLibraryPanelHas('lib:npc:bruiser');
        assertLibraryPanelHas('lib:home');
        assertLibraryPanelHas(__('telegram.btn.overseer'));
    });

    it('opens resident card with back to list', function (string $key, int $tgId): void {
        $player = cityDone($tgId, 'L' . $tgId);

        runLibraryCallback($player->tg_id, 'lib:npc:' . $key);

        assertLibraryPanelHas(__('telegram.library.npcs.' . $key));
        assertLibraryPanelHas('lib:npc');
        assertLibraryPanelMissing('lib:npc:overseer');
    })->with([
        'overseer' => ['overseer', 10161],
        'barkeeper' => ['barkeeper', 10162],
        'healer' => ['healer', 10163],
        'blacksmith' => ['blacksmith', 10164],
        'buyer' => ['buyer', 10165],
        'bruiser' => ['bruiser', 10166],
    ]);
});

describe('library minigames', function (): void {
    it('lists minigames', function (): void {
        $player = cityDone(10170, 'L10170');

        runLibraryCallback($player->tg_id, 'lib:game');

        assertLibraryPanelHas(__('telegram.library.minigames.list'));
        assertLibraryPanelHas('lib:game:lucky_barrel');
        assertLibraryPanelHas('lib:home');
        assertLibraryPanelHas(__('telegram.btn.lucky_barrel'));
    });

    it('opens lucky barrels card with back to minigames', function (): void {
        $player = cityDone(10171, 'L10171');

        runLibraryCallback($player->tg_id, 'lib:game:lucky_barrel');

        assertLibraryPanelHas(__('telegram.library.minigames.lucky_barrel'));
        assertLibraryPanelHas('lib:game');
        assertLibraryPanelMissing('lib:game:lucky_barrel');
    });
});

describe('library invalid keys', function (): void {
    it('falls back to section list for unknown catalog key', function (string $callback, string $listCopy, string $missingMarker, int $tgId): void {
        $player = cityDone($tgId, 'L' . $tgId);

        runLibraryCallback($player->tg_id, $callback);

        assertLibraryPanelHas(__($listCopy));
        assertLibraryPanelHas('lib:home');
        assertLibraryPanelMissing($missingMarker);
    })->with([
        'location' => ['lib:loc:moonbase', 'telegram.library.locations.list', 'lib:loc:moonbase', 10180],
        'enemy' => ['lib:enemy:dragon', 'telegram.library.enemies.list', 'lib:enemy:dragon', 10181],
        'npc' => ['lib:npc:ghost', 'telegram.library.npcs.list', 'lib:npc:ghost', 10182],
        'minigame' => ['lib:game:poker', 'telegram.library.minigames.list', 'lib:game:poker', 10183],
    ]);

    it('ignores unknown gear topic callback without opening a gear card', function (): void {
        $player = cityDone(10184, 'L10184');

        runLibraryCallback($player->tg_id, 'lib:gear:enchant');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/answerCallbackQuery');
        });

        assertLibraryPanelMissing(__('telegram.library.gear.wear'));
        assertLibraryPanelMissing(__('telegram.library.gear.socket'));
        assertLibraryPanelMissing(__('telegram.library.gear.list'));
    });

    it('answers unknown lib callback without opening library home', function (): void {
        $player = cityDone(10185, 'L10185');

        runLibraryCallback($player->tg_id, 'lib:zzz');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/answerCallbackQuery');
        });

        assertLibraryPanelMissing(__('telegram.library.home'));
    });
});

describe('library gates', function (): void {
    it('replies press start for unknown telegram user on /library', function (): void {
        app(UpdateProcessor::class)->handle([
            'update_id' => 10190,
            'message' => [
                'message_id' => 50,
                'text' => '/library',
                'from' => ['id' => 10190, 'is_bot' => false, 'first_name' => 'Ghost'],
                'chat' => ['id' => 10190, 'type' => 'private'],
                'entities' => [['offset' => 0, 'length' => 8, 'type' => 'bot_command']],
            ],
        ]);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/sendMessage')
                && ($request['text'] ?? null) === __('common.press_start');
        });
    });

    it('replies press start for unknown telegram user on library callback', function (): void {
        runLibraryCallback(10191, 'lib:home');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/sendMessage')
                && ($request['text'] ?? null) === __('common.press_start');
        });
    });

    it('nudges registration instead of library while mid-registration', function (): void {
        $player = characters()->createDraft(10192);
        $player->progress_step = ProgressStepEnum::SET_NICK;
        $player->save();

        runLibraryCallback($player->tg_id, 'lib:home');

        assertLibraryPanelMissing(__('telegram.library.home'));
        assertLibraryPanelMissing('lib:combat');
    });

    it('ignores /library during an active fight', function (): void {
        $player = cityDone(10193, 'L10193');
        fights()->createHall($player, woodenSoldier($player));

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
        ]);

        runLibraryCommand($player->tg_id);

        expect(Http::recorded())->toBeEmpty();
    });

    it('swallows /library while overseer meet is awaiting', function (): void {
        $player = cityArrived(10194, 'L10194');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 8]]),
        ]);

        app(UpdateProcessor::class)->handle([
            'update_id' => 101941,
            'message' => [
                'message_id' => 42,
                'text' => '/library',
                'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
                'chat' => ['id' => $player->tg_id, 'type' => 'private'],
                'entities' => [['offset' => 0, 'length' => 8, 'type' => 'bot_command']],
            ],
        ]);

        expect(Http::recorded())->toBeEmpty();
    });
});
