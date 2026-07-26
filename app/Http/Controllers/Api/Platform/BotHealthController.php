<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Telegram\Enums\BotHealthStatus;
use App\Domain\Telegram\Models\TelegramBot;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/platform/v1/bots/health` — every academy's bot at a glance.
 *
 * The response carries public ids and health only. A bot token in a platform
 * API response would defeat all three layers of docs/12 §3 at once.
 */
final class BotHealthController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('platform.infra.manage');

        $bots = TelegramBot::query()
            ->withoutTenantScope()
            ->orderBy('academy_id')
            ->get();

        $counts = [];

        foreach (BotHealthStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $rows = $bots->map(static function (TelegramBot $bot) use (&$counts): array {
            $counts[$bot->health_status->value]++;

            return [
                'academy_id' => (int) $bot->academy_id,
                'public_id' => $bot->public_id,
                'username' => $bot->username,
                'is_active' => (bool) $bot->is_active,
                'health_status' => $bot->health_status->value,
                'consecutive_failures' => (int) $bot->consecutive_failures,
                'pending_update_count' => $bot->pending_update_count === null
                    ? null
                    : (int) $bot->pending_update_count,
                'last_checked_at' => $bot->last_checked_at?->toIso8601String(),
                'last_message_at' => $bot->last_message_at?->toIso8601String(),
            ];
        })->all();

        return $this->payload($request, [
            'summary' => $counts,
            'bots' => $rows,
        ]);
    }
}
