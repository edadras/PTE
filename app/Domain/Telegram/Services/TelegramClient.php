<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Data\BotIdentity;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Support\TokenRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A thin, stateless wrapper over the Bot API.
 *
 * Deliberately dumb: no retries, no rate limiting, no persistence. Those belong
 * to MessageSender and the jobs, which know the business rules. This class only
 * knows how to speak HTTP to Telegram and how to turn `ok: false` into a typed
 * exception.
 *
 * Bind it per bot with `forBot()` / `forToken()` — one instance per token.
 */
final class TelegramClient
{
    private string $token = '';

    public function forBot(TelegramBot $bot): self
    {
        return $this->forToken((string) $bot->token);
    }

    /** Returns a clone so a shared container instance is never mutated. */
    public function forToken(string $token): self
    {
        $clone = new self;
        $clone->token = $token;

        return $clone;
    }

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    // ---------------------------------------------------------------- identity

    public function getMe(): BotIdentity
    {
        return BotIdentity::fromApi($this->call('getMe'));
    }

    /**
     * @param  array{url: string, secret_token?: string, max_connections?: int, allowed_updates?: array<int, string>, drop_pending_updates?: bool}  $params
     */
    public function setWebhook(array $params): bool
    {
        return (bool) $this->call('setWebhook', $params);
    }

