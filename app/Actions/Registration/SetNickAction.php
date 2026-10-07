<?php

declare(strict_types=1);

namespace App\Actions\Registration;

use App\Models\Character;
use App\Services\Registration\RegistrationService;
use App\Support\ActionResult;

final class SetNickAction
{
    public function __construct(
        private readonly RegistrationService $registration,
    ) {}

    public function handle(Character $character, string $raw): ActionResult
    {
        return $this->registration->setNick($character, $raw);
    }
}
