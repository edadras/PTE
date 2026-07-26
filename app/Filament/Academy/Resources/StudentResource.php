<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Identity\Enums\StudentSource;
use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\ClassGroup;
use App\Domain\Identity\Models\Student;
use App\Filament\Academy\Resources\StudentResource\Pages;
use App\Filament\Academy\Resources\StudentResource\RelationManagers\ProgressRelationManager;
use App\Filament\Academy\Support\StudentCsv;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Learners. Tenant scoping comes from BelongsToAcademy; the teacher's second
 * boundary comes from StudentPolicy, which Filament consults automatically.
 *
 * @see docs/02-roles-and-rbac.md §2
 */
final class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.people');
    }

    public static function getModelLabel(): string
    {
        return __('panel.students.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.students.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.students.section.identity'))
                ->schema([
                    Forms\Components\TextInput::make('first_name')
                        ->label(__('panel.students.field.first_name'))->required()->maxLength(80),
                    Forms\Components\TextInput::make('last_name')
                        ->label(__('panel.students.field.last_name'))->maxLength(80),
                    Forms\Components\TextInput::make('student_code')
                        ->label(__('panel.students.field.code'))
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit'),
                    Forms\Components\Select::make('status')
                        ->label(__('panel.students.field.status'))
                        ->options(StudentStatus::options())
                        ->default(StudentStatus::Active->value)
                        ->required(),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.students.section.contact'))
                ->schema([
                    Forms\Components\TextInput::make('email')->label(__('panel.students.field.email'))->email()->maxLength(200),
                    Forms\Components\TextInput::make('phone')->label(__('panel.students.field.phone'))->tel()->maxLength(32),
                    Forms\Components\Select::make('locale')
                        ->label(__('panel.students.field.locale'))
                        ->options(['fa' => 'فارسی', 'en' => 'English'])
                        ->default('fa'),
                ])
                ->columns(3),

            Forms\Components\Section::make(__('panel.students.section.learning'))
                ->schema([
                    Forms\Components\TextInput::make('level')->label(__('panel.students.field.level'))->maxLength(40),
                    Forms\Components\TextInput::make('target_score')->label(__('panel.students.field.target_score'))->numeric(),
                    Forms\Components\Select::make('class_group_id')
                        ->label(__('panel.students.field.class_group'))
                        ->options(fn (): array => ClassGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->dehydrated()
                        ->visibleOn('create'),
                    Forms\Components\Select::make('source')
                        ->label(__('panel.students.field.source'))
                        ->options(StudentSource::options())
                        ->default(StudentSource::Web->value)
                        ->visibleOn('create')
                        ->dehydrated(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student_code')
                    ->label(__('panel.students.field.code'))->searchable()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('first_name')
                    ->label(__('panel.students.field.name'))
                    ->state(fn (Student $record): string => $record->fullName())
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.students.field.status'))
                    ->badge()
                    ->formatStateUsing(fn (StudentStatus $state): string => $state->label())
                    ->color(fn (StudentStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('level')->label(__('panel.students.field.level'))->toggleable(),
                Tables\Columns\TextColumn::make('subscription_expires_at')
                    ->label(__('panel.students.field.subscription'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_active_at')
                    ->label(__('panel.students.field.last_active'))
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('panel.students.field.status'))
                    ->options(StudentStatus::options()),
                Tables\Filters\SelectFilter::make('classGroups')
                    ->label(__('panel.students.field.class_group'))
                    ->relationship('classGroups', 'name'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('import')
                    ->label(__('panel.students.action.import'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (): bool => auth()->user()?->can('students.import') === true)
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label(__('panel.students.field.csv'))
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                            ->storeFiles(false)
                            ->required(),
                        Forms\Components\Select::make('class_group_id')
                            ->label(__('panel.students.field.class_group'))
                            ->options(fn (): array => ClassGroup::query()->orderBy('name')->pluck('name', 'id')->all()),
                    ])
                    ->action(function (array $data): void {
                        $report = app(StudentCsv::class)->import(
                            $data['file'],
                            $data['class_group_id'] === null ? null : (int) $data['class_group_id'],
                        );

                        Notification::make()
                            ->title(__('panel.students.notify.imported', ['count' => $report['imported']]))
                            ->body($report['errors'] === [] ? null : implode("\n", array_slice($report['errors'], 0, 10)))
                            ->status($report['errors'] === [] ? 'success' : 'warning')
                            ->send();
                    }),

                Tables\Actions\Action::make('export')
                    ->label(__('panel.students.action.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => auth()->user()?->can('students.export') === true)
                    ->action(fn (): StreamedResponse => app(StudentCsv::class)->export()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            ProgressRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // No academy filter here on purpose — BelongsToAcademy already applied it.
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'create' => Pages\CreateStudent::route('/create'),
            'view' => Pages\ViewStudent::route('/{record}'),
            'edit' => Pages\EditStudent::route('/{record}/edit'),
        ];
    }
}
