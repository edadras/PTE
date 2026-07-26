<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Telegram\Models\TelegramFlow;
use App\Filament\Academy\Resources\TelegramFlowResource\Pages;
use App\Filament\Support\FlowNodeSchema;
use App\Filament\Support\FlowPreview;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The flow builder of docs/04 §6: nodes and edges as repeaters, a text-tree
 * preview instead of a canvas, and publishing routed through PublishFlow so
 * every structural check (cycles, dangling edges, unsafe webhooks) runs before
 * a student can hit the graph.
 */
final class TelegramFlowResource extends Resource
{
    protected static ?string $model = TelegramFlow::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-pointing-out';

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.telegram');
    }

    public static function getModelLabel(): string
    {
        return __('panel.flows.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.flows.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('telegram.flow.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('telegram.flow.update') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('telegram.flow.update') === true;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('telegram.flow.update') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.flows.section.settings'))
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('panel.flows.field.name'))
                        ->required()
                        ->maxLength(150),
                    Forms\Components\Select::make('trigger_type')
                        ->label(__('panel.flows.field.trigger_type'))
                        ->options([
                            'command' => __('panel.flows.trigger.command'),
                            'start' => __('panel.flows.trigger.start'),
                            'menu' => __('panel.flows.trigger.menu'),
                            'schedule' => __('panel.flows.trigger.schedule'),
                        ])
                        ->default('command')
                        ->required()
                        ->live(),
                    Forms\Components\TextInput::make('trigger_config.command')
                        ->label(__('panel.flows.field.trigger_command'))
                        ->prefix('/')
                        ->alphaDash()
                        ->maxLength(32)
                        ->visible(fn (Get $get): bool => $get('trigger_type') === 'command'),
                    Forms\Components\Placeholder::make('status_hint')
                        ->label(__('panel.common.status'))
                        ->content(fn (?TelegramFlow $record): string => $record === null
                            ? __('telegram.publish_status.draft')
                            : sprintf('%s · v%d', $record->status->label(), $record->version)),
                    Forms\Components\Textarea::make('description')
                        ->label(__('panel.common.description'))
                        ->rows(2)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('entry_node_key')
                        ->label(__('panel.flows.field.entry_node'))
                        ->options(fn (Get $get): array => self::nodeKeyOptions($get('nodes')))
                        ->helperText(__('panel.flows.help.entry_node')),
                    Forms\Components\TextInput::make('max_hops')
                        ->label(__('panel.flows.field.max_hops'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(500)
                        ->helperText(__('panel.flows.help.max_hops')),
                    Forms\Components\TextInput::make('wait_timeout_seconds')
                        ->label(__('panel.flows.field.wait_timeout'))
                        ->numeric()
                        ->minValue(60)
                        ->helperText(__('panel.flows.help.wait_timeout')),
                ])
                ->columns(3),

            Forms\Components\Grid::make(['default' => 1, 'xl' => 3])
                ->schema([
                    Forms\Components\Group::make()
                        ->schema([
                            Forms\Components\Section::make(__('panel.flows.section.nodes'))
                                ->description(__('panel.flows.help.nodes'))
                                ->schema([
                                    Forms\Components\Repeater::make('nodes')
                                        ->hiddenLabel()
                                        ->relationship()
                                        ->collapsible()
                                        ->cloneable()
                                        ->live()
                                        ->itemLabel(fn (array $state): ?string => filled($state['node_key'] ?? null)
                                            ? sprintf('%s · %s', (string) $state['node_key'], (string) ($state['type'] ?? ''))
                                            : null)
                                        ->schema(FlowNodeSchema::schema())
                                        ->mutateRelationshipDataBeforeCreateUsing(
                                            fn (array $data): array => FlowNodeSchema::prune($data)
                                        )
                                        ->mutateRelationshipDataBeforeSaveUsing(
                                            fn (array $data): array => FlowNodeSchema::prune($data)
                                        )
                                        ->columnSpanFull(),
                                ]),

                            Forms\Components\Section::make(__('panel.flows.section.edges'))
                                ->description(__('panel.flows.help.edges'))
                                ->schema([
                                    Forms\Components\Repeater::make('edges')
                                        ->hiddenLabel()
                                        ->relationship()
                                        ->orderColumn('sort_order')
                                        ->reorderable()
                                        ->reorderableWithButtons()
                                        ->collapsible()
                                        ->live()
                                        ->itemLabel(fn (array $state): ?string => filled($state['from_node'] ?? null)
                                            ? sprintf('%s → %s', (string) $state['from_node'], (string) ($state['to_node'] ?? '?'))
                                            : null)
                                        ->schema(self::edgeSchema())
                                        ->mutateRelationshipDataBeforeCreateUsing(
                                            fn (array $data): array => self::pruneEdge($data)
                                        )
                                        ->mutateRelationshipDataBeforeSaveUsing(
                                            fn (array $data): array => self::pruneEdge($data)
                                        )
                                        ->columnSpanFull(),
                                ]),
                        ])
                        ->columnSpan(['default' => 1, 'xl' => 2]),

                    Forms\Components\Section::make(__('panel.flows.section.preview'))
                        ->schema([
                            Forms\Components\Placeholder::make('graph_preview')
                                ->hiddenLabel()
                                ->content(fn (Get $get) => view('filament.academy.forms.flow-preview', [
                                    'graph' => FlowPreview::tree(
                                        is_array($get('nodes')) ? $get('nodes') : [],
                                        is_array($get('edges')) ? $get('edges') : [],
                                        filled($get('entry_node_key')) ? (string) $get('entry_node_key') : null,
                                    ),
                                ])),
                        ])
                        ->columnSpan(1),
                ]),
        ]);
    }

    /**
     * @return array<int, Component>
     */
    private static function edgeSchema(): array
    {
        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('from_node')
                    ->label(__('panel.flows.field.from_node'))
                    ->options(fn (Get $get): array => self::nodeKeyOptions($get('../../nodes')))
                    ->required()
                    ->live(),
                Forms\Components\Select::make('to_node')
                    ->label(__('panel.flows.field.to_node'))
                    ->options(fn (Get $get): array => self::nodeKeyOptions($get('../../nodes')))
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('label')
                    ->label(__('panel.flows.field.edge_label'))
                    ->maxLength(120)
                    ->live(onBlur: true),
            ]),
            Forms\Components\Grid::make(4)->schema([
                Forms\Components\TextInput::make('condition.var')
                    ->label(__('panel.flows.field.condition_var'))
                    ->maxLength(64)
                    ->helperText(__('panel.flows.help.condition_var'))
                    ->live(onBlur: true),
                Forms\Components\Select::make('condition.op')
                    ->label(__('panel.flows.field.condition_op'))
                    ->options(self::operatorOptions())
                    ->default('eq'),
                Forms\Components\TextInput::make('condition.value')
                    ->label(__('panel.flows.field.condition_value'))
                    ->maxLength(190)
                    ->live(onBlur: true),
                Forms\Components\Toggle::make('is_default')
                    ->label(__('panel.flows.field.is_default'))
                    ->helperText(__('panel.flows.help.is_default'))
                    ->inline(false)
                    ->live(),
            ]),
        ];
    }

    /**
     * An edge whose condition has no variable is unconditional; persisting the
     * half-empty array would make ConditionEvaluator fail it forever.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function pruneEdge(array $data): array
    {
        $var = data_get($data, 'condition.var');

        if (blank($var) || ($data['is_default'] ?? false)) {
            $data['condition'] = null;
        } else {
            $data['condition'] = [
                'var' => (string) $var,
                'op' => (string) data_get($data, 'condition.op', 'eq'),
                'value' => data_get($data, 'condition.value'),
            ];
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private static function operatorOptions(): array
    {
        $operators = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'empty', 'not_empty', 'exists'];

        $options = [];

        foreach ($operators as $operator) {
            $options[$operator] = __('panel.flows.op.'.$operator);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function nodeKeyOptions(mixed $nodes): array
    {
        if (! is_array($nodes)) {
            return [];
        }

        $options = [];

        foreach ($nodes as $node) {
            if (is_array($node) && filled($node['node_key'] ?? null)) {
                $key = (string) $node['node_key'];
                $options[$key] = $key;
            }
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('panel.flows.field.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('trigger_type')
                    ->label(__('panel.flows.field.trigger_type'))
                    ->badge(),
                Tables\Columns\TextColumn::make('version')
                    ->label(__('panel.flows.field.version'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (PublishStatus $state): string => $state->label())
                    ->color(fn (PublishStatus $state): string => $state->isLive() ? 'success' : 'gray'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('panel.flows.field.is_active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('nodes_count')
                    ->label(__('panel.flows.field.nodes'))
                    ->counts('nodes'),
                Tables\Columns\TextColumn::make('published_at')
                    ->label(__('panel.flows.field.published_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('panel.common.status'))
                    ->options(fn (): array => collect(PublishStatus::cases())
                        ->mapWithKeys(fn (PublishStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->groups([
                Group::make('name')->label(__('panel.flows.field.name'))->collapsible(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramFlows::route('/'),
            'create' => Pages\CreateTelegramFlow::route('/create'),
            'edit' => Pages\EditTelegramFlow::route('/{record}/edit'),
        ];
    }
}
