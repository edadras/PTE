<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources;

use App\Domain\Assessment\Actions\PublishExam;
use App\Domain\Assessment\Enums\ExamStatus;
use App\Domain\Assessment\Enums\SelectionMode;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Identity\Models\ClassGroup;
use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Filament\Academy\Resources\ExamResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The exam builder of docs/05 §5: reorderable sections, three question
 * selection modes, the rule switches and the availability window.
 */
final class ExamResource extends Resource
{
    protected static ?string $model = Exam::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.nav.assessment');
    }

    public static function getModelLabel(): string
    {
        return __('panel.exams.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.exams.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('exams.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('exams.create') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('exams.update') === true;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('exams.delete') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('panel.exams.section.basics'))
                ->schema([
                    Forms\Components\TextInput::make('title')->label(__('panel.exams.field.title'))->required()->maxLength(180),
                    Forms\Components\TextInput::make('duration_minutes')
                        ->label(__('panel.exams.field.duration'))->numeric()->required()->default(120),
                    Forms\Components\TextInput::make('total_score')
                        ->label(__('panel.exams.field.total_score'))->numeric()->required()->default(90),
                    Forms\Components\TextInput::make('passing_score')
                        ->label(__('panel.exams.field.passing_score'))->numeric(),
                    Forms\Components\Textarea::make('description')->label(__('panel.common.description'))->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.exams.section.sections'))
                ->description(__('panel.exams.help.sections'))
                ->schema([
                    Forms\Components\Repeater::make('sections')
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                        ->schema(self::sectionSchema())
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make(__('panel.exams.section.rules'))
                ->schema([
                    Forms\Components\Toggle::make('rules.ordered_sections')->label(__('panel.exams.rule.ordered_sections'))->default(true),
                    Forms\Components\Toggle::make('rules.allow_back')->label(__('panel.exams.rule.allow_back')),
                    Forms\Components\Toggle::make('rules.shuffle_questions')->label(__('panel.exams.rule.shuffle_questions'))->default(true),
                    Forms\Components\Toggle::make('rules.show_timer')->label(__('panel.exams.rule.show_timer'))->default(true),
                    Forms\Components\Toggle::make('rules.autosave')->label(__('panel.exams.rule.autosave'))->default(true),
                    Forms\Components\Toggle::make('rules.instant_result')->label(__('panel.exams.rule.instant_result')),
                    Forms\Components\Toggle::make('rules.require_teacher_approval')->label(__('panel.exams.rule.require_teacher_approval'))->default(true),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('panel.exams.section.availability'))
                ->schema([
                    Forms\Components\Select::make('availability.audience')
                        ->label(__('panel.exams.field.audience'))
                        ->options([
                            'all' => __('panel.exams.audience.all'),
                            'class_groups' => __('panel.exams.audience.class_groups'),
                            'manual' => __('panel.exams.audience.manual'),
                        ])
                        ->default('all')
                        ->live(),
                    Forms\Components\Select::make('availability.class_group_ids')
                        ->label(__('panel.class_groups.plural'))
                        ->multiple()
                        ->options(fn (): array => ClassGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => $get('availability.audience') === 'class_groups'),
                    Forms\Components\DateTimePicker::make('availability.opens_at')->label(__('panel.exams.field.opens_at')),
                    Forms\Components\DateTimePicker::make('availability.closes_at')->label(__('panel.exams.field.closes_at')),
                    Forms\Components\TextInput::make('availability.max_attempts')
                        ->label(__('panel.exams.field.max_attempts'))->numeric()->default(1)->minValue(1),
                ])
                ->columns(2),
        ]);
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private static function sectionSchema(): array
    {
        return [
            Forms\Components\TextInput::make('title')->label(__('panel.exams.field.section_title'))->required(),
            Forms\Components\Select::make('module_key')
                ->label(__('panel.common.module'))
                ->options(fn (): array => collect(ModuleKey::cases())
                    ->mapWithKeys(fn (ModuleKey $m): array => [$m->value => $m->icon().' '.$m->label()])
                    ->all())
                ->required()
                ->live(),
            Forms\Components\TextInput::make('duration_minutes')->label(__('panel.exams.field.duration'))->numeric()->required()->default(30),
            Forms\Components\TextInput::make('score')->label(__('panel.exams.field.score'))->numeric()->required()->default(20),

            Forms\Components\Select::make('selection_mode')
                ->label(__('panel.exams.field.selection_mode'))
                ->options(fn (): array => collect(SelectionMode::cases())
                    ->mapWithKeys(fn (SelectionMode $m): array => [$m->value => $m->label()])
                    ->all())
                ->default(SelectionMode::Manual->value)
                ->required()
                ->live()
                ->columnSpanFull(),

            Forms\Components\Select::make('selection_config.question_ids')
                ->label(__('panel.exams.field.questions'))
                ->multiple()
                ->searchable()
                ->options(fn (Get $get): array => self::questionOptions($get('module_key')))
                ->visible(fn (Get $get): bool => in_array(
                    $get('selection_mode'),
                    [SelectionMode::Manual->value, SelectionMode::Pool->value],
                    true,
                ))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('selection_config.take')
                ->label(__('panel.exams.field.pool_take'))
                ->numeric()
                ->minValue(1)
                ->helperText(__('panel.exams.help.pool_take'))
                ->visible(fn (Get $get): bool => $get('selection_mode') === SelectionMode::Pool->value),

            Forms\Components\TextInput::make('selection_config.count')
                ->label(__('panel.exams.field.random_count'))
                ->numeric()
                ->minValue(1)
                ->visible(fn (Get $get): bool => $get('selection_mode') === SelectionMode::Random->value),

            Forms\Components\Select::make('selection_config.types')
                ->label(__('panel.questions.field.type'))
                ->multiple()
                ->options(fn (Get $get): array => self::typeOptions($get('module_key')))
                ->visible(fn (Get $get): bool => $get('selection_mode') === SelectionMode::Random->value),

            Forms\Components\Select::make('selection_config.difficulty')
                ->label(__('panel.questions.field.difficulty'))
                ->options(fn (): array => collect(Difficulty::cases())
                    ->mapWithKeys(fn (Difficulty $d): array => [$d->value => $d->label()])
                    ->all())
                ->visible(fn (Get $get): bool => $get('selection_mode') === SelectionMode::Random->value),

            Forms\Components\Select::make('selection_config.bank_id')
                ->label(__('panel.question_banks.singular'))
                ->options(fn (): array => QuestionBank::query()->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (Get $get): bool => $get('selection_mode') === SelectionMode::Random->value),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function questionOptions(mixed $moduleKey): array
    {
        $module = $moduleKey instanceof ModuleKey ? $moduleKey : ModuleKey::tryFrom((string) $moduleKey);

        return Question::query()
            ->published()
            ->when($module !== null, fn ($query) => $query->where('module_key', $module->value))
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'type', 'title'])
            ->mapWithKeys(fn (Question $question): array => [
                $question->getKey() => sprintf(
                    '#%d · %s · %s',
                    $question->getKey(),
                    $question->type->value,
                    $question->title ?? __('panel.questions.untitled'),
                ),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(mixed $moduleKey): array
    {
        $module = $moduleKey instanceof ModuleKey ? $moduleKey : ModuleKey::tryFrom((string) $moduleKey);

        $types = $module === null ? QuestionType::cases() : QuestionType::forModule($module);

        return collect($types)
            ->mapWithKeys(fn (QuestionType $t): array => [$t->value => $t->value.' — '.$t->label()])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label(__('panel.exams.field.title'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (ExamStatus $state): string => $state->label())
                    ->color(fn (ExamStatus $state): string => $state === ExamStatus::Published ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('sections_count')->label(__('panel.exams.field.sections'))->counts('sections'),
                Tables\Columns\TextColumn::make('duration_minutes')->label(__('panel.exams.field.duration')),
                Tables\Columns\TextColumn::make('total_score')->label(__('panel.exams.field.total_score')),
                Tables\Columns\TextColumn::make('sessions_count')->label(__('panel.exams.field.attempts'))->counts('sessions'),
                Tables\Columns\TextColumn::make('published_at')->label(__('panel.exams.field.published_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('panel.common.status'))
                    ->options(fn (): array => collect(ExamStatus::cases())
                        ->mapWithKeys(fn (ExamStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('publish')
                    ->label(__('panel.exams.action.publish'))
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Exam $record): bool => auth()->user()?->can('exams.publish') === true
                        && $record->status !== ExamStatus::Published)
                    ->action(function (Exam $record): void {
                        try {
                            app(PublishExam::class)->handle($record);

                            Notification::make()->success()->title(__('panel.exams.notify.published'))->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('panel.exams.notify.publish_failed'))->body($e->getMessage())->send();
                        }
                    }),
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
            'index' => Pages\ListExams::route('/'),
            'create' => Pages\CreateExam::route('/create'),
            'edit' => Pages\EditExam::route('/{record}/edit'),
        ];
    }
}
