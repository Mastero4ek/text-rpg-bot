<?php

declare(strict_types=1);

use App\Actions\DailyContract\DailyContractAcceptAction;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Enums\ProgressStepEnum;
use App\Models\Character\CharacterDailyContract;
use App\Support\Telegram\DailyContractCopy;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\JournalHandler;
use App\Telegram\Handlers\MenuHandler;
use App\Telegram\UpdateProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

function journalCommandUpdate(int $tgId): TelegramUpdate
{
    return new TelegramUpdate([
        'update_id' => $tgId,
        'message' => [
            'message_id' => 40,
            'text' => '/journal',
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

function journalPanelPayload(): ?string
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

function runJournalCallback(int $tgId, string $data): void
{
    $update = cityCallback($tgId, $data);

    app(JournalHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

function runJournalCommand(int $tgId): void
{
    $update = journalCommandUpdate($tgId);

    app(MenuHandler::class)->handleCommand(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

function acceptDaily(CharacterDailyContract $offer): CharacterDailyContract
{
    $player = $offer->character;
    $city = dailyCity($player);
    $res = app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

    expect($res->ok)->toBeTrue();

    return $offer->fresh();
}

it('opens empty journal via /journal with header and no filters', function (): void {
    $player = cityDone(9901, 'ЖурналПусто');

    runJournalCommand($player->tg_id);

    $panel = journalPanelPayload();

    expect($panel)->toBeString()
        ->and($panel)->toContain(__('telegram.journal.list'))
        ->and($panel)->toContain(__('telegram.journal.empty'))
        ->and($panel)->not->toContain('journal:list:all:1')
        ->and($panel)->not->toContain('journal:list:hunt:1')
        ->and($panel)->not->toContain('daily:accept:');
});

it('lists taken hunt and delivery contracts with all filters', function (): void {
    $player = cityDone(9902, 'ЖурналСписок');
    $hunt = acceptDaily(dailyOffer($player));
    $delivery = acceptDaily(dailyDeliveryOffer($player->fresh()));

    runJournalCommand($player->tg_id);

    $panel = journalPanelPayload();

    expect($panel)->toContain(__('telegram.journal.list'))
        ->and($panel)->toContain('journal:card:' . $hunt->id . ':all')
        ->and($panel)->toContain('journal:card:' . $delivery->id . ':all')
        ->and($panel)->toContain('journal:list:all:1')
        ->and($panel)->toContain('journal:list:hunt:1')
        ->and($panel)->toContain('journal:list:delivery:1')
        ->and($panel)->toContain('"style":"primary"')
        ->and($panel)->not->toContain(__('telegram.journal.empty'))
        ->and($panel)->not->toContain('daily:accept:');
});

it('filters hunt tab and hides delivery rows', function (): void {
    $player = cityDone(9903, 'ЖурналОхота');
    $hunt = acceptDaily(dailyOffer($player));
    $delivery = acceptDaily(dailyDeliveryOffer($player->fresh()));

    runJournalCallback($player->tg_id, 'journal:list:hunt:1');

    $panel = journalPanelPayload();

    expect($panel)->toContain('journal:card:' . $hunt->id . ':hunt')
        ->and($panel)->not->toContain('journal:card:' . $delivery->id . ':hunt')
        ->and($panel)->not->toContain('journal:card:' . $delivery->id . ':all')
        ->and($panel)->toContain(__('telegram.journal.list'));
});

it('shows empty-type copy when delivery filter has no rows', function (): void {
    $player = cityDone(9904, 'ЖурналПустойФильтр');
    acceptDaily(dailyOffer($player));

    runJournalCallback($player->tg_id, 'journal:list:delivery:1');

    $panel = journalPanelPayload();

    expect($panel)->toContain(__('telegram.journal.empty_type'))
        ->and($panel)->toContain(__('telegram.journal.list'))
        ->and($panel)->toContain('journal:list:delivery:1')
        ->and($panel)->toContain('journal:list:all:1');
});

it('opens contract card with board copy and back only', function (): void {
    $player = cityDone(9905, 'ЖурналКарточка');
    $hunt = acceptDaily(dailyOffer($player));
    $copy = app(DailyContractCopy::class);

    runJournalCallback($player->tg_id, 'journal:card:' . $hunt->id . ':all');

    $panel = journalPanelPayload();

    expect($panel)->toContain($copy->cardText($hunt))
        ->and($panel)->toContain('journal:list:all:1')
        ->and($panel)->not->toContain('daily:accept:')
        ->and($panel)->not->toContain(__('telegram.daily.btn_accept'));
});

it('keeps ready and completed contracts in the journal and hides offered expired', function (): void {
    $player = cityDone(9906, 'ЖурналСтатусы');
    $city = dailyCity($player);
    $ready = acceptDaily(dailyOffer($player));
    $ready->status = DailyContractStatusEnum::Ready;
    $ready->save();

    $completed = acceptDaily(dailyDeliveryOffer($player->fresh()));
    $completed->status = DailyContractStatusEnum::Completed;
    $completed->save();

    $offered = dailyService()->ensureOffers($player->fresh(), $city)->first(
        fn (CharacterDailyContract $row): bool => $row->status === DailyContractStatusEnum::Offered,
    );

    expect($offered)->toBeInstanceOf(CharacterDailyContract::class);

    $expired = new CharacterDailyContract;
    $expired->tg_id = $player->tg_id;
    $expired->city_id = $city->id;
    $expired->board_date = now()->toDateString();
    $expired->slot = 90;
    $expired->type = DailyContractTypeEnum::Hunt;
    $expired->status = DailyContractStatusEnum::Expired;
    $expired->enemy_catalog_id = 'forest_bandit';
    $expired->issuer_npc = 'buyer';
    $expired->target_kills = 1;
    $expired->reward_silver = 1;
    $expired->reward_exp = 1;
    $expired->params = ['zone' => 'forest', 'monster_name' => 'Expired Bandit', 'lvl' => 1];
    $expired->save();

    runJournalCommand($player->tg_id);

    $panel = journalPanelPayload();

    expect($panel)->toContain(__('telegram.journal.list'))
        ->and($panel)->toContain('journal:card:' . $ready->id . ':all')
        ->and($panel)->toContain('journal:card:' . $completed->id . ':all')
        ->and($panel)->not->toContain('journal:card:' . $offered->id . ':')
        ->and($panel)->not->toContain('journal:card:' . $expired->id . ':');
});

it('paginates journal when more than five taken contracts', function (): void {
    $player = cityDone(9907, 'ЖурналСтраницы');
    $city = dailyCity($player);
    $ids = [];

    for ($i = 0; $i < 6; $i++) {
        $row = new CharacterDailyContract;
        $row->tg_id = $player->tg_id;
        $row->city_id = $city->id;
        $row->board_date = now()->toDateString();
        $row->slot = $i + 1;
        $row->type = DailyContractTypeEnum::Hunt;
        $row->status = DailyContractStatusEnum::Accepted;
        $row->enemy_catalog_id = 'forest_bandit';
        $row->issuer_npc = 'buyer';
        $row->target_kills = 1;
        $row->reward_silver = 10;
        $row->reward_exp = 10;
        $row->params = [
            'zone' => 'forest',
            'monster_name' => 'Bandit ' . $i,
            'lvl' => 1,
        ];
        $row->save();
        $ids[] = $row->id;
    }

    runJournalCallback($player->tg_id, 'journal:list:all:1');

    $pageOne = journalPanelPayload();

    expect($pageOne)->toContain('journal:list:all:0')
        ->and($pageOne)->toContain('journal:list:all:2')
        ->and($pageOne)->toContain('journal:listpage:1:2');

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]]),
    ]);

    runJournalCallback($player->tg_id, 'journal:list:all:2');

    $pageTwo = journalPanelPayload();

    expect($pageTwo)->toContain('journal:card:' . $ids[0] . ':all')
        ->and($pageTwo)->not->toContain('journal:card:' . $ids[5] . ':all');
});

it('opens journal via UpdateProcessor /journal', function (): void {
    $player = cityDone(9908, 'ЖурналПроцессор');

    app(UpdateProcessor::class)->handle([
        'update_id' => 99081,
        'message' => [
            'message_id' => 41,
            'text' => '/journal',
            'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $player->tg_id, 'type' => 'private'],
            'entities' => [['offset' => 0, 'length' => 8, 'type' => 'bot_command']],
        ],
    ]);

    expect(journalPanelPayload())->toContain(__('telegram.journal.empty'));
});

it('falls back to list when card targets missing or offered contract', function (): void {
    $player = cityDone(9909, 'ЖурналНетКарточки');
    $offer = dailyOffer($player);

    runJournalCallback($player->tg_id, 'journal:card:999999:all');

    expect(journalPanelPayload())->toContain(__('telegram.journal.empty'));

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]]),
    ]);

    runJournalCallback($player->tg_id, 'journal:card:' . $offer->id . ':all');

    $panel = journalPanelPayload();

    expect($panel)->toContain(__('telegram.journal.empty'))
        ->and($panel)->not->toContain('daily:accept:')
        ->and($panel)->not->toContain($offer->params['monster_name'] ?? '___missing___');
});

