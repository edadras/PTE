<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Models\Question;
use Illuminate\Support\Carbon;

/**
 * The side exit of the content workflow. The note is what makes a rejection
 * actionable, so it is mandatory.
 */
final class RejectQuestion
{
    public function handle(Question $question, string $note, ?int $rejectedBy = null): Question
    {
        $metadata = $question->metadata ?? [];
        $metadata['rejection'] = [
            'note' => $note,
            'by' => $rejectedBy,
            'at' => Carbon::now()->toIso8601String(),
        ];

        $question->forceFill([
            'status' => QuestionStatus::Rejected,
            'metadata' => $metadata,
            'published_at' => null,
        ])->save();

        return $question;
    }
}
