<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Support\Facades\DB;

/**
 * The platform's module catalogue.
 *
 * This lives in code rather than in the `modules` table because it is product
 * definition, not tenant data: the table is a mirror the platform seeds from
 * here, and `academy_modules` is the only per-tenant part.
 *
 * @see docs/05-modules-exams-practice.md §1
 */
final class ModuleRegistry
{
    private const PLAN_RANK = [
        'starter' => 1,
        'professional' => 2,
        'enterprise' => 3,
    ];

    /** @var array<string, bool>|null */
    private static ?array $enabledCache = null;

    private static ?int $enabledCacheAcademyId = null;

    /**
     * Modules that are actually shipped and can be enabled today.
     *
     * @return array<int, ModuleKey>
     */
    public static function available(): array
    {
        return array_values(array_filter(
            ModuleKey::cases(),
            static fn (ModuleKey $module): bool => $module->isAvailable()
        ));
    }

    /**
     * @return array<int, ModuleKey>
     */
    public static function all(): array
    {
        return ModuleKey::cases();
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     icon: string,
     *     phase: int,
     *     requires_plan: string,
     *     is_available: bool,
     *     is_beta: bool,
     *     question_types: array<int, string>
     * }
     */
    public static function definition(ModuleKey $module): array
    {
        return [
            'key' => $module->value,
            'name' => $module->label(),
            'icon' => $module->icon(),
            'phase' => self::phase($module),
            'requires_plan' => self::requiresPlan($module),
            'is_available' => $module->isAvailable(),
            'is_beta' => self::phase($module) >= 3,
            'question_types' => array_map(
                static fn (QuestionType $type): string => $type->value,
                $module->questionTypes()
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return array_map(self::definition(...), self::all());
    }

    public static function phase(ModuleKey $module): int
    {
        return match ($module) {
            ModuleKey::PteSpeaking, ModuleKey::PteListening => 1,
            ModuleKey::PteReading, ModuleKey::PteWriting, ModuleKey::Vocabulary,
            ModuleKey::MockExam, ModuleKey::PlacementTest => 2,
            ModuleKey::Grammar, ModuleKey::Ielts => 3,
            ModuleKey::Toefl, ModuleKey::GeneralEnglish => 4,
        };
    }

    public static function requiresPlan(ModuleKey $module): string
    {
        return match ($module) {
            ModuleKey::PteSpeaking, ModuleKey::PteListening => 'starter',
            ModuleKey::Toefl, ModuleKey::GeneralEnglish => 'enterprise',
            default => 'professional',
        };
    }

    public static function isAllowedOnPlan(ModuleKey $module, ?string $planSlug): bool
    {
        $required = self::PLAN_RANK[self::requiresPlan($module)] ?? 1;
        $held = self::PLAN_RANK[strtolower((string) $planSlug)] ?? 0;

        return $held >= $required;
    }

    /**
     * Whether the academy has switched this module on.
     *
     * Read straight from `academy_modules` — the row is owned by the Tenancy
     * context, so this only ever reads it.
     */
    public static function isEnabledFor(Academy $academy, ModuleKey $module): bool
    {
        return self::enabledMapFor($academy)[$module->value] ?? false;
    }

    /**
     * @return array<int, ModuleKey>
     */
    public static function enabledFor(Academy $academy): array
    {
        $map = self::enabledMapFor($academy);

        return array_values(array_filter(
            ModuleKey::cases(),
            static fn (ModuleKey $module): bool => $map[$module->value] ?? false
        ));
    }

    /**
     * @return array<int, QuestionType>
     */
    public static function enabledQuestionTypes(Academy $academy): array
    {
        $types = [];

        foreach (self::enabledFor($academy) as $module) {
            foreach ($module->questionTypes() as $type) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** Invalidated by ToggleAcademyModule; also call this from tests. */
    public static function flushCache(): void
    {
        self::$enabledCache = null;
        self::$enabledCacheAcademyId = null;
    }

    /**
     * @return array<string, bool>
     */
    private static function enabledMapFor(Academy $academy): array
    {
        $academyId = (int) $academy->getKey();

        if (self::$enabledCacheAcademyId === $academyId && self::$enabledCache !== null) {
            return self::$enabledCache;
        }

        $rows = DB::table('academy_modules')
            ->where('academy_id', $academyId)
            ->pluck('is_enabled', 'module_key');

        self::$enabledCache = $rows
            ->map(static fn (mixed $enabled): bool => (bool) $enabled)
            ->all();
        self::$enabledCacheAcademyId = $academyId;

        return self::$enabledCache;
    }
}
