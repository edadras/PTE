<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Identity\Actions\AssignRole;
use App\Domain\Identity\Actions\InviteStaffMember;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\AcademyUserRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Academy\Resources\StaffMemberResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff memberships. A user is global; the membership row is what makes them
 * staff *here*, so this resource manages memberships, not users.
 *
 * @see docs/01-multi-tenancy.md §5
 */
final class StaffMemberResource extends Resource
{
    protected static ?string $model = AcademyUserRole::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.people');
    }

    public static function getModelLabel(): string
    {
        return __('panel.staff.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.staff.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('users.staff.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('users.staff.invite') === true;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->can('users.staff.remove') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('email')
                ->label(__('panel.staff.field.email'))
                ->email()
                ->required()
                ->dehydrated()
                ->visibleOn('create'),
            Forms\Components\TextInput::make('name')
                ->label(__('panel.staff.field.name'))
                ->dehydrated()
                ->visibleOn('create'),
            Forms\Components\Select::make('role_id')
                ->label(__('panel.staff.field.role'))
                ->options(fn (): array => self::roleOptions())
                ->required(),
            Forms\Components\Select::make('status')
                ->label(__('panel.staff.field.status'))
                ->options(fn (): array => collect(MembershipStatus::cases())
                    ->mapWithKeys(fn (MembershipStatus $s): array => [$s->value => $s->label()])
                    ->all())
                ->visibleOn('edit'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label(__('panel.staff.field.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label(__('panel.staff.field.email'))->searchable(),
                Tables\Columns\TextColumn::make('role.name')
                    ->label(__('panel.staff.field.role'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state, AcademyUserRole $record): string => $record->role?->displayName() ?? (string) $state),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.staff.field.status'))
                    ->badge()
                    ->formatStateUsing(fn (MembershipStatus $state): string => $state->label())
                    ->color(fn (MembershipStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('joined_at')->label(__('panel.staff.field.joined_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('panel.staff.field.status'))
                    ->options(fn (): array => collect(MembershipStatus::cases())
                        ->mapWithKeys(fn (MembershipStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('assign_role')
                    ->label(__('panel.staff.action.assign_role'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (): bool => auth()->user()?->can('users.roles.manage') === true)
                    ->form([
                        Forms\Components\Select::make('role_id')
                            ->label(__('panel.staff.field.role'))
                            ->options(fn (): array => self::roleOptions())
                            ->required(),
                    ])
                    ->action(function (AcademyUserRole $record, array $data): void {
                        $role = Role::query()->findOrFail((int) $data['role_id']);

                        app(AssignRole::class)->handle(
                            $record->user,
                            TenantContext::require(),
                            $role,
                            $record->status,
                        );

                        Notification::make()->success()->title(__('panel.staff.notify.role_assigned'))->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /**
     * Invite through the domain action so the user record, the membership and
     * the spatie projection stay in step.
     */
    public static function invite(string $email, int $roleId, ?string $name): AcademyUserRole
    {
        return app(InviteStaffMember::class)->handle(
            TenantContext::require(),
            $email,
            Role::query()->findOrFail($roleId),
            $name,
            auth()->user() instanceof User ? auth()->user() : null,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function roleOptions(): array
    {
        return Role::query()
            ->ofAcademy(TenantContext::id())
            ->orderByDesc('level')
            ->get()
            ->mapWithKeys(fn (Role $role): array => [$role->getKey() => $role->displayName()])
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'role']);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageStaffMembers::route('/'),
        ];
    }
}
