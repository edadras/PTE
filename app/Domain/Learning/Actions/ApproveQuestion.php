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
 * Second pair of eyes on a question. Approval is also the last point at which
 * content is re-validated before it can go live.
 */
final class ApproveQuestion
{
    public function __construct(private readonly QuestionContentValidator $validator) {}

    /**
     * @throws InvalidQuestionContentException
     */
    public function handle(Question $question, ?int $approvedBy = null): Question
    {
        if (! $question->status->canTransitionTo(QuestionStatus::Approved)) {
            throw new DomainException(sprintf(
                'A question in status [%s] cannot be approved.',
                $question->status->value
            ));
        }

        $this->validator->validate($question->type, $question->content ?? []);

        $question->forceFill([
            'status' => QuestionStatus::Approved,
            'approved_by' => $approvedBy,
            'approved_at' => Carbon::now(),
        ])->save();

        return $question;
    }
}
