<?php

declare(strict_types=1);

namespace App\Actions\Appeal;

use App\Enums\Appeal\AppealStatusEnum;
use App\Models\Appeal\Appeal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AppealCloseAction
{
    public function handle(Appeal $ticket): Appeal
    {
        return DB::transaction(function () use ($ticket): Appeal {
            $locked = Appeal::query()->whereKey($ticket->id)->lockForUpdate()->first();

            if (! $locked instanceof Appeal) {
                throw new RuntimeException('Appeal missing for Close.');
            }

            if (! $locked->status->canClose()) {
                return $locked;
            }

            $locked->status = AppealStatusEnum::Closed;
            $locked->save();

            return $locked;
        });
    }
}
