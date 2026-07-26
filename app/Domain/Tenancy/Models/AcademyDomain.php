<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\DomainType;
use App\Domain\Tenancy\Enums\SslStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately NOT tenant-scoped: this table is how a tenant is found in the
 * first place, so scoping it would be circular.
 *
 * @property int $academy_id
 * @property string $hostname
 * @property DomainType $type
 * @property SslStatus $ssl_status
 *
 * @see docs/01-multi-tenancy.md §4.2, §6
 */
final class AcademyDomain extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'ssl_status' => SslStatus::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function academy(): BelongsTo
    {
        return $this->belongsTo(Academy::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForHostname(Builder $query, string $hostname): Builder
    {
        return $query->where('hostname', self::normalizeHostname($hostname));
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Our own subdomains are trusted; custom domains must prove ownership. */
    public function isUsable(): bool
    {
        return $this->type === DomainType::Subdomain || $this->isVerified();
    }

    public function dnsVerificationRecord(): string
    {
        return '_pte-verify.'.$this->hostname;
    }

    public static function normalizeHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('#^https?://#', '', $hostname) ?? $hostname;
        $hostname = explode('/', $hostname)[0];
        $hostname = explode(':', $hostname)[0];

        return rtrim($hostname, '.');
    }
}