it('falls back to own list when card belongs to another player', function (): void {
    $owner = cityDone(9910, 'ЖурналВладелец');
    $intruder = cityDone(9911, 'ЖурналЧужой');
    $hunt = acceptDaily(dailyOffer($owner));

    runJournalCallback($intruder->tg_id, 'journal:card:' . $hunt->id . ':all');

    $panel = journalPanelPayload();

    expect($panel)->toContain(__('telegram.journal.empty'))
        ->and($panel)->not->toContain('journal:card:' . $hunt->id . ':');
});

it('replies press start for unknown telegram user on journal callback', function (): void {
    runJournalCallback(9912, 'journal:list:all:1');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === __('common.press_start');
    });
});

it('nudges registration instead of journal while mid-registration', function (): void {
    $player = characters()->createDraft(9913);
    $player->progress_step = ProgressStepEnum::SET_NICK;
    $player->save();

    runJournalCallback($player->tg_id, 'journal:list:all:1');

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->body(), __('telegram.journal.empty'))
            || str_contains($request->body(), 'journal:list:');
    });
});

it('ignores /journal during an active fight', function (): void {
    $player = cityDone(9914, 'ЖурналВБою');
    fights()->createHall($player, woodenSoldier($player));

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
    ]);

    runJournalCommand($player->tg_id);

    expect(Http::recorded())->toBeEmpty();
});

