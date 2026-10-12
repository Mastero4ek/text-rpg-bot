<?php

declare(strict_types=1);

namespace App\Enums\Quest;

use Filament\Support\Contracts\HasLabel;

enum QuestChapterEnum: string implements HasLabel
{
    case Chapter1 = 'chapter1';

    public function getLabel(): string
    {
        return __('admin.quest_chapters.' . $this->value);
    }
}
