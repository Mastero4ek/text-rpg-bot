<?php

declare(strict_types=1);

use App\Filament\Resources\CityCatalog\Pages\EditCityCatalog;
use App\Filament\Support\TelegramDescriptionField;
use App\Models\City;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('saves tip tap description as telegram html with newlines', function (): void {
    $city = City::factory()->create([
        'name' => 'РичТекст',
        'description' => null,
    ]);

    livewire(EditCityCatalog::class, [
        'record' => $city->getKey(),
    ])
        ->fillForm([
            'description' => '<p><i>Первый абзац <b>город</b>.</i></p><p><i>Второй абзац.</i></p>',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($city->fresh()->description)->toBe("<i>Первый абзац <b>город</b>.</i>\n\n<i>Второй абзац.</i>");
});

it('clears description when rich editor is emptied', function (): void {
    $city = City::factory()->create([
        'name' => 'Пустое',
        'description' => '<i>было</i>',
    ]);

    livewire(EditCityCatalog::class, [
        'record' => $city->getKey(),
    ])
        ->fillForm([
            'description' => '<p></p>',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($city->fresh()->description)->toBeNull();
});

it('hydrates telegram newlines into tip tap breaks on edit', function (): void {
    $telegram = "<i>Строка один.\n\nСтрока два.</i>";
    $city = City::factory()->create([
        'name' => 'Переносы',
        'description' => $telegram,
    ]);

    livewire(EditCityCatalog::class, [
        'record' => $city->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            // TipTap normalizes <i>/<b> to <em>/<strong> in editor state.
            'description' => '<p><em>Строка один.<br><br>Строка два.</em></p>',
        ]);
});

it('rejects unsupported markup from tip tap when saving', function (): void {
    $city = City::factory()->create([
        'name' => 'Стрип',
        'description' => null,
    ]);

    livewire(EditCityCatalog::class, [
        'record' => $city->getKey(),
    ])
        ->fillForm([
            'description' => '<p><h2>Заголовок</h2><script>alert(1)</script>ok <u>u</u></p>',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($city->fresh()->description)->toBe('Заголовокok <u>u</u>');
});

it('converts telegram payload for form fill and clone', function (): void {
    expect(TelegramDescriptionField::forFormFill("<i>a\n\nb</i>"))
        ->toBe('<p><i>a<br><br>b</i></p>');
});

it('leaves blank and non-string form fill values alone', function (mixed $value): void {
    expect(TelegramDescriptionField::forFormFill($value))->toBe($value);
})->with([
    'null' => [null],
    'empty' => [''],
    'empty p' => ['<p></p>'],
    'int' => [1],
]);
