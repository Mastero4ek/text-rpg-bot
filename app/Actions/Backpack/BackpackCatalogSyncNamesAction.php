<?php

declare(strict_types=1);

namespace App\Actions\Backpack;

use App\Models\BackpackCatalog;
use App\Models\BackpackItem;

final class BackpackCatalogSyncNamesAction
{
    public function handle(BackpackCatalog $catalog): void
    {
        BackpackItem::query()
            ->where('catalog_id', $catalog->catalog_id)
            ->update(['item_name' => $catalog->name]);
    }
}
