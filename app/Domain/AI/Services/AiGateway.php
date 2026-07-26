<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Data\AiCompletionRequest;
use App\Domain\AI\Data\AiCompletionResponse;
use App\Domain\AI\Data\AudioMetrics;
use App\Domain\AI\Data\ScoreBreakdown;
use App\Domain\AI\Enums\AiRequestStatus;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Events\AnswerScoringFailed;
use App\Domain\AI\Exceptions\ProviderUnavailableException;
use App\Domain\AI\Exceptions\QuotaExceededException;
use App\Domain\AI\Models\AiRubric;
use App\Domain\AI\Support\TextComparator;
use App\Domain\Assessment\Data\QuestionContext;
use App\Domain\Assessment\Data\ScoreResult;
use App\Domain\Assessment\Enums\ScoredBy;
use App\Domain\Assessment\Enums\ScoringStatus;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The single door to every language model in the product.
 *
 * Order of operations (docs/06 §1): quota → prompt → provider → validate →
 * rubric → meter. Nothing else in the application may call a provider client
 * directly; that is what keeps cost, tenancy and prompt safety in one place.
 */
final class AiGateway
{
    public function __construct(
        private readonly ProviderResolver $resolver,
        private readonly PromptRenderer $renderer,
        private readonly FallbackChain $chain,
        private readonly RubricEngine $rubrics,
        private readonly PromptCache $cache,
        private readonly CostMeter $meter,
        private readonly TextComparator $comparator,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     *
     * @throws QuotaExceededException|ProviderUnavailableException
     */
    public function run(AiTaskKey $task, array $variables, ?Answer $answer = null): AiCompletionResponse
    {
        $academyId = $this->academyId($answer);

        if (! $this->meter->hasQuota($academyId, CostMeter::METRIC_REQUESTS)) {
            throw QuotaExceededException::forMetric($academyId, CostMeter::METRIC_REQUESTS);
        }

        $resolved = $this->resolver->resolve($task, $academyId);
        $rendered = $this->renderer->render($task, $variables, $academyId);

        if ($rendered->unresolvedVariables !== []) {
            // Not fatal — a template may legitimately reference an optional
            // variable — but a prompt quietly missing its transcript is not.
            Log::info('AI prompt had unresolved variables', [
                'task' => $task->value,
                'academy_id' => $academyId,
                'variables' => $rendered->unresolvedVariables,
            ]);
        }

        $request = new AiCompletionRequest(
            task: $task,
            provider: $resolved->provider,
            modelKey: $resolved->modelKey,
            systemPrompt: $rendered->systemPrompt,
            userPrompt: $rendered->userPrompt,
            outputSchema: $rendered->outputSchema,
            temperature: $resolved->temperature,
            maxOutputTokens: $resolved->maxOutputTokens,
            timeoutSeconds: (int) config('pte.ai.request_timeout', 60),
            apiKey: $resolved->apiKey,
            academyId: $academyId,
            studentId: $answer?->student_id,
            answerId: $answer?->getKey(),
            promptVersion: $rendered->promptVersion,
        );

        if ($resolved->cacheEnabled) {
            $cached = $this->cache->get($request);

            if ($cached instanceof AiCompletionResponse) {
                // Metered at zero cost so the saving is visible in reporting.
                $this->meter->record(
                    request: $request,
                    response: $cached,
                    status: AiRequestStatus::Success,
                    model: $resolved->model,
                    byok: $resolved->usesOwnKey,
                );

                return $cached;
            }
        }

        $response = $this->chain->run($resolved, $request);

        if ($resolved->cacheEnabled) {
            $this->cache->put($request, $response);
        }

        return $response;
    }

    /**
     * Score one answer end to end and persist the outcome.
     *
     * Never throws at the student: any failure ends with the answer parked for
     * a teacher and a reassuring message, per docs/06 §8.
     */
    public function scoreAnswer(Answer $answer): void
    {
        $task = $this->taskFor($answer);

        if (! $task instanceof AiTaskKey) {
            return;
        }

        $academyId = $this->academyId($answer);
        $rubric = $this->rubrics->activeFor($task, $academyId);

        try {
            $response = $this->run($task, $this->variablesFor($answer, $task, $rubric), $answer);
        } catch (QuotaExceededException|ProviderUnavailableException|RuntimeException $e) {
            $this->flagForManualReview($answer, $task, $e->getMessage());

            return;
        }

        $breakdown = $rubric instanceof AiRubric
            ? $this->rubrics->apply($rubric, $response->scores())
            : null;

        $confidence = $response->confidence();
        $threshold = (float) config('pte.ai.low_confidence_threshold', 0.6);

        // Three separate reasons to distrust a score, all ending the same way: a
        // teacher looks at it before the student treats it as the truth.
        $needsReview = $confidence === null
            || $confidence < $threshold
            || $breakdown === null
            || ! $breakdown->isComplete();

        $maxScore = $breakdown !== null
            ? (float) $breakdown->scaleMax
            : $answer->effectiveMaxScore();

        $answer->applyScore(
            new ScoreResult(
                score: $breakdown?->scaledScore ?? 0.0,
                maxScore: $maxScore,
                breakdown: $this->mergeBreakdown($answer, $breakdown, $response),
                feedback: $response->feedback(),
                confidence: $confidence ?? 0.0,
            ),
            ScoredBy::Ai,
        );

        $answer->ai_request_id = $response->aiRequestId;

        if ($needsReview) {
            $answer->scoring_status = ScoringStatus::ManualReview;
        }

        $answer->save();
    }

    /**
     * Variables handed to the prompt. Objective metrics come first: they are
     * free, exact, and their presence is what lets a cheap model do the job
     * (docs/06 §5).
     *
     * @return array<string, mixed>
     */
    public function variablesFor(Answer $answer, AiTaskKey $task, ?AiRubric $rubric = null): array
    {
        $context = $answer->context();
        $targetText = $this->questionText($context);
        $transcript = (string) ($answer->transcript ?? '');

        $variables = [
            'question_text' => $targetText,
            'student_level' => (string) data_get($answer, 'student.level', 'unknown'),
            'feedback_locale' => app()->getLocale(),
            'academy_name' => (string) (TenantContext::check() ? TenantContext::get()?->name : ''),
        ];

        if ($rubric instanceof AiRubric) {
            $variables['rubric_weights'] = json_encode($rubric->weightMap(), JSON_UNESCAPED_UNICODE) ?: '{}';
            $variables['rubric_guidance'] = $this->rubrics->guidanceForPrompt($rubric);
            $variables['scale_max'] = $rubric->scale_max;
        }

        foreach (['min_words', 'max_words'] as $rule) {
            $value = $context->content($rule);

            if ($value !== null) {
                $variables[$rule] = $value;
            }
        }

        if ($task->usesAudioMetrics()) {
            $meta = is_array($answer->transcript_meta) ? $answer->transcript_meta : [];
            $metrics = AudioMetrics::fromArray(
                is_array($meta['audio'] ?? null) ? $meta['audio'] : (array) data_get($answer->breakdown, 'audio', [])
            );

            $variables += [
                'transcript' => $transcript,
                'wpm' => $metrics->wordsPerMinute ?? 0,
                'pause_count' => $metrics->pauseCount,
                'pause_total_ms' => $metrics->pauseTotalMs,
                'speech_ratio' => $metrics->speechRatio(),
                'asr_confidence' => (float) ($meta['asr_confidence'] ?? 0),
            ];
        } else {
            $studentText = $this->studentText($answer);

            $variables += [
                'student_text' => $studentText,
                'word_count' => count(preg_split('/\s+/u', trim($studentText), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            ];
        }

        if ($task->usesTargetTextComparison() && $targetText !== '' && $transcript !== '') {
            $alignment = $this->comparator->align($targetText, $transcript);

            $variables += [
                'word_error_rate' => $alignment['word_error_rate'],
                'missing_words' => implode(', ', array_slice($alignment['missing'], 0, 40)),
                'extra_words' => implode(', ', array_slice($alignment['extra'], 0, 40)),
                'mispronounced_candidates' => json_encode(
                    $this->comparator->mispronouncedCandidates($targetText, $transcript),
                    JSON_UNESCAPED_UNICODE
                ) ?: '[]',
            ];
        }

        return $variables;
    }

    /**
     * Reads the question through the answer's context, so an exam is graded
     * against the snapshot taken when the session started rather than against a
     * question the academy may have edited since.
     */
    public function taskFor(Answer $answer): ?AiTaskKey
    {
        try {
            $type = $answer->questionType();
        } catch (RuntimeException) {
            return null;
        }

        return $type instanceof QuestionType ? AiTaskKey::forQuestionType($type) : null;
    }

    private function flagForManualReview(Answer $answer, AiTaskKey $task, string $reason): void
    {
        Log::warning('AI scoring failed; answer parked for manual review', [
            'answer_id' => $answer->getKey(),
            'task' => $task->value,
            'reason' => $reason,
        ]);

        $answer->scoring_status = ScoringStatus::ManualReview;
        $answer->save();

        AnswerScoringFailed::dispatch(
            $this->academyId($answer),
            (int) $answer->getKey(),
            $task->value,
            $reason,
        );
    }

    /**
     * Keep whatever the audio pipeline already stored; the rubric result is
     * merged in beside it rather than over it.
     *
     * @return array<string, mixed>
     */
    private function mergeBreakdown(Answer $answer, ?ScoreBreakdown $breakdown, AiCompletionResponse $response): array
    {
        $existing = is_array($answer->breakdown) ? $answer->breakdown : [];

        return $existing + array_filter([
            'rubric' => $breakdown?->toArray(),
            'problem_words' => data_get($response->parsedJson, 'problem_words'),
            'overall_raw' => data_get($response->parsedJson, 'overall_raw'),
            'model' => $response->modelKey,
            'cache_hit' => $response->cacheHit,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function academyId(?Answer $answer): int
    {
        $fromAnswer = $answer?->academy_id;

        return is_int($fromAnswer) ? $fromAnswer : TenantContext::id();
    }

    /**
     * The material the answer is judged against. Which content key holds it
     * depends on the task: a Read Aloud has `text`, a Re-tell Lecture has the
     * lecture `transcript`, an Essay has the `prompt`.
     */
    private function questionText(QuestionContext $context): string
    {
        foreach (['text', 'transcript', 'prompt', 'passage'] as $key) {
            $value = $context->content($key);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        // Describe Image has no text at all; the key points are what a grader
        // can legitimately be told about the image.
        $keyPoints = $context->content('key_points');

        return is_array($keyPoints)
            ? 'Key elements of the image: '.implode('; ', array_map(strval(...), $keyPoints))
            : '';
    }

    private function studentText(Answer $answer): string
    {
        foreach (['text', 'answer', 'response'] as $key) {
            $value = $answer->payloadValue($key);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return (string) ($answer->transcript ?? '');
    }
}
