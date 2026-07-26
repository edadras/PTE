<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Tenancy\Models\AcademyDomain;
use Closure;
use Throwable;

/**
 * Checks the DNS TXT record that proves a custom domain belongs to the academy
 * that registered it: `_pte-verify.<hostname>` must contain the stored token.
 *
 * @see docs/01-multi-tenancy.md §6
 */
final class DomainVerification
{
    /** @var (Closure(string): array<int, string>)|null test seam for DNS lookups */
    private static ?Closure $lookup = null;

    /**
     * @param  (Closure(string): array<int, string>)|null  $callback
     */
    public static function lookupUsing(?Closure $callback): void
    {
        self::$lookup = $callback;
    }

    /** Marks the domain verified when the TXT token matches. */
    public function verify(AcademyDomain $domain): bool
    {
        $token = (string) $domain->verification_token;

        if ($token === '' || $domain->isVerified()) {
            return $domain->isVerified();
        }

        if (! in_array($token, $this->txtRecords($domain->dnsVerificationRecord()), true)) {
            return false;
        }

        $domain->forceFill(['verified_at' => now()])->save();

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function txtRecords(string $host): array
    {
        if (self::$lookup !== null) {
            return (self::$lookup)($host);
        }

        try {
            $records = dns_get_record($host, DNS_TXT) ?: [];
        } catch (Throwable) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = trim($record['txt']);
            }
        }

        return $values;
    }
}
