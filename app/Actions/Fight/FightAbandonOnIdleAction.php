<?php

declare(strict_types=1);

namespace App\Actions\Fight;

use App\Enums\Fight\FightKindEnum;
use App\Models\Character\Character;
use App\Models\Fight;
use App\Quests\BlacksmithQuest;
use App\Quests\OverseerQuest;
use App\Services\Character\CharacterService;

final class FightAbandonOnIdleAction
{
    public function __construct(
        private readonly BlacksmithQuest $blacksmithQuest,
        private readonly CharacterService $characters,
        private readonly FightClearAction $clearFight,
        private readonly OverseerQuest $overseerQuest,
    ) {}

    public function handle(Character $character): Character
    {
        $fight = Fight::query()->find($character->tg_id);

        if (! $fight instanceof Fight) {
            return $character;
        }

        if ($fight->tg_chat_id !== null && $fight->tg_message_id !== null) {
            $character->tg_chat_id = $fight->tg_chat_id;
            $character->tg_message_id = $fight->tg_message_id;
            $character->save();
        }

        $character = $this->resetQuestState($character, $fight->kind);
        $this->clearFight->handle($character->tg_id);

        $fresh = $character->fresh();

        if ($fresh instanceof Character) {
            return $fresh;
        }

        return $character;
    }

    private function fullHeal(Character $character): Character
    {
        $character = $this->characters->applyRegen($character);
        $character->current_hp = $this->characters->maxHp($character);
        $character->current_stamina = $this->characters->maxStamina($character);
        $character->last_hp_update = now();
        $character->last_stamina_update = now();
        $character->save();

        return $character;
    }

    private function resetQuestState(Character $character, FightKindEnum $kind): Character
    {
        if ($kind === FightKindEnum::QUEST_OVERSEER) {
            $res = $this->overseerQuest->resetAfterLose($character);

            if ($res->ok && $res->character instanceof Character) {
                return $res->character;
            }

            return $this->fullHeal($character);
        }

        if ($kind === FightKindEnum::QUEST_BLACKSMITH) {
            $res = $this->blacksmithQuest->resetAfterLose($character);

            if ($res->ok && $res->character instanceof Character) {
                return $res->character;
            }

            return $this->fullHeal($character);
        }

        if ($kind === FightKindEnum::QUEST_BRUISER || $kind === FightKindEnum::QUEST_HEALER) {
            return $this->fullHeal($character);
        }

        return $character;
    }
}
