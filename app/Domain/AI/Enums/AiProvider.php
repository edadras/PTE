<?php

declare(strict_types=1);

namespace App\Domain\AI\Enums;

use App\Domain\AI\Providers\AnthropicClient;
use App\Domain\AI\Providers\GeminiClient;
use App\Domain\AI\Providers\GoogleSpeechClient;
use App\Domain\AI\Providers\OpenAiClient;
use App\Domain\AI\Providers\WhisperClient;

/**
 * The vendors we are allowed to talk to.
 *
 * This enum and app/Domain/AI/Providers/ are the *only* two places in the code
 * base permitted to know a vendor name (ADR-005). Everything upstream routes on
 * this enum, which is what lets a model catalogue change on a Tuesday without a
 * deployment.
 */
enum AiProvider: string
{
    case Gemini = 'gemini';
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Whisper = 'whisper';
    case GoogleStt = 'google_stt';

    public function label(): string
    {
        return __('ai.providers.'.$this->value);
    }

    /** Key under config('pte.ai.providers.*'). */
    public function configKey(): string
    {
        return $this->value;
    }

    /**
     * Concrete client class. Kept here rather than in a service map so that the
     * architecture rule "no vendor name outside Providers/ and this enum" holds.
     *
     * @return class-string
     */
    public function clientClass(): string
    {
        return match ($this) {
            self::Gemini => GeminiClient::class,
            self::OpenAi => OpenAiClient::class,
            self::Anthropic => AnthropicClient::class,
            self::Whisper => WhisperClient::class,
            self::GoogleStt => GoogleSpeechClient::class,
        };
    }

    /** Speech-to-text vendors implement TranscriptionClient, not AiProviderClient. */
    public function isTranscription(): bool
    {
        return in_array($this, [self::Whisper, self::GoogleStt], true);
    }

    public function isCompletion(): bool
    {
        return ! $this->isTranscription();
    }

    /**
     * Whether the vendor exposes an opt-out from training on submitted data.
     * Surfaced to academies in the panel (docs/06 §9, privacy).
     */
    public function supportsTrainingOptOut(): bool
    {
        return true;
    }

    /**
     * @return array<int, self>
     */
    public static function completionProviders(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p): bool => $p->isCompletion()));
    }

    /**
     * @return array<int, self>
     */
    public static function transcriptionProviders(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p): bool => $p->isTranscription()));
    }
}
