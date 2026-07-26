<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Shared\Support\TenantKey;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Redis;
use JsonException;

/**
 * Per-chat conversation state, in Redis, keyed by academy.
 *
 * Shape (docs/04 §4):
 *
 *     { flow_id, node_id, step, context{}, history[], updated_at }
 *
 * Redis and not the database on purpose: this is written on nearly every
 * inbound update and is worthless after its TTL — putting it in MySQL would
 * make the busiest write path in the product the slowest one.
 */
final class ConversationState
{
    private const HISTORY_LIMIT = 20;

    /**
     * @return array<string, mixed>
     */
    public function get(int|string $chatId, ?int $academyId = null): array
    {
        $raw = Redis::get($this->key($chatId, $academyId));

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function has(int|string $chatId, ?int $academyId = null): bool
    {
        return $this->get($chatId, $academyId) !== [];
    }

    /**
     * Replace the whole state.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function set(int|string $chatId, array $state, ?int $academyId = null): array
    {
        $state['updated_at'] = now()->toIso8601String();

        try {
            $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return $state;
        }

        Redis::setex($this->key($chatId, $academyId), $this->ttl(), $json);

        return $state;
    }

    /**
     * Shallow-merge into the existing state, deep-merging only `context`.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function patch(int|string $chatId, array $changes, ?int $academyId = null): array
    {
        $state = $this->get($chatId, $academyId);

        if (isset($changes['context']) && is_array($changes['context'])) {
            $existing = is_array($state['context'] ?? null) ? $state['context'] : [];
            $changes['context'] = array_replace($existing, $changes['context']);
        }

        return $this->set($chatId, array_replace($state, $changes), $academyId);
    }

    public function clear(int|string $chatId, ?int $academyId = null): void
    {
        Redis::del($this->key($chatId, $academyId));
    }

    /**
     * Refresh the TTL without rewriting the payload — used on every inbound
     * update so an active conversation never expires mid-flow.
     */
    public function touch(int|string $chatId, ?int $academyId = null): void
    {
        Redis::expire($this->key($chatId, $academyId), $this->ttl());
    }

    // -------------------------------------------------------------- accessors

    public function flowId(int|string $chatId, ?int $academyId = null): ?int
    {
        $value = $this->get($chatId, $academyId)['flow_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function nodeId(int|string $chatId, ?int $academyId = null): ?string
    {
        $value = $this->get($chatId, $academyId)['node_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(int|string $chatId, ?int $academyId = null): array
    {
        $context = $this->get($chatId, $academyId)['context'] ?? [];

        return is_array($context) ? $context : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function putContext(int|string $chatId, string $key, mixed $value, ?int $academyId = null): array
    {
        return $this->patch($chatId, ['context' => [$key => $value]], $academyId);
    }

    // ---------------------------------------------------------------- history

    /**
     * Push a screen onto the back-navigation stack.
     *
     * Capped, because a student who taps around for an hour would otherwise
     * grow an unbounded value in Redis.
     *
     * @return array<int, string>
     */
    public function push(int|string $chatId, string $screen, ?int $academyId = null): array
    {
        $history = $this->history($chatId, $academyId);

        // Do not stack duplicates — tapping the same menu twice is not navigation.
        if (end($history) !== $screen) {
            $history[] = $screen;
        }

        if (count($history) > self::HISTORY_LIMIT) {
            $history = array_slice($history, -self::HISTORY_LIMIT);
        }

        $this->patch($chatId, ['history' => array_values($history)], $academyId);

        return $history;
    }

    public function pop(int|string $chatId, ?int $academyId = null): ?string
    {
        $history = $this->history($chatId, $academyId);

        $screen = array_pop($history);

        $this->patch($chatId, ['history' => array_values($history)], $academyId);

        return is_string($screen) ? $screen : null;
    }

    /**
     * @return array<int, string>
     */
    public function history(int|string $chatId, ?int $academyId = null): array
    {
        $history = $this->get($chatId, $academyId)['history'] ?? [];

        if (! is_array($history)) {
            return [];
        }

        return array_values(array_filter($history, 'is_string'));
    }

    /**
     * True when a blocking flow node has been waiting longer than the flow's
     * timeout. The caller clears the state and tells the student.
     */
    public function isStale(int|string $chatId, int $timeoutSeconds, ?int $academyId = null): bool
    {
        $updatedAt = $this->get($chatId, $academyId)['updated_at'] ?? null;

        if (! is_string($updatedAt)) {
            return false;
        }

        return strtotime($updatedAt) < now()->subSeconds($timeoutSeconds)->getTimestamp();
    }

    private function key(int|string $chatId, ?int $academyId): string
    {
        return TenantKey::conversationState($academyId ?? TenantContext::id(), $chatId);
    }

    private function ttl(): int
    {
        return (int) config('pte.telegram.state_ttl', 86400);
    }
}
