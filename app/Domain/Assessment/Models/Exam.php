<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\ExamStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\ExamFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $academy_id
 * @property string $title
 * @property string|null $description
 * @property int $duration_minutes
 * @property float $total_score
 * @property float|null $passing_score
 * @property array<string, mixed>|null $rules
 * @property array<string, mixed>|null $availability
 * @property ExamStatus $status
 * @property Carbon|null $published_at
 * @property int|null $created_by
 */
final class Exam extends Model
{
    /** @use HasFactory<ExamFactory> */
    use BelongsToAcademy;

    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    /** Defaults mirror the Exam Builder screen in docs/05 §5. */
    private const DEFAULT_RULES = [
        'ordered_sections' => true,
        'allow_back' => false,
        'shuffle_questions' => true,
        'show_timer' => true,
        'autosave' => true,
        'instant_result' => false,
        'require_teacher_approval' => true,
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'total_score' => 'float',
            'passing_score' => 'float',
            'rules' => 'array',
            'availability' => 'array',
            'status' => ExamStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamSection::class)->orderBy('sort_order');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ExamStatus::Published->value);
    }

    public function rule(string $key, mixed $default = null): mixed
    {
        return data_get($this->rules ?? [], $key, $default ?? (self::DEFAULT_RULES[$key] ?? null));
    }

    public function opensAt(): ?Carbon
    {
        $value = data_get($this->availability ?? [], 'opens_at');

        return filled($value) ? Carbon::parse((string) $value) : null;
    }

    public function closesAt(): ?Carbon
    {
        $value = data_get($this->availability ?? [], 'closes_at');

        return filled($value) ? Carbon::parse((string) $value) : null;
    }

    public function maxAttempts(): int
    {
        return max(1, (int) data_get($this->availability ?? [], 'max_attempts', 1));
    }

    public function isOpenAt(?Carbon $moment = null): bool
    {
        $moment ??= now();
        $opens = $this->opensAt();
        $closes = $this->closesAt();

        return ! (($opens !== null && $moment->lt($opens)) || ($closes !== null && $moment->gt($closes)));
    }

    public function isAvailable(?Carbon $moment = null): bool
    {
        return $this->status->isStartable() && $this->isOpenAt($moment);
    }
}
