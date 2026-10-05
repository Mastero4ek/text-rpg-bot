<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Tables\Columns\AppearanceImageColumn;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

trait HasAppearanceColumn
{
    public static function appearanceColumn(): AppearanceImageColumn
    {
        return AppearanceImageColumn::make('image')
            ->label(__('admin.labels.appearance'))
            ->collection('image')
            ->circular()
            ->imageSize(40)
            ->alignCenter()
            ->sortable(query: function (Builder $query, string $direction): Builder {
                if ($direction !== 'asc' && $direction !== 'desc') {
                    throw new RuntimeException('Invalid sort direction.');
                }

                $model = $query->getModel();
                $qualifiedKey = $model->getQualifiedKeyName();

                return $query->orderByRaw(
                    'exists (
                        select 1
                        from media
                        where media.model_type = ?
                          and media.model_id = ' . $qualifiedKey . '
                          and media.collection_name = ?
                    ) ' . $direction,
                    [$model->getMorphClass(), 'image'],
                );
            });
    }
}
