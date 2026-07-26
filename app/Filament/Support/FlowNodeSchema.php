<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Telegram\Enums\FlowNodeType;
use App\Domain\Telegram\Support\SafeUrl;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Get;

/**
 * The type-dependent config form of a flow node, mirrored exactly on the keys
 * FlowEngine reads (`setting('text')`, `setting('seconds')`, …) so the editor
 * can only produce config the engine can execute.
 *
 * @see docs/04-telegram-layer.md §6
 */
final class FlowNodeSchema
{
    /**
     * Config keys the engine reads per node type; everything else is pruned at
     * save time so a type switch cannot leave stale settings behind.
     *
     * @var array<string, array<int, string>>
     */
    private const CONFIG_KEYS = [
        'trigger' => [],
        'message' => ['text', 'photo', 'buttons'],
        'question' => ['text', 'variable', 'buttons'],
        'condition' => [],
        'action' => ['action', 'params'],
        'ai' => ['prompt'],
        'delay' => ['seconds'],
        'handoff' => ['note'],
        'webhook' => ['url', 'headers', 'body'],
        'end' => ['text'],
    ];

    /**
     * @return array<int, Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TextInput::make('node_key')
                    ->label(__('panel.flows.field.node_key'))
                    ->required()
                    ->maxLength(64)
                    ->alphaDash()
                    ->distinct()
                    ->live(onBlur: true)
                    ->helperText(__('panel.flows.help.node_key')),
                Forms\Components\Select::make('type')
                    ->label(__('panel.flows.field.node_type'))
                    ->options(fn (): array => collect(FlowNodeType::cases())
                        ->mapWithKeys(fn (FlowNodeType $t): array => [$t->value => $t->label()])
                        ->all())
                    ->default(FlowNodeType::Message->value)
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('title')
                    ->label(__('panel.flows.field.node_title'))
                    ->maxLength(150)
                    ->live(onBlur: true),
            ]),

            ...self::configSchema(),
        ];
    }

    /**
     * Drop config keys the node's type does not read.
     *
     * @param  array<string, mixed>  $data  repeater item state
     * @return array<string, mixed>
     */
    public static function prune(array $data): array
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : ($data['type'] instanceof FlowNodeType ? $data['type']->value : '');
        $allowed = self::CONFIG_KEYS[$type] ?? [];

        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $config = array_intersect_key($config, array_flip($allowed));

        if (isset($config['buttons']) && is_array($config['buttons'])) {
            $config['buttons'] = array_values(array_filter(
                array_map(
                    static fn (mixed $button): array => is_array($button) ? array_filter([
                        'label' => trim((string) ($button['label'] ?? '')),
                        'value' => filled($button['value'] ?? null) ? (string) $button['value'] : null,
                        'url' => filled($button['url'] ?? null) ? (string) $button['url'] : null,
                    ], static fn (mixed $v): bool => $v !== null) : [],
                    $config['buttons'],
                ),
                static fn (array $button): bool => ($button['label'] ?? '') !== '',
            ));
        }

        if (isset($config['seconds'])) {
            $config['seconds'] = max(1, (int) $config['seconds']);
        }

        $data['config'] = array_filter(
            $config,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );

        return $data;
    }

    /**
     * @return array<int, Component>
     */
    private static function configSchema(): array
    {
        return [
            Forms\Components\Textarea::make('config.text')
                ->label(__('panel.flows.field.text'))
                ->rows(3)
                ->live(onBlur: true)
                ->helperText(__('panel.flows.help.placeholders'))
                ->required(fn (Get $get): bool => $get('type') === FlowNodeType::Message->value)
                ->visible(fn (Get $get): bool => in_array($get('type'), [
                    FlowNodeType::Message->value,
                    FlowNodeType::Question->value,
                    FlowNodeType::End->value,
                ], true))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('config.photo')
                ->label(__('panel.flows.field.photo'))
                ->helperText(__('panel.flows.help.photo'))
                ->maxLength(255)
                ->visible(self::isType(FlowNodeType::Message)),

            Forms\Components\TextInput::make('config.variable')
                ->label(__('panel.flows.field.variable'))
                ->alphaDash()
                ->maxLength(64)
                ->helperText(__('panel.flows.help.variable'))
                ->visible(self::isType(FlowNodeType::Question)),

            Forms\Components\Repeater::make('config.buttons')
                ->label(__('panel.flows.field.buttons'))
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->label(__('panel.flows.field.button_label'))
                        ->required()
                        ->maxLength(64),
                    Forms\Components\TextInput::make('value')
                        ->label(__('panel.flows.field.button_value'))
                        ->maxLength(64)
                        ->helperText(__('panel.flows.help.button_value')),
                    Forms\Components\TextInput::make('url')
                        ->label(__('panel.flows.field.button_url'))
                        ->url()
                        ->maxLength(255),
                ])
                ->columns(3)
                ->defaultItems(0)
                ->reorderableWithButtons()
                ->visible(fn (Get $get): bool => in_array($get('type'), [
                    FlowNodeType::Message->value,
                    FlowNodeType::Question->value,
                ], true))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('config.action')
                ->label(__('panel.flows.field.action'))
                ->maxLength(64)
                ->required(self::isType(FlowNodeType::Action))
                ->helperText(__('panel.flows.help.action'))
                ->visible(self::isType(FlowNodeType::Action)),

            Forms\Components\KeyValue::make('config.params')
                ->label(__('panel.flows.field.params'))
                ->visible(self::isType(FlowNodeType::Action))
                ->columnSpanFull(),

            Forms\Components\Textarea::make('config.prompt')
                ->label(__('panel.flows.field.prompt'))
                ->rows(3)
                ->required(self::isType(FlowNodeType::Ai))
                ->helperText(__('panel.flows.help.prompt'))
                ->visible(self::isType(FlowNodeType::Ai))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('config.seconds')
                ->label(__('panel.flows.field.seconds'))
                ->numeric()
                ->minValue(1)
                ->default(60)
                ->required(self::isType(FlowNodeType::Delay))
                ->visible(self::isType(FlowNodeType::Delay)),

            Forms\Components\Textarea::make('config.note')
                ->label(__('panel.flows.field.note'))
                ->rows(2)
                ->visible(self::isType(FlowNodeType::Handoff))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('config.url')
                ->label(__('panel.flows.field.url'))
                ->url()
                ->required(self::isType(FlowNodeType::Webhook))
                ->helperText(__('panel.flows.help.webhook_url'))
                ->rules([
                    static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && $value !== '' && ! SafeUrl::isSafe($value)) {
                            $fail(__('api.errors.webhook_url_rejected'));
                        }
                    },
                ])
                ->visible(self::isType(FlowNodeType::Webhook))
                ->columnSpanFull(),

            Forms\Components\KeyValue::make('config.headers')
                ->label(__('panel.flows.field.headers'))
                ->visible(self::isType(FlowNodeType::Webhook)),

            Forms\Components\KeyValue::make('config.body')
                ->label(__('panel.flows.field.body'))
                ->visible(self::isType(FlowNodeType::Webhook)),
        ];
    }

    private static function isType(FlowNodeType $type): Closure
    {
        return static fn (Get $get): bool => $get('type') === $type->value;
    }
}
