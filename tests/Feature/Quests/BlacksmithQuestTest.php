<?php

declare(strict_types=1);

use App\Actions\Blacksmith\BlacksmithCompleteAction;
use App\Actions\Blacksmith\BlacksmithStartBagFightAction;
use App\Enums\Fight\FightEndUiEnum;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Backpack\BackpackItem;
use App\Quests\BlacksmithQuest;
use App\Services\Fight\FightEndService;
use App\Services\Quest\QuestNpcPlacement;
use App\Support\Telegram\TelegramHtml;
use App\Telegram\Keyboards\TelegramKeyboards;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('shows blacksmith panel on forest during blacksmith phase', function (): void {
    $player = cityBlacksmithPhase(98201, 'SmithMeet');

    expect(app(BlacksmithQuest::class)->awaitsHelp($player))->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:forest');

    assertCityEditMarkupHas('quest:blacksmith:fight');
    assertCityEditMarkupHas('city:gates');
    assertCityEditMarkupHas(__('telegram.quest.blacksmith.btn.fight'));
    assertCityEditMarkupHas(__('telegram.quest.blacksmith.btn.refuse'));

    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'fight:start:forest_bandit');
    });
});

it('grants mail and amulet on win with rewards and unlocks board', function (): void {
    $player = cityBlacksmithPhase(98202, 'SmithWin');
    $silverBefore = $player->silver;

    expect(app(BlacksmithStartBagFightAction::class)->handle($player)->ok)->toBeTrue();

    $fight = fights()->findByTgId($player->tg_id);
    expect($fight->kind)->toBe(FightKindEnum::QUEST_BLACKSMITH)
        ->and($fight->tutorial)->toBeFalse();

    $result = app(FightEndService::class)->finishWin($player->fresh(), $fight);

    expect($result->replyUi)->toBe(FightEndUiEnum::BlacksmithComplete)
        ->and($result->replyText)->toContain(mb_trim(__('telegram.quest.blacksmith.complete')));

    $completeMarkup = TelegramKeyboards::fightEndMarkup($result->replyUi);
    expect($completeMarkup['inline_keyboard'][0][0]['callback_data'] ?? null)->toBe('city:gates')
        ->and($completeMarkup['inline_keyboard'][0][0]['text'] ?? null)
        ->toBe(__('telegram.quest.blacksmith.btn.to_city'));

    $player->refresh();
    $mail = backpack()->findOwned($player->tg_id, 'worn_mail');
    $amulet = backpack()->findOwned($player->tg_id, 'ancient_amulet');

    expect($player->exp)->toBe(110)
        ->and($player->stat_points)->toBe(2)
        ->and($player->silver)->toBe($silverBefore + 10 + 8)
        ->and($player->blacksmith_at_tavern)->toBeTrue()
        ->and($player->unlock_board)->toBeTrue()
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Buyer)
        ->and($player->current_hp)->toBe(characters()->maxHp($player))
        ->and($player->current_stamina)->toBe(characters()->maxStamina($player))
        ->and($mail)->toBeInstanceOf(BackpackItem::class)
        ->and($mail->isEquipped())->toBeTrue()
        ->and($amulet)->toBeInstanceOf(BackpackItem::class)
        ->and($amulet->isEquipped())->toBeFalse()
        ->and(app(BlacksmithQuest::class)->awaitsHelp($player))->toBeFalse()
        ->and(app(QuestNpcPlacement::class)->isBlacksmithAtTavern($player))->toBeTrue()
        ->and(app(QuestNpcPlacement::class)->isBlacksmithAtForest($player))->toBeFalse();
});

it('opens forest pve pick after complete', function (): void {
    $player = cityBlacksmithPhase(98203, 'SmithTravel');
    expect(app(BlacksmithStartBagFightAction::class)->handle($player)->ok)->toBeTrue();
    $fight = fights()->findByTgId($player->tg_id);
    expect(app(FightEndService::class)->finishWin($player->fresh(), $fight)->character->quest_phase)
        ->toBe(QuestPhaseEnum::Buyer);

    cityFlowCallback($player->tg_id, 9, 'city:forest');

    assertCityEditHas(__('telegram.location.forest'));
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'quest:blacksmith:fight');
    });
});

