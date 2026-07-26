<?php

declare(strict_types=1);

namespace App\Domain\AI\Actions;

use App\Domain\AI\Events\RubricPublished;
use App\Domain\AI\Exceptions\InvalidRubricException;
use App\Domain\AI\Models\AiRubric;
use App\Domain\AI\Services\RubricEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Activates a rubric version for its task and retires the previous one.
 *
 * The weights are re-asserted here even though the model checks them on save:
 * a rubric written before a rule change, or edited at the database, must not
 * go live scoring students with weights that no longer total 100.
 *
 * @see docs/06-ai-layer.md §4
 */
final class PublishRubric
{
    public function __construct(private readonly RubricEngine $engine) {}

    /**
     * @throws InvalidRubricException
     */
    public function handle(AiRubric $rubric): AiRubric
    {
        $this->engine->validateCriteria(array_values($rubric->criteria ?? []));

        DB::transaction(function () use ($rubric): void {
            // Same tier only: an academy activating its rubric must not
            // deactivate the platform default other academies fall back to.
            AiRubric::query()
                ->withoutGlobalScope('academy')
                ->where('task_key', $rubric->task_key->value)
                ->when(
                    $rubric->academy_id === null,
                    static fn (Builder $query): Builder => $query->whereNull('academy_id'),
                    static fn (Builder $query): Builder => $query->where('academy_id', $rubric->academy_id),
                )
                ->where('is_active', true)
                ->whereKeyNot($rubric->getKey())
                ->get()
                ->each(static function (AiRubric $previous): void {
                    $previous->forceFill(['is_active' => false])->save();
                });

            $rubric->forceFill(['is_active' => true])->save();
        });

        RubricPublished::dispatch($rubric);

        return $rubric;
    }
}
