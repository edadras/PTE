<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Enums\MediaKind;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionMedia;

/**
 * Reconciles `question_media` rows against the media keys inside a question's
 * content.
 *
 * The question form stores files on the tenant disk and records only the path
 * in `content`; the Telegram sender caches `file_id`s on `question_media`.
 * Without this bridge the cache has nothing to key on and every send is a
 * fresh upload. Runs after every panel create/update.
 */
final class SyncQuestionMedia
{
    public function handle(Question $question): void
    {
        $wanted = $this->wantedPaths($question);

        $existing = QuestionMedia::query()
            ->where('question_id', $question->getKey())
            ->get();

        $seen = [];

        foreach ($existing as $media) {
            $kind = $media->kind->value;
            $path = $wanted[$kind] ?? null;

            // Orphaned kind, or a duplicate row for a kind already handled.
            if ($path === null || isset($seen[$kind])) {
                $media->delete();

                continue;
            }

            $seen[$kind] = true;

            if ($media->s3_path !== $path) {
                // A new file invalidates any cached Telegram upload of the old one.
                $media->forceFill([
                    's3_path' => $path,
                    'telegram_file_id' => null,
                    'telegram_bot_id' => null,
                    'cached_at' => null,
                ])->save();
            }
        }

        foreach ($wanted as $kind => $path) {
            if (! isset($seen[$kind])) {
                QuestionMedia::query()->create([
                    'academy_id' => $question->academy_id,
                    'question_id' => $question->getKey(),
                    'kind' => $kind,
                    's3_path' => $path,
                ]);
            }
        }
    }

    /**
     * @return array<string, string> media kind => tenant-disk path
     */
    private function wantedPaths(Question $question): array
    {
        $content = is_array($question->content) ? $question->content : [];

        $paths = [];

        foreach ([MediaKind::Audio->value => 'audio_key', MediaKind::Image->value => 'image_key'] as $kind => $key) {
            $value = $content[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $paths[$kind] = trim($value);
            }
        }

        return $paths;
    }
}
