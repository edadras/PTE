<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Jobs;

use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Telegram\Data\OutgoingMessage;
use App\Domain\Telegram\Enums\BotHealthStatus;
use App\Domain\Telegram\Events\BotConnected;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Middleware\ResolveTenantFromBot;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Services\BotManager;
use App\Domain\Telegram\Services\MessageSender;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Second half of the connection chain: setWebhook, push the academy's branding
 * into the bot profile, greet the owner, go live.
 *
 * Also used on its own by CheckBotHealth when Telegram's webhook URL drifts.
 */
final class RegisterWebhook extends TenantAwareJob
{
    public int $tries = 5;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60, 180];

    public function __construct(
        int $academyId,
        public readonly int $botId,
        public readonly bool $dropPendingUpdates = true,
        public readonly ?int $notifyChatId = null,
    ) {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.telegram_setup', 'telegram-setup'));
    }

    public function handle(BotManager $manager, MessageSender $sender): void
    {
        $bot = TelegramBot::query()->find($this->botId);

        if (! $bot instanceof TelegramBot || blank($bot->token)) {
            return;
        }

        try {
            $manager->registerWebhook($bot, $this->dropPendingUpdates);
        } catch (TelegramApiException $e) {
            $bot->recordError($e->getMessage());

            // 401 means the token was revoked between the two jobs; retrying
            // cannot help.
            if (! $e->isRetryable()) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $academy = TenantContext::get();

        $manager->syncIdentity(
            $bot,
            $academy?->brand?->display_name,
            $academy?->brand?->tagline,
        );

        $bot->forceFill([
            'is_active' => true,
            'health_status' => BotHealthStatus::Ok,
            'consecutive_failures' => 0,
        ])->save();

        // The middleware caches public_id → id; a newly activated bot must not
        // wait for the TTL to start receiving updates.
        ResolveTenantFromBot::forgetCache($bot->public_id);

        if ($this->notifyChatId !== null) {
            $sender->queue(
                OutgoingMessage::text($this->notifyChatId, __('telegram.connected_test_message')),
                $this->academyId
            );
        }

        BotConnected::dispatch($bot);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Telegram webhook registration failed.', [
            'academy_id' => $this->academyId,
            'bot_id' => $this->botId,
            'error' => $e->getMessage(),
        ]);
    }
}
