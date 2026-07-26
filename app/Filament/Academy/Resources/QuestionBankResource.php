<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Learning\Actions\CloneQuestionBank;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Models\QuestionBank;
use App\Filament\Academy\Resources\QuestionBankResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class QuestionBankResource extends Resource
{
    protected static ?string $model = QuestionBank::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.content');
    }

    public static function getModelLabel(): string
    {
        return __('panel.question_banks.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.question_banks.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('questions.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('question_banks.manage') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('panel.question_banks.field.name'))->required()->maxLength(120),
            Forms\Components\Select::make('module_key')
                ->label(__('panel.common.module'))
                ->options(fn (): array => collect(ModuleKey::cases())
                    ->mapWithKeys(fn (ModuleKey $m): array => [$m->value => $m->icon().' '.$m->label()])
                    ->all()),
            Forms\Components\Toggle::make('is_default')->label(__('panel.question_banks.field.is_default')),
            Forms\Components\Textarea::make('description')->label(__('panel.common.description'))->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('panel.question_banks.field.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('module_key')
                    ->label(__('panel.common.module'))
                    ->badge()
                    ->formatStateUsing(fn (?ModuleKey $state): string => $state?->label() ?? '—'),
                Tables\Columns\TextColumn::make('question_count')->label(__('panel.question_banks.field.questions'))->sortable(),
                Tables\Columns\IconColumn::make('is_default')->label(__('panel.question_banks.field.is_default'))->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('clone')
                    ->label(__('panel.question_banks.action.clone'))
                    ->icon('heroicon-o-document-duplicate')
                    ->visible(fn (): bool => auth()->user()?->can('question_banks.manage') === true)
                    ->form([
                        Forms\Components\TextInput::make('name')->label(__('panel.question_banks.field.name')),
                        Forms\Components\Toggle::make('only_published')
                            ->label(__('panel.question_banks.field.only_published'))
                            ->default(false),
                    ])
                    ->action(function (QuestionBank $record, array $data): void {
                        app(CloneQuestionBank::class)->handle(
                            $record,
                            $data['name'] ?: null,
                            null,
                            (bool) $data['only_published'],
                        );

                        Notification::make()->success()->title(__('panel.question_banks.notify.cloned'))->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageQuestionBanks::route('/'),
        ];
    }
}
