<?php

declare(strict_types=1);

namespace App\Actions\Appeal;

use App\Enums\Appeal\AppealStatusEnum;
use App\Filament\Resources\Appeals\AppealResource;
use App\Models\Appeal\Appeal;
use App\Models\Character\Character;
use App\Models\User;
use App\Services\Appeal\AppealService;
use App\Support\ActionResult;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AppealCreateAction
{
    public function __construct(
        private readonly AppealService $appeals,
    ) {}

    public function handle(Character $character, string $rawBody): ActionResult
    {
        $body = mb_trim($rawBody);

        if ($body === '') {
            return ActionResult::fail(__('telegram.appeal.error.empty'));
        }

        if (mb_strlen($body) > $this->appeals->maxBodyLength()) {
            return ActionResult::fail(__('telegram.appeal.error.too_long', [
                'max' => $this->appeals->maxBodyLength(),
            ]));
        }

        return DB::transaction(function () use ($character, $body): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for AppealCreate.');
            }

            if ($this->appeals->openCount($locked) >= $this->appeals->maxOpenAppeals()) {
                return ActionResult::fail(__('telegram.appeal.error.open_limit', [
                    'max' => $this->appeals->maxOpenAppeals(),
                ]));
            }

            $secondsSince = $this->appeals->secondsSinceLastCreate($locked);

            if (
                is_int($secondsSince)
                && $secondsSince < $this->appeals->cooldownSeconds()
            ) {
                return ActionResult::fail(__('telegram.appeal.error.cooldown'));
            }

            $ticket = new Appeal;
            $ticket->tg_id = $locked->tg_id;
            $ticket->body = $body;
            $ticket->status = AppealStatusEnum::New;
            $ticket->save();

            $this->notifyAdmins($ticket, $locked);

            return ActionResult::ok($locked);
        });
    }

    private function notifyAdmins(Appeal $ticket, Character $character): void
    {
        $username = $character->username;

        if (! is_string($username) || $username === '') {
            $username = (string) $character->tg_id;
        }

        $preview = $ticket->body;

        if (mb_strlen($preview) > 120) {
            $preview = mb_substr($preview, 0, 117) . '...';
        }

        $admins = User::query()->get();

        if ($admins->isEmpty()) {
            return;
        }

        $url = AppealResource::getUrl('view', ['record' => $ticket], isAbsolute: false);

        Notification::make()
            ->title(__('admin.notifications.appeal.title'))
            ->body(__('admin.notifications.appeal.body', [
                'username' => $username,
                'preview' => $preview,
            ]))
            ->actions([
                Action::make('view')
                    ->label(__('admin.actions.view.label'))
                    ->url($url)
                    ->markAsRead(),
            ])
            ->sendToDatabase($admins);
    }
}
