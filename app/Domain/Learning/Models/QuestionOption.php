<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\QuestionOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $academy_id
 * @property int $question_id
 * @property string $option_key
 * @property string $text
 * @property bool $is_correct
 * @property int $sort_order
 * @property string|null $explanation
 */
final class QuestionOption extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<QuestionOptionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * The student-facing payload must never carry is_correct — the bot renders
     * options straight from the model.
     *
     * @var list<string>
     */
    protected $hidden = ['is_correct', 'explanation'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    protected static function newFactory(): QuestionOptionFactory
    {
        return QuestionOptionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
