<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\RoleResource\Pages;

use App\Domain\Identity\Models\Role;
use App\Filament\Academy\Resources\RoleResource;
use App\Filament\Support\PermissionOptions;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $record */
        $record = $this->getRecord();
        $actor = auth()->user();

        $data['permission_groups'] = PermissionOptions::toGroupedState(
            $actor instanceof User ? $actor : null,
            $record->permissions->pluck('name')->map(strval(...))->all(),
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        $permissions = RoleResource::permissionsFromForm($data);

        $record->forceFill([
            'display_name' => $data['display_name'] ?? null,
            'color' => $data['color'] ?? 'primary',
        ]);

        if (! $record->is_system) {
            $record->forceFill([
                'name' => strtolower(trim((string) $data['name'])),
                'level' => (int) ($data['level'] ?? $record->level),
            ]);
        }

        $record->save();

        RoleResource::syncPermissions($record, $permissions);

        return $record;
    }
}
