<?php

declare(strict_types=1);

namespace App\Domain\AI\Support;

use App\Domain\AI\Data\AudioMetrics;
use App\Domain\AI\Exceptions\AudioQualityException;

/**
 * Reads a 16 kHz mono PCM WAV and measures it.
 *
 * Deliberately implemented in PHP rather than shelled out to ffmpeg a second
 * time: these numbers are needed on every single speaking answer, they are
 * cheap to compute from raw samples, and a process spawn per answer is the kind
 * of cost that only shows up at scale.
 *
 * @see docs/06-ai-layer.md §5
 */
final class AudioAnalyzer
{
    private const FRAME_MS = 20;

    /** Silence must last this long to count as a pause rather than a stop consonant. */
    private const MIN_PAUSE_MS = 250;

    public function analyze(string $wavPath): AudioMetrics
    {
        if (! is_file($wavPath) || ! is_readable($wavPath)) {
            throw AudioQualityException::unreadable($wavPath);
        }

        $handle = fopen($wavPath, 'rb');

        if ($handle === false) {
            throw AudioQualityException::unreadable($wavPath);
        }

        try {
            $format = $this->readHeader($handle, $wavPath);
            $frames = $this->frameEnergies($handle, $format);
        } finally {
            fclose($handle);
        }

        return $this->metricsFromFrames($frames, $format['sample_rate']);
    }

    /**
     * Quality gate. Runs before any paid call, because roughly one in ten voice
     * submissions is unusable and paying to transcribe silence is pure loss
     * (docs/06 §5, step 3).
     */
    public function assertUsable(AudioMetrics $metrics): void
    {
        $min = (float) config('pte.media.min_speech_seconds', 2.0);
        $max = (float) config('pte.media.max_speech_seconds', 180.0);
        $silenceThreshold = (float) config('pte.media.silence_rms_threshold', 0.008);

        if ($metrics->durationSeconds < $min) {
            throw AudioQualityException::tooShort($metrics->durationSeconds, $min);
        }

        if ($metrics->durationSeconds > $max) {
            throw AudioQualityException::tooLong($metrics->durationSeconds, $max);
        }

        if ($metrics->rms < $silenceThreshold || $metrics->speechSeconds < $min) {
            throw AudioQualityException::silent($metrics->rms, $silenceThreshold);
        }
    }

    /** Noisy but audible: worth a warning to the student, not a rejection. */
    public function isNoisy(AudioMetrics $metrics): bool
    {
        return $metrics->peak > 0.98 || ($metrics->rms > 0.0 && $metrics->speechRatio() > 0.99);
    }

    /**
     * @param  resource  $handle
     * @return array{channels: int, sample_rate: int, bits: int, data_offset: int, data_size: int}
     */
    private function readHeader($handle, string $path): array
    {
        $riff = (string) fread($handle, 12);

        if (strlen($riff) < 12 || substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WAVE') {
            throw AudioQualityException::unreadable($path);
        }

        $channels = 1;
        $sampleRate = (int) config('pte.media.target_sample_rate', 16000);
        $bits = 16;
        $dataOffset = 0;
        $dataSize = 0;

        while (! feof($handle)) {
            $chunkHeader = (string) fread($handle, 8);

            if (strlen($chunkHeader) < 8) {
                break;
            }

            $id = substr($chunkHeader, 0, 4);
            /** @var array{size: int} $unpacked */
            $unpacked = unpack('Vsize', substr($chunkHeader, 4, 4)) ?: ['size' => 0];
            $size = (int) ($unpacked['size'] ?? 0);

            if ($id === 'fmt ') {
                $fmt = (string) fread($handle, $size);
                $parsed = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', $fmt);

                if ($parsed === false) {
                    throw AudioQualityException::unreadable($path);
                }

                // Only linear PCM is produced by our own ffmpeg step; anything
                // else means the pipeline was bypassed.
                if ((int) $parsed['format'] !== 1) {
                    throw AudioQualityException::unreadable($path);
                }

                $channels = max(1, (int) $parsed['channels']);
                $sampleRate = max(1, (int) $parsed['rate']);
                $bits = (int) $parsed['bits'];

                continue;
            }

            if ($id === 'data') {
                $dataOffset = (int) ftell($handle);
                $dataSize = $size;

                break;
            }

            fseek($handle, $size + ($size % 2), SEEK_CUR);
        }

        if ($dataSize <= 0 || $bits !== 16) {
            throw AudioQualityException::unreadable($path);
        }

        return [
            'channels' => $channels,
            'sample_rate' => $sampleRate,
            'bits' => $bits,
            'data_offset' => $dataOffset,
            'data_size' => $dataSize,
        ];
    }

