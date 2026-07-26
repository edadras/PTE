<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Enums\AiRequestStatus;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One row per provider call — including the ones that failed. This table is the
 * billing truth; anything missing from it is money we cannot explain.
 *
 * @property int $id
 * @property int $academy_id
 * @property int|null $student_id
 * @property int|null $answer_id
 * @property AiTaskKey $task_key
 * @property AiProvider $provider
 * @property string $model_key
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $total_tokens
 * @property float $cost_usd
 * @property int $latency_ms
 * @property AiRequestStatus $status
 * @property bool $cache_hit
 * @property bool $fallback_used
 */
final class AiRequest extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ai_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'task_key' => AiTaskKey::class,
            'provider' => AiProvider::class,
            'status' => AiRequestStatus::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'audio_minutes' => 'float',
            'cost_usd' => 'float',
            'cost_local' => 'float',
            'latency_ms' => 'integer',
            'prompt_version' => 'integer',
            'cache_hit' => 'boolean',
            'fallback_used' => 'boolean',
            'byok' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function log(): HasOne
    {
        return $this->hasOne(AiLog::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', AiRequestStatus::Success->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function isFailure(): bool
    {
        return $this->status->isFailure();
    }
}
