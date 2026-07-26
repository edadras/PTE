<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Services;

use App\Domain\Assessment\Data\ScoreResult;
use App\Domain\Assessment\Enums\ScoredBy;
use App\Domain\Assessment\Enums\ScoringStatus;
use App\Domain\Assessment\Events\AnswerScored;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Scoring\ScorerRegistry;
use App\Domain\Learning\Enums\QuestionType;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The fork in the road that decides the unit economics of the product.
 *
 * Ten of the seventeen task types have an exact answer, so they are graded here
 * and now, inside the request, for nothing. The other seven go to the queue and
 * cost money. Speaking answers take a detour first: their audio has to be
 * fetched from Telegram and transcribed before either path can run — including
 * ASQ, which is deterministic but still needs ASR output to compare against.
 *
 * Cross-context jobs are dispatched by class-string on purpose. Assessment must
 * boot and be testable without the AI and Telegram contexts present.
 *
 * @see docs/05-modules-exams-practice.md §2 · docs/06-ai-layer.md
 */
final class ScoringDispatcher
{
    private const AI_SCORING_JOB = 'App\Domain\AI\Jobs\ScoreAnswerWithAi';

    /** Entry point of the audio pipeline: convert → quality gate → transcribe. */
    private const AUDIO_PIPELINE_JOB = 'App\Domain\AI\Jobs\ProcessAnswerAudio';

    private const TELEGRAM_DOWNLOAD_JOB = 'App\Domain\Telegram\Jobs\DownloadTelegramFile';

    public function __construct(private readonly ScorerRegistry $registry = new ScorerRegistry) {}

    public function dispatch(Answer $answer): void
    {
        $type = $answer->questionType();

        // A skipped question is a deliberate zero, not something to spend a
        // model call on.
        if ($answer->isSkipped()) {
            $this->persist($answer, ScoreResult::zero($answer->effectiveMaxScore(), ['reason' => 'skipped'], [
                'label' => 'assessment.feedback.skipped',
            ]), ScoredBy::System);

            return;
        }

        if ($type->requiresAudioAnswer() && ! $this->hasTranscript($answer)) {
            $this->markPending($answer);
            $this->dispatchMediaPipeline($answer);

            return;
        }

        if ($type->requiresAi()) {
            $this->markPending($answer);
            $this->dispatchAiScoring($answer);

            return;
        }

        $this->scoreDeterministically($answer, $type);
    }

    /**
     * Re-entry point for the media pipeline and for a manual "score again" from
     * the panel.
     */
    public function retry(Answer $answer): void
    {
        $this->dispatch($answer->refresh());
    }

    /**
     * Grade in-process and persist. Returns null when no scorer claims the type,
     * in which case the answer is parked for a human.
     */
    public function scoreDeterministically(Answer $answer, ?QuestionType $type = null): ?ScoreResult
    {
        $type ??= $answer->questionType();
        $scorer = $this->registry->for($type);

        if ($scorer === null) {
            $this->markForReview($answer, 'no_scorer_registered');

            return null;
        }

        try {
            $result = $scorer->score($answer);
        } catch (Throwable $exception) {
            Log::error('Deterministic scoring failed.', [
                'answer_id' => $answer->getKey(),
                'type' => $type->value,
                'exception' => $exception->getMessage(),
            ]);

            $answer->forceFill([
                'scoring_status' => ScoringStatus::Failed,
                'feedback' => ['label' => 'assessment.feedback.scoring_failed'],
            ])->save();

            return null;
        }

        // Confidence zero is a scorer's way of saying the question itself is
        // malformed; silently awarding zero would blame the student for it.
        if ($result->confidence <= 0.0) {
            $this->markForReview($answer, 'unscorable_question', $result);

            return $result;
        }

        $this->persist($answer, $result, ScoredBy::System);

        return $result;
    }

    private function persist(Answer $answer, ScoreResult $result, ScoredBy $by): void
    {
        $answer->applyScore($result, $by)->save();

        AnswerScored::dispatch($answer);
    }

    private function markPending(Answer $answer): void
    {
        $answer->forceFill(['scoring_status' => ScoringStatus::Pending])->save();
    }

    private function markForReview(Answer $answer, string $reason, ?ScoreResult $result = null): void
    {
        $answer->forceFill([
            'scoring_status' => ScoringStatus::ManualReview,
            'max_score' => $result?->maxScore ?? $answer->effectiveMaxScore(),
            'breakdown' => array_merge($result?->breakdown ?? [], ['reason' => $reason]),
            'feedback' => ['label' => 'assessment.feedback.needs_review'],
            'confidence' => 0.0,
        ])->save();

        Log::warning('Answer routed to manual review.', [
            'answer_id' => $answer->getKey(),
            'reason' => $reason,
        ]);
    }

    private function hasTranscript(Answer $answer): bool
    {
        return filled($answer->transcript);
    }

    /**
     * Download → convert → quality gate → transcribe → score.
     *
     * Once the audio is on the tenant disk the AI context owns the rest of the
     * chain, so there is exactly one hand-off here. The download branch is the
     * exception: DownloadTelegramFile returns a path rather than attaching it,
     * so the Telegram layer writes media_path and calls retry() to come back in.
     */
    private function dispatchMediaPipeline(Answer $answer): void
    {
        $mediaQueue = (string) config('pte.queues.media', 'media');

        if (filled($answer->media_path)) {
            $this->dispatchByName(self::AUDIO_PIPELINE_JOB, [
                $answer->academy_id,
                $answer->getKey(),
            ], $mediaQueue);

            return;
        }

        $fileId = $answer->payloadValue('telegram_file_id') ?? $answer->payloadValue('file_id');
        $botId = $answer->payloadValue('telegram_bot_id') ?? $answer->payloadValue('bot_id');

        if (filled($fileId) && filled($botId)) {
            $this->dispatchByName(self::TELEGRAM_DOWNLOAD_JOB, [
                $answer->academy_id,
                (int) $botId,
                (string) $fileId,
                'answers',
            ], $mediaQueue);

            return;
        }

        // Voice expected, nothing recorded: a teacher decides, not a zero.
        $this->markForReview($answer, 'missing_audio');
    }

    private function dispatchAiScoring(Answer $answer): void
    {
        $this->dispatchByName(self::AI_SCORING_JOB, [
            $answer->academy_id,
            $answer->getKey(),
        ], (string) config('pte.queues.ai_scoring', 'ai-scoring'));
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function dispatchByName(string $class, array $arguments, string $queue): bool
    {
        if (! class_exists($class)) {
            // Leaves the answer Pending rather than Failed: the context may
            // simply not be deployed yet, and a redeploy should pick it up.
            Log::warning('Scoring job class is unavailable; answer stays pending.', [
                'job' => $class,
                'arguments' => $arguments,
            ]);

            return false;
        }

        dispatch(new $class(...$arguments))->onQueue($queue);

        return true;
    }
}
