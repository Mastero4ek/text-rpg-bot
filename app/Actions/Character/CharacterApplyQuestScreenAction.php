<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Actions\Fight\FightClearAction;
use App\Enums\Quest\QuestChapterEnum;
use App\Models\Character;
use App\Quests\Chapter1\Chapter1ScreenEnum;
use App\Quests\QuestChapterRegistry;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CharacterApplyQuestScreenAction
{
    public function __construct(
        private readonly FightClearAction $fightClear,
        private readonly QuestChapterRegistry $registry,
    ) {}

    public function handle(Character $character, QuestChapterEnum $chapter, string $screenValue): ActionResult
    {
        return DB::transaction(function () use ($character, $chapter, $screenValue): ActionResult {
            $locked = Character::withTrashed()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for CharacterApplyQuestScreen.');
            }

            if ($locked->trashed()) {
                return ActionResult::fail(__('admin.actions.apply_quest_screen.error.archived'));
            }

            try {
                $module = $this->registry->module($chapter);
            } catch (InvalidArgumentException) {
                return ActionResult::fail(__('admin.actions.apply_quest_screen.error.unknown_chapter'));
            }

            try {
                $screen = $module->screenFrom($screenValue);
            } catch (Throwable) {
                return ActionResult::fail(__('admin.actions.apply_quest_screen.error.unknown_screen'));
            }

            if (
                $screen instanceof Chapter1ScreenEnum
                && $screen->requiresCity()
                && $locked->city_id === null
            ) {
                return ActionResult::fail(__('admin.actions.apply_quest_screen.error.city_required'));
            }

            $this->fightClear->handle($locked->tg_id);
            $module->applySnapshot($locked, $screen);
            $locked->save();

            return ActionResult::ok($locked->fresh());
        });
    }
}
