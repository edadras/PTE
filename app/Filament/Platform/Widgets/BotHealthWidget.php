<?php

declare(strict_types=1);

namespace App\Filament\Platform\Widgets;

use App\Domain\Telegram\Enums\BotHealthStatus;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Every academy's bot at a glance — the thing that breaks first and is noticed
 * last (docs/04 §10).
 */
final class BotHealthWidget extends Widget
{
    protected static string $view = 'filament.platform.widgets.bot-health';

    protected static ?int $sort = -60;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<int, array{academy: string, username: ?string, status: BotHealthStatus, active: bool, failures: int, pending: int, checked_at: ?string, error: ?string}>
     */
    public function getRows(): array
    {
        return DB::table('telegram_bots')
            ->join('academies', 'academies.id', '=', 'telegram_bots.academy_id')
            ->orderByRaw("CASE telegram_bots.health_status WHEN 'failing' THEN 0 WHEN 'degraded' THEN 1 ELSE 2 END")
            ->get([
                'academies.name as academy',
                'telegram_bots.username as username',
                'telegram_bots.health_status as health_status',
                'telegram_bots.is_active as is_active',
                'telegram_bots.consecutive_failures as consecutive_failures',
                'telegram_bots.pending_update_count as pending_update_count',
                'telegram_bots.last_checked_at as last_checked_at',
                'telegram_bots.last_error as last_error',
            ])
            ->map(static fn (object $row): array => [
                'academy' => (string) $row->academy,
                'username' => $row->username === null ? null : '@'.$row->username,
                'status' => BotHealthStatus::tryFrom((string) $row->health_status) ?? BotHealthStatus::Failing,
                'active' => (bool) $row->is_active,
                'failures' => (int) $row->consecutive_failures,
                'pending' => (int) $row->pending_update_count,
                'checked_at' => $row->last_checked_at === null ? null : (string) $row->last_checked_at,
                'error' => $row->last_error === null ? null : (string) $row->last_error,
            ])
            ->all();
    }
}
