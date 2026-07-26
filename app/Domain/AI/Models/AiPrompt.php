<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Enums\PromptStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $academy_id  null = platform default template
 * @property AiTaskKey $key
 * @property int $version
 * @property PromptStatus $status
 * @property string|null $system_prompt
 * @property string $user_template
 * @property array<string, mixed>|null $output_schema
 * @property array<int, string>|null $variables
 * @property string|null $model_hint
 * @property \Illuminate\Support\Carbon|null $tested_at
 * @property \Illuminate\Support\Carbon|null $published_at
 */
final class AiPrompt extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $table = 'ai_prompts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'key' => AiTaskKey::class,
            'status' => PromptStatus::class,
            'version' => 'integer',
            'output_schema' => 'array',
            'variables' => 'array',
            'test_results' => 'array',
            'tested_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * "Mine or the platform's" — the only widening of the tenant scope this
     * model allows. It can never reach another academy's row, so it does not
     * need the platform gate that withoutTenantScope() enforces.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?int $academyId = null): Builder
    {
        $academyId ??= TenantContext::check() ? TenantContext::id() : null;

        return $query
            ->withoutGlobalScope('academy')
            ->where(function (Builder $inner) use ($academyId): void {
                $inner->whereNull('academy_id');

                if ($academyId !== null) {
                    $inner->orWhere('academy_id', $academyId);
                }
            });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PromptStatus::Published->value);
    }

    /**
     * The prompt a call should actually use: the academy's newest published
     * version, otherwise the platform's.
     */
    public static function resolveFor(AiTaskKey $task, ?int $academyId = null): ?self
    {
        return self::query()
            ->visibleTo($academyId)
            ->published()
            ->where('key', $task->value)
            // Academy rows sort before platform rows (0 before 1).
            ->orderByRaw('academy_id IS NULL')
            ->orderByDesc('version')
            ->first();
    }

    public function isPlatformDefault(): bool
    {
        return $this->academy_id === null;
    }

    /** Guardrail: no publishing a prompt that has not been proven on samples. */
    public function isPublishable(): bool
    {
        return $this->tested_at !== null && filled($this->user_template);
    }

    public function nextVersion(): int
    {
        $max = self::query()
            ->visibleTo($this->academy_id)
            ->where('key', $this->key instanceof AiTaskKey ? $this->key->value : $this->key)
            ->max('version');

        return (int) $max + 1;
    }
}
