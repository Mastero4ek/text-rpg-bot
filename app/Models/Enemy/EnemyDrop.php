<?php

declare(strict_types=1);

namespace App\Models\Enemy;

use App\Models\Bag\BagCatalog;
use Carbon\CarbonInterface;
use Database\Factories\EnemyDropFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $enemy_catalog_id
 * @property string $bag_catalog_id
 * @property int $chance_pct
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read EnemyCatalog $enemy
 * @property-read BagCatalog $bagCatalog
 */
#[Fillable([
    'enemy_catalog_id',
    'bag_catalog_id',
    'chance_pct',
])]
final class EnemyDrop extends Model
{
    /** @use HasFactory<EnemyDropFactory> */
    use HasFactory;

    protected static string $factory = EnemyDropFactory::class;

    protected $table = 'enemy_drops';

    /**
     * @return BelongsTo<EnemyCatalog, $this>
     */
    public function enemy(): BelongsTo
    {
        return $this->belongsTo(EnemyCatalog::class, 'enemy_catalog_id', 'catalog_id');
    }

    /**
     * @return BelongsTo<BagCatalog, $this>
     */
    public function bagCatalog(): BelongsTo
    {
        return $this->belongsTo(BagCatalog::class, 'bag_catalog_id', 'catalog_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chance_pct' => 'integer',
        ];
    }
}
