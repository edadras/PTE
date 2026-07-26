<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Debug-only twin of AiRequest. Short-lived by design: it is the one place a
 * rendered prompt (and therefore student text) may be stored, so it is pruned
 * after config('pte.retention.ai_logs') days and readable by Super Admin only.
 *
 * @property int $id
 * @property int $academy_id
 * @property int $ai_request_id
 * @property string|null $rendered_prompt
 * @property string|null $raw_response
 */
final class AiLog extends Model
{
    use BelongsToAcademy;

    public const UPDATED_AT = null;

    protected $table = 'ai_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'ai_request_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query, ?int $days = null): Builder
    {
        $days ??= (int) config('pte.retention.ai_logs', 7);

        return $query->where('created_at', '<', now()->subDays($days));
    }
}
