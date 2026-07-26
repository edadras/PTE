<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Data\PracticeConfig;
use App\Domain\Assessment\Enums\PracticeAccess;
use App\Domain\Assessment\Enums\SessionStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Exceptions\DailyPracticeLimitReached;
use App\Domain\Assessment\Exceptions\NoQuestionsAvailable;
use App\Domain\Assessment\Exceptions\SubscriptionRequired;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Services\QuestionSelector;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Opens a practice session, subject to the academy's practice policy: which
 * types are on, how many questions, how often a question may repeat, the daily
 * cap, and who is allowed to practise at all.
 *
 * Every one of those gates is a business decision the academy paid for, so a
 * blocked start fails loudly with a domain exception the bot can translate —
 * never a silently empty session.
 *
 * @see docs/05-modules-exams-practice.md §4
 */
final class StartPracticeSession
{
    public function __construct(private readonly QuestionSelector $selector) {}

    public function handle(Student $student, ModuleKey|QuestionType $target, int $count): PracticeSession
    {
        $module = $target instanceof QuestionType ? $target->module() : $target;
        $type = $target instanceof QuestionType ? $target : null;

        $academy = TenantContext::require();
        $config = PracticeConfig::fromArray($this->rawConfig($academy), $module);

        if ($type !== null && ! $config->allows($type)) {
            throw NoQuestionsAvailable::for($type->value);
        }

        $this->assertAccess($student, $config);

        $requested = $count > 0 ? $count : $config->questionsPerSession;
        $requested = $this->applyDailyCap($student, $academy, $config, $requested);

        $questions = $this->selector->select($target, $student, $requested, [
            'mode' => $config->selection->value,
            'exclude' => $excluded = $this->recentlyAnswered($student, $config),
            'exclude_question_ids' => $excluded,
            'cooldown_days' => $config->repeatAfterDays,
            'types' => $type !== null ? [$type->value] : $config->enabledTypes,
        ]);

        $questionIds = $questions
            ->map(static fn (Model $question): int => (int) $question->getKey())
            ->values()
            ->all();

        if ($questionIds === []) {
            throw NoQuestionsAvailable::for($type?->value ?? $module->value);
        }

        /** @var PracticeSession $session */
        $session = PracticeSession::query()->create([
            'student_id' => $student->getKey(),
            'module_key' => $module,
            'question_type' => $type,
            'status' => SessionStatus::InProgress,
            'total_questions' => count($questionIds),
            'answered' => 0,
            'question_ids' => $questionIds,
            'started_at' => now(),
        ]);

        // The drawn models ride along so the caller does not re-query them.
        $session->setRelation('questions', $questions);

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawConfig(Academy $academy): array
    {
        $settings = $academy->settings ?? null;
        $config = $settings instanceof Model ? $settings->getAttribute('practice_config') : null;

        return is_array($config) ? $config : [];
    }

    private function assertAccess(Student $student, PracticeConfig $config): void
    {
        if (! $config->access->requiresSubscription() || $this->hasActiveSubscription($student)) {
            return;
        }

        if ($config->access === PracticeAccess::FreeThenSubscribe) {
            $used = Answer::query()
                ->where('student_id', $student->getKey())
                ->where('session_type', SessionType::Practice->value)
                ->count();

            if ($used < $config->freeAttempts) {
                return;
            }

            throw SubscriptionRequired::forStudent((int) $student->getKey(), $used);
        }

        throw SubscriptionRequired::forStudent((int) $student->getKey());
    }

    /**
     * Commerce owns subscriptions; Assessment only asks. The duck-typed lookup
     * keeps this context compiling and testable without the billing module.
     */
    private function hasActiveSubscription(Student $student): bool
    {
        foreach (['hasActiveSubscription', 'isSubscribed', 'subscriptionIsActive'] as $method) {
            if (method_exists($student, $method)) {
                return (bool) $student->{$method}();
            }
        }

        return (bool) ($student->getAttribute('is_subscribed') ?? false);
    }

    /**
     * Returns how many questions may still be drawn today. A cap that is already
     * exhausted throws; a cap that is partly used shortens the session instead of
     * refusing it.
     */
    private function applyDailyCap(
        Student $student,
        Academy $academy,
        PracticeConfig $config,
        int $requested,
    ): int {
        if (! $config->hasDailyLimit()) {
            return $requested;
        }

        // "Today" is the academy's calendar day, not the server's — a Tehran
        // student's allowance must not reset at 03:30 local time.
        $timezone = (string) ($academy->getAttribute('timezone') ?: 'UTC');
        $start = Carbon::now($timezone)->startOfDay()->utc();
        $end = Carbon::now($timezone)->endOfDay()->utc();

        $used = Answer::query()
            ->where('student_id', $student->getKey())
            ->where('session_type', SessionType::Practice->value)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $remaining = $config->dailyLimit - $used;

        if ($remaining <= 0) {
            throw DailyPracticeLimitReached::forStudent(
                (int) $student->getKey(),
                $config->dailyLimit,
                $used
            );
        }

        return min($requested, $remaining);
    }

    /**
     * Questions this student answered inside the repeat cooldown window.
     *
     * @return array<int, int>
     */
    private function recentlyAnswered(Student $student, PracticeConfig $config): array
    {
        if ($config->allowsRepeat()) {
            return [];
        }

        return Answer::query()
            ->where('student_id', $student->getKey())
            ->where('created_at', '>=', now()->subDays($config->repeatAfterDays))
            ->distinct()
            ->pluck('question_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
