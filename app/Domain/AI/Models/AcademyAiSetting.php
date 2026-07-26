<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $academy_id
 * @property AiTaskKey $task_key
 * @property AiProvider $provider
 * @property string $model_key
 * @property float|null $temperature
 * @property int|null $max_output_tokens
 * @property array<int, string>|null $fallback_chain
 * @property bool $use_own_key
 * @property string|null $api_key
 * @property string|null $api_key_last4
 * @property bool $cache_enabled
 * @property bool $economy_mode
 */
final class AcademyAiSetting extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $table = 'academy_ai_settings';

    protected $guarded = ['id'];

    /** The key is write-only: the panel shows api_key_last4 and nothing else. */
    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'task_key' => AiTaskKey::class,
            'provider' => AiProvider::class,
            'temperature' => 'float',
            'max_output_tokens' => 'integer',
            'fallback_chain' => 'array',
            'use_own_key' => 'boolean',
            'api_key' => 'encrypted',
            'cache_enabled' => 'boolean',
            'economy_mode' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            $key = $setting->api_key;

            $setting->api_key_last4 = filled($key) ? substr((string) $key, -4) : null;
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTask(Builder $query, AiTaskKey $task): Builder
    {
        return $query->where('task_key', $task->value);
    }

    public function usesByok(): bool
    {
        return $this->use_own_key && filled($this->api_key);
    }

    public function model(): ?AiModel
    {
        return AiModel::findByKey($this->model_key);
    }

    /**
     * @return array<int, string>
     */
    public function chain(): array
    {
        $chain = $this->fallback_chain ?? [];

        return array_values(array_unique(array_merge([$this->model_key], $chain)));
    }
}
