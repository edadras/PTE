<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\ClassGroup;
use App\Domain\Telegram\Actions\StartBroadcast;
use App\Domain\Telegram\Enums\BroadcastStatus;
use App\Domain\Telegram\Models\Broadcast;
use App\Filament\Academy\Resources\BroadcastResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Broadcasts with an audience filter and live progress (docs/04 §7).
 */
final class BroadcastResource extends Resource
{
    protected static ?string $model = Broadcast::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.telegram');
    }

    public static function getModelLabel(): string
    {
        return __('panel.broadcasts.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.broadcasts.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('telegram.broadcast.send') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Broadcast
            && ! $record->status->isFinished()
            && $record->status !== BroadcastStatus::Running;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.broadcasts.section.message'))
                ->schema([
                    Forms\Components\TextInput::make('title')->label(__('panel.broadcasts.field.title'))->required()->maxLength(150),
                    Forms\Components\Select::make('content.parse_mode')
                        ->label(__('panel.broadcasts.field.parse_mode'))
                        ->options([
                            'MarkdownV2' => 'MarkdownV2',
                            'HTML' => 'HTML',
                            '' => __('panel.broadcasts.plain'),
                        ])
                        ->default('HTML'),
                    Forms\Components\Textarea::make('content.text')
                        ->label(__('panel.broadcasts.field.text'))
                        ->required()
                        ->rows(6)
                        ->maxLength(4000)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.broadcasts.section.audience'))
                ->schema([
                    Forms\Components\Select::make('audience_filter.status')
                        ->label(__('panel.students.field.status'))
                        ->options(StudentStatus::options())
                        ->default(StudentStatus::Active->value),
                    Forms\Components\Select::make('audience_filter.subscription')
                        ->label(__('panel.broadcasts.field.subscription'))
                        ->options([
                            'any' => __('panel.broadcasts.subscription.any'),
                            'active' => __('panel.broadcasts.subscription.active'),
                            'expired' => __('panel.broadcasts.subscription.expired'),
                        ])
                        ->default('any'),
                    Forms\Components\Select::make('audience_filter.class_group_ids')
                        ->label(__('panel.class_groups.plural'))
                        ->multiple()
                        ->options(fn (): array => ClassGroup::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Forms\Components\TextInput::make('audience_filter.level')
                        ->label(__('panel.students.field.level')),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.broadcasts.section.schedule'))
                ->schema([
                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label(__('panel.broadcasts.field.scheduled_at'))
                        ->helperText(__('panel.broadcasts.help.scheduled_at')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label(__('panel.broadcasts.field.title'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (BroadcastStatus $state): string => $state->label())
                    ->color(fn (BroadcastStatus $state): string => match ($state) {
                        BroadcastStatus::Completed => 'success',
                        BroadcastStatus::Running => 'info',
                        BroadcastStatus::Failed, BroadcastStatus::Cancelled => 'danger',
                        BroadcastStatus::Scheduled => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('progress')
                    ->label(__('panel.broadcasts.field.progress'))
                    ->state(fn (Broadcast $record): string => sprintf(
                        '%d%% · %d/%d',
                        $record->progressPercent(),
                        $record->sent,
                        $record->total,
                    )),
                Tables\Columns\TextColumn::make('failed')->label(__('panel.broadcasts.field.failed'))->toggleable(),
                Tables\Columns\TextColumn::make('blocked')->label(__('panel.broadcasts.field.blocked'))->toggleable(),
                Tables\Columns\TextColumn::make('scheduled_at')->label(__('panel.broadcasts.field.scheduled_at'))->dateTime()->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('start')
                    ->label(__('panel.broadcasts.action.start'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Broadcast $record): bool => ! $record->status->isFinished()
                        && $record->status !== BroadcastStatus::Running)
                    ->action(function (Broadcast $record): void {
                        try {
                            app(StartBroadcast::class)->handle($record);

                            Notification::make()->success()->title(__('panel.broadcasts.notify.started'))->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('panel.broadcasts.notify.failed'))->body($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label(__('panel.broadcasts.action.cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Broadcast $record): bool => $record->status->canBeCancelled())
                    ->action(function (Broadcast $record): void {
                        try {
                            app(StartBroadcast::class)->cancel($record);

                            Notification::make()->warning()->title(__('panel.broadcasts.notify.cancelled'))->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('panel.broadcasts.notify.failed'))->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBroadcasts::route('/'),
            'create' => Pages\CreateBroadcast::route('/create'),
            'edit' => Pages\EditBroadcast::route('/{record}/edit'),
        ];
    }
}
