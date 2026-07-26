<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Middleware;

use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for an inbound webhook from the bot's `public_id`, then
 * proves the request really came from Telegram.
 *
 * The webhook URL is public and unauthenticated by nature, so the secret header
 * is the only thing standing between a stranger and a bot's message stream. It
 * is compared with hash_equals — a timing-safe comparison here is cheap, and a
 * leaked secret is a full tenant compromise.
 *
 * The lookup is cached because it runs on *every* update; at a hundred bots
 * this is the single hottest query in the system.
 *
 * @see docs/04-telegram-layer.md §3
 */
final class ResolveTenantFromBot
{
    public const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    private const CACHE_TTL = 300;

    public const BOT_ATTRIBUTE = 'telegram_bot';

    public function handle(Request $request, Closure $next): Response
    {
        $publicId = (string) $request->route('botPublicId');

        $botId = $this->resolveBotId($publicId);

        if ($botId === null) {
            abort(404);
        }

        // The global tenant scope is not usable yet — resolving the tenant is
        // precisely what this middleware is here to do.
        $bot = TelegramBot::query()->withoutGlobalScope('academy')->find($botId);

        if (! $bot instanceof TelegramBot || ! $bot->is_active) {
            abort(404);
        }

        $provided = (string) $request->header(self::SECRET_HEADER, '');
        $expected = (string) $bot->webhook_secret;

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401);
        }

        $academy = Academy::query()->withoutGlobalScopes()->find($bot->academy_id);

        if (! $academy instanceof Academy) {
            abort(404);
        }

        return TenantContext::runFor($academy, function () use ($request, $next, $bot): Response {
            $request->attributes->set(self::BOT_ATTRIBUTE, $bot);

            return $next($request);
        });
    }

    /**
     * Cache only the id, never the model — the model carries the encrypted
     * token and webhook secret, which have no business sitting in a cache
     * driver we may not control.
     */
    private function resolveBotId(string $publicId): ?int
    {
        if ($publicId === '' || ! preg_match('/^[0-9A-Za-z]{26}$/', $publicId)) {
            return null;
        }

        $id = Cache::remember(
            'tg:bot:public:'.$publicId,
            self::CACHE_TTL,
            static fn (): ?int => TelegramBot::query()
                ->withoutGlobalScope('academy')
                ->where('public_id', $publicId)
                ->value('id')
        );

        return is_numeric($id) ? (int) $id : null;
    }

    public static function forgetCache(string $publicId): void
    {
        Cache::forget('tg:bot:public:'.$publicId);
    }
}
