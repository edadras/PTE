<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Filament\Support\PlatformAudit;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Viewer for `platform_audit_logs` (docs/02 §7).
 *
 * The table is not created by the migrations that shipped with the domain
 * layer; until it is, PlatformAudit writes to the log channel and this page
 * says so instead of pretending there is nothing to show.
 */
final class PlatformAuditLog extends Page
{
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

    public function isAvailable(): bool
    {
        return PlatformAudit::isAvailable();
    }

    /**
     * @return LengthAwarePaginator<int, object>|null
     */
    public function getEntries(): ?LengthAwarePaginator
    {
        $query = PlatformAudit::query();

        if ($query === null) {
            return null;
        }

        if (filled($this->action)) {
            $query->where('action', 'like', $this->action.'%');
        }

        if (filled($this->academyId)) {
            $query->where('academy_id', (int) $this->academyId);
        }

        return $query->orderByDesc('created_at')->paginate(25);
    }

    /**
     * @return array<int, string>
     */
    public function getActionOptions(): array
    {
        $query = PlatformAudit::query();

        if ($query === null) {
            return [];
        }

        return $query->distinct()->orderBy('action')->pluck('action')->all();
    }

    /**
     * @return array<int, string>
     */
    public function getAcademyOptions(): array
    {
        return DB::table('academies')->orderBy('name')->pluck('name', 'id')->all();
    }
}
