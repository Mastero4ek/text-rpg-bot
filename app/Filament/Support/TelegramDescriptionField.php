<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Telegram\TelegramHtml;
use Filament\Forms\Components\RichEditor;
use Illuminate\Database\Eloquent\Model;

final class TelegramDescriptionField
{
    /**
     * RichEditor limited to Telegram Bot API HTML tags (parse_mode=HTML).
     *
     * @see https://core.telegram.org/bots/api#html-style
     */
    public static function make(): RichEditor
    {
        return RichEditor::make('description')
            ->label(__('admin.labels.description'))
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'strike', 'link'],
                ['blockquote', 'code', 'codeBlock'],
                ['undo', 'redo'],
            ])
            ->fileAttachments(false)
            ->afterStateHydrated(function (RichEditor $component): void {
                $raw = self::telegramSource($component);

                if ($raw === null) {
                    return;
                }

                $component->state(TelegramHtml::toEditor($raw));
            })
            ->dehydrateStateUsing(function (mixed $state): ?string {
                if (! is_string($state)) {
                    return null;
                }

                if (TelegramHtml::isBlankEditorHtml($state)) {
                    return null;
                }

                return TelegramHtml::fromEditor($state);
            });
    }

    /**
     * Prefill/clone payloads still store Telegram newlines — convert before TipTap fill.
     */
    public static function forFormFill(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (TelegramHtml::isBlankEditorHtml($value)) {
            return $value;
        }

        return TelegramHtml::toEditor($value);
    }

    private static function telegramSource(RichEditor $component): ?string
    {
        $record = $component->getRecord();

        if (! $record instanceof Model) {
            return null;
        }

        $raw = $record->getAttribute($component->getName());

        if (! is_string($raw)) {
            return null;
        }

        if (TelegramHtml::isBlankEditorHtml($raw)) {
            return null;
        }

        return $raw;
    }
}
