<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Models\AiRubric;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Academy\Resources\AiRubricResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The rubric editor of docs/06 §4.
 *
 * Weights must total exactly 100. That is enforced by the model itself (it
 * throws on save), so the form validates the same rule up front and shows the
 * running total live rather than letting the save blow up.
 */
final class AiRubricResource extends Resource
{
    protected static ?string $model = AiRubric::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.ai');
    }

    public static function getModelLabel(): string
    {
        return __('panel.rubrics.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.rubrics.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ai.rubrics.manage') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('ai.rubrics.manage') === true
            && $record instanceof AiRubric
            && ! $record->isPlatformDefault();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canEdit($record);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.rubrics.section.basics'))
                ->schema([
                    Forms\Components\TextInput::make('name')->label(__('panel.rubrics.field.name'))->required()->maxLength(120),
                    Forms\Components\Select::make('task_key')
                        ->label(__('panel.rubrics.field.task'))
                        ->options(fn (): array => collect(AiTaskKey::scoringTasks())
                            ->mapWithKeys(fn (AiTaskKey $t): array => [$t->value => $t->label()])
                            ->all())
                        ->required(),
                    Forms\Components\Toggle::make('is_active')->label(__('panel.common.active'))->default(true),
                ])
                ->columns(3),

            Forms\Components\Section::make(__('panel.rubrics.section.criteria'))
                ->schema([
                    Forms\Components\Repeater::make('criteria')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\TextInput::make('key')
                                ->label(__('panel.rubrics.field.criterion_key'))->required()->maxLength(40),
                            Forms\Components\TextInput::make('label')
                                ->label(__('panel.rubrics.field.criterion_label'))->required()->maxLength(80),
                            Forms\Components\TextInput::make('weight')
                                ->label(__('panel.rubrics.field.weight'))
                                ->numeric()->minValue(0)->maxValue(100)->required()->live(onBlur: true),
                            Forms\Components\Textarea::make('guidance')
                                ->label(__('panel.rubrics.field.guidance'))->rows(2)->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->reorderable()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->live()
                        ->columnSpanFull()
                        ->rules([
                            fn (): callable => static function (string $attribute, mixed $value, callable $fail): void {
                                $total = array_sum(array_map(
                                    static fn (mixed $row): int => is_array($row) ? (int) ($row['weight'] ?? 0) : 0,
                                    (array) $value,
                                ));

                                if ($total !== 100) {
                                    $fail(__('panel.rubrics.error.weights', ['total' => $total]));
                                }
                            },
                        ]),

                    Forms\Components\Placeholder::make('weight_total')
                        ->label(__('panel.rubrics.field.total'))
                        ->content(function (Get $get): string {
                            $total = array_sum(array_map(
                                static fn (mixed $row): int => is_array($row) ? (int) ($row['weight'] ?? 0) : 0,
                                (array) $get('criteria'),
                            ));

                            return $total === 100 ? $total.'% ✅' : $total.'% ⚠️';
                        }),
                ]),

            Forms\Components\Section::make(__('panel.rubrics.section.scale'))
                ->schema([
                    Forms\Components\TextInput::make('scale_min')->label(__('panel.rubrics.field.scale_min'))->numeric()->default(0)->required(),
                    Forms\Components\TextInput::make('scale_max')->label(__('panel.rubrics.field.scale_max'))->numeric()->default(90)->required(),
                    Forms\Components\Select::make('rounding')
                        ->label(__('panel.rubrics.field.rounding'))
                        ->options([
                            AiRubric::ROUNDING_NEAREST => __('panel.rubrics.rounding.nearest'),
                            AiRubric::ROUNDING_FLOOR => __('panel.rubrics.rounding.floor'),
                            AiRubric::ROUNDING_CEIL => __('panel.rubrics.rounding.ceil'),
                            AiRubric::ROUNDING_TENTH => __('panel.rubrics.rounding.tenth'),
                        ])
                        ->default(AiRubric::ROUNDING_NEAREST)
                        ->required(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('panel.rubrics.field.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('task_key')
                    ->label(__('panel.rubrics.field.task'))
                    ->badge()
                    ->formatStateUsing(fn (AiTaskKey $state): string => $state->label()),
                Tables\Columns\TextColumn::make('version')->label(__('panel.prompts.field.version')),
                Tables\Columns\TextColumn::make('criteria')
                    ->label(__('panel.rubrics.field.criteria_count'))
                    ->state(fn (AiRubric $record): int => count($record->criteria ?? [])),
                Tables\Columns\IconColumn::make('is_active')->label(__('panel.common.active'))->boolean(),
                Tables\Columns\IconColumn::make('academy_id')
                    ->label(__('panel.prompts.field.owner'))
                    ->icon(fn (?int $state): string => $state === null ? 'heroicon-o-globe-alt' : 'heroicon-o-building-office'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return AiRubric::query()->visibleTo(TenantContext::id());
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiRubrics::route('/'),
            'create' => Pages\CreateAiRubric::route('/create'),
            'edit' => Pages\EditAiRubric::route('/{record}/edit'),
        ];
    }
}
