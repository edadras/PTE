<?php

declare(strict_types=1);

namespace App\Domain\AI\Actions;

use App\Domain\AI\Enums\PromptStatus;
use App\Domain\AI\Events\PromptPublished;
use App\Domain\AI\Models\AiPrompt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Makes a draft the live prompt for its task.
 *
 * The previously published version is archived, never deleted — a scoring
 * dispute months later needs the exact template that produced the number.
 * Only one row per (key, owner) may ever be Published at a time; resolveFor
 * depends on that.
 *
 * @see docs/06-ai-layer.md §3
 */
final class PublishPrompt
{
    public function handle(AiPrompt $prompt): AiPrompt
    {
        if (! $prompt->isPublishable()) {
            throw new LogicException(
                'A prompt must be tested on samples before it can be published.'
            );
        }

        DB::transaction(function () use ($prompt): void {
            // Scope-lifted but pinned to this prompt's own academy (or the
            // platform tier): an academy publishing its prompt must never
            // archive the platform default other academies fall back to.
            AiPrompt::query()
                ->withoutGlobalScope('academy')
                ->where('key', $prompt->key->value)
                ->when(
                    $prompt->academy_id === null,
                    static fn (Builder $query): Builder => $query->whereNull('academy_id'),
                    static fn (Builder $query): Builder => $query->where('academy_id', $prompt->academy_id),
                )
                ->where('status', PromptStatus::Published->value)
                ->whereKeyNot($prompt->getKey())
                ->get()
                ->each(static function (AiPrompt $previous): void {
                    $previous->forceFill(['status' => PromptStatus::Archived])->save();
                });

            $prompt->forceFill([
                'status' => PromptStatus::Published,
                'published_at' => now(),
            ])->save();
        });

        PromptPublished::dispatch($prompt);

        return $prompt;
    }
}
