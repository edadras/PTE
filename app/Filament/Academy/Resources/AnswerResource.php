<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Assessment\Actions\OverrideAnswerScore;
use App\Domain\Assessment\Enums\ScoredBy;
use App\Domain\Assessment\Enums\ScoringStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Filament\Academy\Resources\AnswerResource\Pages;
use App\Filament\Academy\Support\AnswerMedia;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The teacher's grading queue: listen, read the AI breakdown, override.
 *
 * An override always goes through OverrideAnswerScore, which is what keeps the
 * model's original number, the reason and the actor on the record (docs/02 §7).
 */
final class AnswerResource extends Resource
{
    protected static ?string $model = Answer::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.assessment');
    }

    public static function getModelLabel(): string
    {
        return __('panel.answers.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.answers.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('answers.view') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = self::getModel()::query()
            ->whereIn('scoring_status', [ScoringStatus::ManualReview->value, ScoringStatus::Failed->value])
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('student.student_code')->label(__('panel.students.field.code'))->searchable(),
                Tables\Columns\TextColumn::make('question.type')
                    ->label(__('panel.questions.field.type'))
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state?->value ?? '—'),
                Tables\Columns\TextColumn::make('session_type')
                    ->label(__('panel.answers.field.session'))
                    ->badge()
                    ->formatStateUsing(fn (SessionType $state): string => $state->label()),
                Tables\Columns\TextColumn::make('score')
                    ->label(__('panel.answers.field.score'))
                    ->state(fn (Answer $record): string => $record->score === null
                        ? '—'
                        : sprintf('%.1f / %.0f', $record->score, $record->effectiveMaxScore()))
                    ->sortable(),
                Tables\Columns\TextColumn::make('scoring_status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (ScoringStatus $state): string => $state->label())
                    ->color(fn (ScoringStatus $state): string => match ($state) {
                        ScoringStatus::Scored => 'success',
                        ScoringStatus::Failed => 'danger',
                        ScoringStatus::ManualReview => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('graded_manually')->label(__('panel.answers.field.overridden'))->boolean(),
                Tables\Columns\TextColumn::make('scored_at')->label(__('panel.answers.field.scored_at'))->dateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('scoring_status')
                    ->label(__('panel.common.status'))
                    ->options(fn (): array => collect(ScoringStatus::cases())
                        ->mapWithKeys(fn (ScoringStatus $s): array => [$s->value => $s->label()])
                        ->all())
                    ->default(ScoringStatus::ManualReview->value),
                Tables\Filters\SelectFilter::make('session_type')
                    ->label(__('panel.answers.field.session'))
                    ->options(fn (): array => collect(SessionType::cases())
                        ->mapWithKeys(fn (SessionType $s): array => [$s->value => $s->label()])
                        ->all()),
                Tables\Filters\SelectFilter::make('scored_by')
                    ->label(__('panel.answers.field.scored_by'))
                    ->options(fn (): array => collect(ScoredBy::cases())
                        ->mapWithKeys(fn (ScoredBy $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::overrideAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function overrideAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('override')
            ->label(__('panel.answers.action.override'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->visible(fn (): bool => auth()->user()?->can('answers.override_ai_score') === true)
            ->form([
                Forms\Components\TextInput::make('score')
                    ->label(__('panel.answers.field.new_score'))
                    ->numeric()
                    ->required()
                    ->minValue(0),
                Forms\Components\Textarea::make('reason')
                    ->label(__('panel.answers.field.reason'))
                    ->required()
                    ->minLength(5)
                    ->helperText(__('panel.answers.help.reason')),
            ])
            ->fillForm(fn (Answer $record): array => ['score' => $record->score])
            ->action(function (Answer $record, array $data): void {
                try {
                    app(OverrideAnswerScore::class)->handle(
                        $record,
                        (float) $data['score'],
                        (string) $data['reason'],
                        (int) auth()->id(),
                    );

                    Notification::make()->success()->title(__('panel.answers.notify.overridden'))->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('panel.answers.notify.override_failed'))->body($e->getMessage())->send();
                }
            });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('panel.answers.section.submission'))
                ->schema([
                    Infolists\Components\TextEntry::make('student.student_code')->label(__('panel.students.field.code')),
                    Infolists\Components\TextEntry::make('question.type')
                        ->label(__('panel.questions.field.type'))
                        ->formatStateUsing(fn (mixed $state): string => $state?->label() ?? '—'),
                    Infolists\Components\TextEntry::make('scoring_status')
                        ->label(__('panel.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (ScoringStatus $state): string => $state->label()),
                    Infolists\Components\ViewEntry::make('media_path')
                        ->label(__('panel.answers.field.audio'))
                        ->view('filament.academy.entries.answer-audio')
                        ->visible(fn (Answer $record): bool => filled($record->media_path))
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('transcript')
                        ->label(__('panel.answers.field.transcript'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                    Infolists\Components\KeyValueEntry::make('answer_data')
                        ->label(__('panel.answers.field.payload'))
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Infolists\Components\Section::make(__('panel.answers.section.scoring'))
                ->schema([
                    Infolists\Components\TextEntry::make('score')
                        ->label(__('panel.answers.field.score'))
                        ->state(fn (Answer $record): string => $record->score === null
                            ? '—'
                            : sprintf('%.1f / %.0f', $record->score, $record->effectiveMaxScore())),
                    Infolists\Components\TextEntry::make('original_ai_score')
                        ->label(__('panel.answers.field.original_ai_score'))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('confidence')->label(__('panel.answers.field.confidence'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('scored_by')
                        ->label(__('panel.answers.field.scored_by'))
                        ->badge()
                        ->formatStateUsing(fn (?ScoredBy $state): string => $state?->label() ?? '—'),
                    Infolists\Components\KeyValueEntry::make('breakdown')
                        ->label(__('panel.answers.field.breakdown'))
                        ->columnSpanFull(),
                    Infolists\Components\KeyValueEntry::make('feedback')
                        ->label(__('panel.answers.field.feedback'))
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('override_reason')
                        ->label(__('panel.answers.field.reason'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(4),
        ]);
    }

    public static function mediaUrl(Answer $answer): ?string
    {
        return app(AnswerMedia::class)->temporaryUrl($answer);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['student', 'question']);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnswers::route('/'),
            'view' => Pages\ViewAnswer::route('/{record}'),
        ];
    }
}
