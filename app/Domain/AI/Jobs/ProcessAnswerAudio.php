<?php

declare(strict_types=1);

namespace App\Domain\AI\Jobs;

use App\Domain\AI\Data\AudioMetrics;
use App\Domain\AI\Exceptions\AudioQualityException;
use App\Domain\AI\Support\AudioAnalyzer;
use App\Domain\Assessment\Enums\ScoringStatus;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Shared\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Step 2 and 3 of the speaking pipeline: normalise the recording, measure it,
 * and throw it out if it is not worth paying for.
 *
 * The quality gate sits here, before ASR, deliberately. Five to ten percent of
 * voice submissions are empty, clipped or silent, and transcribing them costs
 * exactly as much as transcribing a good one (docs/06 §5).
 */
final class ProcessAnswerAudio extends TenantAwareJob
{
    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(int $academyId, public readonly int $answerId)
    {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.media', 'media'));
    }

    public function handle(AudioAnalyzer $analyzer): void
    {
        $answer = Answer::query()->find($this->answerId);

        if ($answer === null || blank($answer->media_path)) {
            return;
        }

        $disk = Storage::disk('tenant');
        $localSource = $this->pullToLocal((string) $answer->media_path);

        if ($localSource === null) {
            Log::warning('Answer audio missing from tenant disk', ['answer_id' => $this->answerId]);

            return;
        }

        $wavPath = $this->temporaryPath('wav');

        try {
            $this->convert($localSource, $wavPath);

            $metrics = $analyzer->analyze($wavPath);
            $analyzer->assertUsable($metrics);

            // The converted WAV goes back to tenant storage so the transcription
            // job — possibly on another worker — can reach it. It is deleted
            // there as soon as the transcript exists.
            $remoteWav = 'ai/audio/'.$this->answerId.'-'.Str::random(8).'.wav';
            $stream = fopen($wavPath, 'rb');

            if ($stream !== false) {
                $disk->put($remoteWav, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $this->storeMetrics($answer, $metrics, $analyzer->isNoisy($metrics));

            TranscribeAnswerAudio::dispatch(
                $this->academyId,
                $this->answerId,
                $remoteWav,
                $metrics->toArray(),
            );
        } catch (AudioQualityException $e) {
            $this->rejectAudio($answer, $e);
        } finally {
            @unlink($localSource);
            @unlink($wavPath);
        }
    }

    /**
     * 16 kHz mono PCM is what every ASR vendor wants and what AudioAnalyzer can
     * read directly — one conversion serves both.
     */
    private function convert(string $source, string $destination): void
    {
        $result = Process::timeout(120)->run([
            (string) config('pte.media.ffmpeg_binary', 'ffmpeg'),
            '-y',
            '-hide_banner',
            '-loglevel', 'error',
            '-i', $source,
            '-ar', (string) config('pte.media.target_sample_rate', 16000),
            '-ac', (string) config('pte.media.target_channels', 1),
            '-c:a', 'pcm_s16le',
            $destination,
        ]);

        if (! $result->successful() || ! is_file($destination) || filesize($destination) === 0) {
            throw AudioQualityException::unreadable($source);
        }
    }

    private function pullToLocal(string $mediaPath): ?string
    {
        $disk = Storage::disk('tenant');

        if (! $disk->exists($mediaPath)) {
            return null;
        }

        $local = $this->temporaryPath(pathinfo($mediaPath, PATHINFO_EXTENSION) ?: 'ogg');
        $stream = $disk->readStream($mediaPath);

        if ($stream === null || $stream === false) {
            return null;
        }

        $handle = fopen($local, 'wb');

        if ($handle === false) {
            return null;
        }

        stream_copy_to_stream($stream, $handle);
        fclose($handle);
        fclose($stream);

        return $local;
    }

    private function storeMetrics(Answer $answer, AudioMetrics $metrics, bool $noisy): void
    {
        $meta = is_array($answer->transcript_meta) ? $answer->transcript_meta : [];
        $meta['audio'] = $metrics->toArray();
        $meta['audio_noisy'] = $noisy;

        $answer->transcript_meta = $meta;
        $answer->save();
    }

    /**
     * Unusable audio is a final state, not a review queue item: there is nothing
     * for a teacher to grade. The student gets a plain-language reason and can
     * simply record again.
     */
    private function rejectAudio(Answer $answer, AudioQualityException $e): void
    {
        $answer->scoring_status = ScoringStatus::Failed;
        $answer->feedback = ['summary' => $e->studentMessage(), 'reason' => $e->reason];
        $answer->save();

        Log::info('Answer audio rejected before spending on AI', [
            'answer_id' => $this->answerId,
            'reason' => $e->reason,
        ]);
    }

    private function temporaryPath(string $extension): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/pte-'.Str::random(16).'.'.$extension;
    }
}
