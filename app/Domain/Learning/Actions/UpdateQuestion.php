<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Enums\MediaKind;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Exceptions\InvalidQuestionContentException;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Services\QuestionContentValidator;
use Illuminate\Support\Facades\DB;

final class UpdateQuestion
{
    public function __construct(private readonly QuestionContentValidator $validator) {}

    /**
     * @param  array{
     *     title?: string|null,
     *     content?: array<string, mixed>,
     *     correct_answer?: array<string, mixed>|null,
     *     metadata?: array<string, mixed>|null,
     *     tags?: array<int, string>,
     *     difficulty?: Difficulty|string,
     *     bank_id?: int,
     *     options?: array<int, array<string, mixed>>,
     *     media?: array<int, array<string, mixed>>
     * }  $attributes
     *
     * @throws InvalidQuestionContentException
     */
    public function handle(Question $question, array $attributes): Question
    {
        $content = $attributes['content'] ?? $question->content;
        $options = $attributes['options'] ?? null;

        $errors = $this->validator->errorsFor($question->type, $content);

        if ($options !== null && $this->validator->requiresOptions($question->type)) {
            $errors = [...$errors, ...$this->validator->errorsForOptions($question->type, $options, $content)];
        }

        if ($errors !== []) {
            throw InvalidQuestionContentException::for($question->type, $errors);
        }

        return DB::transaction(function () use ($question, $attributes, $content, $options): Question {
            $question->fill(array_filter([
                'title' => $attributes['title'] ?? null,
                'content' => $content,
                'correct_answer' => $attributes['correct_answer'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
                'tags' => $attributes['tags'] ?? null,
                'bank_id' => isset($attributes['bank_id']) ? (int) $attributes['bank_id'] : null,
                'difficulty' => $this->difficulty($attributes),
            ], static fn (mixed $value): bool => $value !== null));

            // Editing an approved or published question sends it back for review:
            // a student must never receive content nobody has checked.
            if (in_array($question->status, [QuestionStatus::Approved, QuestionStatus::Published], true)
                && $question->isDirty(['content', 'correct_answer'])) {
                $question->status = QuestionStatus::PendingReview;
                $question->approved_at = null;
                $question->approved_by = null;
                $question->published_at = null;
            }

            $question->save();

            if ($options !== null) {
                $this->replaceOptions($question, $options);
            }

            if (isset($attributes['media'])) {
                $this->replaceMedia($question, $attributes['media']);
            }

            return $question->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function difficulty(array $attributes): ?string
    {
        if (! isset($attributes['difficulty'])) {
            return null;
        }

        $difficulty = $attributes['difficulty'] instanceof Difficulty
            ? $attributes['difficulty']
            : Difficulty::tryFrom((string) $attributes['difficulty']);

        return $difficulty?->value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    private function replaceOptions(Question $question, array $options): void
    {
        $question->options()->delete();

        foreach ($options as $index => $option) {
            $question->options()->create([
                'option_key' => (string) ($option['key'] ?? $option['option_key'] ?? chr(65 + $index)),
                'text' => (string) $option['text'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'sort_order' => (int) ($option['sort_order'] ?? $index),
                'explanation' => $option['explanation'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     */
    private function replaceMedia(Question $question, array $media): void
    {
        $question->media()->delete();

        foreach ($media as $item) {
            $question->media()->create([
                'kind' => MediaKind::from((string) $item['kind']),
                's3_path' => (string) $item['s3_path'],
                'mime' => $item['mime'] ?? null,
                'size_bytes' => isset($item['size_bytes']) ? (int) $item['size_bytes'] : null,
                'duration_ms' => isset($item['duration_ms']) ? (int) $item['duration_ms'] : null,
                'transcript' => $item['transcript'] ?? null,
            ]);
        }
    }
}
