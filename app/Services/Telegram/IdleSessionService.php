<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Actions\Fight\FightClearAction;
use App\Jobs\IdleSessionResetJob;
use App\Models\Character;
use App\Models\Fight;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\CityHandler;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class IdleSessionService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CityHandler $city,
        private readonly FightClearAction $clearFight,
        private readonly GameConfig $config,
    ) {}

    public function actionAtMatches(Character $character, int $actionAtUnix): bool
    {
        if ($character->last_action_at === null) {
            return false;
        }

        return $character->last_action_at->getTimestamp() === $actionAtUnix;
    }

    public function notifyIfIdle(Character $character, TelegramResponder $responder): void
    {
        if (! $this->shouldNotify($character)) {
            return;
        }

        $character = $this->abandonActiveFight($character);
        $this->city->sendIdleHomePanel($responder, $character);
        $this->touch($character);
    }

    public function shouldNotify(Character $character): bool
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

        if ($character->last_action_at === null) {
            return false;
        }

        $deadline = $character->last_action_at->copy()->addSeconds($this->idleSessionSeconds());

        return ! $deadline->isFuture();
    }

    public function touch(Character $character): Character
    {
        $character->last_action_at = now();
        $character->save();

        $this->schedule($character);

        return $character;
    }

    private function abandonActiveFight(Character $character): Character
    {
        $fight = Fight::query()->find($character->tg_id);

        if (! $fight instanceof Fight) {
            return $character;
        }

        if ($fight->tg_chat_id !== null && $fight->tg_message_id !== null) {
            $character->tg_chat_id = $fight->tg_chat_id;
            $character->tg_message_id = $fight->tg_message_id;
            $character->save();
        }

        $this->clearFight->handle($character->tg_id);

        return $character;
    }

    private function idleSessionSeconds(): int
    {
        $telegram = $this->config->telegram();

        if (! array_key_exists('idleSessionSeconds', $telegram) || ! is_int($telegram['idleSessionSeconds'])) {
            throw new RuntimeException('settings.telegram.idleSessionSeconds missing.');
        }

        if ($telegram['idleSessionSeconds'] < 1) {
            throw new RuntimeException('settings.telegram.idleSessionSeconds must be >= 1.');
        }

        return $telegram['idleSessionSeconds'];
    }

    private function schedule(Character $character): void
    {
        if ($character->last_action_at === null) {
            throw new RuntimeException('last_action_at missing after touch.');
        }

        $actionAtUnix = $character->last_action_at->getTimestamp();
        $seconds = $this->idleSessionSeconds();

        if (app()->runningUnitTests()) {
            dispatch(new IdleSessionResetJob($character->tg_id, $actionAtUnix))
                ->delay(now()->addSeconds($seconds));

            return;
        }

        if ($this->queueSupportsDelay()) {
            dispatch(new IdleSessionResetJob($character->tg_id, $actionAtUnix))
                ->delay(now()->addSeconds($seconds));

            return;
        }

        Process::path(base_path())
            ->timeout($seconds + 60)
            ->start([
                PHP_BINARY,
                base_path('artisan'),
                'telegram:idle-session-reset',
                (string) $character->tg_id,
                (string) $actionAtUnix,
                (string) $seconds,
            ]);
    }

    private function queueSupportsDelay(): bool
    {
        $driver = config('queue.default');

        if (! is_string($driver)) {
            return false;
        }

        if ($driver === 'redis') {
            return true;
        }

        if ($driver === 'database') {
            return true;
        }

        if ($driver === 'beanstalkd') {
            return true;
        }

        return $driver === 'sqs';
    }
}
