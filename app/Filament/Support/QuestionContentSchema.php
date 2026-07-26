<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Learning\Enums\QuestionType;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Get;

/**
 * The per-type half of the question form.
 *
 * Every field writes into the `content` JSON column using exactly the keys
 * QuestionContentValidator checks, so the panel and the importer produce the
 * same shape and the same errors.
 *
 * @see app/Domain/Learning/Services/QuestionContentValidator.php
 * @see docs/05-modules-exams-practice.md
 */
final class QuestionContentSchema
{
    /** Storage folder (on the tenant disk) each media kind lands in. */
    private const AUDIO_DIRECTORY = 'questions/audio';

    private const IMAGE_DIRECTORY = 'questions/images';

    /**
     * Content keys each type owns. Used to strip leftovers when an author
     * changes the type of a draft question.
     *
     * @return array<int, string>
     */
    public static function keysFor(QuestionType $type): array
    {
        return match ($type) {
            QuestionType::ReadAloud => ['text', 'prep_seconds', 'record_seconds'],
            QuestionType::RepeatSentence => ['audio_key', 'transcript', 'record_seconds'],
            QuestionType::DescribeImage => ['image_key', 'prep_seconds', 'record_seconds', 'key_points'],
            QuestionType::RetellLecture => ['audio_key', 'transcript', 'record_seconds'],
            QuestionType::AnswerShortQuestion => ['audio_key', 'transcript', 'accepted_answers'],
            QuestionType::SummarizeSpokenText => ['audio_key', 'transcript', 'min_words', 'max_words'],
            QuestionType::WriteFromDictation => ['transcript'],
            QuestionType::MultipleChoiceListening => ['prompt', 'audio_key', 'multiple'],
            QuestionType::MultipleChoiceReading => ['prompt', 'passage', 'multiple'],
            QuestionType::HighlightIncorrectWords => ['audio_key', 'display_text', 'spoken_text', 'errors'],
            QuestionType::FillInBlanksListening => ['passage', 'audio_key', 'blanks'],
            QuestionType::FillInBlanksReading,
            QuestionType::FillInBlanksReadingWriting => ['passage', 'blanks'],
            QuestionType::SelectMissingWord => ['audio_key', 'transcript'],
            QuestionType::ReorderParagraphs => ['paragraphs', 'correct_order'],
            QuestionType::SummarizeWrittenText => ['passage', 'min_words', 'max_words'],
            QuestionType::Essay => ['prompt', 'min_words', 'max_words', 'duration_minutes'],
        };
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function prune(QuestionType $type, array $content): array
    {
        return array_intersect_key($content, array_flip(self::keysFor($type)));
    }

    /**
     * The whole per-type block: one visible group, sixteen hidden ones.
     *
     * @return array<int, Component>
     */
    public static function schema(): array
    {
        $groups = [];

        foreach (QuestionType::cases() as $type) {
            $groups[] = Forms\Components\Group::make(self::fieldsFor($type))
                ->columns(2)
                ->visible(fn (Get $get): bool => self::selectedType($get) === $type);
        }

        return $groups;
    }

    /** Whether the type currently selected in the form needs an options table. */
    public static function needsOptions(Get $get): bool
    {
        $type = self::selectedType($get);

        return $type !== null && in_array($type, [
            QuestionType::MultipleChoiceListening,
            QuestionType::MultipleChoiceReading,
            QuestionType::SelectMissingWord,
        ], true);
    }

    public static function selectedType(Get $get): ?QuestionType
    {
        $value = $get('type');

        if ($value instanceof QuestionType) {
            return $value;
        }

        return is_string($value) ? QuestionType::tryFrom($value) : null;
    }

    /**
     * @return array<int, Component>
     */
    private static function fieldsFor(QuestionType $type): array
    {
        return match ($type) {
            QuestionType::ReadAloud => [
                self::textarea('content.text', 'read_aloud_text')->required()->minLength(20)->maxLength(500),
                self::seconds('content.prep_seconds', 'prep_seconds', 5, 300),
                self::seconds('content.record_seconds', 'record_seconds', 5, 300),
            ],
            QuestionType::RepeatSentence => [
                self::audio(),
                self::textarea('content.transcript', 'transcript')->required()->minLength(5)->maxLength(500),
                self::seconds('content.record_seconds', 'record_seconds', 3, 120),
            ],
            QuestionType::DescribeImage => [
                self::image(),
                self::seconds('content.prep_seconds', 'prep_seconds', 5, 300),
                self::seconds('content.record_seconds', 'record_seconds', 5, 300),
                self::tags('content.key_points', 'key_points'),
            ],
            QuestionType::RetellLecture => [
                self::audio(),
                self::textarea('content.transcript', 'transcript')->required()->minLength(20)->rows(8),
                self::seconds('content.record_seconds', 'record_seconds', 5, 300),
            ],
            QuestionType::AnswerShortQuestion => [
                self::audio(),
                self::textarea('content.transcript', 'transcript')->required()->minLength(3)->maxLength(500),
                self::tags('content.accepted_answers', 'accepted_answers')->required(),
            ],
            QuestionType::SummarizeSpokenText => [
                self::audio(),
                self::textarea('content.transcript', 'transcript')->required()->minLength(50)->rows(8),
                ...self::wordRange(5, 500),
            ],
            QuestionType::WriteFromDictation => [
                self::textarea('content.transcript', 'transcript')->required()->minLength(10)->maxLength(300),
            ],
            QuestionType::MultipleChoiceListening => [
                self::audio(),
                self::textarea('content.prompt', 'prompt')->required()->minLength(5)->maxLength(1000),
                self::multipleToggle(),
            ],
            QuestionType::MultipleChoiceReading => [
                self::textarea('content.passage', 'passage')->required()->minLength(50)->rows(8),
                self::textarea('content.prompt', 'prompt')->required()->minLength(5)->maxLength(1000),
                self::multipleToggle(),
            ],
            QuestionType::HighlightIncorrectWords => [
                self::audio(),
                self::textarea('content.display_text', 'display_text')->required()->minLength(50)->rows(6)
                    ->helperText(__('panel.questions.help.display_text')),
                self::textarea('content.spoken_text', 'spoken_text')->required()->minLength(50)->rows(6),
                Forms\Components\Repeater::make('content.errors')
                    ->label(__('panel.questions.field.errors'))
                    ->schema([
                        Forms\Components\TextInput::make('index')
                            ->label(__('panel.questions.field.word_index'))
                            ->numeric()->minValue(0)->required(),
                        Forms\Components\TextInput::make('shown')->label(__('panel.questions.field.word_shown')),
                        Forms\Components\TextInput::make('spoken')->label(__('panel.questions.field.word_spoken'))->required(),
                    ])
                    ->columns(3)
                    ->reorderable()
                    ->defaultItems(1)
                    ->columnSpanFull(),
            ],
            QuestionType::FillInBlanksListening => [
                self::audio(),
                ...self::blanks(withOptions: false),
            ],
            QuestionType::FillInBlanksReading,
            QuestionType::FillInBlanksReadingWriting => self::blanks(withOptions: true),
            QuestionType::SelectMissingWord => [
                self::audio(),
                self::textarea('content.transcript', 'transcript')->required()->minLength(20)->rows(6),
            ],
            QuestionType::ReorderParagraphs => [
                Forms\Components\Repeater::make('content.paragraphs')
                    ->label(__('panel.questions.field.paragraphs'))
                    ->schema([
                        Forms\Components\TextInput::make('key')->label(__('panel.questions.field.paragraph_key'))->required(),
                        Forms\Components\Textarea::make('text')->label(__('panel.questions.field.paragraph_text'))->required()->rows(3),
                    ])
                    ->columns(2)
                    ->reorderable()
                    ->minItems(2)
                    ->defaultItems(2)
                    ->columnSpanFull(),
                self::tags('content.correct_order', 'correct_order')
                    ->required()
                    ->helperText(__('panel.questions.help.correct_order'))
                    ->columnSpanFull(),
            ],
            QuestionType::SummarizeWrittenText => [
                self::textarea('content.passage', 'passage')->required()->minLength(100)->rows(10),
                ...self::wordRange(5, 200),
            ],
            QuestionType::Essay => [
                self::textarea('content.prompt', 'prompt')->required()->minLength(30)->rows(5),
                ...self::wordRange(50, 1000),
                self::seconds('content.duration_minutes', 'duration_minutes', 1, 180),
            ],
        };
    }

    /**
     * @return array<int, Component>
     */
    private static function blanks(bool $withOptions): array
    {
        $schema = [
            self::tags('answers', 'accepted_answers')->required(),
        ];

        if ($withOptions) {
            $schema[] = self::tags('options', 'blank_options')
                ->required()
                ->helperText(__('panel.questions.help.blank_options'));
        }

        return [
            self::textarea('content.passage', 'passage')
                ->required()
                ->minLength(30)
                ->rows(8)
                ->helperText(__('panel.questions.help.blank_passage')),
            Forms\Components\Repeater::make('content.blanks')
                ->label(__('panel.questions.field.blanks'))
                ->schema($schema)
                ->reorderable()
                ->minItems(1)
                ->defaultItems(1)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private static function wordRange(int $floor, int $ceiling): array
    {
        return [
            Forms\Components\TextInput::make('content.min_words')
                ->label(__('panel.questions.field.min_words'))
                ->numeric()->minValue($floor)->maxValue($ceiling)->default($floor),
            Forms\Components\TextInput::make('content.max_words')
                ->label(__('panel.questions.field.max_words'))
                ->numeric()->minValue($floor)->maxValue($ceiling)->default($ceiling),
        ];
    }

    private static function textarea(string $name, string $labelKey): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make($name)
            ->label(__('panel.questions.field.'.$labelKey))
            ->rows(4)
            ->columnSpanFull();
    }

    private static function tags(string $name, string $labelKey): Forms\Components\TagsInput
    {
        return Forms\Components\TagsInput::make($name)
            ->label(__('panel.questions.field.'.$labelKey));
    }

    private static function seconds(string $name, string $labelKey, int $min, int $max): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)
            ->label(__('panel.questions.field.'.$labelKey))
            ->numeric()
            ->minValue($min)
            ->maxValue($max);
    }

    private static function multipleToggle(): Forms\Components\Toggle
    {
        return Forms\Components\Toggle::make('content.multiple')
            ->label(__('panel.questions.field.multiple'))
            ->helperText(__('panel.questions.help.multiple'));
    }

    private static function audio(): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make('content.audio_key')
            ->label(__('panel.questions.field.audio'))
            ->disk('tenant')
            ->directory(self::AUDIO_DIRECTORY)
            ->visibility('private')
            ->acceptedFileTypes(['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm'])
            ->maxSize(20 * 1024)
            ->columnSpanFull();
    }

    private static function image(): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make('content.image_key')
            ->label(__('panel.questions.field.image'))
            ->disk('tenant')
            ->directory(self::IMAGE_DIRECTORY)
            ->visibility('private')
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(5 * 1024)
            ->columnSpanFull();
    }
}
