<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Identity\Actions\AssignRole;
use App\Domain\Identity\Actions\SeedAcademyRoles;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Tenancy\Data\CreateAcademyData;
use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Enums\DomainType;
use App\Domain\Tenancy\Enums\SslStatus;
use App\Domain\Tenancy\Events\AcademyCreated;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyBrand;
use App\Domain\Tenancy\Models\AcademyDomain;
use App\Domain\Tenancy\Models\AcademyModule;
use App\Domain\Tenancy\Models\AcademySettings;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Brings a tenant into existence: academy row, settings, brand, default
 * subdomain, system roles and the owner membership.
 *
 * Everything is `firstOrCreate`-shaped and wrapped in one transaction, so a
 * retried onboarding step never produces half a tenant or a duplicate.
 *
 * @see docs/01-multi-tenancy.md §8 · docs/03-white-label.md §9
 */
final class CreateAcademy
{
    public function __construct(
        private readonly SeedAcademyRoles $seedAcademyRoles,
        private readonly AssignRole $assignRole,
    ) {}

    public function handle(CreateAcademyData $data): Academy
    {
        $slug = $this->validatedSlug($data->resolvedSlug());

        $academy = DB::transaction(function () use ($data, $slug): Academy {
            /** @var Academy $academy */
            $academy = Academy::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data->name,
                    'legal_name' => $data->legalName,
                    'status' => AcademyStatus::Active,
                    'plan_id' => $data->planId,
                    'timezone' => $data->timezone,
                    'country' => $data->country,
                ]
            );

            TenantContext::runFor($academy, function () use ($academy, $data): void {
                $this->ensureSettings($academy, $data);
                $this->ensureBrand($academy, $data);
                $this->ensureModules($academy, $data);
            });

            $this->ensurePrimaryDomain($academy, $slug);

            $this->seedAcademyRoles->handle($academy);

            $this->ensureOwner($academy, $data);

            return $academy;
        });

        $academy->refresh();

        // Mandatory audit hook (docs/02 §7); fired after commit so a listener
        // never sees a tenant that could still roll back.
        AcademyCreated::dispatch($academy);

        return $academy;
    }

    private function validatedSlug(string $slug): string
    {
        if ($slug === '') {
            throw new InvalidArgumentException('An academy needs a slug.');
        }

        /** @var array<int, string> $reserved */
        $reserved = config('pte.tenancy.reserved_slugs', []);

        if (in_array($slug, $reserved, true)) {
            throw new InvalidArgumentException("The slug [{$slug}] is reserved by the platform.");
        }

        return $slug;
    }

    private function ensureSettings(Academy $academy, CreateAcademyData $data): void
    {
        AcademySettings::query()->firstOrCreate(
            ['academy_id' => $academy->getKey()],
            [
                'locale' => $data->locale,
                'currency' => $data->currency,
                ...$data->settings,
            ]
        );
    }

    private function ensureBrand(Academy $academy, CreateAcademyData $data): void
    {
        AcademyBrand::query()->firstOrCreate(
            ['academy_id' => $academy->getKey()],
            [
                'display_name' => $data->name,
                'short_name' => Str::limit($data->name, 40, ''),
                'default_locale' => $data->locale,
                'supported_locales' => array_values(array_unique([$data->locale, 'en'])),
                ...$data->brand,
            ]
        );
    }

    private function ensureModules(Academy $academy, CreateAcademyData $data): void
    {
        foreach ($data->moduleKeys() as $key) {
            AcademyModule::query()->firstOrCreate(
                ['academy_id' => $academy->getKey(), 'module_key' => $key],
                ['is_enabled' => true, 'enabled_at' => now()]
            );
        }
    }

    private function ensurePrimaryDomain(Academy $academy, string $slug): void
    {
        $hostname = AcademyDomain::normalizeHostname(
            $slug.'.'.(string) config('pte.platform.root_domain')
        );

        AcademyDomain::query()->firstOrCreate(
            ['hostname' => $hostname],
            [
                'academy_id' => $academy->getKey(),
                'type' => DomainType::Subdomain,
                'is_primary' => true,
                // Our own wildcard certificate already covers this host.
                'ssl_status' => SslStatus::Issued,
                'verified_at' => now(),
            ]
        );
    }

    private function ensureOwner(Academy $academy, CreateAcademyData $data): void
    {
        $owner = $this->resolveOwner($data);

        if (! $owner instanceof User) {
            return;
        }

        $this->assignRole->handle(
            $owner,
            $academy,
            SystemRole::Owner,
            MembershipStatus::Active,
        );

        if ($academy->owner_user_id === null) {
            $academy->forceFill(['owner_user_id' => $owner->getKey()])->save();
        }
    }

    private function resolveOwner(CreateAcademyData $data): ?User
    {
        if ($data->ownerUserId !== null) {
            return User::query()->find($data->ownerUserId);
        }

        if (blank($data->ownerEmail)) {
            return null;
        }

        return User::query()->firstOrCreate(
            ['email' => Str::lower(trim((string) $data->ownerEmail))],
            [
                'name' => $data->ownerName ?? $data->name,
                'password' => Str::password(32),
                'is_super_admin' => false,
            ]
        );
    }
}
