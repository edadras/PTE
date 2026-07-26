<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\ApiKey;
use App\Domain\Tenancy\Enums\DomainType;
use App\Domain\Tenancy\Enums\SslStatus;
use App\Domain\Tenancy\Middleware\ResolveTenantFromDomain;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyDomain;
use App\Domain\Tenancy\TenantContext;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    protected function academy(string $slug): Academy
    {
        /** @var Academy $academy */
        $academy = Academy::factory()->create(['slug' => $slug, 'name' => ucfirst($slug).' Academy']);

        return $academy;
    }

    /**
     * @param  array<int, string>  $scopes
     * @return array{key: ApiKey, plain_text: string}
     */
    protected function apiKeyFor(Academy $academy, array $scopes = ['*']): array
    {
        return TenantContext::runFor(
            $academy,
            static fn (): array => ApiKey::issue('test key', $scopes)
        );
    }

    /**
     * @return array<string, string>
     */
    protected function keyHeaders(string $plainText): array
    {
        return ['Authorization' => 'Bearer '.$plainText, 'Accept' => 'application/json'];
    }

    /** Registers a hostname the `tenant.domain` middleware will resolve. */
    protected function hostFor(Academy $academy): string
    {
        $hostname = $academy->slug.'.pte-platform.test';

        AcademyDomain::query()->create([
            'academy_id' => $academy->getKey(),
            'hostname' => $hostname,
            'type' => DomainType::Subdomain,
            'is_primary' => true,
            'ssl_status' => SslStatus::Issued,
            'verified_at' => now(),
        ]);

        ResolveTenantFromDomain::forget($hostname);

        return $hostname;
    }
}
