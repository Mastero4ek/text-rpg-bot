<?php

declare(strict_types=1);

namespace App\Quests;

use App\Enums\Quest\QuestChapterEnum;
use App\Quests\Chapter1\Chapter1QuestModule;

final class QuestChapterRegistry
{
    public function __construct(
        private readonly Chapter1QuestModule $chapter1,
    ) {}

    /**
     * @return list<QuestChapterModuleContract>
     */
    public function modules(): array
    {
        return [$this->chapter1];
    }

    public function module(QuestChapterEnum $chapter): QuestChapterModuleContract
    {
        return match ($chapter) {
            QuestChapterEnum::Chapter1 => $this->chapter1,
        };
    }
}
