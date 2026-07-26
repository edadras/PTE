<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\StaffMemberResource\Pages;

use App\Filament\Academy\Resources\StaffMemberResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;

final class ManageStaffMembers extends ManageRecords
{
    protected static string $resource = StaffMemberResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('panel.staff.action.invite'))
                ->using(function (array $data): Model {
                    return StaffMemberResource::invite(
                        (string) $data['email'],
                        (int) $data['role_id'],
                        $data['name'] ?? null,
                    );
                }),
        ];
    }
}
