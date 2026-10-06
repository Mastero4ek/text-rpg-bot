<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Filament\Resources\Characters\CharacterResource;
use App\Models\Character;
use App\Models\City;
use App\Models\User;
use App\Services\OnboardingService;
use App\Support\ActionResult;
use Filament\Notifications\Notification;

final class CharacterSetLocationAction
{
    public function __construct(
        private readonly OnboardingService $onboarding,
    ) {}

    public function handle(Character $character, string $cityKey): ActionResult
    {
        $result = $this->onboarding->setLocation($character, $cityKey);

        if (! $result->ok || ! $result->character instanceof Character) {
            return $result;
        }

        $this->notifyAdmin($result->character);

        return $result;
    }

    private function notifyAdmin(Character $character): void
    {
        $admin = User::query()->orderBy('id')->first();

        if (! $admin instanceof User) {
            return;
        }

        $character->loadMissing('city');

        if ($character->username === null || ! $character->city instanceof City) {
            return;
        }

        $url = e(CharacterResource::getUrl('view', ['record' => $character], isAbsolute: false));
        $nick = e($character->username);
        $location = e($character->city->name);

        Notification::make()
            ->title(__('admin.notifications.character_appeared.title'))
            ->body(__('admin.notifications.character_appeared.body', [
                'nick' => '<a href="' . $url . '" class="fi-link">' . $nick . '</a>',
                'location' => $location,
            ]))
            ->sendToDatabase($admin);
    }
}
