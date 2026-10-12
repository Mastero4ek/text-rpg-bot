<?php

declare(strict_types=1);

namespace App\Quests;

use App\Enums\Quest\QuestChapterEnum;
use App\Models\Character\Character;
use App\Support\ActionResult;

interface QuestChapterModuleContract
{
    public function chapter(): QuestChapterEnum;

    /**
     * @return array<string, string> screen value => admin label
     */
    public function screenOptionsForSection(int $section): array;

    public function sectionCount(): int;

    public function detect(Character $character): mixed;

    public function screenFrom(string $screenValue): mixed;

    public function applySnapshot(Character $character, mixed $screen): void;

    public function pushPanel(Character $character, mixed $screen): ActionResult;

    /**
     * @return list<string>
     */
    public function ownedStateKeys(): array;
}
