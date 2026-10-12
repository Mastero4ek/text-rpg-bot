<?php

declare(strict_types=1);

namespace App\Actions\Registration;

use App\Models\Character;
use App\Services\Registration\RegistrationService;
use App\Support\ActionResult;

final class SetSkinAction
{
    public function __construct(
        private readonly RegistrationService $registration,
    ) {}

    public function handle(Character $character, string $skinKey): ActionResult
    {
        return $this->registration->setSkin($character, $skinKey);
    }
}
