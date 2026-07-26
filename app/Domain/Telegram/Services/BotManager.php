<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Data\BotIdentity;
use App\Domain\Telegram\Enums\BotHealthStatus;
use App\Domain\Telegram\Exceptions\InvalidBotTokenException;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TokenAlreadyInUseException;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Support\TokenRedactor;
use Illuminate\Support\Str;

/**
 * Everything about a bot's lifecycle: validate, claim, register, sync, rotate,
 * health-check.
 *
 * The jobs orchestrate; this class holds the rules.
 *
 * @see docs/04-telegram-layer.md §1, §10
 */
final class BotManager
{
    /** BotFather tokens look like `8123456789:AAG_...` (35 chars after the colon). */
    private const TOKEN_PATTERN = '/^\d{8,10}:[A-Za-z0-9_-]{35}$/';

    private const WEBHOOK_SECRET_LENGTH = 32;

    /** Three consecutive failed health checks flips a bot to `failing`. */
    private const FAILING_THRESHOLD = 3;

    public function __construct(private readonly TelegramClient $client) {}

    // -------------------------------------------------------------- validation

    /**
     * @throws InvalidBotTokenException
     */
    public function validateToken(string $token): BotIdentity
    {
        $token = trim($token);

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw InvalidBotTokenException::malformed();
        }

