<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\City;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\BuyerHandler;
use App\Telegram\Handlers\InventoryHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('sells inventory row through buyer sell_yes callback', function (): void {
    $p = placeInCity(characters()->createDraft(6401), City::KEY_ANKRAT);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $payout = backpack()->sellPayout($knife);

    $update = new TelegramUpdate([
        'update_id' => 6401,
        'callback_query' => [
            'id' => 'cb-sell-1',
            'data' => 'city:buyer:sell_yes:bp:' . $knife->id,
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 11,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'buyer',
            ],
        ],
    ]);

    app(BuyerHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(BackpackItem::query()->whereKey($knife->id)->exists())->toBeFalse()
        ->and(characters()->findByTgId($p->tg_id)->silver)->toBe($payout);
});

it('discards backpack item through inv discard_yes callback', function (): void {
    $p = characters()->createDraft(6402);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->save();

    backpack()->addItem($p->tg_id, 'axe_0');
    $axe = backpack()->findOwned($p->tg_id, 'axe_0');

    $update = new TelegramUpdate([
        'update_id' => 6402,
        'callback_query' => [
            'id' => 'cb-discard-1',
            'data' => 'inv:discard_yes:' . $axe->id,
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 12,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'inv',
            ],
        ],
    ]);

    app(InventoryHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(BackpackItem::query()->whereKey($axe->id)->exists())->toBeFalse();

    Http::assertSent(function (Request $request) use ($axe): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        return ($request['text'] ?? null) === __('profile.discarded', [
            'name' => $axe->item_name,
        ]);
    });
});

it('discards gem from bag through bag discard_yes callback', function (): void {
    $p = characters()->createDraft(6403);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->save();
    $p = grantGemDurability($p, 'ruby_0', 9);
    $p = grantGemDurability($p, 'emerald_0', 4);
    $ruby = looseGem($p, 'ruby_0');

    $update = new TelegramUpdate([
        'update_id' => 6403,
        'callback_query' => [
            'id' => 'cb-bag-discard-1',
            'data' => 'bag:discard_yes:' . $ruby->id,
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 13,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'bag',
            ],
        ],
    ]);

    app(InventoryHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $character = characters()->findByTgId($p->tg_id);

    expect(bag()->looseGems($character))->toHaveCount(1)
        ->and(hasLooseGem($character, 'emerald_0'))->toBeTrue()
        ->and(looseGem($character, 'emerald_0')->durability)->toBe(4);
    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        return ($request['text'] ?? null) === __('profile.discarded', [
            'name' => 'Рубин ученика',
        ]);
    });
});
