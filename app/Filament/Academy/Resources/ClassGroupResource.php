<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Identity\Enums\ClassGroupStatus;
use App\Domain\Identity\Models\AcademyUserRole;
use App\Domain\Identity\Models\ClassGroup;
use App\Filament\Academy\Resources\ClassGroupResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Class groups: the teacher's second scope and the unit an exam can be
 * assigned to.
 */
final class ClassGroupResource extends Resource
{
    protected static ?string $model = ClassGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.people');
    }

    public static function getModelLabel(): string
    {
        return __('panel.class_groups.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.class_groups.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('students.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('students.create') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('panel.class_groups.field.name'))->required()->maxLength(120),
            Forms\Components\Select::make('status')
                ->label(__('panel.class_groups.field.status'))
                ->options(fn (): array => collect(ClassGroupStatus::cases())
                    ->mapWithKeys(fn (ClassGroupStatus $c): array => [$c->value => $c->label()])
                    ->all())
                ->default(ClassGroupStatus::Planned->value)
                ->required(),
            Forms\Components\Select::make('teacher_id')
                ->label(__('panel.class_groups.field.teacher'))
                ->options(fn (): array => self::staffOptions())
                ->searchable(),
            Forms\Components\TextInput::make('capacity')->label(__('panel.class_groups.field.capacity'))->numeric()->minValue(1),
            Forms\Components\DateTimePicker::make('starts_at')->label(__('panel.class_groups.field.starts_at')),
            Forms\Components\DateTimePicker::make('ends_at')->label(__('panel.class_groups.field.ends_at')),
            Forms\Components\Textarea::make('description')->label(__('panel.common.description'))->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('panel.class_groups.field.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.class_groups.field.status'))
                    ->badge()
                    ->formatStateUsing(fn (ClassGroupStatus $state): string => $state->label()),
                Tables\Columns\TextColumn::make('teacher.name')->label(__('panel.class_groups.field.teacher'))->placeholder('—'),
                Tables\Columns\TextColumn::make('students_count')
                    ->label(__('panel.class_groups.field.students'))
                    ->counts('students'),
                Tables\Columns\TextColumn::make('capacity')->label(__('panel.class_groups.field.capacity'))->placeholder('∞'),
                Tables\Columns\TextColumn::make('starts_at')->label(__('panel.class_groups.field.starts_at'))->date()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('panel.class_groups.field.status'))
                    ->options(fn (): array => collect(ClassGroupStatus::cases())
                        ->mapWithKeys(fn (ClassGroupStatus $c): array => [$c->value => $c->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function staffOptions(): array
    {
        $userIds = AcademyUserRole::query()->active()->pluck('user_id')->unique()->all();

        return User::query()->whereKey($userIds)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageClassGroups::route('/'),
        ];
    }
}
