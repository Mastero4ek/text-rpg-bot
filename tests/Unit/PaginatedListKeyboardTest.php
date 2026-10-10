<?php

declare(strict_types=1);

use App\Telegram\Keyboards\PaginatedListKeyboard;

it('treats empty list page 1 as in range and other pages as edges', function (): void {
    expect(PaginatedListKeyboard::isOutOfRange(1, 0))->toBeFalse()
        ->and(PaginatedListKeyboard::isOutOfRange(0, 0))->toBeTrue()
        ->and(PaginatedListKeyboard::isOutOfRange(2, 0))->toBeTrue()
        ->and(PaginatedListKeyboard::clampedPage(9, 0))->toBe(1)
        ->and(PaginatedListKeyboard::pageCount(0))->toBe(1);
});

it('computes page bounds for a full list', function (): void {
    expect(PaginatedListKeyboard::pageCount(5))->toBe(1)
        ->and(PaginatedListKeyboard::pageCount(6))->toBe(2)
        ->and(PaginatedListKeyboard::isOutOfRange(1, 6))->toBeFalse()
        ->and(PaginatedListKeyboard::isOutOfRange(2, 6))->toBeFalse()
        ->and(PaginatedListKeyboard::isOutOfRange(0, 6))->toBeTrue()
        ->and(PaginatedListKeyboard::isOutOfRange(3, 6))->toBeTrue()
        ->and(PaginatedListKeyboard::clampedPage(0, 6))->toBe(1)
        ->and(PaginatedListKeyboard::clampedPage(9, 6))->toBe(2);
});

it('parses list callback state and rejects foreign prefixes', function (): void {
    expect(PaginatedListKeyboard::listState('city:buyer:chest:list:RUBY:2', 'city:buyer:chest'))
        ->toBe(['filter' => 'RUBY', 'page' => 2])
        ->and(PaginatedListKeyboard::listState('city:buyer:chest:list:all:0', 'city:buyer:chest'))
        ->toBe(['filter' => 'all', 'page' => 0])
        ->and(PaginatedListKeyboard::listState('city:buyer:sell:list:bag:1', 'city:buyer:chest'))
        ->toBeNull()
        ->and(PaginatedListKeyboard::listState('city:buyer:chest', 'city:buyer:chest'))
        ->toBeNull();
});
