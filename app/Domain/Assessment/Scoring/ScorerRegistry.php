<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Scoring;

use App\Domain\Assessment\Contracts\Scorer;
use App\Domain\Assessment\Scoring\Scorers\AnswerShortQuestionScorer;
use App\Domain\Assessment\Scoring\Scorers\FillInBlanksScorer;
use App\Domain\Assessment\Scoring\Scorers\HighlightIncorrectWordsScorer;
use App\Domain\Assessment\Scoring\Scorers\MultipleChoiceScorer;
use App\Domain\Assessment\Scoring\Scorers\ReorderParagraphsScorer;
use App\Domain\Assessment\Scoring\Scorers\WriteFromDictationScorer;
use App\Domain\Learning\Enums\QuestionType;

/**
 * Resolves the deterministic scorer for a question type.
 *
 * It self-populates rather than relying on a service provider binding, because
 * this domain must not touch the shared bootstrap files; a caller that wants a
 * different set (a future IELTS module, or a stub in a test) passes its own.
 */
final class ScorerRegistry
{
    /** @var array<int, Scorer> */
    private array $scorers;

    /** @var array<string, Scorer|null> */
    private array $resolved = [];

    /**
     * @param  array<int, Scorer>  $scorers
     */
    public function __construct(array $scorers = [])
    {
        $this->scorers = $scorers === [] ? self::defaults() : array_values($scorers);
    }

    /**
     * @return array<int, Scorer>
     */
    public static function defaults(): array
    {
        return [
            new WriteFromDictationScorer,
            new MultipleChoiceScorer,
            new HighlightIncorrectWordsScorer,
            new FillInBlanksScorer,
            new ReorderParagraphsScorer,
            new AnswerShortQuestionScorer,
        ];
    }

    public function for(QuestionType $type): ?Scorer
    {
        if (array_key_exists($type->value, $this->resolved)) {
            return $this->resolved[$type->value];
        }

        foreach ($this->scorers as $scorer) {
            if ($scorer->supports($type)) {
                return $this->resolved[$type->value] = $scorer;
            }
        }

        return $this->resolved[$type->value] = null;
    }

    public function has(QuestionType $type): bool
    {
        return $this->for($type) instanceof Scorer;
    }

    public function register(Scorer $scorer): self
    {
        // Prepended so a late registration wins over the built-in default.
        array_unshift($this->scorers, $scorer);
        $this->resolved = [];

        return $this;
    }

    /**
     * @return array<int, Scorer>
     */
    public function all(): array
    {
        return $this->scorers;
    }

    /**
     * Question types that claim to be deterministic but have no scorer — a
     * misconfiguration that would otherwise only show up as answers stuck in
     * manual review.
     *
     * @return array<int, QuestionType>
     */
    public function unsupportedDeterministicTypes(): array
    {
        return array_values(array_filter(
            QuestionType::deterministicallyScored(),
            fn (QuestionType $type): bool => ! $this->has($type)
        ));
    }
}
