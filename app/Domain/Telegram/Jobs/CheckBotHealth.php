<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Jobs;

use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Telegram\Enums\BotHealthStatus;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Services\BotManager;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every five minutes for every active bot.
 *
 * A bot whose webhook silently stopped working looks identical to a quiet
 * academy from the outside — this job is the only thing that tells them apart.
 *
 * @see docs/04-telegram-layer.md §10
 */
final class CheckBotHealth extends TenantAwareJob
{
    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(int $academyId, public readonly int $botId)
    {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.maintenance', 'maintenance'));
    }

    public function handle(BotManager $manager): void
    {
        $bot = TelegramBot::query()->find($this->botId);

        if (! $bot instanceof TelegramBot || ! $bot->is_active) {
            return;
        }

        $status = $manager->checkHealth($bot);

        if ($status === BotHealthStatus::Failing) {
            // Owner notification and the Super Admin alert are owned by the Ops
            // context; the state change here is what they observe.
            Log::error('Telegram bot is failing health checks.', [
                'academy_id' => $this->academyId,
                'bot_id' => $this->botId,
                'consecutive_failures' => $bot->consecutive_failures,
            ]);
        }
    }

    /**
     * Fan out one job per active bot. Call from the scheduler.
     *
     * Runs unscoped on purpose: this is a platform-level sweep, and each
     * dispatched job re-enters its own tenant via TenantAwareJob.
     */
    public static function dispatchForAllActiveBots(): int
    {
        $count = 0;

        TelegramBot::query()
            ->withoutGlobalScope('academy')
            ->where('is_active', true)
            ->select(['id', 'academy_id'])
            ->chunkById(200, function ($bots) use (&$count): void {
                foreach ($bots as $bot) {
                    self::dispatch((int) $bot->academy_id, (int) $bot->getKey());
                    $count++;
                }
            });

        return $count;
    }

    /** Convenience for a single academy, e.g. from the panel's "test" button. */
    public static function dispatchForAcademy(Academy $academy): void
    {
        TenantContext::runFor($academy, static function () use ($academy): void {
            $bot = TelegramBot::query()->active()->first();

            if ($bot instanceof TelegramBot) {
                self::dispatch((int) $academy->getKey(), (int) $bot->getKey());
            }
        });
    }
}
