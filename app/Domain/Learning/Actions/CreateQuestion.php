<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Data\QuestionData;
use App\Domain\Learning\Enums\MediaKind;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Exceptions\InvalidQuestionContentException;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Services\QuestionContentValidator;
use Illuminate\Support\Facades\DB;

final class CreateQuestion
{
    public function __construct(private readonly QuestionContentValidator $validator) {}

    /**
     * @throws InvalidQuestionContentException
     */
    public function handle(QuestionData $data, QuestionStatus $status = QuestionStatus::Draft): Question
    {
        $this->assertValid($data);

        return DB::transaction(function () use ($data, $status): Question {
            $question = Question::query()->create([
                ...$data->toAttributes(),
                'status' => $status,
            ]);

            $this->syncOptions($question, $data);
            $this->syncMedia($question, $data);

            $question->bank?->refreshQuestionCount();

            return $question->refresh();
        });
    }

    /**
     * @throws InvalidQuestionContentException
     */
    private function assertValid(QuestionData $data): void
    {
        $errors = $this->validator->errorsFor($data->type, $data->content);

        if ($this->validator->requiresOptions($data->type)) {
            $errors = [...$errors, ...$this->validator->errorsForOptions($data->type, $data->options, $data->content)];
        }

        if ($errors !== []) {
            throw InvalidQuestionContentException::for($data->type, $errors);
        }
    }

    private function syncOptions(Question $question, QuestionData $data): void
    {
        foreach ($data->options as $index => $option) {
            $question->options()->create([
                'option_key' => (string) ($option['key'] ?? $option['option_key'] ?? chr(65 + $index)),
                'text' => (string) $option['text'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'sort_order' => (int) ($option['sort_order'] ?? $index),
                'explanation' => $option['explanation'] ?? null,
            ]);
        }
    }

    private function syncMedia(Question $question, QuestionData $data): void
    {
        foreach ($data->media as $media) {
            $question->media()->create([
                'kind' => MediaKind::from((string) $media['kind']),
                's3_path' => (string) $media['s3_path'],
                'mime' => $media['mime'] ?? null,
                'size_bytes' => isset($media['size_bytes']) ? (int) $media['size_bytes'] : null,
                'duration_ms' => isset($media['duration_ms']) ? (int) $media['duration_ms'] : null,
                'transcript' => $media['transcript'] ?? null,
            ]);
        }
    }
}
