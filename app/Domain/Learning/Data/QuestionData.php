<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data;

use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Enums\QuestionType;

/**
 * Everything needed to create or update a question, from any entry point:
 * Filament form, REST API, CSV import or seeder.
 */
final readonly class QuestionData
{
    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>|null  $correctAnswer
     * @param  array<string, mixed>|null  $metadata
     * @param  array<int, string>  $tags
     * @param  array<int, array{key?: string, option_key?: string, text: string, is_correct?: bool, sort_order?: int, explanation?: string|null}>  $options
     * @param  array<int, array{kind: string, s3_path: string, mime?: string|null, size_bytes?: int|null, duration_ms?: int|null, transcript?: string|null}>  $media
     */
    public function __construct(
        public QuestionType $type,
        public int $bankId,
        public array $content,
        public ?string $title = null,
        public Difficulty $difficulty = Difficulty::Medium,
        public ?array $correctAnswer = null,
        public ?array $metadata = null,
        public array $tags = [],
        public array $options = [],
        public array $media = [],
        public ?int $createdBy = null,
        public ?string $importBatchId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $type = $attributes['type'] ?? null;
        $difficulty = $attributes['difficulty'] ?? null;

        return new self(
            type: $type instanceof QuestionType ? $type : QuestionType::from((string) $type),
            bankId: (int) ($attributes['bank_id'] ?? 0),
            content: is_array($attributes['content'] ?? null) ? $attributes['content'] : [],
            title: isset($attributes['title']) && $attributes['title'] !== '' ? (string) $attributes['title'] : null,
            difficulty: $difficulty instanceof Difficulty
                ? $difficulty
                : (Difficulty::tryFrom((string) $difficulty) ?? Difficulty::Medium),
            correctAnswer: is_array($attributes['correct_answer'] ?? null) ? $attributes['correct_answer'] : null,
            metadata: is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : null,
            tags: is_array($attributes['tags'] ?? null) ? array_values(array_map('strval', $attributes['tags'])) : [],
            options: is_array($attributes['options'] ?? null) ? array_values($attributes['options']) : [],
            media: is_array($attributes['media'] ?? null) ? array_values($attributes['media']) : [],
            createdBy: isset($attributes['created_by']) ? (int) $attributes['created_by'] : null,
            importBatchId: isset($attributes['import_batch_id']) ? (string) $attributes['import_batch_id'] : null,
        );
    }

    /**
     * Column values for the questions table. Options and media are written
     * separately by the action.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'bank_id' => $this->bankId,
            'module_key' => $this->type->module(),
            'type' => $this->type,
            'difficulty' => $this->difficulty,
            'difficulty_index' => $this->difficulty->defaultIndex(),
            'title' => $this->title,
            'content' => $this->content,
            'correct_answer' => $this->correctAnswer,
            'metadata' => $this->metadata,
            'tags' => $this->tags,
            'created_by' => $this->createdBy,
            'import_batch_id' => $this->importBatchId,
        ];
    }
}
