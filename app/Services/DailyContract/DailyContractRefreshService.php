<?php

declare(strict_types=1);

namespace App\Services\DailyContract;

use App\Enums\Quest\QuestPhaseEnum;
use App\Jobs\DailyContractBoardPushJob;
use App\Jobs\DailyContractRefreshCharacterJob;
use App\Models\Character\Character;
use App\Models\City;
use App\Services\CharacterService;
use App\Services\CityMenuService;
use App\Services\GameConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use RuntimeException;

final class DailyContractRefreshService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CityMenuService $cityMenu,
        private readonly DailyContractService $dailyContracts,
        private readonly GameConfig $config,
    ) {}

    public function dispatchRefreshJobs(): int
    {
        if (! $this->refreshEnabled()) {
            return 0;
        }

        $chunk = $this->refreshChunk();
        $dispatched = 0;
        $afterTgId = 0;

        while (true) {
            $rows = $this->eligibleQuery()
                ->where('tg_id', '>', $afterTgId)
                ->orderBy('tg_id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                dispatch(new DailyContractRefreshCharacterJob($row->tg_id));
                $dispatched++;
                $afterTgId = $row->tg_id;
            }
        }

        return $dispatched;
    }

    public function refreshCharacter(int $tgId): bool
    {
        if (! $this->refreshEnabled()) {
            return false;
        }

        $character = Character::query()->find($tgId);

        if (! $character instanceof Character) {
            return false;
        }

        if (! $this->isEligible($character)) {
            return false;
        }

        $city = $this->cityMenu->currentCity($character);

        if (! $city instanceof City || ! $city->has_quest_board) {
            return false;
        }

        $created = $this->dailyContracts->materializeTodayBoard($character, $city);

        if (! $this->pushEnabled()) {
            return $created;
        }

        if (! $this->claimDailyPushSlot($character->tg_id)) {
            return $created;
        }

        $spread = $this->pushSpreadSeconds();

        if ($spread < 1) {
            dispatch(new DailyContractBoardPushJob($character->tg_id));
        } else {
            $delaySeconds = random_int(0, $spread);
            dispatch(new DailyContractBoardPushJob($character->tg_id))
                ->delay(Date::now()->addSeconds($delaySeconds));
        }

        return true;
    }

    public function isEligible(Character $character): bool
    {
        if ($character->trashed()) {
            return false;
        }

        if ($this->characters->hasActiveBan($character)) {
            return false;
        }

        if (! $character->progress_step->canPlayCity()) {
            return false;
        }

        if ($character->quest_phase !== QuestPhaseEnum::Done) {
            return false;
        }

        if (! $character->unlock_board) {
            return false;
        }

        if ($character->tg_chat_id === null) {
            return false;
        }

        return $character->city_id !== null;
    }

    /**
     * @return Builder<Character>
     */
    public function eligibleQuery(): Builder
    {
        return Character::query()
            ->where('unlock_board', true)
            ->where('quest_phase', QuestPhaseEnum::Done)
            ->whereNotNull('tg_chat_id')
            ->whereNotNull('city_id')
            ->where(function (Builder $query): void {
                $query->whereNull('banned_until')
                    ->orWhere('banned_until', '<=', Date::now());
            })
            ->whereHas('city', function (Builder $query): void {
                $query->where('has_quest_board', true);
            });
    }

    private function claimDailyPushSlot(int $tgId): bool
    {
        $today = Date::now(config('app.timezone'))->toDateString();
        $key = 'daily-contract-push:' . $tgId . ':' . $today;

        return Cache::add($key, true, Date::now(config('app.timezone'))->endOfDay());
    }

    private function pushEnabled(): bool
    {
        $refresh = $this->refreshConfig();

        if (! array_key_exists('push', $refresh) || ! is_bool($refresh['push'])) {
            throw new RuntimeException('daily_contracts.refresh.push missing.');
        }

        return $refresh['push'];
    }

    private function pushSpreadSeconds(): int
    {
        $refresh = $this->refreshConfig();

        if (! array_key_exists('push_spread_seconds', $refresh) || ! is_int($refresh['push_spread_seconds'])) {
            throw new RuntimeException('daily_contracts.refresh.push_spread_seconds missing.');
        }

        if ($refresh['push_spread_seconds'] < 0) {
            throw new RuntimeException('daily_contracts.refresh.push_spread_seconds must be >= 0.');
        }

        return $refresh['push_spread_seconds'];
    }

    private function refreshChunk(): int
    {
        $refresh = $this->refreshConfig();

        if (! array_key_exists('chunk', $refresh) || ! is_int($refresh['chunk'])) {
            throw new RuntimeException('daily_contracts.refresh.chunk missing.');
        }

        if ($refresh['chunk'] < 1) {
            throw new RuntimeException('daily_contracts.refresh.chunk must be >= 1.');
        }

        return $refresh['chunk'];
    }

    private function refreshEnabled(): bool
    {
        $refresh = $this->refreshConfig();

        if (! array_key_exists('enabled', $refresh) || ! is_bool($refresh['enabled'])) {
            throw new RuntimeException('daily_contracts.refresh.enabled missing.');
        }

        return $refresh['enabled'];
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshConfig(): array
    {
        $cfg = $this->config->dailyContracts();

        if (! array_key_exists('refresh', $cfg) || ! is_array($cfg['refresh'])) {
            throw new RuntimeException('daily_contracts.refresh missing.');
        }

        return $cfg['refresh'];
    }
}
