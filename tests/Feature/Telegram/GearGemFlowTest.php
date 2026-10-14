<?php

declare(strict_types=1);

use App\Enums\Equipment\SlotEnum;
use App\Models\Bag\BagItem;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\InventoryHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

function gearGemPanelPayload(): ?string
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

function runGearGemCallback(int $tgId, string $data): void
{
    $update = cityCallback($tgId, $data);

    app(InventoryHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );
}

it('opens gem confirm from unequipped gear pick with warn and no yes', function (): void {
    $player = cityDone(99101, 'ГемПик');
    $knife = backpack()->addItem($player->tg_id, 'knife_0');
    $player = grantGem($player, 'ruby_0', 1);
    $gem = looseGem($player, 'ruby_0');
    $slot = SlotEnum::RIGHT_HAND->value;

    runGearGemCallback($player->tg_id, 'inv:card:' . $knife->id . ':pick:' . $slot);

    $card = gearGemPanelPayload();

    expect($card)->toContain('gear:gem:pick:' . $knife->id . ':pick:' . $slot)
        ->and($card)->not->toContain('gear:gem:socket:' . $knife->id . ':' . $gem->id);

    runGearGemCallback($player->tg_id, 'gear:gem:pick:' . $knife->id . ':pick:' . $slot);

    $pick = gearGemPanelPayload();

    expect($pick)->toContain(__('menu.gear_gem_pick_title'))
        ->and($pick)->toContain('gear:gem:ask:' . $knife->id . ':' . $gem->id . ':pick:' . $slot)
        ->and($pick)->toContain('inv:card:' . $knife->id . ':pick:' . $slot)
        ->and($pick)->not->toContain('inv:list:WEAPON');

    runGearGemCallback(
        $player->tg_id,
        'gear:gem:ask:' . $knife->id . ':' . $gem->id . ':pick:' . $slot,
    );

    $ask = gearGemPanelPayload();

    expect($ask)->toContain(__('profile.gear_gem_socket_warn'))
        ->and($ask)->toContain('gear:gem:pick:' . $knife->id . ':pick:' . $slot)
        ->and($ask)->toContain('gear:gem:socket:' . $knife->id . ':' . $gem->id . ':pick:' . $slot)
        ->and($ask)->toContain('"style":"danger"')
        ->and($ask)->toContain('"style":"success"');
});

it('returns to unequipped gear pick card after socket and keeps slot not backpack', function (): void {
    $player = cityDone(99102, 'ГемСокет');
    $knife = backpack()->addItem($player->tg_id, 'knife_0');
    $player = grantGem($player, 'ruby_0', 1);
    $gem = looseGem($player, 'ruby_0');
    $slot = SlotEnum::RIGHT_HAND->value;

    runGearGemCallback(
        $player->tg_id,
        'gear:gem:socket:' . $knife->id . ':' . $gem->id . ':pick:' . $slot,
    );

    $panel = gearGemPanelPayload();
    $gem->refresh();

    expect($gem->backpack_item_id)->toBe($knife->id)
        ->and($panel)->toContain($knife->item_name)
        ->and($panel)->toContain('gear:pick:' . $slot)
        ->and($panel)->toContain('inv:eq:ask:' . $knife->id . ':' . $slot)
        ->and($panel)->toContain('gear:gem:view:' . $knife->id . ':')
        ->and($panel)->toContain(':pick:' . $slot)
        ->and($panel)->not->toContain('inv:list:WEAPON')
        ->and($panel)->not->toContain('inv:discard:');
});

it('cancels gem ask back to pick list with pick slot preserved', function (): void {
    $player = cityDone(99103, 'ГемНет');
    $knife = backpack()->addItem($player->tg_id, 'knife_0');
    $player = grantGem($player, 'ruby_0', 1);
    $gem = looseGem($player, 'ruby_0');
    $slot = SlotEnum::RIGHT_HAND->value;

    runGearGemCallback(
        $player->tg_id,
        'gear:gem:ask:' . $knife->id . ':' . $gem->id . ':pick:' . $slot,
    );
    runGearGemCallback($player->tg_id, 'gear:gem:pick:' . $knife->id . ':pick:' . $slot);

    $pick = gearGemPanelPayload();

    expect($pick)->toContain(__('menu.gear_gem_pick_title'))
        ->and($pick)->toContain('gear:gem:ask:' . $knife->id . ':' . $gem->id . ':pick:' . $slot)
        ->and($pick)->toContain('inv:card:' . $knife->id . ':pick:' . $slot)
        ->and(hasLooseGem($player->fresh(), 'ruby_0'))->toBeTrue()
        ->and($pick)->not->toContain('inv:list:WEAPON');
});

it('returns to equipped gear card after socket without backpack back', function (): void {
    $player = cityDone(99104, 'ГемОдет');
    $player = giveAndEquipStarterKnuckles($player);
    $knuckles = backpack()->findOwned($player->tg_id, shopCatalog()->starterKnucklesId());
    $player = grantGem($player, 'sapphire_0', 1);
    $gem = looseGem($player, 'sapphire_0');

    runGearGemCallback($player->tg_id, 'gear:view:' . $knuckles->id);

    $card = gearGemPanelPayload();

    expect($card)->toContain('gear:gem:pick:' . $knuckles->id)
        ->and($card)->not->toContain(':pick:RIGHT_HAND')
        ->and($card)->toContain('inv:uneq:ask:' . $knuckles->id);

    runGearGemCallback($player->tg_id, 'gear:gem:socket:' . $knuckles->id . ':' . $gem->id);

    $panel = gearGemPanelPayload();
    $gem->refresh();

    expect($gem->backpack_item_id)->toBe($knuckles->id)
        ->and($panel)->toContain($knuckles->item_name)
        ->and($panel)->toContain('menu:gear')
        ->and($panel)->toContain('inv:uneq:ask:' . $knuckles->id)
        ->and($panel)->toContain('gear:gem:view:' . $knuckles->id . ':' . $gem->id)
        ->and($panel)->not->toContain('inv:list:WEAPON')
        ->and($panel)->not->toContain('inv:eq:ask:');
});

it('shows socketed gem card from equipped item with back to gear view', function (): void {
    $player = cityDone(99105, 'ГемВью');
    $player = giveAndEquipStarterKnuckles($player);
    $knuckles = backpack()->findOwned($player->tg_id, shopCatalog()->starterKnucklesId());
    $player = grantGem($player, 'emerald_0', 1);
    $socket = socketGem($player, $knuckles, 'emerald_0');
    expect($socket->ok)->toBeTrue();
    $gem = BagItem::query()
        ->where('tg_id', $player->tg_id)
        ->where('backpack_item_id', $knuckles->id)
        ->where('catalog_id', 'emerald_0')
        ->firstOrFail();

    runGearGemCallback($player->tg_id, 'gear:gem:view:' . $knuckles->id . ':' . $gem->id);

    $panel = gearGemPanelPayload();
    $def = bagCatalog()->findGem('emerald_0');

    expect($panel)->toContain($def->name)
        ->and($panel)->toContain($def->type->getLabel())
        ->and($panel)->toContain('gear:view:' . $knuckles->id)
        ->and($panel)->not->toContain('gear:gem:socket:')
        ->and($panel)->not->toContain(__('profile.gear_gem_socket_warn'));
});
