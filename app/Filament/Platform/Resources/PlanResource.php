<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Domain\Commerce\Enums\UsageMetric;
use App\Domain\Commerce\Models\Plan;
use App\Filament\Platform\Resources\PlanResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The plan catalogue: price, limits, feature flags.
 *
 * @see docs/09-billing-and-plans.md §1
 */
final class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('panel.plans.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.plans.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('platform.plans.manage') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.plans.section.identity'))
                ->schema([
                    Forms\Components\TextInput::make('key')->label(__('panel.plans.field.key'))->required()->maxLength(40),
                    Forms\Components\TextInput::make('name')->label(__('panel.plans.field.name'))->required()->maxLength(100),
                    Forms\Components\TextInput::make('description')->label(__('panel.plans.field.description'))->maxLength(255)->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.plans.section.pricing'))
                ->schema([
                    Forms\Components\TextInput::make('price_monthly')
                        ->label(__('panel.plans.field.price_monthly'))
                        ->numeric()
                        ->helperText(__('panel.plans.help.minor_units')),
                    Forms\Components\TextInput::make('price_yearly')
                        ->label(__('panel.plans.field.price_yearly'))
                        ->numeric(),
                    Forms\Components\TextInput::make('currency')
                        ->label(__('panel.plans.field.currency'))
                        ->default('IRR')
                        ->maxLength(3),
                ])
                ->columns(3),

            Forms\Components\Section::make(__('panel.plans.section.limits'))
                ->description(__('panel.plans.help.limits'))
                ->schema([
                    Forms\Components\Grid::make(3)->schema(
                        array_map(
                            static fn (UsageMetric $metric): Forms\Components\TextInput => Forms\Components\TextInput::make('limits.'.$metric->value)
                                ->label($metric->label())
                                ->numeric()
                                ->placeholder(__('panel.plans.unlimited')),
                            UsageMetric::cases(),
                        )
                    ),
                ]),

            Forms\Components\Section::make(__('panel.plans.section.features'))
                ->schema([
                    Forms\Components\KeyValue::make('features')
                        ->label(__('panel.plans.field.features'))
                        ->keyLabel(__('panel.common.key'))
                        ->valueLabel(__('panel.common.value')),
                ]),

            Forms\Components\Section::make(__('panel.plans.section.visibility'))
                ->schema([
                    Forms\Components\Toggle::make('is_public')->label(__('panel.plans.field.is_public'))->default(true),
                    Forms\Components\Toggle::make('is_active')->label(__('panel.plans.field.is_active'))->default(true),
                    Forms\Components\TextInput::make('sort_order')->label(__('panel.common.sort_order'))->numeric()->default(0),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('panel.plans.field.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('key')->label(__('panel.plans.field.key'))->badge(),
                Tables\Columns\TextColumn::make('price_monthly')
                    ->label(__('panel.plans.field.price_monthly'))
                    ->formatStateUsing(fn (?int $state, Plan $record): string => $state === null
                        ? __('panel.plans.negotiated')
                        : number_format($state).' '.$record->currency),
                Tables\Columns\TextColumn::make('subscriptions_count')
                    ->label(__('panel.plans.field.subscribers')),
                Tables\Columns\IconColumn::make('is_public')->label(__('panel.plans.field.is_public'))->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label(__('panel.plans.field.is_active'))->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    /**
     * `subscriptions` is tenant-scoped, so withCount() would trip the global
     * scope in a panel that has no tenant. A plain subquery is the honest way
     * to count across academies here.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->addSelect([
            'subscriptions_count' => DB::table('subscriptions')
                ->selectRaw('COUNT(*)')
                ->whereColumn('subscriptions.plan_id', 'plans.id'),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePlans::route('/'),
        ];
    }
}
