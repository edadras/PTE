<?php

declare(strict_types=1);

namespace App\Domain\AI\Jobs;

use App\Domain\AI\Data\AudioMetrics;
use App\Domain\AI\Data\TranscriptionRequest;
use App\Domain\AI\Enums\AiRequestStatus;
use App\Domain\AI\Exceptions\ProviderUnavailableException;
use App\Domain\AI\Services\CostMeter;
use App\Domain\AI\Services\ProviderRegistry;
use App\Domain\AI\Services\ProviderResolver;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Shared\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Step 4 of the speaking pipeline. Produces the transcript, then completes the
 * acoustic metrics with words-per-minute and hands over to scoring.
 */
final class TranscribeAnswerAudio extends TenantAwareJob
{
    public int $tries = 3;

    public int $timeout = 180;

    /**
     * @param  array<string, mixed>  $audioMetrics
     */
    public function __construct(
        int $academyId,
        public readonly int $answerId,
        public readonly string $wavPath,
        public readonly array $audioMetrics = [],
    ) {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.ai_scoring', 'ai-scoring'));
    }

    public function handle(
        ProviderResolver $resolver,
        ProviderRegistry $registry,
        CostMeter $meter,
    ): void {
        $answer = Answer::query()->find($this->answerId);

        if ($answer === null) {
            return;
        }

        $disk = Storage::disk('tenant');
        $local = $this->pullToLocal();

        if ($local === null) {
            Log::warning('Converted audio missing before transcription', ['answer_id' => $this->answerId]);

            return;
        }

        $metrics = AudioMetrics::fromArray($this->audioMetrics);
        $resolved = $resolver->resolveTranscription($this->academyId);

        $request = new TranscriptionRequest(
            wavPath: $local,
            provider: $resolved->provider,
            modelKey: $resolved->modelKey,
            languageCode: 'en',
            sampleRate: (int) config('pte.media.target_sample_rate', 16000),
            durationSeconds: $metrics->durationSeconds,
            timeoutSeconds: (int) config('pte.ai.request_timeout', 60),
            apiKey: $resolved->apiKey,
            academyId: $this->academyId,
            answerId: $this->answerId,
            vocabularyHint: null,
        );

        try {
            $result = $registry->transcriptionClient($resolved->provider)->transcribe($request);
        } catch (ProviderUnavailableException $e) {
            $meter->recordTranscription(
                request: $request,
                result: null,
                status: AiRequestStatus::Failed,
                model: $resolved->model,
                errorCode: $e->errorCode,
                byok: $resolved->usesOwnKey,
            );

            @unlink($local);

            // Retryable: the student is not told anything yet.
            throw $e;
        }

        $meter->recordTranscription(
            request: $request,
            result: $result,
            status: AiRequestStatus::Success,
            model: $resolved->model,
            byok: $resolved->usesOwnKey,
        );

        @unlink($local);

        // Raw converted audio has served its purpose; keeping it would cost
        // storage and widen the privacy surface for no benefit.
        $disk->delete($this->wavPath);

        $completed = $metrics->withWordCount($result->wordCount());

        $breakdown = is_array($answer->breakdown) ? $answer->breakdown : [];
        $breakdown['audio'] = $completed->toArray();
        $breakdown['asr_confidence'] = $result->confidence;
        $breakdown['asr_model'] = $result->modelKey;

        $answer->forceFill([
            'transcript' => $result->text,
            'breakdown' => $breakdown,
        ])->save();

        ScoreAnswerWithAi::dispatch($this->academyId, $this->answerId);
    }

    private function pullToLocal(): ?string
    {
        $disk = Storage::disk('tenant');

        if (! $disk->exists($this->wavPath)) {
            return null;
        }

        $stream = $disk->readStream($this->wavPath);

        if ($stream === null || $stream === false) {
            return null;
        }

        $local = rtrim(sys_get_temp_dir(), '/').'/pte-'.Str::random(16).'.wav';
        $handle = fopen($local, 'wb');

        if ($handle === false) {
            fclose($stream);

            return null;
        }

        stream_copy_to_stream($stream, $handle);
        fclose($handle);
        fclose($stream);

        return $local;
    }
}
