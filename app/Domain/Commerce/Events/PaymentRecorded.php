<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use App\Domain\Commerce\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

final class PaymentRecorded
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
