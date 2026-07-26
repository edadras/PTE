<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Gateways;

use App\Domain\Commerce\Contracts\PaymentGateway;
use App\Domain\Commerce\Data\PaymentRequest;
use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared plumbing for the gateway drivers: config access, an HTTP client with
 * sane timeouts, and the default callback URL.
 */
abstract class AbstractGateway implements PaymentGateway
{
    protected const TIMEOUT_SECONDS = 20;

    protected const CONNECT_TIMEOUT_SECONDS = 5;

    abstract public function key(): PaymentGatewayKey;

    protected function config(string $key, mixed $default = null): mixed
    {
        return config($this->key()->configKey().'.'.$key, $default);
    }

    protected function http(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->retry(2, 200, throw: false);
    }

    protected function callbackUrl(PaymentRequest $request): string
    {
        $url = $request->callbackUrl
            ?? $this->config('callback_url')
            ?? rtrim((string) config('pte.platform.webhook_base_url', config('app.url')), '/').'/billing/callback/'.$this->key()->value;

        return (string) $url;
    }

    /** Our own idempotent reference for this attempt. */
    protected function orderId(PaymentRequest $request): string
    {
        return $request->orderId ?? 'ac'.$request->academyId.'-'.Str::lower(Str::ulid()->toBase32());
    }

    protected function fail(string $reason): never
    {
        throw PaymentGatewayException::requestFailed($this->key(), $reason);
    }

    /**
     * Default: the gateway has no webhook signature and must be verified by
     * calling its API instead. Drivers that do sign override this.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyCallbackSignature(string $payload, array $headers): bool
    {
        return false;
    }
}
