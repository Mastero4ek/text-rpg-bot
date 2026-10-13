<?php

declare(strict_types=1);

use App\Services\Fight\FightReportStore;
use Illuminate\Support\Facades\Cache;

it('leaves short captions unchanged', function (): void {
    $text = "<b>Раунд 1</b>\nГерой бьёт в голову на 12";

    expect(app(FightReportStore::class)->clipCaption($text))->toBe($text);
});

it('keeps fight start and end when caption exceeds telegram limit', function (): void {
    $lines = [];

    for ($i = 1; $i <= 80; $i++) {
        $lines[] = sprintf('<b>Раунд %d</b>', $i);
        $lines[] = sprintf('Герой бьёт в голову на %d', $i);
        $lines[] = sprintf('Враг бьёт в грудь на %d', $i);
        $lines[] = '';
    }

    $text = implode("\n", $lines);

    expect(mb_strlen($text))->toBeGreaterThan(1024);

    $clipped = app(FightReportStore::class)->clipCaption($text);

    expect(mb_strlen($clipped))->toBeLessThanOrEqual(1024)
        ->and($clipped)->toContain("\n\n…\n\n")
        ->and($clipped)->toStartWith('<b>Раунд 1</b>')
        ->and($clipped)->toContain('Герой бьёт в голову на 1')
        ->and($clipped)->toContain('<b>Раунд 80</b>')
        ->and($clipped)->toContain('Враг бьёт в грудь на 80')
        ->and($clipped)->not->toContain('<b>Раунд 40</b>');
});

it('keeps both ends of a single oversized line', function (): void {
    $text = str_repeat('А', 600) . 'СЕРЕДИНА' . str_repeat('Я', 600);

    $clipped = app(FightReportStore::class)->clipCaption($text);

    expect(mb_strlen($clipped))->toBeLessThanOrEqual(1024)
        ->and($clipped)->toContain("\n\n…\n\n")
        ->and($clipped)->toStartWith(str_repeat('А', 20))
        ->and($clipped)->toEndWith(str_repeat('Я', 20))
        ->and($clipped)->not->toContain('СЕРЕДИНА');
});

it('stores and returns report summary with log', function (): void {
    Cache::flush();

    $store = app(FightReportStore::class);
    $store->put(42, 7, 'summary-text', "log-line-1\nlog-line-2");

    expect($store->get(42, 7))->toBe([
        'summary' => 'summary-text',
        'log' => "log-line-1\nlog-line-2",
    ]);
});

it('returns null when report cache is missing or malformed', function (): void {
    Cache::flush();

    $store = app(FightReportStore::class);

    expect($store->get(42, 7))->toBeNull();

    Cache::put(FightReportStore::cacheKey(42, 7), ['summary' => 'only'], now()->addMinute());

    expect($store->get(42, 7))->toBeNull();
});
