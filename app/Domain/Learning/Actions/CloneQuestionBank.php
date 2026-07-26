<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Learning\Models\QuestionMedia;
use App\Domain\Learning\Models\QuestionOption;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Support\Facades\DB;

/**
 * Copies a bank, questions and all.
 *
 * A real copy, not a share (docs/05 §3): the receiving academy owns the rows
 * and may edit or delete them without affecting the source. This is also how
 * the starter pack reaches a new academy.
 */
final class CloneQuestionBank
{
    private const CHUNK = 200;

    /**
     * @param  Academy|int|null  $target  Destination academy; defaults to the source's own.
     */
    public function handle(
        QuestionBank $source,
        ?string $name = null,
        Academy|int|null $target = null,
        bool $onlyPublished = false,
    ): QuestionBank {
        $targetAcademyId = match (true) {
            $target instanceof Academy => (int) $target->getKey(),
            is_int($target) => $target,
            default => (int) $source->academy_id,
        };

        return DB::transaction(function () use ($source, $name, $targetAcademyId, $onlyPublished): QuestionBank {
            $clone = QuestionBank::query()->create([
                'academy_id' => $targetAcademyId,
                'name' => $name ?? $source->name.' (copy)',
                'module_key' => $source->module_key,
                'description' => $source->description,
                'is_default' => false,
                'question_count' => 0,
            ]);

            $copied = 0;

            $source->questions()
                ->when($onlyPublished, static fn ($query) => $query->published())
                ->with(['options', 'media'])
                ->chunkById(self::CHUNK, function ($questions) use ($clone, $targetAcademyId, &$copied): void {
                    foreach ($questions as $question) {
                        $this->copyQuestion($question, $clone, $targetAcademyId);
                        $copied++;
                    }
                });

            $clone->forceFill(['question_count' => $copied])->save();

            return $clone;
        });
    }

    private function copyQuestion(Question $question, QuestionBank $clone, int $targetAcademyId): void
    {
        $copy = Question::query()->create([
            'academy_id' => $targetAcademyId,
            'bank_id' => $clone->getKey(),
            'module_key' => $question->module_key,
            'type' => $question->type,
            'difficulty' => $question->difficulty,
            // Statistics belong to the source academy's students, not to the copy.
            'difficulty_index' => $question->difficulty->defaultIndex(),
            'title' => $question->title,
            'content' => $question->content,
            'correct_answer' => $question->correct_answer,
            'metadata' => $question->metadata,
            'tags' => $question->tags,
            'status' => $question->status,
            'usage_count' => 0,
            'avg_score' => null,
            'created_by' => $question->created_by,
            'approved_by' => $question->approved_by,
            'approved_at' => $question->approved_at,
            'published_at' => $question->published_at,
        ]);

        foreach ($question->options as $option) {
            QuestionOption::query()->create([
                'academy_id' => $targetAcademyId,
                'question_id' => $copy->getKey(),
                'option_key' => $option->option_key,
                'text' => $option->text,
                'is_correct' => $option->is_correct,
                'sort_order' => $option->sort_order,
                'explanation' => $option->explanation,
            ]);
        }

        foreach ($question->media as $media) {
            QuestionMedia::query()->create([
                'academy_id' => $targetAcademyId,
                'question_id' => $copy->getKey(),
                'kind' => $media->kind,
                's3_path' => $media->s3_path,
                'mime' => $media->mime,
                'size_bytes' => $media->size_bytes,
                'duration_ms' => $media->duration_ms,
                'transcript' => $media->transcript,
                // file_id caches are per bot, so the copy starts cold.
                'telegram_file_id' => null,
                'telegram_bot_id' => null,
            ]);
        }
    }
}
