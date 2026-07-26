<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Highlight Incorrect Words — the on-screen transcript differs from the audio
 * in a handful of places and the student marks them.
 */
final readonly class HighlightIncorrectWordsContent extends QuestionContent
{
    /**
     * @param  array<int, array{index: int, shown: string, spoken: string}>  $errors
     */
    public function __construct(
        public string $audioKey,
        public string $displayText,
        public string $spokenText,
        public array $errors,
        public int $playCount = 1,
    ) {}

    public static function fromArray(array $data): static
    {
        $errors = [];

        foreach (self::list($data, 'errors') as $error) {
            if (! is_array($error)) {
                continue;
            }

            $errors[] = [
                'index' => is_numeric($error['index'] ?? null) ? (int) $error['index'] : -1,
                'shown' => is_scalar($error['shown'] ?? null) ? (string) $error['shown'] : '',
                'spoken' => is_scalar($error['spoken'] ?? null) ? (string) $error['spoken'] : '',
            ];
        }

        return new self(
            audioKey: self::string($data, 'audio_key'),
            displayText: self::string($data, 'display_text'),
            spokenText: self::string($data, 'spoken_text'),
            errors: $errors,
            playCount: self::int($data, 'play_count', 1),
        );
    }

    public function toArray(): array
    {
        return [
            'audio_key' => $this->audioKey,
            'display_text' => $this->displayText,
            'spoken_text' => $this->spokenText,
            'errors' => $this->errors,
            'play_count' => $this->playCount,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function displayWords(): array
    {
        return preg_split('/\s+/u', trim($this->displayText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return array<int, int>
     */
    public function errorIndexes(): array
    {
        return array_map(static fn (array $error): int => $error['index'], $this->errors);
    }
}
