<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\Fight\FightEndUiEnum;
use App\Models\Character;

final readonly class FightEndResult
{
    public function __construct(
        public Character $character,
        public string $editText,
        public FightEndUiEnum $editUi,
        public ?string $replyText,
        public FightEndUiEnum $replyUi,
    ) {}
}
