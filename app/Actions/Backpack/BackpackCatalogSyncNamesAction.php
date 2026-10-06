<?php

declare(strict_types=1);

namespace App\Actions\Backpack;

use App\Models\Backpack\BackpackCatalog;
use App\Models\Backpack\BackpackItem;

final class BackpackCatalogSyncNamesAction
{
    public function handle(BackpackCatalog $catalog): void
    {
        BackpackItem::query()
            ->where('catalog_id', $catalog->catalog_id)
            ->update(['item_name' => $catalog->name]);
    }
}
