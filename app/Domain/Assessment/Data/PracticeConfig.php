<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Data;

use App\Domain\Assessment\Enums\PracticeAccess;
use App\Domain\Assessment\Enums\PracticeSelection;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;

/**
 * A typed view over `academy_settings.practice_config`.
 *
 * The stored JSON is per-module with a `defaults` block underneath, because an
 * academy usually wants one policy everywhere and a different question count for
 * Speaking. Reading it through a DTO keeps every consumer from re-implementing
 * that merge — and from silently getting a null where it expected an int.
 *
 * @see docs/05-modules-exams-practice.md §4
 */
final readonly class PracticeConfig
{
    /**
     * @param  array<int, string>  $enabledTypes
     */
    public function __construct(
        public int $questionsPerSession = 5,
        public PracticeSelection $selection = PracticeSelection::Random,
        public int $repeatAfterDays = 30,
        public int $dailyLimit = 0,
        public PracticeAccess $access = PracticeAccess::Everyone,
        public int $freeAttempts = 3,
        public bool $showCorrectAnswer = true,
        public bool $fullFeedback = true,
        public array $enabledTypes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config  the raw practice_config payload
     */
    public static function fromArray(array $config, ModuleKey $module): self
    {
        $defaults = self::section($config, 'defaults');
        $scoped = self::section($config, $module->value);
        $merged = array_merge($defaults, $scoped);

        return new self(
            questionsPerSession: max(1, (int) ($merged['questions_per_session'] ?? $merged['count'] ?? 5)),
            selection: PracticeSelection::tryFrom((string) ($merged['selection_mode'] ?? '')) ?? PracticeSelection::Random,
            repeatAfterDays: max(0, (int) ($merged['repeat_after_days'] ?? 30)),
            dailyLimit: max(0, (int) ($merged['daily_limit'] ?? 0)),
            access: PracticeAccess::tryFrom((string) ($merged['access'] ?? '')) ?? PracticeAccess::Everyone,
            freeAttempts: max(0, (int) ($merged['free_attempts'] ?? 3)),
            showCorrectAnswer: (bool) ($merged['show_correct_answer'] ?? true),
            fullFeedback: ($merged['feedback_mode'] ?? 'full') !== 'score_only',
            enabledTypes: array_values(array_filter(
                (array) ($merged['enabled_types'] ?? []),
                static fn (mixed $type): bool => is_string($type) && QuestionType::tryFrom($type) instanceof QuestionType
            )),
        );
    }

    /** Zero means "no cap" in the panel, which is the friendlier default. */
    public function hasDailyLimit(): bool
    {
        return $this->dailyLimit > 0;
    }

    public function allowsRepeat(): bool
    {
        return $this->repeatAfterDays === 0;
    }

    /** An empty enabled_types list means the academy has not narrowed the module. */
    public function allows(QuestionType $type): bool
    {
        return $this->enabledTypes === [] || in_array($type->value, $this->enabledTypes, true);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $section = $config[$key] ?? [];

        return is_array($section) ? $section : [];
    }
}
