<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\CourseResource\RelationManagers;

use App\Domain\Learning\Enums\CourseStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class LessonsRelationManager extends RelationManager
{
    protected static string $relationship = 'lessons';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('panel.lessons.plural');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label(__('panel.lessons.field.title'))->required()->maxLength(150),
            Forms\Components\Select::make('status')
                ->label(__('panel.common.status'))
                ->options(fn (): array => collect(CourseStatus::cases())
                    ->mapWithKeys(fn (CourseStatus $c): array => [$c->value => $c->label()])
                    ->all())
                ->default(CourseStatus::Draft->value),
            Forms\Components\TextInput::make('duration_minutes')->label(__('panel.lessons.field.duration'))->numeric(),
            Forms\Components\TextInput::make('sort_order')->label(__('panel.common.sort_order'))->numeric()->default(0),
            Forms\Components\Toggle::make('is_free')->label(__('panel.lessons.field.is_free')),
            Forms\Components\FileUpload::make('media_path')
                ->label(__('panel.lessons.field.media'))
                ->disk('tenant')
                ->directory('lessons')
                ->visibility('private'),
            Forms\Components\RichEditor::make('content.body')
                ->label(__('panel.lessons.field.body'))
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label(__('panel.lessons.field.title'))->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (CourseStatus $state): string => $state->label()),
                Tables\Columns\IconColumn::make('is_free')->label(__('panel.lessons.field.is_free'))->boolean(),
                Tables\Columns\TextColumn::make('duration_minutes')->label(__('panel.lessons.field.duration'))->placeholder('—'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('sort_order');
    }
}