        try {
            return $this->client->forToken($token)->getMe();
        } catch (TelegramApiException $e) {
            if ($e->isUnauthorized()) {
                throw InvalidBotTokenException::rejected($e);
            }

            throw $e;
        }
    }

    /**
     * Refuse a bot that another academy already owns.
     *
     * Queried with the global scope lifted rather than via withoutTenantScope():
     * this is a uniqueness probe run by an academy owner who has no
     * platform-wide gate, and it never reads another tenant's data — only
     * whether a row exists.
     *
     * @throws TokenAlreadyInUseException
     */
    public function ensureTokenNotUsedElsewhere(BotIdentity $identity, ?int $exceptBotId = null): void
    {
        $exists = TelegramBot::query()
            ->withoutGlobalScope('academy')
            ->where('bot_user_id', $identity->id)
            ->when($exceptBotId !== null, fn ($query) => $query->whereKeyNot($exceptBotId))
            ->exists();

        if ($exists) {
            throw TokenAlreadyInUseException::forBotUser($identity->id);
        }
    }

    public function generateWebhookSecret(): string
    {
        // Telegram accepts A-Z a-z 0-9 _ - only, 1..256 chars.
        return Str::random(self::WEBHOOK_SECRET_LENGTH);
    }

    // ----------------------------------------------------------------- webhook

    /**
     * Point the bot at our webhook URL.
     *
     * @throws TelegramApiException
     */
    public function registerWebhook(TelegramBot $bot, bool $dropPendingUpdates = true): bool
    {
        if (blank($bot->webhook_secret)) {
            $bot->webhook_secret = $this->generateWebhookSecret();
        }

        $url = $bot->webhookUrl();

        $ok = $this->client->forBot($bot)->setWebhook([
            'url' => $url,
            'secret_token' => (string) $bot->webhook_secret,
            'max_connections' => (int) config('pte.telegram.max_connections', 40),
            'allowed_updates' => array_values((array) config('pte.telegram.allowed_updates', [])),
            'drop_pending_updates' => $dropPendingUpdates,
        ]);

        if ($ok) {
            $bot->forceFill([
                'webhook_url' => $url,
                'webhook_registered_at' => now(),
                'last_error' => null,
                'last_error_at' => null,
            ])->save();
        }

        return $ok;
    }

    public function removeWebhook(TelegramBot $bot, bool $dropPendingUpdates = false): bool
    {
        $ok = $this->client->forBot($bot)->deleteWebhook($dropPendingUpdates);

        if ($ok) {
            $bot->forceFill(['webhook_url' => null, 'webhook_registered_at' => null])->save();
        }

        return $ok;
    }

    /**
     * The webhook host Telegram currently has on file, if it is not ours.
     *
     * Surfaced to the owner as "this bot was previously connected to another
     * service — continue?" (docs §1).
     */
    public function foreignWebhookHost(string $token): ?string
    {
        try {
            $info = $this->client->forToken($token)->getWebhookInfo();
        } catch (TelegramApiException) {
            return null;
        }

        $url = $info['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $ourHost = parse_url((string) config('pte.platform.webhook_base_url'), PHP_URL_HOST);

        return is_string($host) && $host !== $ourHost ? $host : null;
    }

    // ---------------------------------------------------------------- identity

    /**
     * Push the academy's branding into the bot's Telegram-side profile.
     *
     * Best-effort per call: Telegram rejects setMyName more often than it
     * accepts it (rate-limited to a few changes per hour), and a failed rename
     * must not abandon a connection that is otherwise fine.
     *
     * @return array<string, bool>
     */
    public function syncIdentity(TelegramBot $bot, ?string $name = null, ?string $description = null): array
    {
        $client = $this->client->forBot($bot);
        $results = [];

        if (filled($name)) {
            $results['name'] = $this->attempt(fn (): bool => $client->setMyName(mb_substr($name, 0, 64)));
        }

        if (filled($description)) {
            $results['description'] = $this->attempt(
                fn (): bool => $client->setMyDescription(mb_substr($description, 0, 512))
            );
        }

        $results['commands'] = $this->attempt(
            fn (): bool => $client->setMyCommands(UpdateRouter::botCommands())
        );

        return $results;
    }

    // ------------------------------------------------------------------ health

    /**
     * Inspect getWebhookInfo and roll the result into `health_status`.
     *
     * @see docs/04-telegram-layer.md §10
     */
    public function checkHealth(TelegramBot $bot): BotHealthStatus
    {
        try {
            $info = $this->client->forBot($bot)->getWebhookInfo();
        } catch (TelegramApiException $e) {
            return $this->recordFailure($bot, $e->getMessage());
        }

        $expected = $bot->webhookUrl();
        $actual = is_string($info['url'] ?? null) ? $info['url'] : '';

        if ($actual !== $expected) {
            // Drift means someone else called setWebhook on this token; take it back.
            $this->attempt(fn (): bool => $this->registerWebhook($bot, false));
        }

        $pending = (int) ($info['pending_update_count'] ?? 0);
        $lastErrorDate = isset($info['last_error_date']) ? (int) $info['last_error_date'] : null;
        $lastErrorMessage = is_string($info['last_error_message'] ?? null) ? $info['last_error_message'] : null;

        $recentError = $lastErrorDate !== null
            && $lastErrorDate > now()->subHour()->getTimestamp();

        if ($recentError || $pending > 100) {
            return $this->recordFailure(
                $bot,
                $lastErrorMessage ?? "Webhook backlog of {$pending} updates.",
                $pending,
            );
        }

        $bot->forceFill([
            'health_status' => BotHealthStatus::Ok,
            'consecutive_failures' => 0,
            'pending_update_count' => $pending,
            'last_checked_at' => now(),
            'last_error' => null,
        ])->save();

        return BotHealthStatus::Ok;
    }

    private function recordFailure(TelegramBot $bot, string $message, int $pending = 0): BotHealthStatus
    {
        $failures = $bot->consecutive_failures + 1;

        $status = $failures >= self::FAILING_THRESHOLD
            ? BotHealthStatus::Failing
            : BotHealthStatus::Degraded;

        $bot->forceFill([
            'health_status' => $status,
            'consecutive_failures' => $failures,
            'pending_update_count' => $pending,
            'last_error' => TokenRedactor::redact($message),
            'last_error_at' => now(),
            'last_checked_at' => now(),
        ])->save();

        return $status;
    }

    // --------------------------------------------------------------- rotation

    /**
     * Swap in a token regenerated via BotFather's /revoke.
     *
     * The row keeps its id, so every message, identity and broadcast attached
     * to this bot survives the rotation untouched.
     *
     * @throws InvalidBotTokenException|TokenAlreadyInUseException
     */
    public function rotateToken(TelegramBot $bot, string $newToken): BotIdentity
    {
        $identity = $this->validateToken($newToken);

        $this->ensureTokenNotUsedElsewhere($identity, (int) $bot->getKey());

        if ($bot->bot_user_id !== null && $bot->bot_user_id !== $identity->id) {
            throw InvalidBotTokenException::rejected();
        }

        $bot->setToken($newToken);
        $bot->webhook_secret = $this->generateWebhookSecret();
        $bot->username = $identity->username;
        $bot->first_name = $identity->firstName;
        $bot->bot_user_id = $identity->id;
        $bot->save();

        $this->registerWebhook($bot, false);

        return $identity;
    }

    /**
     * @param  callable(): bool  $callback
     */
    private function attempt(callable $callback): bool
    {
        try {
            return $callback();
        } catch (TelegramApiException) {
            return false;
        }
    }
}
