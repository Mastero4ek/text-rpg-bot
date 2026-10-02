<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Telegram\UpdateProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, UpdateProcessor $processor): JsonResponse
    {
        $payload = $request->all();

        if (config('bot.async') === true) {
            dispatch(new ProcessTelegramUpdateJob($payload));
        } else {
            $processor->handle($payload);
        }

        return response()->json(['ok' => true]);
    }
}
