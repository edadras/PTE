<?php

declare(strict_types=1);

namespace App\Filament\Academy\Support;

use App\Domain\Assessment\Models\Answer;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Short-lived links to a student's recording.
 *
 * Answer media lives on the private tenant disk (CONVENTIONS §3), so the panel
 * never renders a raw path — only a signed URL with the configured TTL.
 */
final class AnswerMedia
{
    public function temporaryUrl(Answer $answer): ?string
    {
        if (blank($answer->media_path)) {
            return null;
        }

        $minutes = (int) config('pte.media.signed_url_ttl_minutes', 15);

        try {
            return Storage::disk('tenant')->temporaryUrl($answer->media_path, now()->addMinutes($minutes));
        } catch (Throwable) {
            try {
                return Storage::disk('tenant')->url($answer->media_path);
            } catch (Throwable) {
                return null;
            }
        }
    }
}
