<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Support\PermissionCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Academy\Resources\RoleResource\Pages;
use App\Filament\Support\PermissionOptions;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The custom-role builder of docs/02 §5.
 *
 * Two rules are structural rather than validated after the fact: the picker is
 * built from what the *current user* holds (Privilege Escalation Guard), and it
 * is built from the academy-scoped catalogue only, so `platform.*` has no way
 * to appear at all.
 */
final class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.people');
    }

    public static function getModelLabel(): string
    {
        return __('panel.roles.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.roles.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('users.roles.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('users.roles.manage') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('users.roles.manage') === true;
    }

    /** System roles are the contract of the platform: copyable, never deletable. */
    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('users.roles.manage') === true
            && $record instanceof Role
            && ! $record->is_system;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.roles.section.identity'))
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('panel.roles.field.name'))
                        ->required()
                        ->maxLength(60)
                        ->helperText(__('panel.roles.help.name'))
                        ->disabled(fn (?Role $record): bool => $record?->is_system === true),
                    Forms\Components\TextInput::make('display_name')
                        ->label(__('panel.roles.field.display_name'))
                        ->maxLength(80),
                    Forms\Components\Select::make('color')
                        ->label(__('panel.roles.field.color'))
                        ->options([
                            'primary' => 'primary',
                            'success' => 'success',
                            'warning' => 'warning',
                            'danger' => 'danger',
                            'info' => 'info',
                            'gray' => 'gray',
                        ])
                        ->default('primary'),
                    Forms\Components\TextInput::make('level')
                        ->label(__('panel.roles.field.level'))
                        ->numeric()
                        ->default(10)
                        ->helperText(__('panel.roles.help.level'))
                        ->disabled(fn (?Role $record): bool => $record?->is_system === true),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.roles.section.permissions'))
                ->description(__('panel.roles.help.permissions'))
                ->schema(self::permissionSchema()),
        ]);
    }

    /**
     * @return array<int, Component>
     */
    private static function permissionSchema(): array
    {
        $actor = auth()->user();
        $actor = $actor instanceof User ? $actor : null;

        $sections = [];

        foreach (PermissionOptions::grantableGroups($actor) as $group => $options) {
            $sections[] = Forms\Components\Fieldset::make(PermissionCatalog::groupLabel($group))
                ->schema([
                    Forms\Components\CheckboxList::make('permission_groups.'.$group)
                        ->hiddenLabel()
                        ->options($options)
                        ->columns(3)
                        ->bulkToggleable()
                        ->columnSpanFull(),
                ]);
        }

        if ($sections === []) {
            $sections[] = Forms\Components\Placeholder::make('no_permissions')
                ->label('')
                ->content(__('panel.roles.no_grantable'));
        }

        return $sections;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('panel.roles.field.name'))
                    ->formatStateUsing(fn (string $state, Role $record): string => $record->displayName())
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_system')->label(__('panel.roles.field.is_system'))->boolean(),
                Tables\Columns\TextColumn::make('level')->label(__('panel.roles.field.level'))->sortable(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label(__('panel.roles.field.permissions'))
                    ->counts('permissions'),
            ])
            ->defaultSort('level', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /**
     * Roles are not BelongsToAcademy (spatie owns the team key), so the
     * academy filter uses the model's own scope rather than a raw where.
     */
    public static function getEloquentQuery(): Builder
    {
        return Role::query()->ofAcademy(TenantContext::id());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function permissionsFromForm(array $data): array
    {
        $actor = auth()->user();

        return PermissionOptions::fromGroupedState(
            $actor instanceof User ? $actor : null,
            is_array($data['permission_groups'] ?? null) ? $data['permission_groups'] : [],
        );
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public static function syncPermissions(Role $role, array $permissions): void
    {
        $models = Permission::query()
            ->where('guard_name', $role->guard_name)
            ->whereIn('name', $permissions)
            ->get();

        $role->syncPermissions($models);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
