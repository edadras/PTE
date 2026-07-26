<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\Telegram\Jobs\RegisterWebhook;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * @see docs/10-infrastructure-and-ops.md §8
 */
final class TelegramRegisterWebhookCommand extends Command
{
    use ResolvesAcademy;

    protected $signature = 'telegram:register-webhook
        {academy : Academy id or slug}
        {--bot= : Bot public id, when the academy has more than one}
        {--keep-pending : Do not drop updates Telegram has queued}
        {--sync : Register inline instead of queueing}';

    protected $description = 'Point an academy bot at our webhook URL and bring it online.';

    public function handle(): int
    {
        $academy = $this->requireAcademy($this->argument('academy'), withTrashed: false);

        if ($academy === null) {
            return self::FAILURE;
        }

        return TenantContext::runFor($academy, function () use ($academy): int {
            $bot = $this->resolveBot();

            if (! $bot instanceof TelegramBot) {
                $this->components->error(__('reports.console.no_bot', ['academy' => (string) $academy->slug]));

                return self::FAILURE;
            }

            $job = new RegisterWebhook(
                (int) $academy->getKey(),
                (int) $bot->getKey(),
                $this->option('keep-pending') !== true,
            );

            if ($this->option('sync') === true) {
                app()->call([$job, 'handle']);
            } else {
                dispatch($job);
            }

            $this->components->info(__('reports.console.webhook_registered', [
                'bot' => (string) ($bot->username ?? $bot->public_id),
                'url' => $bot->webhookUrl(),
            ]));

            return self::SUCCESS;
        });
    }

    private function resolveBot(): ?TelegramBot
    {
        $publicId = $this->option('bot');

        if (is_string($publicId) && $publicId !== '') {
            return TelegramBot::query()->where('public_id', $publicId)->first();
        }

        return TelegramBot::query()->orderByDesc('is_active')->orderBy('id')->first();
    }
}
