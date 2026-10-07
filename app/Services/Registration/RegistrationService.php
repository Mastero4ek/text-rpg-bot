<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\CharacterService;
use App\Support\ActionResult;
use App\Support\LangVariant;
use App\Support\NickValidator;
use Illuminate\Support\Facades\DB;

final class RegistrationService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CityQuery $cityQuery,
        private readonly NickValidator $nickValidator,
    ) {}

    /**
     * @return list<City>
     */
    public function cities(): array
    {
        $cities = [];

        foreach ($this->cityQuery->enabled() as $city) {
            $cities[] = $city;
        }

        return $cities;
    }

    public function cityButtonError(): string
    {
        return LangVariant::pick('telegram.registration.errors.pick_city_button');
    }

    public function ensurePlayer(int $tgId): Character
    {
        $character = Character::withTrashed()->find($tgId);

        if ($character === null) {
            return $this->characters->createDraft($tgId);
        }

        return $character;
    }

    public function rise(Character $character): Character
    {
        if ($character->progress_step !== ProgressStepEnum::SPLASH) {
            return $character;
        }

        $character->progress_step = ProgressStepEnum::SET_NICK;
        $character->save();

        return $character;
    }

    public function backToSplash(Character $character): Character
    {
        if ($character->progress_step !== ProgressStepEnum::SET_NICK) {
            return $character;
        }

        $character->progress_step = ProgressStepEnum::SPLASH;
        $character->save();

        return $character;
    }

    public function rememberTelegramMessage(Character $character, int|string $chatId, int $messageId): Character
    {
        $character->tg_chat_id = (int) $chatId;
        $character->tg_message_id = $messageId;
        $character->save();

        return $character;
    }

    public function rememberPendingDelete(Character $character, int $messageId): Character
    {
        $ids = $character->tg_pending_delete_ids;

        if (! is_array($ids)) {
            $ids = [];
        }

        if (! in_array($messageId, $ids, true)) {
            $ids[] = $messageId;
        }

        $character->tg_pending_delete_ids = $ids;
        $character->save();

        return $character;
    }

    /**
     * @return list<int>
     */
    public function pendingDeletes(Character $character): array
    {
        $ids = $character->tg_pending_delete_ids;

        if (! is_array($ids) || $ids === []) {
            return [];
        }

        return $ids;
    }

    public function clearPendingDeletes(Character $character): Character
    {
        $character->tg_pending_delete_ids = null;
        $character->save();

        return $character;
    }

    public function setNick(Character $character, string $raw): ActionResult
    {
        return DB::transaction(function () use ($character, $raw): ActionResult {
            $error = $this->nickValidator->validate($raw);

            if ($error !== null) {
                return ActionResult::fail($error);
            }

            $nick = mb_trim($raw);

            if ($this->characters->usernameTakenByOther($nick, $character->tg_id)) {
                return ActionResult::fail(LangVariant::pick('telegram.registration.errors.nick_taken'));
            }

            $character->username = $nick;
            $character->progress_step = ProgressStepEnum::SET_CITY;
            $character->save();

            return ActionResult::ok($character);
        });
    }

    public function setLocation(Character $character, string $cityKey): ActionResult
    {
        return DB::transaction(function () use ($character, $cityKey): ActionResult {
            $city = $this->cityQuery->findEnabledByKey($cityKey);

            if (! $city instanceof City) {
                return ActionResult::fail($this->cityButtonError());
            }

            $character->birth_city_id = $city->id;
            $character->city_id = $city->id;
            $character->progress_step = ProgressStepEnum::ARRIVED;
            $character->save();

            return ActionResult::ok($character);
        });
    }
}
