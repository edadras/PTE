<?php

declare(strict_types=1);

namespace App\Filament\Academy\Pages;

use App\Domain\Learning\Actions\ToggleAcademyModule;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Support\ModuleRegistry;
use App\Domain\Tenancy\TenantContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Module switches with their plan gating (docs/05 §1).
 *
 * Toggling is ToggleAcademyModule's job — including the plan check, which is
 * why an over-plan module fails here with the domain's own message instead of
 * being hidden and silently ignored.
 */
final class ModulesPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.academy.pages.modules';

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.modules.title');
    }

    public function getTitle(): string
    {
        return __('panel.modules.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('modules.view') === true;
    }

    public function canToggle(): bool
    {
        return auth()->user()?->can('modules.toggle') === true;
    }

    /**
     * @return array<int, array{key: string, name: string, icon: string, phase: int, requires_plan: string, is_available: bool, enabled: bool, allowed: bool}>
     */
    public function getModules(): array
    {
        $academy = TenantContext::require();
        $planSlug = $this->planSlug();

        $enabled = DB::table('academy_modules')
            ->where('academy_id', $academy->getKey())
            ->pluck('is_enabled', 'module_key');

        return array_map(
            static function (ModuleKey $module) use ($enabled, $planSlug): array {
                $definition = ModuleRegistry::definition($module);

                return [
                    ...$definition,
                    'enabled' => (bool) ($enabled[$module->value] ?? false),
                    'allowed' => $planSlug === null || ModuleRegistry::isAllowedOnPlan($module, $planSlug),
                ];
            },
            ModuleKey::cases(),
        );
    }

    public function toggle(string $moduleKey, bool $enabled): void
    {
        Gate::authorize('modules.toggle');

        $module = ModuleKey::tryFrom($moduleKey);

        if ($module === null) {
            return;
        }

        try {
            app(ToggleAcademyModule::class)->handle(TenantContext::require(), $module, $enabled);

            Notification::make()
                ->success()
                ->title(__($enabled ? 'panel.modules.notify.enabled' : 'panel.modules.notify.disabled', [
                    'module' => $module->label(),
                ]))
                ->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('panel.modules.notify.failed'))->body($e->getMessage())->send();
        }
    }

    public function planSlug(): ?string
    {
        $planId = TenantContext::require()->plan_id;

        if ($planId === null) {
            return null;
        }

        $value = DB::table('plans')->where('id', $planId)->value('key');

        return $value === null ? null : (string) $value;
    }
}
