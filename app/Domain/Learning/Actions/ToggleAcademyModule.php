<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Exceptions\ModuleNotEnabledException;
use App\Domain\Learning\Support\ModuleRegistry;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Switches a module on or off for one academy.
 *
 * Disabling only flips a flag — questions, banks, sessions and reports stay
 * exactly where they are and reappear intact when the module is re-enabled.
 * Deleting content here would be unrecoverable for the customer, so this action
 * never issues a delete (docs/05 §1).
 */
final class ToggleAcademyModule
{
    private const TABLE = 'academy_modules';

    /**
     * @param  array<string, mixed>|null  $settings  Merged into the module's settings when given.
     * @return bool The resulting enabled state.
     */
    public function handle(Academy $academy, ModuleKey $module, bool $enabled, ?array $settings = null): bool
    {
        if ($enabled) {
            $this->assertEnableable($academy, $module);
        }

        $academyId = (int) $academy->getKey();
        $now = Carbon::now();

        $existing = DB::table(self::TABLE)
            ->where('academy_id', $academyId)
            ->where('module_key', $module->value)
            ->first();

        $payload = [
            'is_enabled' => $enabled,
            'updated_at' => $now,
        ];

        if ($settings !== null) {
            $current = $existing !== null && is_string($existing->settings ?? null)
                ? (array) json_decode($existing->settings, true)
                : [];

            $payload['settings'] = json_encode([...$current, ...$settings]);
        }

        if ($existing === null) {
            DB::table(self::TABLE)->insert([
                ...$payload,
                'academy_id' => $academyId,
                'module_key' => $module->value,
                'settings' => $payload['settings'] ?? json_encode($settings ?? []),
                'enabled_at' => $enabled ? $now : null,
                'created_at' => $now,
            ]);
        } else {
            // enabled_at records the first activation and is never cleared, so
            // "since when has this academy taught Speaking" stays answerable.
            if ($enabled && $existing->enabled_at === null) {
                $payload['enabled_at'] = $now;
            }

            DB::table(self::TABLE)
                ->where('academy_id', $academyId)
                ->where('module_key', $module->value)
                ->update($payload);
        }

        ModuleRegistry::flushCache();

        return $enabled;
    }

    public function enable(Academy $academy, ModuleKey $module): bool
    {
        return $this->handle($academy, $module, true);
    }

    public function disable(Academy $academy, ModuleKey $module): bool
    {
        return $this->handle($academy, $module, false);
    }

    private function assertEnableable(Academy $academy, ModuleKey $module): void
    {
        if (! $module->isAvailable()) {
            throw new ModuleNotEnabledException(
                $module,
                sprintf('Module [%s] has not shipped yet.', $module->value)
            );
        }

        $planSlug = $academy->plan->slug ?? null;

        // A null plan means the plan record has not been loaded or the academy is
        // on a trial; the Commerce layer is the authority, so this only blocks
        // when it can positively say the plan is too low.
        if ($planSlug !== null && ! ModuleRegistry::isAllowedOnPlan($module, (string) $planSlug)) {
            throw new ModuleNotEnabledException(
                $module,
                sprintf('Module [%s] requires the %s plan.', $module->value, ModuleRegistry::requiresPlan($module))
            );
        }
    }
}
