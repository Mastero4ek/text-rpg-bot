<?php

declare(strict_types=1);

namespace App\Models\Appeal;

use App\Enums\Appeal\AppealStatusEnum;
use App\Models\Character\Character;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tg_id
 * @property string $body
 * @property AppealStatusEnum $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Character|null $character
 */
final class Appeal extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tg_id',
        'body',
        'status',
    ];

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'tg_id', 'tg_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tg_id' => 'integer',
            'status' => AppealStatusEnum::class,
        ];
    }
}
