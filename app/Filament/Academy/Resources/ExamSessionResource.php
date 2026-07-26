<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Assessment\Enums\SessionStatus;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSession;
use App\Filament\Academy\Resources\ExamSessionResource\Pages;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Attempts and their results. Read-only: a session is produced by the exam
 * runner, never by the panel.
 */
final class ExamSessionResource extends Resource
{
    protected static ?string $model = ExamSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.assessment');
    }

    public static function getModelLabel(): string
    {
        return __('panel.exam_sessions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.exam_sessions.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('scores.view') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('exam.title')->label(__('panel.exams.singular'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('student.student_code')->label(__('panel.students.field.code'))->searchable(),
                Tables\Columns\TextColumn::make('student.first_name')
                    ->label(__('panel.students.singular'))
                    ->state(fn (ExamSession $record): string => $record->student?->fullName() ?? '—'),
                Tables\Columns\TextColumn::make('attempt_number')->label(__('panel.exam_sessions.field.attempt')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (SessionStatus $state): string => $state->label())
                    ->color(fn (SessionStatus $state): string => match ($state) {
                        SessionStatus::Scored => 'success',
                        SessionStatus::Expired => 'danger',
                        SessionStatus::InProgress => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('total_score')
                    ->label(__('panel.exam_sessions.field.score'))
                    ->numeric(1)
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\IconColumn::make('passed')->label(__('panel.exam_sessions.field.passed'))->boolean()->placeholder('—'),
                Tables\Columns\TextColumn::make('submitted_at')->label(__('panel.exam_sessions.field.submitted_at'))->dateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('exam_id')
                    ->label(__('panel.exams.singular'))
                    ->options(fn (): array => Exam::query()->orderByDesc('id')->pluck('title', 'id')->all()),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('panel.common.status'))
                    ->options(fn (): array => collect(SessionStatus::forExam())
                        ->mapWithKeys(fn (SessionStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('panel.exam_sessions.section.summary'))
                ->schema([
                    Infolists\Components\TextEntry::make('exam.title')->label(__('panel.exams.singular')),
                    Infolists\Components\TextEntry::make('student.student_code')->label(__('panel.students.field.code')),
                    Infolists\Components\TextEntry::make('total_score')
                        ->label(__('panel.exam_sessions.field.score'))
                        ->state(fn (ExamSession $record): string => sprintf(
                            '%s / %s',
                            $record->total_score === null ? '—' : number_format($record->total_score, 1),
                            number_format($record->maxScore(), 0),
                        )),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('panel.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (SessionStatus $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('started_at')->label(__('panel.exam_sessions.field.started_at'))->dateTime(),
                    Infolists\Components\TextEntry::make('submitted_at')->label(__('panel.exam_sessions.field.submitted_at'))->dateTime()->placeholder('—'),
                ])
                ->columns(3),

            Infolists\Components\Section::make(__('panel.exam_sessions.section.sections'))
                ->schema([
                    Infolists\Components\KeyValueEntry::make('section_scores')
                        ->label('')
                        ->keyLabel(__('panel.exams.field.section_title'))
                        ->valueLabel(__('panel.exam_sessions.field.score')),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['exam', 'student']);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExamSessions::route('/'),
            'view' => Pages\ViewExamSession::route('/{record}'),
        ];
    }
}
