<?php

declare(strict_types=1);

namespace App\Actions\Appeal;

use App\Enums\Appeal\AppealStatusEnum;
use App\Models\Appeal\Appeal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AppealMarkReadAction
{
    public function handle(Appeal $ticket): Appeal
    {
        return DB::transaction(function () use ($ticket): Appeal {
            $locked = Appeal::query()->whereKey($ticket->id)->lockForUpdate()->first();

            if (! $locked instanceof Appeal) {
                throw new RuntimeException('Appeal missing for MarkRead.');
            }

            if (! $locked->status->canMarkRead()) {
                return $locked;
            }

            $locked->status = AppealStatusEnum::Read;
            $locked->save();

            return $locked;
        });
    }
}
