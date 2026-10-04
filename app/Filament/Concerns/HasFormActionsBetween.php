<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\Alignment;

/**
 * @method Action getCancelFormAction()
 * @method Action getSubmitFormAction()
 */
trait HasFormActionsBetween
{
    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::Between;
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction(),
            $this->getSubmitFormAction(),
        ];
    }
}
