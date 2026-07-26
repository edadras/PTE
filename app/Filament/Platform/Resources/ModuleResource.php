<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Support\ModuleRegistry;
use App\Domain\Tenancy\Models\Module;
use App\Filament\Platform\Resources\ModuleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The module catalogue mirror. ModuleRegistry is the source of truth in code;
 * this table is what academies switch on, so the two are shown side by side.
 *
 * @see docs/05-modules-exams-practice.md §1
 */
final class ModuleResource extends Resource
{
    protected static ?string $model = Module::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('panel.modules.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.modules.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('platform.modules.manage') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('key')
                ->label(__('panel.modules.field.key'))
                ->required()
                ->maxLength(64)
                ->disabledOn('edit'),
            Forms\Components\TextInput::make('name')->label(__('panel.modules.field.name'))->required()->maxLength(100),
            Forms\Components\TextInput::make('icon')->label(__('panel.modules.field.icon'))->maxLength(16),
            Forms\Components\TextInput::make('version')->label(__('panel.modules.field.version'))->default('1.0.0')->maxLength(20),
            Forms\Components\Select::make('requires_plan')
                ->label(__('panel.modules.field.requires_plan'))
                ->options([
                    'starter' => __('billing.plan.starter'),
                    'professional' => __('billing.plan.professional'),
                    'enterprise' => __('billing.plan.enterprise'),
                ]),
            Forms\Components\Toggle::make('is_beta')->label(__('panel.modules.field.is_beta')),
            Forms\Components\Textarea::make('description')->label(__('panel.modules.field.description'))->columnSpanFull(),
            Forms\Components\CheckboxList::make('question_types')
                ->label(__('panel.modules.field.question_types'))
                ->options(fn (): array => collect(QuestionType::cases())
                    ->mapWithKeys(fn (QuestionType $type): array => [$type->value => $type->value.' — '.$type->label()])
                    ->all())
                ->columns(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->label(__('panel.modules.field.key'))->searchable(),
                Tables\Columns\TextColumn::make('name')->label(__('panel.modules.field.name'))->searchable(),
                Tables\Columns\TextColumn::make('requires_plan')
                    ->label(__('panel.modules.field.requires_plan'))
                    ->badge()
                    ->default('—'),
                Tables\Columns\TextColumn::make('phase')
                    ->label(__('panel.modules.field.phase'))
                    ->state(fn (Module $record): string => (string) (
                        $record->moduleKey() === null ? '—' : ModuleRegistry::phase($record->moduleKey())
                    )),
                Tables\Columns\IconColumn::make('is_beta')->label(__('panel.modules.field.is_beta'))->boolean(),
                Tables\Columns\TextColumn::make('academies_count')
                    ->label(__('panel.modules.field.enabled_by')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    /** `academy_modules` is tenant-scoped, so it is counted with a raw subquery. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->addSelect([
            'academies_count' => DB::table('academy_modules')
                ->selectRaw('COUNT(*)')
                ->whereColumn('academy_modules.module_key', 'modules.key')
                ->where('academy_modules.is_enabled', true),
        ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageModules::route('/'),
        ];
    }
}
