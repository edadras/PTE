<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Services\ReportCardBuilder;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Services\BrandResolver;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;

/**
 * Renders ReportCardBuilder's array as branded, self-contained HTML.
 *
 * Deliberately stops at HTML. Turning this into the PNG the bot sends needs a
 * headless browser (Chromium via Browsershot or a rendering service) and that is
 * an infrastructure decision, not a code one — adding a screenshot dependency
 * here would put a 300MB binary into every worker image, including the ones
 * that only send messages. See the report note: an image/PDF step is still
 * required and is not implemented in this layer.
 *
 * The markup is inline-styled and standalone so the same string works as a
 * panel view, an email body and the input to that future rendering step.
 *
 * @see docs/03-white-label.md · docs/05-modules-exams-practice.md §5
 */
final class ReportCardRenderer
{
    public const VIEW = 'reports.report-card';

    public function __construct(
        private readonly ReportCardBuilder $builder,
        private readonly BrandResolver $brands,
    ) {}

    public function forSession(PracticeSession|ExamSession $session, ?Academy $academy = null): string
    {
        return $this->render($this->builder->build($session), $academy);
    }

    /**
     * @param  array<string, mixed>  $card
     */
    public function render(array $card, ?Academy $academy = null): string
    {
        return $this->view($card, $academy)->render();
    }

    /**
     * @param  array<string, mixed>  $card
     */
    public function view(array $card, ?Academy $academy = null): View
    {
        $academy ??= TenantContext::get();
        $brand = $this->brands->resolve($academy);

        $locale = $academy?->preferredLocale() ?? app()->getLocale();

        return ViewFactory::make(self::VIEW, [
            'card' => $card,
            'brand' => $brand,
            'palette' => $brand->colorPalette(),
            'logo' => $brand->logoUrl(),
            'locale' => $locale,
            'rtl' => in_array($locale, ['fa', 'ar', 'he', 'ur'], true),
            'academyName' => $brand->display_name ?? $academy?->name ?? '',
        ]);
    }
}
