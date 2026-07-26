<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Actions;

use App\Domain\Telegram\Enums\BotHealthStatus;
use App\Domain\Telegram\Exceptions\InvalidBotTokenException;
use App\Domain\Telegram\Exceptions\TokenAlreadyInUseException;
use App\Domain\Telegram\Jobs\ConnectTelegramBot;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Services\BotManager;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The one thing an academy has to do: paste a token.
 *
 * Validation happens synchronously so the owner gets an immediate, honest
 * answer ("that token is wrong", "that bot belongs to someone else"); the slow,
 * retryable half — setWebhook, profile sync — is queued.
 *
 * @see docs/04-telegram-layer.md §1
 */
final class ConnectBot
{
    public function __construct(private readonly BotManager $manager) {}

    /**
     * @throws InvalidBotTokenException|TokenAlreadyInUseException
     */
    public function handle(string $token, ?int $notifyChatId = null, bool $dropPendingUpdates = true): TelegramBot
    {
        $token = trim($token);

        $identity = $this->manager->validateToken($token);

        $existing = TelegramBot::query()->first();

        $this->manager->ensureTokenNotUsedElsewhere(
            $identity,
            $existing instanceof TelegramBot ? (int) $existing->getKey() : null
        );

        $bot = DB::transaction(function () use ($existing, $token, $identity, $notifyChatId): TelegramBot {
            $bot = $existing ?? new TelegramBot(['academy_id' => TenantContext::id()]);

            $bot->setToken($token);
            $bot->bot_user_id = $identity->id;
            $bot->username = $identity->username;
            $bot->first_name = $identity->firstName;
            $bot->webhook_secret = $this->manager->generateWebhookSecret();
            $bot->health_status = BotHealthStatus::Ok;
            $bot->consecutive_failures = 0;
            $bot->last_error = null;
            $bot->last_error_at = null;

            // Stays inactive until the webhook is actually registered, so the
            // panel never shows "connected" for a bot Telegram cannot reach.
            $bot->is_active = false;

            $bot->settings = array_replace(
                is_array($bot->settings) ? $bot->settings : [],
                ['notify_chat_id' => $notifyChatId],
            );

            $bot->save();

            return $bot;
        });

        ConnectTelegramBot::dispatch($bot->academy_id, (int) $bot->getKey(), $dropPendingUpdates);

        return $bot;
    }

    /**
     * Pre-flight check for the panel: is this bot already pointed somewhere
     * else? The owner is warned before we steal the webhook from another
     * service.
     */
    public function foreignWebhookHost(string $token): ?string
    {
        return $this->manager->foreignWebhookHost(trim($token));
    }
}
