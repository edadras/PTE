<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Models\Course;
use App\Filament\Academy\Resources\CourseResource\Pages;
use App\Filament\Academy\Resources\CourseResource\RelationManagers\LessonsRelationManager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.content');
    }

    public static function getModelLabel(): string
    {
        return __('panel.courses.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.courses.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('courses.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('courses.manage') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('courses.manage') === true;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('courses.manage') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.courses.section.basics'))
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label(__('panel.courses.field.title'))
                        ->required()
                        ->maxLength(150)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Forms\Set $set, ?string $state): void {
                            $set('slug', Str::slug((string) $state));
                        }),
                    Forms\Components\TextInput::make('slug')->label(__('panel.common.slug'))->required()->maxLength(150),
                    Forms\Components\Select::make('module_key')
                        ->label(__('panel.common.module'))
                        ->options(fn (): array => collect(ModuleKey::cases())
                            ->mapWithKeys(fn (ModuleKey $m): array => [$m->value => $m->label()])
                            ->all()),
                    Forms\Components\Select::make('status')
                        ->label(__('panel.common.status'))
                        ->options(fn (): array => collect(CourseStatus::cases())
                            ->mapWithKeys(fn (CourseStatus $c): array => [$c->value => $c->label()])
                            ->all())
                        ->default(CourseStatus::Draft->value)
                        ->required(),
                    Forms\Components\Textarea::make('description')->label(__('panel.common.description'))->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.courses.section.commercial'))
                ->schema([
                    Forms\Components\TextInput::make('price')->label(__('panel.courses.field.price'))->numeric()->default(0),
                    Forms\Components\TextInput::make('currency')->label(__('panel.plans.field.currency'))->default('IRR')->maxLength(3),
                    Forms\Components\TextInput::make('duration_days')->label(__('panel.courses.field.duration_days'))->numeric(),
                    Forms\Components\TextInput::make('sort_order')->label(__('panel.common.sort_order'))->numeric()->default(0),
                    Forms\Components\FileUpload::make('cover_path')
                        ->label(__('panel.courses.field.cover'))
                        ->disk('tenant')
                        ->directory('courses/covers')
                        ->visibility('private')
                        ->image()
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label(__('panel.courses.field.title'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('module_key')
                    ->label(__('panel.common.module'))
                    ->badge()
                    ->formatStateUsing(fn (?ModuleKey $state): string => $state?->label() ?? '—'),
                Tables\Columns\TextColumn::make('lessons_count')->label(__('panel.courses.field.lessons'))->counts('lessons'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (CourseStatus $state): string => $state->label())
                    ->color(fn (CourseStatus $state): string => $state === CourseStatus::Published ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('price')->label(__('panel.courses.field.price'))->money(fn (Course $record): string => $record->currency),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            LessonsRelationManager::class,
        ];
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'create' => Pages\CreateCourse::route('/create'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }
}
