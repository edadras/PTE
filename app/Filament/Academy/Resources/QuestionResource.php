<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Learning\Actions\ApproveQuestion;
use App\Domain\Learning\Actions\PublishQuestion;
use App\Domain\Learning\Actions\RejectQuestion;
use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Learning\Support\ModuleRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Academy\Resources\QuestionResource\Pages;
use App\Filament\Support\QuestionContentSchema;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The question bank editor.
 *
 * The form body switches on QuestionType (QuestionContentSchema), and every
 * save is routed through CreateQuestion / UpdateQuestion so the content passes
 * QuestionContentValidator — the panel never writes a question itself.
 *
 * @see docs/05-modules-exams-practice.md §3
 */
final class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.content');
    }

    public static function getModelLabel(): string
    {
        return __('panel.questions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.questions.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('questions.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('questions.create') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('questions.update') === true;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('questions.delete') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.questions.section.basics'))
                ->schema([
                    Forms\Components\Select::make('bank_id')
                        ->label(__('panel.question_banks.singular'))
                        ->options(fn (): array => QuestionBank::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->required()
                        ->searchable(),
                    Forms\Components\Select::make('type')
                        ->label(__('panel.questions.field.type'))
                        ->options(fn (): array => self::enabledTypeOptions())
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        ->helperText(__('panel.questions.help.type')),
                    Forms\Components\TextInput::make('title')->label(__('panel.questions.field.title'))->maxLength(200),
                    Forms\Components\Select::make('difficulty')
                        ->label(__('panel.questions.field.difficulty'))
                        ->options(fn (): array => collect(Difficulty::cases())
                            ->mapWithKeys(fn (Difficulty $d): array => [$d->value => $d->label()])
                            ->all())
                        ->default(Difficulty::Medium->value)
                        ->required(),
                    Forms\Components\TagsInput::make('tags')->label(__('panel.questions.field.tags'))->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.questions.section.content'))
                ->schema(QuestionContentSchema::schema()),

            Forms\Components\Section::make(__('panel.questions.section.options'))
                ->visible(fn (Get $get): bool => QuestionContentSchema::needsOptions($get))
                ->schema([
                    Forms\Components\Repeater::make('options')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\TextInput::make('key')
                                ->label(__('panel.questions.field.option_key'))
                                ->required()
                                ->maxLength(8),
                            Forms\Components\TextInput::make('text')
                                ->label(__('panel.questions.field.option_text'))
                                ->required()
                                ->columnSpan(2),
                            Forms\Components\Toggle::make('is_correct')
                                ->label(__('panel.questions.field.is_correct')),
                            Forms\Components\TextInput::make('explanation')
                                ->label(__('panel.questions.field.explanation'))
                                ->columnSpan(2),
                        ])
                        ->columns(4)
                        ->reorderable()
                        ->minItems(2)
                        ->defaultItems(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('panel.questions.field.type'))
                    ->badge()
                    ->formatStateUsing(fn (QuestionType $state): string => $state->value)
                    ->description(fn (Question $record): string => $record->type->label()),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('panel.questions.field.title'))
                    ->searchable()
                    ->limit(60)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('bank.name')->label(__('panel.question_banks.singular'))->toggleable(),
                Tables\Columns\TextColumn::make('difficulty')
                    ->label(__('panel.questions.field.difficulty'))
                    ->badge()
                    ->formatStateUsing(fn (Difficulty $state): string => $state->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (QuestionStatus $state): string => $state->label())
                    ->color(fn (QuestionStatus $state): string => match ($state) {
                        QuestionStatus::Published => 'success',
                        QuestionStatus::Approved => 'info',
                        QuestionStatus::PendingReview => 'warning',
                        QuestionStatus::Rejected => 'danger',
                        QuestionStatus::Draft => 'gray',
                    }),
                Tables\Columns\TextColumn::make('usage_count')->label(__('panel.questions.field.usage'))->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('avg_score')->label(__('panel.questions.field.avg_score'))->numeric(1)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('panel.questions.field.type'))
                    ->options(fn (): array => collect(QuestionType::cases())
                        ->mapWithKeys(fn (QuestionType $t): array => [$t->value => $t->value.' — '.$t->label()])
                        ->all()),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('panel.common.status'))
                    ->options(fn (): array => collect(QuestionStatus::cases())
                        ->mapWithKeys(fn (QuestionStatus $s): array => [$s->value => $s->label()])
                        ->all()),
                Tables\Filters\SelectFilter::make('bank_id')
                    ->label(__('panel.question_banks.singular'))
                    ->options(fn (): array => QuestionBank::query()->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('difficulty')
                    ->label(__('panel.questions.field.difficulty'))
                    ->options(fn (): array => collect(Difficulty::cases())
                        ->mapWithKeys(fn (Difficulty $d): array => [$d->value => $d->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('approve')
                    ->label(__('panel.questions.action.approve'))
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->visible(fn (Question $record): bool => auth()->user()?->can('questions.approve') === true
                        && $record->status->canTransitionTo(QuestionStatus::Approved))
                    ->action(function (Question $record): void {
                        app(ApproveQuestion::class)->handle($record, auth()->id());

                        Notification::make()->success()->title(__('panel.questions.notify.approved'))->send();
                    }),

                Tables\Actions\Action::make('publish')
                    ->label(__('panel.questions.action.publish'))
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (Question $record): bool => auth()->user()?->can('questions.approve') === true
                        && $record->status->canTransitionTo(QuestionStatus::Published))
                    ->action(function (Question $record): void {
                        app(PublishQuestion::class)->handle($record, auth()->id());

                        Notification::make()->success()->title(__('panel.questions.notify.published'))->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label(__('panel.questions.action.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Question $record): bool => auth()->user()?->can('questions.approve') === true
                        && $record->status->canTransitionTo(QuestionStatus::Rejected))
                    ->form([
                        Forms\Components\Textarea::make('note')->label(__('panel.questions.field.reject_note'))->required(),
                    ])
                    ->action(function (Question $record, array $data): void {
                        app(RejectQuestion::class)->handle($record, (string) $data['note'], auth()->id());

                        Notification::make()->warning()->title(__('panel.questions.notify.rejected'))->send();
                    }),

                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * Only the types the academy has actually switched on — offering a Reading
     * task to an academy that teaches Speaking only is a support ticket waiting
     * to happen.
     *
     * @return array<string, string>
     */
    public static function enabledTypeOptions(): array
    {
        $academy = TenantContext::get();

        $types = $academy === null
            ? QuestionType::cases()
            : ModuleRegistry::enabledQuestionTypes($academy);

        if ($types === []) {
            $types = QuestionType::cases();
        }

        $options = [];

        foreach ($types as $type) {
            $options[$type->value] = sprintf(
                '%s %s — %s',
                $type->module()->icon(),
                $type->value,
                $type->label(),
            );
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function moduleOptions(): array
    {
        return collect(ModuleKey::cases())
            ->mapWithKeys(fn (ModuleKey $m): array => [$m->value => $m->label()])
            ->all();
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestions::route('/'),
            'create' => Pages\CreateQuestion::route('/create'),
            'edit' => Pages\EditQuestion::route('/{record}/edit'),
        ];
    }
}
