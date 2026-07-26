<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Http\Request;

/**
 * Health and identity only. The token, the webhook secret and the payment
 * provider token are `$hidden` on the model and must never be added here —
 * docs/12 §3 makes an API response an explicit leak channel.
 *
 * @mixin TelegramBot
 */
final class TelegramBotResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'public_id' => $this->public_id,
            'username' => $this->username,
            'token_last4' => $this->token_last4,
            'is_active' => (bool) $this->is_active,
            'is_connected' => $this->isConnected(),
            'health_status' => $this->health_status->value,
            'consecutive_failures' => (int) $this->consecutive_failures,
            'pending_update_count' => $this->pending_update_count === null
                ? null
                : (int) $this->pending_update_count,
            'webhook_registered_at' => $this->webhook_registered_at?->toIso8601String(),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_error_at' => $this->last_error_at?->toIso8601String(),
        ];
    }
}
