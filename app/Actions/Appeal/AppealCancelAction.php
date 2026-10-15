<?php

declare(strict_types=1);

namespace App\Actions\Appeal;

use App\Enums\Appeal\AppealStatusEnum;
use App\Models\Appeal\Appeal;
use App\Models\Character\Character;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AppealCancelAction
{
    public function handle(Character $character, int $ticketId): ActionResult
    {
        return DB::transaction(function () use ($character, $ticketId): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for AppealCancel.');
            }

            $ticket = Appeal::query()
                ->whereKey($ticketId)
                ->where('tg_id', $locked->tg_id)
                ->lockForUpdate()
                ->first();

            if (! $ticket instanceof Appeal) {
                return ActionResult::fail(__('telegram.appeal.error.not_found'));
            }

            if (! $ticket->status->canCancel()) {
                return ActionResult::fail(__('telegram.appeal.error.cannot_cancel'));
            }

            $ticket->status = AppealStatusEnum::Cancelled;
            $ticket->save();

            return ActionResult::ok($locked);
        });
    }
}
