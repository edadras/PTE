<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Exceptions\InvalidQuestionContentException;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Services\QuestionContentValidator;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Makes a question visible to students. Nothing else in the platform may set
 * status to published.
 */
final class PublishQuestion
{
    public function __construct(private readonly QuestionContentValidator $validator) {}

    /**
     * @throws InvalidQuestionContentException
     */
    public function handle(Question $question, ?int $publishedBy = null): Question
    {
        if ($question->status === QuestionStatus::Published) {
            return $question;
        }

        if (! $question->status->canTransitionTo(QuestionStatus::Published)) {
            throw new DomainException(sprintf(
                'A question in status [%s] cannot be published; it must be approved first.',
                $question->status->value
            ));
        }

        $this->validator->validate($question->type, $question->content ?? []);

        $now = Carbon::now();

        $question->forceFill([
            'status' => QuestionStatus::Published,
            'published_at' => $now,
            'approved_at' => $question->approved_at ?? $now,
            'approved_by' => $question->approved_by ?? $publishedBy,
        ])->save();

        return $question;
    }

    /** Pull a live question back without losing its approval history. */
    public function unpublish(Question $question): Question
    {
        if ($question->status !== QuestionStatus::Published) {
            return $question;
        }

        $question->forceFill([
            'status' => QuestionStatus::Approved,
            'published_at' => null,
        ])->save();

        return $question;
    }
}
