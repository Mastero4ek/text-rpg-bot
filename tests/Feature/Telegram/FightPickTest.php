<?php

declare(strict_types=1);

use App\Enums\OnboardingStepEnum;
use App\Models\Enemy\EnemyCatalog;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\FightHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('lists only enabled in_fight_menu enemies with kind labels', function (): void {
    $p = characters()->createDraft(7401);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->level = 4;
    $p->save();

    EnemyCatalog::factory()->create([
        'catalog_id' => 'hidden_mob',
        'name' => 'Скрытый',
        'enabled' => true,
        'in_fight_menu' => false,
    ]);

    $update = fightCallback($p->tg_id, 'menu:fight');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageText')) {
            return false;
        }

        $markup = $request['reply_markup'] ?? '';

        if (! is_string($markup)) {
            return false;
        }

        return str_contains($markup, 'fight:start:chance_wanderer')
            && ! str_contains($markup, 'wooden_soldier')
            && ! str_contains($markup, 'hidden_mob');
    });
});

it('starts training fight by catalog_id', function (): void {
    $p = characters()->createDraft(7402);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->save();

    $update = fightCallback($p->tg_id, 'fight:start:chance_wanderer');
    app(FightHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    expect(fights()->exists($p->tg_id))->toBeTrue()
        ->and(fights()->enemy(fights()->findByTgId($p->tg_id))->catalogId)->toBe('chance_wanderer')
        ->and(fights()->findByTgId($p->tg_id)->tutorial)->toBeFalse();
});

function fightCallback(int $tgId, string $data): TelegramUpdate
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
                'text' => 'fight',
            ],
        ],
    ]);
}
