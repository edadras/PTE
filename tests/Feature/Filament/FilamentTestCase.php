<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Identity\Actions\AssignRole;
use App\Domain\Identity\Actions\SeedAcademyRoles;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Tenancy\Enums\DomainType;
use App\Domain\Tenancy\Enums\SslStatus;
use App\Domain\Tenancy\Middleware\ResolveTenantFromDomain;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyDomain;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Support\BrandContext;
use App\Models\User;
use App\Providers\Filament\AcademyPanelProvider;
use App\Providers\Filament\PlatformPanelProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Tests\TestCase;

/**
 * Base class for the panel smoke tests.
 *
 * The two panel providers are merged in here rather than assumed, so the suite
 * is green whether or not bootstrap/providers.php has been updated yet — and
 * still green afterwards, because the merge is de-duplicated.
 */
abstract class FilamentTestCase extends TestCase
{
    public function createApplication(): Application
    {
        RegisterProviders::merge(
            [PlatformPanelProvider::class, AcademyPanelProvider::class],
            dirname(__DIR__, 3).'/bootstrap/providers.php',
        );

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        BrandContext::flush();

        parent::tearDown();
    }

    /** An academy with system roles seeded and a resolvable hostname. */
    protected function makeAcademy(string $slug): Academy
    {
        /** @var Academy $academy */
        $academy = Academy::factory()->create(['slug' => $slug, 'name' => ucfirst($slug).' Academy']);

        AcademyDomain::query()->create([
            'academy_id' => $academy->getKey(),
            'hostname' => $this->hostFor($academy),
            'type' => DomainType::Subdomain,
            'is_primary' => true,
            'ssl_status' => SslStatus::Issued,
            'verified_at' => now(),
        ]);

        app(SeedAcademyRoles::class)->handle($academy);

        return $academy;
    }

    protected function makeStaff(Academy $academy, SystemRole $role): User
    {
        /** @var User $user */
        $user = User::factory()->create();

        app(AssignRole::class)->handle($user, $academy, $role, MembershipStatus::Active);

        return $user;
    }

    protected function hostFor(Academy $academy): string
    {
        return AcademyDomain::normalizeHostname(
            $academy->slug.'.'.(string) config('pte.platform.root_domain')
        );
    }

    protected function panelUrl(Academy $academy, string $path = ''): string
    {
        return 'http://'.$this->hostFor($academy).'/panel'.($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /** The hostname cache is written before any tenant exists; drop it per test. */
    protected function forgetHostCache(Academy $academy): void
    {
        ResolveTenantFromDomain::forget($this->hostFor($academy));
    }

    protected function tearDownTenant(): void
    {
        TenantContext::forget();
    }
}
