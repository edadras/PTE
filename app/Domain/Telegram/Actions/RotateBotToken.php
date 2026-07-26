<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Actions;

use App\Domain\Telegram\Data\BotIdentity;
use App\Domain\Telegram\Exceptions\InvalidBotTokenException;
use App\Domain\Telegram\Exceptions\TokenAlreadyInUseException;
use App\Domain\Telegram\Jobs\RegisterWebhook;
use App\Domain\Telegram\Middleware\ResolveTenantFromBot;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Services\BotManager;

/**
 * Replaces a leaked token with a freshly revoked one.
 *
 * The row keeps its identity, so history, identities, broadcasts and menus all
 * survive; only the credential changes. Pending updates are deliberately *not*
 * dropped — a rotation is an emergency, and losing the student messages that
 * arrived during it would make a bad day worse.
 *
 * @see docs/04-telegram-layer.md §1
 */
final class RotateBotToken
{
    public function __construct(private readonly BotManager $manager) {}

    /**
     * @throws InvalidBotTokenException|TokenAlreadyInUseException
     */
    public function handle(TelegramBot $bot, string $newToken): BotIdentity
    {
        $identity = $this->manager->rotateToken($bot, trim($newToken));

        // The secret changed, so the cached public_id → id mapping is still
        // valid but any in-flight request holding the old secret must fail.
        ResolveTenantFromBot::forgetCache($bot->public_id);

        RegisterWebhook::dispatch($bot->academy_id, (int) $bot->getKey(), false);

        return $identity;
    }
}
