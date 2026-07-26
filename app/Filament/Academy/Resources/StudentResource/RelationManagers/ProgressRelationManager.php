<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\StudentResource\RelationManagers;

use App\Domain\Learning\Enums\QuestionType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The materialised per-question-type aggregates the bot and the report card
 * read; shown read-only, since they are derived data.
 */
final class ProgressRelationManager extends RelationManager
{
    protected static string $relationship = 'progress';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('panel.students.progress');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('question_type')
                    ->label(__('panel.students.field.question_type'))
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : (QuestionType::tryFrom($state)?->label() ?? $state)),
                Tables\Columns\TextColumn::make('attempts')->label(__('panel.students.field.attempts'))->sortable(),
                Tables\Columns\TextColumn::make('avg_score')
                    ->label(__('panel.students.field.avg_score'))
                    ->numeric(1)
                    ->sortable(),
                Tables\Columns\TextColumn::make('best_score')->label(__('panel.students.field.best_score'))->numeric(1),
                Tables\Columns\TextColumn::make('last_score')->label(__('panel.students.field.last_score'))->numeric(1),
                Tables\Columns\TextColumn::make('streak_days')->label(__('panel.students.field.streak')),
                Tables\Columns\TextColumn::make('last_practiced_at')->label(__('panel.students.field.last_practiced'))->dateTime()->placeholder('—'),
            ])
            ->paginated([25, 50])
            ->defaultSort('attempts', 'desc');
    }
}
