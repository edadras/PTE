<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\Telegram\Events\BotWebhookReset;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Services\BotManager;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Delete and re-create a bot's webhook.
 *
 * Runbook #1 ("an academy's bot has stopped answering"): Telegram sometimes
 * holds a webhook that looks registered but delivers nothing, and the only
 * reliable fix is deleteWebhook followed by setWebhook. Dropping the pending
 * queue is the default because a backlog of stale updates replayed at once is
 * usually worse than losing them.
 *
 * @see docs/10-infrastructure-and-ops.md §8
 */
final class TelegramResetWebhookCommand extends Command
{
    use ResolvesAcademy;

    protected $signature = 'telegram:reset-webhook
        {academy : Academy id or slug}
        {--bot= : Bot public id, when the academy has more than one}
        {--keep-pending : Keep updates Telegram has queued}';

    protected $description = 'Remove and re-register an academy bot webhook.';

    public function handle(BotManager $manager): int
    {
        $academy = $this->requireAcademy($this->argument('academy'), withTrashed: false);

        if ($academy === null) {
            return self::FAILURE;
        }

        return TenantContext::runFor($academy, function () use ($academy, $manager): int {
            $publicId = $this->option('bot');

            $bot = is_string($publicId) && $publicId !== ''
                ? TelegramBot::query()->where('public_id', $publicId)->first()
                : TelegramBot::query()->orderByDesc('is_active')->orderBy('id')->first();

            if (! $bot instanceof TelegramBot) {
                $this->components->error(__('reports.console.no_bot', ['academy' => (string) $academy->slug]));

                return self::FAILURE;
            }

            $drop = $this->option('keep-pending') !== true;

            try {
                $manager->removeWebhook($bot, $drop);
                $manager->registerWebhook($bot, $drop);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            // The audit row is written by RecordWebhookReset, so a reset
            // triggered from the panel or the API is recorded identically.
            BotWebhookReset::dispatch($bot, $drop);

            $this->components->info(__('reports.console.webhook_reset', [
                'bot' => (string) ($bot->username ?? $bot->public_id),
            ]));

            return self::SUCCESS;
        });
    }
}
