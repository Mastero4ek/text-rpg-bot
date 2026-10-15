<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appeals\Schemas;

use App\Enums\Appeal\AppealStatusEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class AppealForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin.sections.appeal'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('character.username')
                            ->label(__('admin.labels.username')),
                        TextInput::make('tg_id')
                            ->label(__('admin.labels.tg_id')),
                        Select::make('status')
                            ->label(__('admin.labels.appeal_status'))
                            ->options(AppealStatusEnum::class)
                            ->native(false),
                        DateTimePicker::make('created_at')
                            ->label(__('admin.labels.created_at'))
                            ->displayFormat('d.m.Y H:i:s')
                            ->seconds(true)
                            ->native(false),
                        Textarea::make('body')
                            ->label(__('admin.labels.appeal_body'))
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
