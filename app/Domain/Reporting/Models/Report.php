<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\Reporting\Enums\ReportStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A generated file plus its expiry. The row outlives the file on purpose: after
 * the retention window the object is gone but the audit question "who exported
 * the student list in March" still has an answer.
 *
 * @property int $id
 * @property int $academy_id
 * @property ReportType $type
 * @property string $format
 * @property array<string, mixed>|null $params
 * @property string|null $file_path
 * @property ReportStatus $status
 * @property int|null $requested_by
 * @property Carbon|null $generated_at
 * @property Carbon|null $expires_at
 *
 * @see docs/07-database-schema.md §10
 */
final class Report extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    public const DISK = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'status' => ReportStatus::class,
            'params' => 'array',
            'file_size' => 'integer',
            'row_count' => 'integer',
            'requested_by' => 'integer',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query, ?Carbon $moment = null): Builder
    {
        return $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $moment ?? now());
    }

    public function isExpired(?Carbon $moment = null): bool
    {
        return $this->expires_at !== null && ($moment ?? now())->greaterThanOrEqualTo($this->expires_at);
    }

    public function isDownloadable(): bool
    {
        return $this->status->isDownloadable() && ! $this->isExpired() && filled($this->file_path);
    }

    /**
     * A short-lived signed link, per docs/10 §4: report files are private and
     * are never served from a public bucket path.
     *
     * Falls back to a plain URL on drivers that cannot sign (the local disk in
     * development), and to null when even that is unavailable — a broken link
     * is better than an exception in a list view.
     */
    public function temporaryUrl(?int $minutes = null): ?string
    {
        if (! $this->isDownloadable()) {
            return null;
        }

        $minutes ??= (int) config('pte.media.signed_url_ttl_minutes', 15);
        $disk = Storage::disk(self::DISK);

        try {
            return $disk->temporaryUrl((string) $this->file_path, now()->addMinutes($minutes));
        } catch (Throwable) {
            try {
                return $disk->url((string) $this->file_path);
            } catch (Throwable) {
                return null;
            }
        }
    }

    public function deleteFile(): bool
    {
        if (blank($this->file_path)) {
            return false;
        }

        try {
            return Storage::disk(self::DISK)->delete((string) $this->file_path);
        } catch (Throwable) {
            return false;
        }
    }
}
