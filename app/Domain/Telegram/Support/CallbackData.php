<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Shared\Support\TenantKey;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use JsonException;

/**
 * The compact `callback_data` codec.
 *
 * Telegram hard-caps callback_data at 64 *bytes*. The compact form is
 *
 *     a:{action}|t:{target}|p:{param}
 *
 * Anything that will not fit is spilled to Redis and replaced with a short
 * pointer (`cb:{token}`), which is why decode() needs a tenant in scope.
 *
 * @see docs/04-telegram-layer.md §4
 */
final class CallbackData
{
    public const MAX_BYTES = 64;

    private const SPILL_PREFIX = 'cb:';

    private const TOKEN_LENGTH = 16;

    /**
     * @param  array<string, scalar|null>  $extra
     */
    private function __construct(
        public readonly string $action,
        public readonly ?string $target = null,
        public readonly ?string $param = null,
        public readonly array $extra = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $extra
     */
    public static function make(
        string $action,
        ?string $target = null,
        string|int|null $param = null,
        array $extra = [],
    ): self {
        return new self(
            action: $action,
            target: $target,
            param: $param === null ? null : (string) $param,
            extra: $extra,
        );
    }

    /**
     * Render to a string that fits in callback_data, spilling to Redis if needed.
     */
    public function encode(): string
    {
        $compact = $this->compact();

        if ($this->extra === [] && strlen($compact) <= self::MAX_BYTES) {
            return $compact;
        }

        return self::spill($this->toArray());
    }

    public function compact(): string
    {
        $segments = ['a:'.$this->action];

        if ($this->target !== null) {
            $segments[] = 't:'.$this->target;
        }

        if ($this->param !== null) {
            $segments[] = 'p:'.$this->param;
        }

        return implode('|', $segments);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'a' => $this->action,
            't' => $this->target,
            'p' => $this->param,
            'x' => $this->extra,
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    /**
     * Parse whatever Telegram handed back. Returns null for garbage rather than
     * throwing — callback_data is user-reachable input (an old keyboard, a
     * replayed message) and must never take a worker down.
     */
    public static function decode(string $raw): ?self
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, self::SPILL_PREFIX)) {
            return self::fromSpill(substr($raw, strlen(self::SPILL_PREFIX)));
        }

        $parsed = [];

        foreach (explode('|', $raw) as $segment) {
            $pair = explode(':', $segment, 2);

            if (count($pair) === 2) {
                $parsed[$pair[0]] = $pair[1];
            }
        }

        if (! isset($parsed['a']) || $parsed['a'] === '') {
            return null;
        }

        return new self(
            action: $parsed['a'],
            target: $parsed['t'] ?? null,
            param: $parsed['p'] ?? null,
        );
    }

    /**
     * Store a payload too large for 64 bytes and return its pointer.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function spill(array $payload): string
    {
        $token = Str::lower(Str::random(self::TOKEN_LENGTH));

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            $json = '{}';
        }

        Redis::setex(
            TenantKey::callbackPayload(TenantContext::id(), $token),
            (int) config('pte.telegram.callback_payload_ttl', 3600),
            $json
        );

        return self::SPILL_PREFIX.$token;
    }

    private static function fromSpill(string $token): ?self
    {
        if (! preg_match('/^[a-z0-9]{1,32}$/', $token)) {
            return null;
        }

        $json = Redis::get(TenantKey::callbackPayload(TenantContext::id(), $token));

        if (! is_string($json)) {
            return null;
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['a']) || ! is_string($payload['a'])) {
            return null;
        }

        /** @var array<string, scalar|null> $extra */
        $extra = is_array($payload['x'] ?? null) ? $payload['x'] : [];

        return new self(
            action: $payload['a'],
            target: is_scalar($payload['t'] ?? null) ? (string) $payload['t'] : null,
            param: is_scalar($payload['p'] ?? null) ? (string) $payload['p'] : null,
            extra: $extra,
        );
    }
}
