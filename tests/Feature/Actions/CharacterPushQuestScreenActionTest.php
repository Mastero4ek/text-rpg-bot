<?php

declare(strict_types=1);

use App\Actions\Character\CharacterPushQuestScreenAction;
use App\Enums\Quest\QuestChapterEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Quests\Chapter1\Chapter1ScreenEnum;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
    ]);
});

it('pushes overseer_gates panel with accept and skip callbacks', function (): void {
    $player = cityArrived(88401, 'PushGates');
    $player->tg_chat_id = 88401;
    $player->tg_message_id = 10;
    $player->save();

    $result = app(CharacterPushQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::OverseerGates->value,
    );

    expect($result->ok)->toBeTrue()
        ->and($player->fresh()->quest_phase)->toBe(QuestPhaseEnum::Overseer);

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return str_contains($body, 'quest:overseer:accept')
            && str_contains($body, 'quest:overseer:skip');
    });
});

it('pushes healer_meet with quest:healer:take', function (): void {
    $player = cityArrived(88402, 'PushHeal');
    $player->tg_chat_id = 88402;
    $player->tg_message_id = 11;
    $player->save();

    $result = app(CharacterPushQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::HealerMeet->value,
    );

    expect($result->ok)->toBeTrue()
        ->and($player->fresh()->quest_phase)->toBe(QuestPhaseEnum::Healer);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->body(), 'quest:healer:take');
    });
});

it('pushes buyer_turn_in with quest:buyer:turnin', function (): void {
    $player = cityDone(88403, 'PushTurn');
    $player->tg_chat_id = 88403;
    $player->tg_message_id = 12;
    $player->save();

    $result = app(CharacterPushQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::BuyerTurnIn->value,
    );

    expect($result->ok)->toBeTrue()
        ->and(bag()->ownsQuestItem($player->tg_id, 'buyer_purse'))->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->body(), 'quest:buyer:turnin');
    });
});

it('applies screen but fails push when tg_message_id missing', function (): void {
    $player = cityArrived(88404, 'PushNoMsg');
    $player->tg_chat_id = 88404;
    $player->tg_message_id = null;
    $player->save();

    $result = app(CharacterPushQuestScreenAction::class)->handle(
        $player,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::HealerMeet->value,
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(__('admin.actions.push_quest_screen.error.no_message'))
        ->and($player->fresh()->quest_phase)->toBe(QuestPhaseEnum::Healer)
        ->and($player->fresh()->healer_meet_open)->toBeFalse();

    Http::assertNothingSent();
});

it('fails archived without telegram panel', function (): void {
    $player = cityArrived(88405, 'PushArch');
    $player->tg_chat_id = 88405;
    $player->tg_message_id = 13;
    $player->save();
    $player->delete();

    $archived = Character::withTrashed()->findOrFail(88405);

    $result = app(CharacterPushQuestScreenAction::class)->handle(
        $archived,
        QuestChapterEnum::Chapter1,
        Chapter1ScreenEnum::OverseerGates->value,
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(__('admin.actions.apply_quest_screen.error.archived'));

    Http::assertNothingSent();
});
