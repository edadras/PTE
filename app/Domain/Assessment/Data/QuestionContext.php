<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Data;

use App\Domain\Learning\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a scorer needs to know about the question being graded, detached
 * from the Question model itself.
 *
 * The indirection is not decoration: an exam grades against the frozen snapshot
 * taken when the session started, not against the live row, which the academy
 * may have edited in the meantime. Both sources produce the same value object.
 */
final readonly class QuestionContext
{
    /**
     * @param  array<string, mixed>  $content
     * @param  array<array-key, mixed>  $correctAnswer
     */
    public function __construct(
        public QuestionType $type,
        public array $content = [],
        public array $correctAnswer = [],
        public float $maxScore = 90.0,
    ) {}

    public static function fromQuestion(Model $question, ?float $maxScore = null): self
    {
        $type = $question->getAttribute('type');
        $type = $type instanceof QuestionType
            ? $type
            : (QuestionType::tryFrom((string) $type) ?? QuestionType::ReadAloud);

        return new self(
            type: $type,
            content: self::normalise($question->getAttribute('content')),
            correctAnswer: self::normalise($question->getAttribute('correct_answer')),
            maxScore: $maxScore ?? (float) $type->defaultMaxScore(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $type = $payload['type'] ?? null;
        $type = $type instanceof QuestionType
            ? $type
            : (QuestionType::tryFrom((string) $type) ?? QuestionType::ReadAloud);

        return new self(
            type: $type,
            content: self::normalise($payload['content'] ?? []),
            correctAnswer: self::normalise($payload['correct_answer'] ?? []),
            maxScore: isset($payload['max_score'])
                ? (float) $payload['max_score']
                : (float) $type->defaultMaxScore(),
        );
    }

    public function content(string $key, mixed $default = null): mixed
    {
        return data_get($this->content, $key, $default);
    }

    public function correct(string $key, mixed $default = null): mixed
    {
        return data_get($this->correctAnswer, $key, $default);
    }

    /**
     * The correct answer is sometimes a bare list (`["C","A","D"]`) and sometimes
     * a map (`{"order": [...]}`). Callers should not have to care.
     *
     * @param  array<int, string>  $keys  candidate keys to look under
     * @return array<int, mixed>
     */
    public function correctList(array $keys = []): array
    {
        if (array_is_list($this->correctAnswer) && $this->correctAnswer !== []) {
            return $this->correctAnswer;
        }

        foreach ($keys as $key) {
            $value = data_get($this->correctAnswer, $key)
                ?? data_get($this->content, $key);

            if (is_array($value) && $value !== []) {
                return array_values($value);
            }
        }

        return [];
    }

    public function withMaxScore(float $maxScore): self
    {
        return new self($this->type, $this->content, $this->correctAnswer, $maxScore);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'content' => $this->content,
            'correct_answer' => $this->correctAnswer,
            'max_score' => $this->maxScore,
        ];
    }

    /**
     * Question payloads arrive as arrays from Eloquent casts but as raw JSON
     * strings from a snapshot that was stored before the cast existed.
     *
     * @return array<array-key, mixed>
     */
    private static function normalise(mixed $value): array
    {
        return match (true) {
            is_array($value) => $value,
            is_string($value) && $value !== '' => (array) (json_decode($value, true) ?? [$value]),
            default => [],
        };
    }
}
