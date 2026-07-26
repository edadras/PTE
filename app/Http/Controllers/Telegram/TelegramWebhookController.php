<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Domain\Shared\Support\TenantKey;
use App\Domain\Telegram\Enums\UpdateType;
use App\Domain\Telegram\Jobs\ProcessTelegramUpdate;
use App\Domain\Telegram\Middleware\ResolveTenantFromBot;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramUpdate;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The webhook endpoint. Deliberately the thinnest controller in the codebase.
 *
 * Telegram waits up to 60 seconds for a response and re-sends the update if it
 * does not get one. Any real work done here therefore turns one slow request
 * into a retry storm and duplicate messages for the student — so this method
 * only dedupes, persists and enqueues, and answers 204 in well under 200 ms.
 *
 * @see docs/04-telegram-layer.md §3
 */
final class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var TelegramBot|null $bot */
        $bot = $request->attributes->get(ResolveTenantFromBot::BOT_ATTRIBUTE);

        if (! $bot instanceof TelegramBot) {
            abort(404);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $updateId = isset($payload['update_id']) && is_numeric($payload['update_id'])
            ? (int) $payload['update_id']
            : null;

        if ($updateId === null) {
            return response()->noContent();
        }

        if (! $this->claim($bot->academy_id, $updateId)) {
            return response()->noContent();
        }

        try {
            $this->persist($bot, $updateId, $payload);
        } catch (Throwable $e) {
            // A duplicate that slipped past Redis hits the unique index here;
            // either way the answer to Telegram is the same.
            Log::warning('Could not persist a Telegram update.', [
                'academy_id' => $bot->academy_id,
                'update_id' => $updateId,
                'error' => $e->getMessage(),
            ]);

            return response()->noContent();
        }

        ProcessTelegramUpdate::dispatch($bot->academy_id, (int) $bot->getKey(), $updateId)
            ->onQueue((string) config('pte.queues.telegram_in', 'telegram-in'));

        return response()->noContent();
    }

    /**
     * SETNX + TTL: the first caller wins, every retry of the same update_id is
     * answered 204 and dropped.
     */
    private function claim(int $academyId, int $updateId): bool
    {
        $key = TenantKey::updateSeen($academyId, $updateId);
        $ttl = (int) config('pte.telegram.update_dedupe_ttl', 3600);

        return (bool) Redis::set($key, 1, 'EX', $ttl, 'NX');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persist(TelegramBot $bot, int $updateId, array $payload): void
    {
        $type = UpdateType::detect($payload);

        TelegramUpdate::query()->create([
            'academy_id' => $bot->academy_id,
            'telegram_bot_id' => $bot->getKey(),
            'update_id' => $updateId,
            'type' => $type,
            'payload' => $payload,
            'chat_id' => $this->extractInt($payload, 'chat.id'),
            'telegram_user_id' => $this->extractInt($payload, 'from.id'),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractInt(array $payload, string $suffix): ?int
    {
        foreach (['message', 'edited_message', 'callback_query.message', 'callback_query', 'my_chat_member'] as $prefix) {
            $value = data_get($payload, $prefix.'.'.$suffix);

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
