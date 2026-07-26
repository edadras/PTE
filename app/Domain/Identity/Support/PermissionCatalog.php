<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Enums\SystemRole;

/**
 * The single, authoritative list of every permission in the platform, grouped
 * for the panel and split by scope.
 *
 * Permissions are code, not data: they are declared here and synced into the
 * `permissions` table. An academy may compose them into custom roles, but it
 * can never invent one — and `platform.*` never appears in an academy's picker.
 *
 * @see docs/02-roles-and-rbac.md §3, §4
 */
final class PermissionCatalog
{
    public const SCOPE_ACADEMY = 'academy';

    public const SCOPE_PLATFORM = 'platform';

    /** @var array<string, array<int, string>> */
    private const ACADEMY = [
        'branding' => [
            'academy.brand.view',
            'academy.brand.update',
            'academy.settings.view',
            'academy.settings.update',
            'academy.domain.manage',
        ],
        'telegram' => [
            'telegram.bot.view',
            'telegram.bot.update',
            'telegram.menu.view',
            'telegram.menu.update',
            'telegram.flow.view',
            'telegram.flow.update',
            'telegram.flow.publish',
            'telegram.broadcast.send',
        ],
        'users' => [
            'users.staff.view',
            'users.staff.invite',
            'users.staff.remove',
            'users.roles.view',
            'users.roles.manage',
        ],
        'students' => [
            'students.view',
            'students.create',
            'students.update',
            'students.delete',
            'students.import',
            'students.export',
        ],
        'content' => [
            'courses.view',
            'courses.manage',
            'lessons.view',
            'lessons.manage',
            'questions.view',
            'questions.create',
            'questions.update',
            'questions.delete',
            'questions.import',
            'questions.approve',
            'question_banks.manage',
        ],
        'assessment' => [
            'exams.view',
            'exams.create',
            'exams.update',
            'exams.delete',
            'exams.publish',
            'exams.schedule',
            'practice.configure',
            'answers.view',
            'answers.grade',
            'answers.override_ai_score',
            'scores.view',
            'scores.publish',
        ],
        'ai' => [
            'ai.settings.view',
            'ai.settings.update',
            'ai.prompts.view',
            'ai.prompts.update',
            'ai.prompts.publish',
            'ai.rubrics.manage',
            'ai.usage.view',
        ],
        'reports' => [
            'reports.dashboard.view',
            'reports.detailed.view',
            'reports.export_excel',
            'reports.financial.view',
        ],
        'billing' => [
            'billing.view',
            'billing.manage',
            'billing.payment_methods',
            'subscriptions.students.manage',
        ],
        'support' => [
            'support.tickets.view',
            'support.tickets.reply',
            'support.tickets.close',
            'support.conversations.view',
        ],
        'modules' => [
            'modules.view',
            'modules.toggle',
        ],
        'system' => [
            'audit.view',
            'api_keys.manage',
            'webhooks.manage',
        ],
    ];

    /** @var array<string, array<int, string>> */
    private const PLATFORM = [
        'platform' => [
            'platform.academies.manage',
            'platform.analytics.view',
            'platform.plans.manage',
            'platform.billing.manage',
            'platform.logs.view',
            'platform.infra.manage',
            'platform.ai.manage',
            'platform.impersonate',
            'platform.modules.manage',
            'platform.view_all',
        ],
    ];

