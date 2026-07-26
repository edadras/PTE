<?php

declare(strict_types=1);

namespace App\Domain\Learning\Jobs;

use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Services\DifficultyCalculator;
use App\Domain\Shared\Jobs\TenantAwareJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Refreshes difficulty_index from real answers.
 *
 * Dispatched for a single question after it is answered, or with no question id
 * from the nightly maintenance schedule to sweep a whole academy.
 */
final class RecalculateDifficultyIndex extends TenantAwareJob
{
    public int $tries = 3;

    private const CHUNK = 250;

    public function __construct(int $academyId, public readonly ?int $questionId = null)
    {
        parent::__construct($academyId);
    }

    public function handle(DifficultyCalculator $calculator): void
    {
        if ($this->questionId !== null) {
            $question = Question::query()->find($this->questionId);

            if ($question instanceof Question) {
                $calculator->recalculate($question);
            }

            return;
        }

        Question::query()
            ->where(static function (Builder $query): void {
                $query->where('usage_count', '>', 0)->orWhereNotNull('avg_score');
            })
            ->chunkById(self::CHUNK, static function (Collection $questions) use ($calculator): void {
                foreach ($questions as $question) {
                    $calculator->recalculate($question);
                }
            });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [...parent::tags(), $this->questionId !== null ? 'question:'.$this->questionId : 'sweep'];
    }

    public function uniqueId(): string
    {
        return $this->academyId.':'.($this->questionId ?? 'all');
    }
}
