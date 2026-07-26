<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Data\QuestionContext;
use App\Domain\Assessment\Data\SubmitAnswerData;
use App\Domain\Assessment\Enums\ScoringStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Exceptions\InvalidAnswerShape;
use App\Domain\Assessment\Exceptions\SessionNotActive;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Services\ScoringDispatcher;
use App\Domain\Learning\Enums\AnswerKind;
use App\Domain\Learning\Models\Question;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Records one submitted answer and hands it to the scoring pipeline.
 *
 * The shape check is not defensive paranoia: the Telegram layer routes a voice
 * note and a button press through the same entry point, and an ordering answer
 * arriving where a single choice was expected would otherwise be scored zero and
 * blamed on the student.
 */
final class SubmitAnswer
{
    public function __construct(private readonly ScoringDispatcher $scoring = new ScoringDispatcher) {}

    public function handle(SubmitAnswerData $data): Answer
    {
        $session = $this->session($data);

        if (! $session->isOpen()) {
            throw SessionNotActive::is($session->status);
        }

        $context = $this->context($data);

        if (! $data->skipped) {
            $this->assertShape($context->type->answerKind(), $data);
        }

        $answer = DB::transaction(function () use ($data, $context, $session): Answer {
            /** @var Answer $answer */
            $answer = Answer::query()->create([
                'session_type' => $data->sessionType,
                'session_id' => $data->sessionId,
                'question_id' => $data->questionId,
                'student_id' => $data->studentId,
                // question_type is denormalised into the payload so reporting and
                // report cards survive a question being edited or deleted.
                'answer_data' => array_merge($data->answerData, array_filter([
                    'question_type' => $context->type->value,
                    'skipped' => $data->skipped ?: null,
                ], static fn (mixed $value): bool => $value !== null)),
                'media_path' => $data->mediaPath,
                'transcript' => $data->transcript,
                'transcript_meta' => $data->transcriptMeta,
                'max_score' => $data->maxScore ?? $context->maxScore,
                'scoring_status' => ScoringStatus::Pending,
            ]);

            if ($session instanceof PracticeSession) {
                $session->forceFill(['answered' => $session->answers()->count()])->save();
            }

            return $answer;
        });

        $answer->attachContext($context->withMaxScore((float) $answer->max_score));

        $this->scoring->dispatch($answer);

        return $answer;
    }

    private function session(SubmitAnswerData $data): PracticeSession|ExamSession
    {
        return $data->sessionType === SessionType::Exam
            ? ExamSession::query()->findOrFail($data->sessionId)
            : PracticeSession::query()->findOrFail($data->sessionId);
    }

    private function context(SubmitAnswerData $data): QuestionContext
    {
        if ($data->questionType !== null) {
            return QuestionContext::fromArray(array_merge(
                $data->questionPayload ?? [],
                array_filter([
                    'type' => $data->questionType->value,
                    'max_score' => $data->maxScore,
                ], static fn (mixed $value): bool => $value !== null)
            ));
        }

        /** @var Model $question */
        $question = Question::query()->findOrFail($data->questionId);

        return QuestionContext::fromQuestion($question, $data->maxScore);
    }

    private function assertShape(AnswerKind $kind, SubmitAnswerData $data): void
    {
        $payload = $data->answerData;

        $has = static function (array $keys) use ($payload): mixed {
            foreach ($keys as $key) {
                if (isset($payload[$key]) && $payload[$key] !== '' && $payload[$key] !== []) {
                    return $payload[$key];
                }
            }

            return null;
        };

        match ($kind) {
            AnswerKind::Voice => $this->assert(
                $data->mediaPath !== null || $data->transcript !== null || $data->telegramFileId() !== null,
                $kind,
                'expected media_path, transcript or a telegram file id'
            ),
            AnswerKind::Text => $this->assert(
                is_string($has(['text', 'answer', 'response'])),
                $kind,
                'expected a non-empty "text" string'
            ),
            AnswerKind::SingleChoice => $this->assert(
                is_scalar($has(['option', 'answer', 'selected', 'key', 'choice'])),
                $kind,
                'expected a single "option" key'
            ),
            AnswerKind::MultipleChoice => $this->assert(
                is_array($has(['options', 'selected', 'words', 'keys', 'positions'])),
                $kind,
                'expected an array of selected options'
            ),
            AnswerKind::Blanks => $this->assert(
                is_array($has(['blanks', 'answers', 'values'])),
                $kind,
                'expected an array of blank fillings'
            ),
            AnswerKind::Ordering => $this->assert(
                is_array($order = $has(['order', 'sequence', 'keys'])) && count($order) >= 2,
                $kind,
                'expected an ordered list of at least two keys'
            ),
        };
    }

    private function assert(bool $condition, AnswerKind $kind, string $detail): void
    {
        if (! $condition) {
            throw InvalidAnswerShape::expected($kind, $detail);
        }
    }
}