it('lose heals and keeps phase for retry without rewards', function (): void {
    $player = cityBlacksmithPhase(98204, 'SmithLose');
    $expBefore = $player->exp;
    $silverBefore = $player->silver;

    expect(app(BlacksmithStartBagFightAction::class)->handle($player)->ok)->toBeTrue();
    $fight = fights()->findByTgId($player->tg_id);
    $result = app(FightEndService::class)->finishLose($player->fresh(), $fight);

    expect($result->replyUi->value)->toBe('blacksmith_meet')
        ->and($result->replyText)->toContain(mb_trim(__('telegram.quest.blacksmith.meet_retry')))
        ->and($result->replyText)->not->toContain(mb_trim(__('telegram.quest.blacksmith.meet')));

    $player->refresh();
    expect($player->exp)->toBe($expBefore)
        ->and($player->silver)->toBe($silverBefore)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Blacksmith)
        ->and($player->blacksmith_at_tavern)->toBeFalse()
        ->and($player->unlock_board)->toBeFalse()
        ->and($player->current_hp)->toBe(characters()->maxHp($player))
        ->and($player->current_stamina)->toBe(characters()->maxStamina($player))
        ->and(backpack()->owns($player->tg_id, 'worn_mail'))->toBeFalse()
        ->and(app(BlacksmithQuest::class)->awaitsHelp($player))->toBeTrue();

    expect(app(BlacksmithStartBagFightAction::class)->handle($player->fresh())->ok)->toBeTrue();
});

it('keeps fog on forest without unlock', function (): void {
    $player = cityArrivedSkipped(98205, 'SmithFog');
    $player->loadMissing('city');
    $cityEscaped = TelegramHtml::escape($player->city->name);

    cityFlowCallback($player->tg_id, 9, 'city:forest');

    expect($player->fresh()->unlock_forest)->toBeFalse();
    assertCityEditHas($cityEscaped);
});

it('rejects start and complete outside blacksmith phase', function (): void {
    $wrong = cityHealerPhase(98206, 'SmithWrong');

    expect(app(BlacksmithStartBagFightAction::class)->handle($wrong)->ok)->toBeFalse();
    expect(app(BlacksmithCompleteAction::class)->handle($wrong)->ok)->toBeFalse();
});

it('fails complete when backpack is full without partial rewards', function (): void {
    $player = cityBlacksmithPhase(98207, 'SmithFull');
    $player->backpack_max_rows = 1;
    $player->save();
    backpack()->addItem($player->tg_id, 'knife_0');

    $res = app(BlacksmithCompleteAction::class)->handle($player->fresh());

    expect($res->ok)->toBeFalse()
        ->and($res->error)->toBe(__('telegram.quest.blacksmith.error.backpack_full'));

    $player->refresh();
    expect($player->quest_phase)->toBe(QuestPhaseEnum::Blacksmith)
        ->and($player->blacksmith_at_tavern)->toBeFalse()
        ->and($player->unlock_board)->toBeFalse()
        ->and($player->exp)->toBe(70)
        ->and(backpack()->owns($player->tg_id, 'worn_mail'))->toBeFalse()
        ->and(backpack()->owns($player->tg_id, 'ancient_amulet'))->toBeFalse();
});

it('starts fight from telegram callback', function (): void {
    $player = cityBlacksmithPhase(98208, 'SmithTg');

    cityFlowCallback($player->tg_id, 9, 'city:forest');
    cityFlowCallback($player->tg_id, 10, 'quest:blacksmith:fight');

    $fight = fights()->findByTgId($player->tg_id);
    expect($fight->kind)->toBe(FightKindEnum::QUEST_BLACKSMITH)
        ->and(fights()->enemy($fight)->catalogId)->toBe('forest_bandit');
});

it('refuse from meet opens city gates without advancing quest', function (): void {
    $player = cityBlacksmithPhase(98209, 'SmithRefuse');

    cityFlowCallback($player->tg_id, 9, 'city:forest');
    cityFlowCallback($player->tg_id, 10, 'city:gates');

    $player->refresh();
    expect($player->quest_phase)->toBe(QuestPhaseEnum::Blacksmith)
        ->and($player->blacksmith_at_tavern)->toBeFalse()
        ->and(app(BlacksmithQuest::class)->awaitsHelp($player))->toBeTrue();

    assertCityEditHas(__('telegram.location.gates'));
});

it('rejects double complete after win', function (): void {
    $player = cityBlacksmithPhase(98210, 'SmithTwice');
    expect(app(BlacksmithStartBagFightAction::class)->handle($player)->ok)->toBeTrue();
    $fight = fights()->findByTgId($player->tg_id);
    expect(app(FightEndService::class)->finishWin($player->fresh(), $fight)->character->quest_phase)
        ->toBe(QuestPhaseEnum::Buyer);

    $again = app(BlacksmithCompleteAction::class)->handle($player->fresh());
    expect($again->ok)->toBeFalse()
        ->and(backpack()->findOwned($player->tg_id, 'worn_mail'))->toBeInstanceOf(BackpackItem::class)
        ->and(BackpackItem::query()->where('tg_id', $player->tg_id)->where('catalog_id', 'worn_mail')->count())->toBe(1)
        ->and(BackpackItem::query()->where('tg_id', $player->tg_id)->where('catalog_id', 'ancient_amulet')->count())->toBe(1);
});
