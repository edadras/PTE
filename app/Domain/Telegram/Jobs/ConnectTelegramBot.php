<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Jobs;

use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Telegram\Enums\BotHealthStatus;
use App\Domain\Telegram\Exceptions\InvalidBotTokenException;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TokenAlreadyInUseException;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Services\BotManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * First half of the connection chain: prove the token, claim it for this
 * academy, mint a webhook secret. RegisterWebhook finishes the job.
 *
 * Split in two because everything up to here is idempotent and cheap to retry,
 * while setWebhook has a side effect on Telegram's side.
 *
 * @see docs/04-telegram-layer.md §1
 */
final class ConnectTelegramBot extends TenantAwareJob
{
    public int $tries = 5;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60, 180];

    public function __construct(
        int $academyId,
        public readonly int $botId,
        public readonly bool $dropPendingUpdates = true,
    ) {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.telegram_setup', 'telegram-setup'));
    }

    public function handle(BotManager $manager): void
    {
        $bot = TelegramBot::query()->find($this->botId);

        if (! $bot instanceof TelegramBot || blank($bot->token)) {
            return;
        }

        try {
            $identity = $manager->validateToken((string) $bot->token);
            $manager->ensureTokenNotUsedElsewhere($identity, (int) $bot->getKey());
        } catch (InvalidBotTokenException|TokenAlreadyInUseException $e) {
            $this->markUnconnectable($bot, $e->getMessage());
            $this->fail($e);

            return;
        }

        $bot->forceFill([
            'bot_user_id' => $identity->id,
            'username' => $identity->username,
            'first_name' => $identity->firstName,
            'token_last4' => mb_substr((string) $bot->token, -4),
            'webhook_secret' => $manager->generateWebhookSecret(),
            'health_status' => BotHealthStatus::Ok,
            'consecutive_failures' => 0,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        RegisterWebhook::dispatch($this->academyId, $this->botId, $this->dropPendingUpdates);
    }

    private function markUnconnectable(TelegramBot $bot, string $reason): void
    {
        $bot->forceFill([
            'is_active' => false,
            'health_status' => BotHealthStatus::Failing,
        ])->save();

        $bot->recordError($reason);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Telegram bot connection failed.', [
            'academy_id' => $this->academyId,
            'bot_id' => $this->botId,
            'error' => $e instanceof TelegramApiException ? $e->friendlyKey() : $e->getMessage(),
        ]);
    }
}