    /**
     * Default matrix of docs/02 §4 — the ✅ column of each role.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_DEFAULTS = [
        'manager' => [
            'academy.brand.view',
            'academy.settings.view',
            'telegram.menu.view',
            'telegram.flow.view',
            'telegram.broadcast.send',
            'users.staff.view',
            'users.roles.view',
            'students.view',
            'students.create',
            'students.update',
            'students.import',
            'students.export',
            'courses.view',
            'courses.manage',
            'lessons.view',
            'lessons.manage',
            'questions.view',
            'questions.create',
            'questions.update',
            'questions.delete',
            'questions.import',
            'questions.approve',
            'question_banks.manage',
            'exams.view',
            'exams.create',
            'exams.update',
            'exams.publish',
            'exams.schedule',
            'practice.configure',
            'answers.view',
            'answers.grade',
            'answers.override_ai_score',
            'scores.view',
            'scores.publish',
            'reports.dashboard.view',
            'reports.detailed.view',
            'reports.export_excel',
            'support.tickets.view',
            'support.tickets.reply',
            'support.tickets.close',
            'support.conversations.view',
            'modules.view',
        ],
        'teacher' => [
            'students.view',
            'courses.view',
            'lessons.view',
            'questions.view',
            'exams.view',
            'answers.view',
            'answers.grade',
            'answers.override_ai_score',
            'scores.view',
            'reports.dashboard.view',
        ],
        'support' => [
            'students.view',
            'support.tickets.view',
            'support.tickets.reply',
            'support.tickets.close',
            'support.conversations.view',
        ],
    ];

    /**
     * The ⚙️ column of docs/02 §4: off by default, but an Owner may grant them.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_OPTIONAL = [
        'manager' => [
            'telegram.menu.update',
            'telegram.flow.update',
            'telegram.flow.publish',
            'students.delete',
            'exams.delete',
            'ai.prompts.view',
            'ai.prompts.update',
            'ai.usage.view',
        ],
        'teacher' => [
            'questions.create',
            'questions.update',
            'exams.create',
            'practice.configure',
        ],
        'support' => [
            'telegram.broadcast.send',
            'reports.dashboard.view',
        ],
    ];

    /**
     * Every permission, grouped. Academy groups first, platform last.
     *
     * @return array<string, array<int, string>>
     */
    public static function all(): array
    {
        return self::ACADEMY + self::PLATFORM;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function academyGroups(): array
    {
        return self::ACADEMY;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function platformGroups(): array
    {
        return self::PLATFORM;
    }

    /**
     * @return array<int, string>
     */
    public static function academyScoped(): array
    {
        return array_merge(...array_values(self::ACADEMY));
    }

    /**
     * @return array<int, string>
     */
    public static function platformScoped(): array
    {
        return array_merge(...array_values(self::PLATFORM));
    }

    /**
     * @return array<int, string>
     */
    public static function flat(): array
    {
        return array_merge(self::academyScoped(), self::platformScoped());
    }

    /**
     * Permissions a freshly seeded role starts with.
     *
     * The Owner is special-cased: it always holds every academy-scoped
     * permission, so a new permission added to the catalogue reaches Owners
     * automatically rather than silently locking them out.
     *
     * @return array<int, string>
     */
    public static function defaultsForRole(string $roleKey): array
    {
        $key = strtolower(trim($roleKey));

        if ($key === SystemRole::Owner->value) {
            return self::academyScoped();
        }

        return self::ROLE_DEFAULTS[$key] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public static function optionalForRole(string $roleKey): array
    {
        return self::ROLE_OPTIONAL[strtolower(trim($roleKey))] ?? [];
    }

    public static function groupOf(string $permission): ?string
    {
        foreach (self::all() as $group => $permissions) {
            if (in_array($permission, $permissions, true)) {
                return $group;
            }
        }

        return null;
    }

    public static function scopeOf(string $permission): string
    {
        return str_starts_with($permission, 'platform.')
            ? self::SCOPE_PLATFORM
            : self::SCOPE_ACADEMY;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::flat(), true);
    }

    /**
     * Strip anything an academy is not allowed to hand out.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    public static function onlyAcademyScoped(array $permissions): array
    {
        return array_values(array_intersect($permissions, self::academyScoped()));
    }

    public static function labelFor(string $permission): string
    {
        return __('permissions.items.'.$permission);
    }

    public static function groupLabel(string $group): string
    {
        return __('permissions.groups.'.$group);
    }
}
