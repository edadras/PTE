<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\PlatformAuditLog as PlatformAuditLogModel;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

/**
 * Viewer for `platform_audit_logs` — every Super Admin action across tenants
 * (docs/02 §7). Read-only by construction: the model refuses updates and
 * deletes, so there is nothing to expose here but the list.
 */
final class PlatformAuditLog extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.platform.pages.audit-log';

    public ?string $action = null;

    public ?string $academyId = null;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.audit.title');
    }

    public function getTitle(): string
    {
        return __('panel.audit.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('platform.logs.view') === true;
    }

    /**
     * @return LengthAwarePaginator<int, PlatformAuditLogModel>
     */
    public function getEntries(): LengthAwarePaginator
    {
        return PlatformAuditLogModel::query()
            ->with(['user', 'targetAcademy'])
            ->when(filled($this->action), fn ($query) => $query->where('action', $this->action))
            ->when(filled($this->academyId), fn ($query) => $query->where('target_academy_id', (int) $this->academyId))
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    /**
     * @return array<string, string>
     */
    public function getActionOptions(): array
    {
        return collect(AuditAction::cases())
            ->mapWithKeys(fn (AuditAction $case): array => [$case->value => $case->label()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function getAcademyOptions(): array
    {
        return DB::table('academies')->orderBy('name')->pluck('name', 'id')->all();
    }
}
