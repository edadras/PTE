<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Learning\Models\Question;
use BackedEnum;
use Illuminate\Http\Request;

/**
 * The same question as seen by the person answering it.
 *
 * `correct_answer` and the `is_correct` flag on options are absent by
 * construction rather than by filtering — a serialiser that has to remember to
 * strip the answer key will one day forget.
 *
 * @mixin Question
 */
final class StudentQuestionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'module' => $this->module_key->value,
            'type' => $this->type->value,
            'title' => $this->title,
            'content' => $this->content,
            'options' => $this->whenLoaded('options', fn (): array => $this->options
                ->map(static fn (object $option): array => [
                    'key' => $option->option_key,
                    'text' => $option->text,
                ])
                ->all()),
            'media' => $this->whenLoaded('media', fn (): array => $this->media
                ->map(static fn (object $media): array => [
                    'kind' => $media->kind instanceof BackedEnum ? $media->kind->value : $media->kind,
                    'duration_ms' => $media->duration_ms,
                ])
                ->all()),
        ];
    }
}