    public function deleteWebhook(bool $dropPendingUpdates = false): bool
    {
        return (bool) $this->call('deleteWebhook', ['drop_pending_updates' => $dropPendingUpdates]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebhookInfo(): array
    {
        $result = $this->call('getWebhookInfo');

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<int, array{command: string, description: string}>  $commands
     */
    public function setMyCommands(array $commands, ?string $languageCode = null): bool
    {
        $params = ['commands' => $commands];

        if ($languageCode !== null) {
            $params['language_code'] = $languageCode;
        }

        return (bool) $this->call('setMyCommands', $params);
    }

    public function setMyName(string $name, ?string $languageCode = null): bool
    {
        $params = ['name' => $name];

        if ($languageCode !== null) {
            $params['language_code'] = $languageCode;
        }

        return (bool) $this->call('setMyName', $params);
    }

    public function setMyDescription(string $description, ?string $languageCode = null): bool
    {
        $params = ['description' => $description];

        if ($languageCode !== null) {
            $params['language_code'] = $languageCode;
        }

        return (bool) $this->call('setMyDescription', $params);
    }

    /**
     * @param  array<string, mixed>  $menuButton
     */
    public function setChatMenuButton(array $menuButton, ?int $chatId = null): bool
    {
        $params = ['menu_button' => $menuButton];

        if ($chatId !== null) {
            $params['chat_id'] = $chatId;
        }

        return (bool) $this->call('setChatMenuButton', $params);
    }

    // ---------------------------------------------------------------- sending

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function sendMessage(array $params): array
    {
        return $this->resultArray('sendMessage', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function sendPhoto(array $params): array
    {
        return $this->resultArray('sendPhoto', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function sendAudio(array $params): array
    {
        return $this->resultArray('sendAudio', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function sendVoice(array $params): array
    {
        return $this->resultArray('sendVoice', $params);
    }

    /**
     * The "typing…" / "recording voice…" hint. Best-effort — never let a failed
     * chat action break the message that follows it.
     */
    public function sendChatAction(int $chatId, string $action = 'typing'): bool
    {
        try {
            return (bool) $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
        } catch (TelegramApiException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function editMessageText(array $params): array
    {
        $result = $this->call('editMessageText', $params);

        // Telegram returns `true` when editing an inline message.
        return is_array($result) ? $result : [];
    }

    public function answerCallbackQuery(
        string $callbackQueryId,
        string $text = '',
        bool $showAlert = false,
        ?string $url = null,
    ): bool {
        $params = array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text !== '' ? mb_substr($text, 0, 200) : null,
            'show_alert' => $showAlert ?: null,
            'url' => $url,
        ], static fn (mixed $value): bool => $value !== null);

        try {
            return (bool) $this->call('answerCallbackQuery', $params);
        } catch (TelegramApiException $e) {
            // "query is too old" is routine after a worker backlog; not worth failing a job over.
            if ($e->errorCode === 400) {
                return false;
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------ files

    /**
     * @return array<string, mixed>
     */
    public function getFile(string $fileId): array
    {
        return $this->resultArray('getFile', ['file_id' => $fileId]);
    }

    /**
     * Stream a file from Telegram's CDN to a local path.
     *
     * Enforces the 20 MB Bot API download ceiling from config so a malicious or
     * broken upload cannot fill the worker's disk.
     *
     * @return int bytes written
     */
    public function downloadFile(string $filePath, string $destination): int
    {
        $maxBytes = (int) config('pte.telegram.download_max_bytes', 20 * 1024 * 1024);
        $url = $this->fileBaseUrl().'/'.ltrim($filePath, '/');

        try {
            $response = $this->request()->withOptions(['stream' => true])->get($url);
        } catch (ConnectionException $e) {
            throw TelegramApiException::transport('downloadFile', $e);
        }

        if ($response->failed()) {
            throw new TelegramApiException(
                message: '[downloadFile] Telegram returned HTTP '.$response->status().'.',
                errorCode: $response->status(),
                method: 'downloadFile',
            );
        }

        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new TelegramApiException(
                message: '[downloadFile] Could not create the destination directory.',
                method: 'downloadFile',
            );
        }

        $handle = fopen($destination, 'wb');

        if ($handle === false) {
            throw new TelegramApiException(
                message: '[downloadFile] Could not open the destination file.',
                method: 'downloadFile',
            );
        }

        $written = 0;
        $body = $response->toPsrResponse()->getBody();

        try {
            while (! $body->eof()) {
                $chunk = $body->read(8192);

                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);

                if ($written > $maxBytes) {
                    throw new TelegramApiException(
                        message: '[downloadFile] File exceeds the maximum allowed size.',
                        method: 'downloadFile',
                    );
                }

                fwrite($handle, $chunk);
            }
        } catch (TelegramApiException $e) {
            fclose($handle);
            @unlink($destination);

            throw $e;
        }

        fclose($handle);

        return $written;
    }

    // --------------------------------------------------------------- internals

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function resultArray(string $method, array $params): array
    {
        $result = $this->call($method, $params);

        return is_array($result) ? $result : [];
    }

    /**
     * Perform a Bot API call and unwrap `result`.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws TelegramApiException
     */
    public function call(string $method, array $params = []): mixed
    {
        if ($this->token === '') {
            throw new TelegramApiException(
                message: "[{$method}] No bot token is bound to this client.",
                errorCode: 401,
                method: $method,
            );
        }

        $params = $this->encodeStructured($params);

        try {
            $response = $this->request()->asJson()->post($this->methodUrl($method), $params);
        } catch (ConnectionException $e) {
            throw TelegramApiException::transport($method, $e);
        } catch (Throwable $e) {
            throw TelegramApiException::transport($method, $e);
        }

        return $this->unwrap($method, $response);
    }

    private function unwrap(string $method, Response $response): mixed
    {
        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (! is_array($body)) {
            throw new TelegramApiException(
                message: "[{$method}] Unexpected non-JSON response (HTTP {$response->status()}).",
                errorCode: $response->status(),
                method: $method,
            );
        }

        if (($body['ok'] ?? false) !== true) {
            throw TelegramApiException::fromResponse($method, $body);
        }

        return $body['result'] ?? true;
    }

    /**
     * Telegram expects nested structures (reply_markup, allowed_updates,
     * commands) as JSON strings, not as nested JSON objects.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function encodeStructured(array $params): array
    {
        foreach (['reply_markup', 'entities', 'caption_entities', 'menu_button', 'commands', 'prices'] as $key) {
            if (isset($params[$key]) && is_array($params[$key])) {
                $params[$key] = json_encode($params[$key], JSON_UNESCAPED_UNICODE);
            }
        }

        return array_filter($params, static fn (mixed $value): bool => $value !== null);
    }

    private function request(): PendingRequest
    {
        return Http::timeout((int) config('pte.telegram.timeout', 15))
            ->connectTimeout((int) config('pte.telegram.connect_timeout', 5))
            ->acceptJson();
    }

    private function methodUrl(string $method): string
    {
        return $this->baseUrl().'/bot'.$this->token.'/'.$method;
    }

    private function fileBaseUrl(): string
    {
        return $this->baseUrl().'/file/bot'.$this->token;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('pte.telegram.api_base_url', 'https://api.telegram.org'), '/');
    }

    /** Guards against a `dd($client)` or a serialized job payload exposing the token. */
    public function __debugInfo(): array
    {
        return ['token' => TokenRedactor::REPLACEMENT];
    }
}
