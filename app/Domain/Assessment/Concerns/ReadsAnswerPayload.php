<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Concerns;

use App\Domain\Assessment\Models\Answer;

/**
 * Answer payloads arrive from three different surfaces (bot callbacks, REST,
 * panel) which do not agree on key names. Rather than force one vocabulary on
 * every client, scorers accept a short list of aliases per shape.
 */
trait ReadsAnswerPayload
{
    /**
     * @param  array<int, string>  $keys
     * @return array<int, mixed>
     */
    protected function submittedList(Answer $answer, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $answer->payloadValue($key);

            if (is_array($value)) {
                return $value;
            }

            if (is_string($value) && $value !== '') {
                return array_map(trim(...), explode(',', $value));
            }
        }

        return [];
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function submittedScalar(Answer $answer, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $answer->payloadValue($key);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }

            // Single-choice clients occasionally send a one-element array.
            if (is_array($value) && count($value) === 1 && is_scalar(reset($value))) {
                return (string) reset($value);
            }
        }

        return null;
    }

    /**
     * Free text, whether typed by the student or produced by ASR.
     *
     * @param  array<int, string>  $keys
     */
    protected function submittedText(Answer $answer, array $keys = ['text', 'answer', 'response']): ?string
    {
        $value = $this->submittedScalar($answer, $keys);

        if ($value !== null && trim($value) !== '') {
            return $value;
        }

        return filled($answer->transcript) ? (string) $answer->transcript : null;
    }
}
