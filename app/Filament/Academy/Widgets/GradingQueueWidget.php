<?php

declare(strict_types=1);

namespace App\Filament\Academy\Widgets;

use App\Domain\Assessment\Enums\ScoringStatus;
use App\Domain\Assessment\Models\Answer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the teacher has to look at right now.
 */
final class GradingQueueWidget extends TableWidget
{
    protected static ?int $sort = -80;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('answers.grade') === true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('panel.stats.grading_queue'))
            ->query(fn (): Builder => Answer::query()
                ->with(['student', 'question'])
                ->whereIn('scoring_status', [
                    ScoringStatus::ManualReview->value,
                    ScoringStatus::Failed->value,
                ])
                ->orderByDesc('id'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('student.student_code')->label(__('panel.students.field.code')),
                Tables\Columns\TextColumn::make('question.type')
                    ->label(__('panel.questions.field.type'))
                    ->formatStateUsing(fn (mixed $state): string => $state?->value ?? '—'),
                Tables\Columns\TextColumn::make('scoring_status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (ScoringStatus $state): string => $state->label()),
                Tables\Columns\TextColumn::make('created_at')->label(__('panel.common.created_at'))->since(),
            ])
            ->paginated([5, 10]);
    }
}
