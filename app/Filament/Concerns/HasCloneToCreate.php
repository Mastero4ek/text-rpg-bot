<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Clone record → Create page with form prefilled (not immediate DB replicate).
 *
 * View/Edit: call getCloneAction() in getHeaderActions().
 * Create: call fillFormFromClone() after parent::mount().
 *
 * @method Model getRecord()
 * @method static class-string<Resource> getResource()
 */
trait HasCloneToCreate
{
    protected function fillFormFromClone(): void
    {
        $sessionKey = $this->cloneSessionKey();

        if (! session()->has($sessionKey)) {
            return;
        }

        /** @var array<string, mixed> $data */
        $data = session()->pull($sessionKey);

        $this->form->fill($data);
    }

    protected function getCloneAction(): Action
    {
        return Action::make('clone')
            ->label(__('admin.actions.clone.label'))
            ->color('gray')
            ->action(function (): void {
                session()->put(
                    $this->cloneSessionKey(),
                    $this->cloneFormData(),
                );

                $this->redirect(static::getResource()::getUrl('create'));
            });
    }

    /**
     * @return list<string>
     */
    protected function getCloneExcludedAttributes(): array
    {
        $record = $this->getRecord();

        return [
            $record->getKeyName(),
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cloneFormData(): array
    {
        $record = $this->getRecord();
        $data = Arr::except(
            $record->attributesToArray(),
            $this->getCloneExcludedAttributes(),
        );

        if (! method_exists($record, 'cities')) {
            return $data;
        }

        $record->loadMissing('cities');
        $related = $record->getRelation('cities');
        $ids = [];

        if ($related instanceof \Illuminate\Database\Eloquent\Collection) {
            foreach ($related as $city) {
                $ids[] = $city->getKey();
            }
        }

        $data['cities'] = $ids;

        return $data;
    }

    private function cloneSessionKey(): string
    {
        return 'filament.clone.' . static::getResource();
    }
}
