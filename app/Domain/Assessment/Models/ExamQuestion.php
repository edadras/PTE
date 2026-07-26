<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Learning\Models\Question;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $academy_id
 * @property int $exam_section_id
 * @property int $question_id
 * @property int $sort_order
 * @property float|null $score
 */
final class ExamQuestion extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'score' => 'float',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
