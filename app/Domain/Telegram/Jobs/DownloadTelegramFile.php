<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Jobs;

use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Services\TelegramClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls a student's uploaded media off Telegram's CDN onto the tenant disk.
 *
 * getFile links expire after about an hour, so this runs promptly and on its
 * own queue — a slow ASR pipeline must not delay the download that feeds it.
 *
 * @see docs/04-telegram-layer.md §9
 */
final class DownloadTelegramFile extends TenantAwareJob
{
    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        int $academyId,
        public readonly int $botId,
        public readonly string $fileId,
        public readonly string $directory = 'answers',
        public readonly ?string $extension = null,
    ) {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.media', 'media'));
    }

    /**
     * @return string|null the path on the tenant disk
     */
    public function handle(TelegramClient $client): ?string
    {
        $bot = TelegramBot::query()->find($this->botId);

        if (! $bot instanceof TelegramBot) {
            return null;
        }

        try {
            $file = $client->forBot($bot)->getFile($this->fileId);
        } catch (TelegramApiException $e) {
            Log::warning('Could not resolve a Telegram file.', [
                'academy_id' => $this->academyId,
                'error' => $e->getMessage(),
            ]);

            if (! $e->isRetryable()) {
                $this->delete();
            }

            return null;
        }

        $remotePath = $file['file_path'] ?? null;

        if (! is_string($remotePath) || $remotePath === '') {
            return null;
        }

        $maxBytes = (int) config('pte.telegram.download_max_bytes', 20 * 1024 * 1024);
        $size = isset($file['file_size']) ? (int) $file['file_size'] : 0;

        if ($size > $maxBytes) {
            Log::warning('Telegram file exceeds the download ceiling.', [
                'academy_id' => $this->academyId,
                'size' => $size,
            ]);

            $this->delete();

            return null;
        }

        $extension = $this->extension ?? (pathinfo($remotePath, PATHINFO_EXTENSION) ?: 'bin');
        $relative = trim($this->directory, '/').'/'.Str::uuid()->toString().'.'.$extension;

        // Download to a scratch file first so a half-written object never
        // appears on the tenant disk for the ASR pipeline to pick up.
        $temp = tempnam(sys_get_temp_dir(), 'tg_');

        if ($temp === false) {
            return null;
        }

        try {
            $client->forBot($bot)->downloadFile($remotePath, $temp);

            $stream = fopen($temp, 'rb');

            if ($stream === false) {
                return null;
            }

            Storage::disk('tenant')->put($relative, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            return $relative;
        } catch (Throwable $e) {
            Log::warning('Telegram file download failed.', [
                'academy_id' => $this->academyId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            @unlink($temp);
        }
    }
}
