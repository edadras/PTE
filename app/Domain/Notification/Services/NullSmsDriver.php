<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Contracts\SmsDriver;
use Illuminate\Support\Facades\Log;

/**
 * The default SMS transport: records the intent and delivers nothing.
 *
 * It reports `isAvailable() === false` so the dispatcher marks the notification
 * *skipped* rather than *sent*. That distinction matters — an academy that has
 * not bought an SMS gateway must not see a green "delivered" next to a message
 * that never left the building.
 */
final class NullSmsDriver implements SmsDriver
{
    public function name(): string
    {
        return 'null';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function send(string $to, string $message): bool
    {
        Log::info('SMS suppressed: no SMS driver is configured.', [
            'to' => mb_substr($to, 0, 4).'***',
            'length' => mb_strlen($message),
        ]);

        return false;
    }
}