    /**
     * @param  resource  $handle
     * @param  array{channels: int, sample_rate: int, bits: int, data_offset: int, data_size: int}  $format
     * @return array<int, float> RMS per frame, normalised to 0..1
     */
    private function frameEnergies($handle, array $format): array
    {
        fseek($handle, $format['data_offset']);

        $samplesPerFrame = (int) round($format['sample_rate'] * self::FRAME_MS / 1000);
        $bytesPerFrame = $samplesPerFrame * 2 * $format['channels'];
        $remaining = $format['data_size'];
        $frames = [];

        while ($remaining > 0) {
            $chunk = (string) fread($handle, min($bytesPerFrame, $remaining));
            $length = strlen($chunk);

            if ($length < 2) {
                break;
            }

            $remaining -= $length;

            /** @var array<int, int>|false $samples */
            $samples = unpack('v'.intdiv($length, 2), $chunk);

            if ($samples === false) {
                break;
            }

            $sum = 0.0;
            $count = 0;

            foreach ($samples as $value) {
                // WAV stores little-endian two's complement; 'v' gives unsigned.
                $signed = $value >= 32768 ? $value - 65536 : $value;
                $normalised = $signed / 32768.0;
                $sum += $normalised * $normalised;
                $count++;
            }

            $frames[] = $count > 0 ? sqrt($sum / $count) : 0.0;
        }

        return $frames;
    }

    /**
     * @param  array<int, float>  $frames
     */
    private function metricsFromFrames(array $frames, int $sampleRate): AudioMetrics
    {
        if ($frames === []) {
            return AudioMetrics::empty();
        }

        $threshold = (float) config('pte.media.silence_rms_threshold', 0.008);
        $frameCount = count($frames);
        $durationSeconds = $frameCount * self::FRAME_MS / 1000;

        $globalRms = sqrt(array_sum(array_map(static fn (float $f): float => $f * $f, $frames)) / $frameCount);
        $peak = max($frames);

        $speechFrames = array_keys(array_filter($frames, static fn (float $f): bool => $f >= $threshold));

        if ($speechFrames === []) {
            return new AudioMetrics(
                durationSeconds: $durationSeconds,
                speechSeconds: 0.0,
                rms: round($globalRms, 6),
                peak: round($peak, 6),
                pauseCount: 0,
                pauseTotalMs: 0,
                sampleRate: $sampleRate,
            );
        }

        $firstSpeech = min($speechFrames);
        $lastSpeech = max($speechFrames);
        $speechSeconds = count($speechFrames) * self::FRAME_MS / 1000;

        // Only silence *between* speech counts as a pause; leading and trailing
        // silence is recording slack, not hesitation.
        $pauses = [];
        $runStart = null;

        for ($i = $firstSpeech; $i <= $lastSpeech; $i++) {
            $isSilent = $frames[$i] < $threshold;

            if ($isSilent && $runStart === null) {
                $runStart = $i;

                continue;
            }

            if (! $isSilent && $runStart !== null) {
                $this->pushPause($pauses, $runStart, $i);
                $runStart = null;
            }
        }

        if ($runStart !== null) {
            $this->pushPause($pauses, $runStart, $lastSpeech);
        }

        return new AudioMetrics(
            durationSeconds: round($durationSeconds, 3),
            speechSeconds: round($speechSeconds, 3),
            rms: round($globalRms, 6),
            peak: round($peak, 6),
            pauseCount: count($pauses),
            pauseTotalMs: (int) array_sum(array_column($pauses, 'duration_ms')),
            pauses: $pauses,
            sampleRate: $sampleRate,
        );
    }

    /**
     * @param  array<int, array{start_ms: int, end_ms: int, duration_ms: int}>  $pauses
     */
    private function pushPause(array &$pauses, int $startFrame, int $endFrame): void
    {
        $durationMs = ($endFrame - $startFrame) * self::FRAME_MS;

        if ($durationMs < self::MIN_PAUSE_MS) {
            return;
        }

        $pauses[] = [
            'start_ms' => $startFrame * self::FRAME_MS,
            'end_ms' => $endFrame * self::FRAME_MS,
            'duration_ms' => $durationMs,
        ];
    }
}