it('swallows /journal while overseer meet is awaiting', function (): void {
    $player = cityArrived(9915, 'ЖурналОффер');

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 8]]),
    ]);

    app(UpdateProcessor::class)->handle([
        'update_id' => 99151,
        'message' => [
            'message_id' => 42,
            'text' => '/journal',
            'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $player->tg_id, 'type' => 'private'],
            'entities' => [['offset' => 0, 'length' => 8, 'type' => 'bot_command']],
        ],
    ]);

    expect(Http::recorded())->toBeEmpty();
});

it('flashes empty-type copy on out-of-range journal page then keeps clamped markup', function (): void {
    $player = cityDone(9916, 'ЖурналКрай');
    $city = dailyCity($player);

    for ($i = 0; $i < 6; $i++) {
        $row = new CharacterDailyContract;
        $row->tg_id = $player->tg_id;
        $row->city_id = $city->id;
        $row->board_date = now()->toDateString();
        $row->slot = $i + 1;
        $row->type = DailyContractTypeEnum::Hunt;
        $row->status = DailyContractStatusEnum::Accepted;
        $row->enemy_catalog_id = 'forest_bandit';
        $row->issuer_npc = 'buyer';
        $row->target_kills = 1;
        $row->reward_silver = 10;
        $row->reward_exp = 10;
        $row->params = [
            'zone' => 'forest',
            'monster_name' => 'BanditEdge ' . $i,
            'lvl' => 1,
        ];
        $row->save();
    }

    runJournalCallback($player->tg_id, 'journal:list:all:99');

    assertCityEditHas(__('telegram.journal.empty_type'));
    assertTelegramMarkupBody(fn (string $body): bool => str_contains($body, 'journal:list:all:1')
        && str_contains($body, 'journal:listpage:2:2'));
});
