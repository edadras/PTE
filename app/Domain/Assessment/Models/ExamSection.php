<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\SelectionMode;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\ExamSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $academy_id
 * @property int $exam_id
 * @property string $title
 * @property ModuleKey $module_key
 * @property int $duration_minutes
 * @property float $score
 * @property int $sort_order
 * @property SelectionMode $selection_mode
 * @property array<string, mixed>|null $selection_config
 */
final class ExamSection extends Model
{
    /** @use HasFactory<ExamSectionFactory> */
    use BelongsToAcademy;
    use HasFactory;

    protected $guarded = ['id'];

    /** Models live outside App\\Models, so the factory is named explicitly. */
    protected static function newFactory(): ExamSectionFactory
    {
        return ExamSectionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'module_key' => ModuleKey::class,
            'duration_minutes' => 'integer',
            'score' => 'float',
            'sort_order' => 'integer',
            'selection_mode' => SelectionMode::class,
            'selection_config' => 'array',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('sort_order');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->selection_config ?? [], $key, $default);
    }

    /**
     * How many questions the student ends up with, regardless of mode.
     */
    public function questionCount(): int
    {
        return match ($this->selection_mode) {
            SelectionMode::Manual => $this->questions()->count(),
            SelectionMode::Random => max(0, (int) $this->config('count', 0)),
            SelectionMode::Pool => max(0, (int) $this->config('take', 0)),
        };
    }

    /**
     * Question types this section draws from, for `random` mode.
     *
     * @return array<int, QuestionType>
     */
    public function configuredTypes(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $type): ?QuestionType => is_string($type) ? QuestionType::tryFrom($type) : null,
            (array) $this->config('types', [])
        )));
    }
}
