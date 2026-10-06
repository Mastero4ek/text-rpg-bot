<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Models\Character;
use App\Services\CharacterService;

final class CharacterCreateDraftAction
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function handle(int $tgId): Character
    {
        return $this->characters->createDraft($tgId);
    }
}
