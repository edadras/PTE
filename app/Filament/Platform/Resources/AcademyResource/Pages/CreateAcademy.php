<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\AcademyResource\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Tenancy\Actions\CreateAcademy as CreateAcademyAction;
use App\Domain\Tenancy\Data\CreateAcademyData;
use App\Filament\Platform\Resources\AcademyResource;
use App\Filament\Support\PlatformAudit;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Creation goes through the domain action, which also seeds settings, brand,
 * the default subdomain, the four system roles and the owner membership.
 */
final class CreateAcademy extends CreateRecord
{
    protected static string $resource = AcademyResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $academy = app(CreateAcademyAction::class)->handle(
            CreateAcademyData::fromArray($data)
        );

        PlatformAudit::record(
            action: AuditAction::AcademyCreated,
            actor: auth()->user() instanceof User ? auth()->user() : null,
            target: $academy,
            payload: ['name' => $academy->name, 'slug' => $academy->slug],
        );

        return $academy;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
