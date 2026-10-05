<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Models\Character;
use App\Models\Inventory;
use App\Services\Fight\FightService;
use Illuminate\Support\Facades\DB;

final class CharacterDeleteAction
{
    public function __construct(
        private readonly FightService $fights,
    ) {}

    public function handle(Character $character): void
    {
        DB::transaction(function () use ($character): void {
            $this->fights->clear($character->tg_id);

            Inventory::query()
                ->where('tg_id', $character->tg_id)
                ->delete();

            $character->forceDelete();
        });
    }
}
