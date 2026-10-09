<?php

declare(strict_types=1);

use App\Support\Telegram\TelegramHtml;

it('converts tip tap paragraphs and marks into telegram html', function (): void {
    $html = '<p><strong>Жирный</strong> и <em>курсив</em></p><p>второй абзац</p>';

    expect(TelegramHtml::fromEditor($html))->toBe("<b>Жирный</b> и <i>курсив</i>\n\nвторой абзац");
});

it('converts hard breaks into telegram newlines', function (): void {
    $html = '<p><i>строка 1<br><br>строка 2</i></p>';

    expect(TelegramHtml::fromEditor($html))->toBe("<i>строка 1\n\nстрока 2</i>");
});

it('keeps telegram-allowed tags and strips the rest', function (): void {
    $html = '<p><span class="x">текст</span> <u>подчёркнутый</u> <s>зачёркнутый</s></p>';

    expect(TelegramHtml::fromEditor($html))->toBe('текст <u>подчёркнутый</u> <s>зачёркнутый</s>');
});

it('keeps links code pre and blockquote', function (): void {
    $html = '<p><a href="https://example.com">ссылка</a> <code>x</code></p><blockquote><p>речь</p></blockquote><pre><code>block</code></pre>';

    expect(TelegramHtml::fromEditor($html))->toBe('<a href="https://example.com">ссылка</a> <code>x</code><blockquote>речь</blockquote><pre><code>block</code></pre>');
});

it('rejects empty tip tap html', function (): void {
    TelegramHtml::fromEditor('<p></p><p><br></p>');
})->throws(InvalidArgumentException::class);

it('converts telegram newlines into tip tap breaks', function (): void {
    $telegram = "<i>Абзац один.\n\nАбзац два.</i>";

    expect(TelegramHtml::toEditor($telegram))->toBe('<p><i>Абзац один.<br><br>Абзац два.</i></p>');
});

it('converts literal backslash-n into tip tap breaks', function (): void {
    $telegram = '<i>раз\\n\\nдва</i>';

    expect(TelegramHtml::toEditor($telegram))->toBe('<p><i>раз<br><br>два</i></p>');
});

it('leaves tip tap html with paragraphs untouched', function (): void {
    $html = '<p><i>уже редактор</i></p>';

    expect(TelegramHtml::toEditor($html))->toBe($html);
});

it('rejects empty telegram html for the editor', function (): void {
    TelegramHtml::toEditor('   ');
})->throws(InvalidArgumentException::class);

it('round-trips city-style description through tip tap', function (): void {
    $telegram = "<i>Ты стоишь на площади <b>Анкрата</b>.\n\nЭто цитадель.</i>";

    $editor = TelegramHtml::toEditor($telegram);
    $back = TelegramHtml::fromEditor($editor);

    expect($back)->toBe($telegram);
});

it('detects blank editor html', function (string $html): void {
    expect(TelegramHtml::isBlankEditorHtml($html))->toBeTrue();
})->with([
    'empty' => [''],
    'spaces' => ['   '],
    'empty paragraph' => ['<p></p>'],
    'br only' => ['<p><br></p>'],
    'nbsp' => ['<p>&nbsp;</p>'],
]);

it('does not treat meaningful html as blank', function (): void {
    expect(TelegramHtml::isBlankEditorHtml('<p><i>текст</i></p>'))->toBeFalse();
});
