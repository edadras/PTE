<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use RuntimeException;

/**
 * Thrown by the quality gate *before* any paid call. In practice 5–10% of voice
 * submissions are unusable; spending ASR and scoring money on them is pure loss
 * (docs/06 §5, step 3).
 */
final class AudioQualityException extends RuntimeException
{
    public const REASON_TOO_SHORT = 'too_short';

    public const REASON_TOO_LONG = 'too_long';

    public const REASON_SILENT = 'silent';

    public const REASON_UNREADABLE = 'unreadable';

    private function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function tooShort(float $seconds, float $minimum): self
    {
        return new self(
            sprintf('Audio is %.2fs, below the %.2fs minimum.', $seconds, $minimum),
            self::REASON_TOO_SHORT,
        );
    }

    public static function tooLong(float $seconds, float $maximum): self
    {
        return new self(
            sprintf('Audio is %.2fs, above the %.2fs maximum.', $seconds, $maximum),
            self::REASON_TOO_LONG,
        );
    }

    public static function silent(float $rms, float $threshold): self
    {
        return new self(
            sprintf('Audio RMS %.5f is below the %.5f silence threshold.', $rms, $threshold),
            self::REASON_SILENT,
        );
    }

    public static function unreadable(string $path): self
    {
        return new self("Audio at [{$path}] could not be decoded.", self::REASON_UNREADABLE);
    }

    /** What the student actually sees — never a technical detail. */
    public function studentMessage(): string
    {
        return __('ai.messages.audio.'.$this->reason);
    }
}
