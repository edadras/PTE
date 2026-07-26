<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Models\AiModel;
use App\Filament\Platform\Resources\AiModelResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The model catalogue and its prices — the numbers every cost report multiplies
 * by, so they are edited here and nowhere else.
 *
 * @see docs/06-ai-layer.md §7.1
 */
final class AiModelResource extends Resource
{
    protected static ?string $model = AiModel::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('panel.ai_models.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.ai_models.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('platform.ai.manage') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.ai_models.section.identity'))
                ->schema([
                    Forms\Components\Select::make('provider')
                        ->label(__('panel.ai_models.field.provider'))
                        ->options(fn (): array => collect(AiProvider::cases())
                            ->mapWithKeys(fn (AiProvider $p): array => [$p->value => $p->label()])
                            ->all())
                        ->required(),
                    Forms\Components\TextInput::make('model_key')
                        ->label(__('panel.ai_models.field.model_key'))
                        ->required()
                        ->maxLength(120),
                    Forms\Components\TextInput::make('display_name')
                        ->label(__('panel.ai_models.field.display_name'))
                        ->required()
                        ->maxLength(120),
                    Forms\Components\Toggle::make('is_active')->label(__('panel.common.active'))->default(true),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.ai_models.section.pricing'))
                ->description(__('panel.ai_models.help.pricing'))
                ->schema([
                    Forms\Components\TextInput::make('input_price_per_1m')
                        ->label(__('panel.ai_models.field.input_price'))
                        ->numeric()->step('0.000001')->default(0)->required(),
                    Forms\Components\TextInput::make('output_price_per_1m')
                        ->label(__('panel.ai_models.field.output_price'))
                        ->numeric()->step('0.000001')->default(0)->required(),
                    Forms\Components\TextInput::make('price_per_audio_minute')
                        ->label(__('panel.ai_models.field.audio_price'))
                        ->numeric()->step('0.000001'),
                    Forms\Components\TextInput::make('currency')
                        ->label(__('panel.plans.field.currency'))
                        ->default('USD')->maxLength(3),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.ai_models.section.capabilities'))
                ->schema([
                    Forms\Components\TextInput::make('max_input_tokens')->label(__('panel.ai_models.field.max_input'))->numeric(),
                    Forms\Components\TextInput::make('max_output_tokens')->label(__('panel.ai_models.field.max_output'))->numeric(),
                    Forms\Components\CheckboxList::make('is_default_for')
                        ->label(__('panel.ai_models.field.default_for'))
                        ->options(fn (): array => collect(AiTaskKey::cases())
                            ->mapWithKeys(fn (AiTaskKey $t): array => [$t->value => $t->label()])
                            ->all())
                        ->columns(2)
                        ->columnSpanFull(),
                    Forms\Components\KeyValue::make('capabilities')
                        ->label(__('panel.ai_models.field.capabilities'))
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('sort_order')->label(__('panel.common.sort_order'))->numeric()->default(0),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')->label(__('panel.ai_models.field.display_name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('provider')
                    ->label(__('panel.ai_models.field.provider'))
                    ->badge()
                    ->formatStateUsing(fn (AiProvider $state): string => $state->label()),
                Tables\Columns\TextColumn::make('model_key')->label(__('panel.ai_models.field.model_key'))->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('input_price_per_1m')
                    ->label(__('panel.ai_models.field.input_price'))
                    ->formatStateUsing(fn (mixed $state): string => '$'.number_format((float) $state, 4)),
                Tables\Columns\TextColumn::make('output_price_per_1m')
                    ->label(__('panel.ai_models.field.output_price'))
                    ->formatStateUsing(fn (mixed $state): string => '$'.number_format((float) $state, 4)),
                Tables\Columns\TextColumn::make('price_per_audio_minute')
                    ->label(__('panel.ai_models.field.audio_price'))
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : '$'.number_format((float) $state, 4)),
                Tables\Columns\IconColumn::make('is_active')->label(__('panel.common.active'))->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->label(__('panel.ai_models.field.provider'))
                    ->options(fn (): array => collect(AiProvider::cases())
                        ->mapWithKeys(fn (AiProvider $p): array => [$p->value => $p->label()])
                        ->all()),
                Tables\Filters\TernaryFilter::make('is_active')->label(__('panel.common.active')),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAiModels::route('/'),
        ];
    }
}
