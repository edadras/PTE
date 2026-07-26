<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

/**
 * SMS transport.
 *
 * An interface rather than a concrete gateway because the Iranian SMS market is
 * a dozen near-identical providers (Kavenegar, SMS.ir, Ghasedak, …) and which
 * one an academy uses is a deployment decision. The platform ships a null
 * implementation and binds the real one from configuration:
 *
 *   // config/pte.php — NOT YET PRESENT, see the wiring report
 *   'notifications' => [
 *       'sms' => [
 *           'driver' => env('PTE_SMS_DRIVER', 'null'),   // null|kavenegar|smsir
 *           'sender' => env('PTE_SMS_SENDER'),
 *           'api_key' => env('PTE_SMS_API_KEY'),
 *       ],
 *   ],
 */
interface SmsDriver
{
    /** The configured driver name, for logging and the panel's status line. */
    public function name(): string;

    /** Whether this driver can actually deliver — false for the null driver. */
    public function isAvailable(): bool;

    /**
     * @param  string  $to  E.164 or local Iranian format; the driver normalises.
     * @return bool true when the provider accepted the message
     */
    public function send(string $to, string $message): bool;
}
