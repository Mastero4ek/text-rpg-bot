<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appeals\Pages;

use App\Filament\Resources\Appeals\AppealResource;
use Filament\Resources\Pages\ListRecords;

final class ListAppeals extends ListRecords
{
    protected static string $resource = AppealResource::class;
}
