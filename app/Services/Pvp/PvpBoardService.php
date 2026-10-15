<?php

declare(strict_types=1);

namespace App\Services\Pvp;

use App\Enums\Pvp\PvpChallengeStatusEnum;
use App\Jobs\Pvp\PvpBoardWatchJob;
use App\Models\Character\Character;
use App\Models\City;
use App\Models\Pvp\PvpChallenge;
use App\Services\CityMenuService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Services\Registration\RegistrationService;
use App\Support\Telegram\TelegramClient;
use App\Telegram\Keyboards\CityKeyboard;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class PvpBoardService
{
    private const string WATCH_KEY_PREFIX = 'pvp.board.watch.';

    private const string SEQ_KEY_PREFIX = 'pvp.board.watch.seq.';

    public function __construct(
        private readonly CityMenuService $cityMenu,
        private readonly FightService $fights,
        private readonly GameConfig $config,
        private readonly PvpChallengeService $challenges,
        private readonly RegistrationService $registration,
        private readonly TelegramClient $telegram,
    ) {}

    public function boardTickSeconds(): int
    {
        $pvp = $this->config->pvp();

        if (! array_key_exists('boardTickSeconds', $pvp) || ! is_int($pvp['boardTickSeconds'])) {
            return 5;
        }

        if ($pvp['boardTickSeconds'] < 1) {
            return 5;
        }

        return $pvp['boardTickSeconds'];
    }

    /**
     * @return array{text: string, markup: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}, image: string|null}|null
     */
    public function panel(Character $player, int $page): ?array
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_fights_list) {
            return null;
        }

        $this->expireOwnIfDue($player);

        $open = $this->challenges->openInCity($city->id);
        $own = $this->challenges->openChallengeFor($player);
        $items = [];

        foreach ($open as $challenge) {
            $challenger = $challenge->challenger;

            if ($challenger === null) {
                continue;
            }

            if ($challenge->challenger_tg_id === $player->tg_id) {
                continue;
            }

            if (! $this->challenges->withinLevelBracket($player, $challenger)) {
                continue;
            }

            $name = $challenger->username;

            if ($name === null) {
                $name = __('common.unnamed');
            }

            $items[] = [
                'text' => __('telegram.pvp.list_item', [
                    'name' => $name,
                    'level' => $challenger->level,
                ]),
                'callback_data' => 'pvp:card:' . $challenge->id,
            ];
        }

        if ($own instanceof PvpChallenge) {
            $footer = [
                'text' => __('telegram.btn.pvp_cancel'),
                'callback_data' => 'pvp:cancel',
            ];
        } else {
            $footer = [
                'text' => __('telegram.btn.pvp_create'),
                'callback_data' => 'pvp:create',
                'style' => 'success',
            ];
        }

        if (empty($items)) {
            $text = __('telegram.location.fights_empty');
        } else {
            $text = __('telegram.location.fights');
        }

        if ($own instanceof PvpChallenge) {
            $text .= "\n\n" . $this->ownChallengeTimerCaption($own);
        }

        $arenaImage = config('bot.city_arena_image');

        if (! is_string($arenaImage) || $arenaImage === '' || ! is_file($arenaImage)) {
            $arenaImage = null;
        }

        return [
            'text' => $text,
            'markup' => CityKeyboard::fightsList($items, $page, $footer),
            'image' => $arenaImage,
        ];
    }

    public function startWatch(Character $player, int|string $chatId, int $messageId, int $page): void
    {
        $seq = $this->bumpSeq($player->tg_id);

        $this->putWatch($player->tg_id, [
            'seq' => $seq,
            'page' => max(1, $page),
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);

        $this->dispatchWatch($player->tg_id, $seq);
    }

    public function stopWatch(int $tgId): void
    {
        $this->bumpSeq($tgId);
        Cache::forget($this->watchKey($tgId));
    }

    public function tick(int $tgId, int $seq): void
    {
        $payload = Cache::get($this->watchKey($tgId));

        if (! is_array($payload)) {
            return;
        }

        if (! array_key_exists('seq', $payload) || (int) $payload['seq'] !== $seq) {
            return;
        }

        if (! array_key_exists('page', $payload) || ! is_int($payload['page'])) {
            return;
        }

        if (! array_key_exists('chat_id', $payload)) {
            return;
        }

        if (! array_key_exists('message_id', $payload) || ! is_int($payload['message_id'])) {
            return;
        }

        $chatId = $payload['chat_id'];
        $messageId = $payload['message_id'];
        $page = $payload['page'];

        if (! is_int($chatId) && ! is_string($chatId)) {
            return;
        }

        $player = Character::query()->find($tgId);

        if (! $player instanceof Character) {
            return;
        }

        if ($player->tg_message_id !== $messageId) {
            $this->stopWatch($tgId);

            return;
        }

        if ($this->fights->exists($tgId)) {
            $this->stopWatch($tgId);

            return;
        }

        $watch = [
            'seq' => $seq,
            'page' => $page,
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];

        $this->putWatch($tgId, $watch);

        $panel = $this->panel($player, $page);

        if ($panel === null) {
            $this->stopWatch($tgId);

            return;
        }

        try {
            $this->telegram->editMessageCaption($chatId, $messageId, $panel['text'], $panel['markup']);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'message is not modified')) {
                // keep watching
            } elseif (str_contains($e->getMessage(), 'there is no caption in the message to edit')) {
                try {
                    $this->telegram->editMessageText($chatId, $messageId, $panel['text'], $panel['markup']);
                } catch (Throwable) {
                    $this->stopWatch($tgId);

                    return;
                }
            } else {
                $this->stopWatch($tgId);

                return;
            }
        }

        $this->registration->rememberTelegramMessage($player, $chatId, $messageId);
        $this->dispatchWatch($tgId, $seq);
    }

    private function ownChallengeTimerCaption(PvpChallenge $challenge): string
    {
        $remaining = $this->challengeTimerDisplaySeconds($challenge);
        $minutes = intdiv($remaining, 60);
        $seconds = $remaining % 60;

        return __('telegram.pvp.challenge_timer', [
            'minutes' => $minutes,
            'seconds' => $seconds,
        ]);
    }

    private function challengeTimerDisplaySeconds(PvpChallenge $challenge): int
    {
        $timeout = $this->challenges->challengeTtlSeconds();
        $tick = $this->boardTickSeconds();
        $remaining = $challenge->expires_at->getTimestamp() - now()->getTimestamp();

        if ($remaining <= 0) {
            return 0;
        }

        $elapsed = $timeout - $remaining;

        if ($elapsed < 0) {
            $elapsed = 0;
        }

        $ticksPassed = intdiv($elapsed, $tick);
        $display = $timeout - ($ticksPassed * $tick);

        if ($display < 0) {
            return 0;
        }

        return $display;
    }

    private function expireOwnIfDue(Character $player): void
    {
        $own = $this->challenges->openChallengeFor($player);

        if (! $own instanceof PvpChallenge) {
            return;
        }

        if ($own->expires_at->isFuture()) {
            return;
        }

        $own->status = PvpChallengeStatusEnum::Expired;
        $own->save();
    }

    /**
     * @param  array{seq: int, page: int, chat_id: int|string, message_id: int}  $payload
     */
    private function putWatch(int $tgId, array $payload): void
    {
        Cache::put(
            $this->watchKey($tgId),
            $payload,
            now()->addSeconds($this->watchCacheTtlSeconds()),
        );
    }

    private function watchCacheTtlSeconds(): int
    {
        return $this->challenges->challengeTtlSeconds() + 120;
    }

    private function bumpSeq(int $tgId): int
    {
        $key = self::SEQ_KEY_PREFIX . $tgId;
        $seq = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $seq, now()->addSeconds($this->watchCacheTtlSeconds()));

        return $seq;
    }

    private function watchKey(int $tgId): string
    {
        return self::WATCH_KEY_PREFIX . $tgId;
    }

    private function dispatchWatch(int $tgId, int $seq): void
    {
        $delaySeconds = $this->boardTickSeconds();

        dispatch(new PvpBoardWatchJob($tgId, $seq))
            ->onConnection($this->watchQueueConnection())
            ->delay(now()->addSeconds($delaySeconds));
    }

    private function watchQueueConnection(): string
    {
        $driver = config('queue.default');

        if (is_string($driver) && $this->queueSupportsDelay($driver)) {
            return $driver;
        }

        return 'database';
    }

    private function queueSupportsDelay(string $driver): bool
    {
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
