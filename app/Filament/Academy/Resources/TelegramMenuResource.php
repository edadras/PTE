<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Assessment\Models\Exam;
use App\Domain\Commerce\Models\Plan;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Course;
use App\Domain\Telegram\Enums\MenuActionType;
use App\Domain\Telegram\Enums\MenuType;
use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Telegram\Models\TelegramFlow;
use App\Domain\Telegram\Models\TelegramMenu;
use App\Domain\Telegram\Support\VisibilityRule;
use App\Filament\Academy\Resources\TelegramMenuResource\Pages;
use App\Filament\Support\MenuPreview;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The menu builder of docs/04 §5: reorderable items, per-action payload forms,
 * visibility rules, and a live keyboard preview beside the list.
 */
final class TelegramMenuResource extends Resource
{
    protected static ?string $model = TelegramMenu::class;

    protected static ?string $navigationIcon = 'heroicon-o-bars-3';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.telegram');
    }

    public static function getModelLabel(): string
    {
        return __('panel.menus.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.menus.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('telegram.menu.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('telegram.menu.update') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('telegram.menu.update') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.menus.section.settings'))
                ->schema([
                    Forms\Components\TextInput::make('name')->label(__('panel.menus.field.name'))->required()->maxLength(100),
                    Forms\Components\Select::make('type')
                        ->label(__('panel.menus.field.type'))
                        ->options(fn (): array => collect(MenuType::cases())
                            ->mapWithKeys(fn (MenuType $t): array => [$t->value => $t->label()])
                            ->all())
                        ->default(MenuType::Main->value)
                        ->required(),
                    Forms\Components\Placeholder::make('status_hint')
                        ->label(__('panel.common.status'))
                        ->content(fn (?TelegramMenu $record): string => $record === null
                            ? __('telegram.publish_status.draft')
                            : sprintf('%s · v%d', $record->status->label(), $record->version)),
                ])
                ->columns(3),

            Forms\Components\Grid::make(['default' => 1, 'lg' => 3])
                ->schema([
                    Forms\Components\Section::make(__('panel.menus.section.items'))
                        ->schema([
                            Forms\Components\Repeater::make('items')
                                ->hiddenLabel()
                                ->relationship()
                                ->orderColumn('sort_order')
                                ->reorderable()
                                ->reorderableWithButtons()
                                ->collapsible()
                                ->cloneable()
                                ->live()
                                ->itemLabel(fn (array $state): ?string => MenuPreview::buttonText($state) ?: null)
                                ->schema(self::itemSchema())
                                ->columnSpanFull(),
                        ])
                        ->columnSpan(['default' => 1, 'lg' => 2]),

                    Forms\Components\Section::make(__('panel.menus.section.preview'))
                        ->schema([
                            Forms\Components\Placeholder::make('keyboard_preview')
                                ->hiddenLabel()
                                ->content(fn (Get $get) => view('filament.academy.forms.menu-preview', [
                                    'rows' => MenuPreview::rows(is_array($get('items')) ? $get('items') : []),
                                ])),
                        ])
                        ->columnSpan(1),
                ]),
        ]);
    }

    /**
     * @return array<int, Component>
     */
    private static function itemSchema(): array
    {
        return [
            Forms\Components\Toggle::make('is_enabled')
                ->label(__('panel.menus.field.is_enabled'))
                ->default(true)
                ->live(),
            Forms\Components\TextInput::make('icon')->label(__('panel.menus.field.icon'))->maxLength(8)->live(onBlur: true),
            Forms\Components\TextInput::make('label')->label(__('panel.menus.field.label'))->required()->maxLength(64)->live(onBlur: true),

            Forms\Components\Select::make('action_type')
                ->label(__('panel.menus.field.action_type'))
                ->options(fn (): array => collect(MenuActionType::cases())
                    ->mapWithKeys(fn (MenuActionType $a): array => [$a->value => $a->label()])
                    ->all())
                ->default(MenuActionType::RunCommand->value)
                ->required()
                ->live(),

            Forms\Components\TextInput::make('row')->label(__('panel.menus.field.row'))->numeric()->default(0)->live(onBlur: true),
            Forms\Components\TextInput::make('column')->label(__('panel.menus.field.column'))->numeric()->default(0)->live(onBlur: true),

            ...self::payloadSchema(),

            Forms\Components\Textarea::make('visibility_rule')
                ->label(__('panel.menus.field.visibility_rule'))
                ->helperText(__('panel.menus.help.visibility_rule'))
                ->rows(3)
                ->columnSpanFull()
                ->formatStateUsing(fn (mixed $state): ?string => is_array($state)
                    ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    : $state)
                ->dehydrateStateUsing(fn (?string $state): ?array => self::decodeRule($state))
                ->rules([
                    fn (): callable => static function (string $attribute, mixed $value, callable $fail): void {
                        if (blank($value)) {
                            return;
                        }

                        $decoded = json_decode((string) $value, true);

                        if (! is_array($decoded)) {
                            $fail(__('panel.menus.error.invalid_json'));

                            return;
                        }

                        $unsupported = VisibilityRule::unsupportedKeys($decoded);

                        if ($unsupported !== []) {
                            $fail(__('panel.menus.error.unsupported_keys', ['keys' => implode(', ', $unsupported)]));
                        }
                    },
                ]),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private static function payloadSchema(): array
    {
        return [
            Forms\Components\TextInput::make('action_payload.url')
                ->label(__('panel.menus.payload.url'))->url()->required()
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::OpenUrl->value),

            Forms\Components\Textarea::make('action_payload.text')
                ->label(__('panel.menus.payload.text'))->required()->rows(3)->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::SendMessage->value),

            Forms\Components\Select::make('action_payload.module')
                ->label(__('panel.menus.payload.module'))
                ->options(fn (): array => collect(ModuleKey::cases())
                    ->mapWithKeys(fn (ModuleKey $m): array => [$m->value => $m->label()])
                    ->all())
                ->required()
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::OpenModule->value),

            Forms\Components\Select::make('action_payload.type')
                ->label(__('panel.menus.payload.practice_type'))
                ->options(fn (): array => collect(QuestionType::cases())
                    ->mapWithKeys(fn (QuestionType $t): array => [$t->value => $t->value.' — '.$t->label()])
                    ->all())
                ->required()
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::StartPractice->value),

            Forms\Components\TextInput::make('action_payload.count')
                ->label(__('panel.menus.payload.count'))->numeric()->default(5)
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::StartPractice->value),

            Forms\Components\Select::make('action_payload.exam_id')
                ->label(__('panel.menus.payload.exam'))
                ->options(fn (): array => Exam::query()->orderByDesc('id')->pluck('title', 'id')->all())
                ->required()
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::StartExam->value),

            Forms\Components\Select::make('action_payload.course_id')
                ->label(__('panel.menus.payload.course'))
                ->options(fn (): array => Course::query()->orderBy('title')->pluck('title', 'id')->all())
                ->required()
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::OpenCourse->value),

            Forms\Components\Select::make('action_payload.plan_id')
                ->label(__('panel.menus.payload.plan'))
                ->options(fn (): array => Plan::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                ->required()
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::BuyPlan->value),

            Forms\Components\Select::make('action_payload.flow_id')
                ->label(__('panel.menus.payload.flow'))
                ->options(fn (): array => TelegramFlow::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::RunFlow->value),

            Forms\Components\TextInput::make('action_payload.path')
                ->label(__('panel.menus.payload.path'))->required()
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::OpenWebapp->value),

            Forms\Components\Select::make('action_payload.command')
                ->label(__('panel.menus.payload.command'))
                ->options([
                    'my_scores' => __('panel.menus.command.my_scores'),
                    'my_progress' => __('panel.menus.command.my_progress'),
                    'leaderboard' => __('panel.menus.command.leaderboard'),
                ])
                ->required()
                ->visible(fn (Get $get): bool => $get('action_type') === MenuActionType::RunCommand->value),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeRule(?string $state): ?array
    {
        if (blank($state)) {
            return null;
        }

        $decoded = json_decode($state, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('panel.menus.field.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('panel.menus.field.type'))
                    ->badge()
                    ->formatStateUsing(fn (MenuType $state): string => $state->label()),
                Tables\Columns\TextColumn::make('version')->label(__('panel.menus.field.version')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (PublishStatus $state): string => $state->label())
                    ->color(fn (PublishStatus $state): string => $state->isLive() ? 'success' : 'gray'),
                Tables\Columns\IconColumn::make('is_active')->label(__('panel.menus.field.is_active'))->boolean(),
                Tables\Columns\TextColumn::make('items_count')->label(__('panel.menus.field.items'))->counts('items'),
                Tables\Columns\TextColumn::make('published_at')->label(__('panel.menus.field.published_at'))->dateTime()->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramMenus::route('/'),
            'create' => Pages\CreateTelegramMenu::route('/create'),
            'edit' => Pages\EditTelegramMenu::route('/{record}/edit'),
        ];
    }
}
