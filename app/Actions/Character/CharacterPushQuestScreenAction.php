<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Enums\Quest\QuestChapterEnum;
use App\Models\Character;
use App\Quests\QuestChapterRegistry;
use App\Support\ActionResult;
use RuntimeException;
use Throwable;

final class CharacterPushQuestScreenAction
{
    public function __construct(
        private readonly CharacterApplyQuestScreenAction $apply,
        private readonly QuestChapterRegistry $registry,
    ) {}

    public function handle(Character $character, QuestChapterEnum $chapter, string $screenValue): ActionResult
    {
        $applied = $this->apply->handle($character, $chapter, $screenValue);

        if (! $applied->ok) {
            return $applied;
        }

        $fresh = $applied->character;

        if (! $fresh instanceof Character) {
            throw new RuntimeException('Quest screen apply succeeded without character.');
        }

        $module = $this->registry->module($chapter);

        try {
            $screen = $module->screenFrom($screenValue);
        } catch (Throwable) {
            return ActionResult::fail(__('admin.actions.apply_quest_screen.error.unknown_screen'));
        }

        return $module->pushPanel($fresh, $screen);
    }
}
