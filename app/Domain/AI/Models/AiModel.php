<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Enums\AiTaskKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide model catalogue (non-tenant — CONVENTIONS §3).
 *
 * @property int $id
 * @property AiProvider $provider
 * @property string $model_key
 * @property string $display_name
 * @property array<string, mixed>|null $capabilities
 * @property float $input_price_per_1m
 * @property float $output_price_per_1m
 * @property float|null $price_per_audio_minute
 * @property string $currency
 * @property int|null $max_input_tokens
 * @property int|null $max_output_tokens
 * @property bool $is_active
 * @property array<int, string>|null $is_default_for
 */
final class AiModel extends Model
{
    protected $table = 'ai_models';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'capabilities' => 'array',
            'is_default_for' => 'array',
            'input_price_per_1m' => 'float',
            'output_price_per_1m' => 'float',
            'price_per_audio_minute' => 'float',
            'max_input_tokens' => 'integer',
            'max_output_tokens' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeProvider(Builder $query, AiProvider $provider): Builder
    {
        return $query->where('provider', $provider->value);
    }

    /**
     * Platform default for a task.
     *
     * Filtered in PHP rather than with whereJsonContains: the catalogue is a
     * dozen rows and JSON containment is not portable to the SQLite used by the
     * test suite.
     */
    public static function defaultFor(AiTaskKey $task): ?self
    {
        return self::query()
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->first(fn (self $model): bool => in_array($task->value, $model->is_default_for ?? [], true));
    }

    /**
     * @return Collection<int, self>
     */
    public static function activeCatalogue(): Collection
    {
        return self::query()->active()->orderBy('sort_order')->get();
    }

    public static function findByKey(string $modelKey): ?self
    {
        return self::query()->where('model_key', $modelKey)->first();
    }

    public function costFor(int $promptTokens, int $completionTokens): float
    {
        $cost = ($promptTokens / 1_000_000) * $this->input_price_per_1m
            + ($completionTokens / 1_000_000) * $this->output_price_per_1m;

        return round($cost, 6);
    }

    public function costForAudioMinutes(float $minutes): float
    {
        return round($minutes * (float) ($this->price_per_audio_minute ?? 0.0), 6);
    }

    public function isTranscription(): bool
    {
        return $this->provider->isTranscription();
    }

    public function supportsTask(AiTaskKey $task): bool
    {
        $tasks = $this->capabilities['tasks'] ?? null;

        return ! is_array($tasks) || $tasks === [] || in_array($task->value, $tasks, true);
    }

    /** Cheapest active model of the same provider — the economy-mode target. */
    public function economyAlternative(): ?self
    {
        return self::query()
            ->active()
            ->provider($this->provider)
            ->orderBy('input_price_per_1m')
            ->first();
    }
}
