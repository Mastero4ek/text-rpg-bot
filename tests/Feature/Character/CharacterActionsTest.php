<?php

declare(strict_types=1);

use App\Actions\Character\CharacterDeleteAction;
use App\Actions\Character\CharacterGrantExpAction;
use App\Actions\Character\CharacterGrantGoldAction;
use App\Actions\Character\CharacterGrantSilverAction;
use App\Actions\Character\CharacterGrantStatPointsAction;
use App\Actions\Character\CharacterResetStatsAction;
use App\Actions\Character\CharacterResetStatsForGoldAction;
use App\Actions\Character\CharacterSetBackpackMaxRowsAction;
use App\Actions\Character\CharacterSetLocationAction;
use App\Enums\OnboardingStepEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

it('grant actions bump wallets points and experience thresholds', function (): void {
    $p = characters()->createDraft(1201);
    $p->stat_points = 0;
    $p->silver = 0;
    $p->gold = 0;
    $p->exp = 0;
    $p->save();

    $p = app(CharacterGrantSilverAction::class)->handle($p, 5);
    $p = app(CharacterGrantGoldAction::class)->handle($p, 2);
    $p = app(CharacterGrantStatPointsAction::class)->handle($p, 4);
    $p = app(CharacterGrantExpAction::class)->handle($p, 200);

    expect($p->silver)->toBe(5 + 8 + 8 + 8 + 17)
        ->and($p->gold)->toBe(2)
        ->and($p->level)->toBe(1)
        ->and($p->stat_points)->toBe(4 + 1 + 1 + 1 + 3);
});

it('grant exp rejects non positive amount', function (): void {
    $p = characters()->createDraft(1202);

    expect(fn () => app(CharacterGrantExpAction::class)->handle($p, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('reset stats actions wrap character service', function (): void {
    $start = gameConfig()->onboarding()['start'];
    $p = characters()->createDraft(1203);
    $p->strength = 12;
    $p->gold = characters()->statResetGoldCost();
    $p->save();

    $force = app(CharacterResetStatsAction::class)->handle($p->fresh());
    expect($force->strength)->toBe($start['strength']);

    $p = $force;
    $p->strength = 12;
    $p->gold = characters()->statResetGoldCost();
    $p->save();

    $paid = app(CharacterResetStatsForGoldAction::class)->handle($p->fresh());
    expect($paid->ok)->toBeTrue()
        ->and($paid->character->gold)->toBe(0)
        ->and($paid->character->strength)->toBe($start['strength']);
});

it('set location notifies admin in database', function (): void {
    $admin = User::factory()->create();
    $p = characters()->createDraft(1204);
    $p->username = 'NotifyHero';
    $p->onboarding_step = OnboardingStepEnum::CITY;
    $p->save();

    $city = onboarding()->cities()[0];
    $res = app(CharacterSetLocationAction::class)->handle($p, $city->key);

    expect($res->ok)->toBeTrue()
        ->and($res->character->city_id)->toBe($city->id);

    $this->assertDatabaseHas('notifications', [
        'notifiable_type' => User::class,
        'notifiable_id' => $admin->id,
    ]);

    $row = $admin->notifications()->first();
    expect($row)->not->toBeNull()
        ->and($row->data['title'])->toBe(__('admin.notifications.character_appeared.title'))
        ->and($row->data['body'])->toContain('NotifyHero')
        ->and($row->data['body'])->toContain($city->name);
});

it('set location failure does not notify', function (): void {
    User::factory()->create();
    $p = characters()->createDraft(1205);
    $p->username = 'NoCity';
    $p->onboarding_step = OnboardingStepEnum::CITY;
    $p->save();

    $res = app(CharacterSetLocationAction::class)->handle($p, 'Nowhere');

    expect($res->ok)->toBeFalse();
    $this->assertDatabaseCount('notifications', 0);
});

it('set inventory max rows persists capacity and rejects zero', function (): void {
    $p = characters()->createDraft(1207);

    $updated = app(CharacterSetBackpackMaxRowsAction::class)->handle($p, 12);

    expect($updated->backpack_max_rows)->toBe(12)
        ->and($p->fresh()->backpack_max_rows)->toBe(12);

    expect(fn () => app(CharacterSetBackpackMaxRowsAction::class)->handle($updated, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('delete action clears fight inventory and force deletes', function (): void {
    Bus::fake();

    $p = characters()->createDraft(1206);
    $p->username = 'Doomed';
    $p->save();
    backpack()->addItem($p->tg_id, 'knife_0');
    fights()->createTraining($p, woodenSoldier($p));

    expect(BackpackItem::query()->where('tg_id', $p->tg_id)->exists())->toBeTrue()
        ->and($p->fight()->exists())->toBeTrue();

    app(CharacterDeleteAction::class)->handle($p);

    expect(Character::withTrashed()->whereKey(1206)->exists())->toBeFalse()
        ->and(BackpackItem::query()->where('tg_id', 1206)->exists())->toBeFalse()
        ->and($p->fight()->exists())->toBeFalse();
});
