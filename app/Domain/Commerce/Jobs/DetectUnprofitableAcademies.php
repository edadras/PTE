<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Jobs;

use App\Domain\Commerce\Data\ProfitabilitySnapshot;
use App\Domain\Commerce\Events\AcademyUnprofitable;
use App\Domain\Commerce\Services\ProfitabilityAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily: AI cost ÷ revenue per academy; anything over the threshold raises an
 * alarm in the Super Admin panel.
 *
 * The point is timing, not accuracy — knowing on day 9 that a customer is
 * burning 80% of their fee is what allows a price or routing change while the
 * month can still be saved (docs/09 §6).
 */
final class DetectUnprofitableAcademies implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ?string $period = null) {}

    public function handle(ProfitabilityAnalyzer $analyzer): void
    {
        foreach ($analyzer->unprofitable($this->period) as $snapshot) {
            $this->raise($snapshot);
        }
    }

    private function raise(ProfitabilitySnapshot $snapshot): void
    {
        Log::warning('Academy is running below the profitability threshold.', $snapshot->toArray());

        AcademyUnprofitable::dispatch($snapshot);
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['commerce', 'unit-economics'];
    }
}
