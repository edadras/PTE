<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * The seventeen PTE task types.
 *
 * The important distinction encoded here is requiresAi(): ten of these have a
 * deterministic correct answer and are scored in PHP for free, in milliseconds,
 * reproducibly. Only seven genuinely need a language model. Routing on this
 * flag removes roughly half of all AI calls — see ADR-006.
 *
 * @see docs/05-modules-exams-practice.md
 */
enum QuestionType: string
{
    // Speaking
    case ReadAloud = 'RA';
    case RepeatSentence = 'RS';
    case DescribeImage = 'DI';
    case RetellLecture = 'RL';
    case AnswerShortQuestion = 'ASQ';

    // Listening
    case SummarizeSpokenText = 'SST';
    case WriteFromDictation = 'WFD';
    case MultipleChoiceListening = 'MCQ_L';
    case HighlightIncorrectWords = 'HIW';
    case FillInBlanksListening = 'FIB_L';
    case SelectMissingWord = 'SMW';

    // Reading
    case ReorderParagraphs = 'RO';
    case FillInBlanksReading = 'FIB_R';
    case FillInBlanksReadingWriting = 'FIB_RW';
    case MultipleChoiceReading = 'MCQ_R';

    // Writing
    case SummarizeWrittenText = 'SWT';
    case Essay = 'ESSAY';

    public function label(): string
    {
        return __('question_types.'.$this->value);
    }

    public function module(): ModuleKey
    {
        return match ($this) {
            self::ReadAloud, self::RepeatSentence, self::DescribeImage,
            self::RetellLecture, self::AnswerShortQuestion => ModuleKey::PteSpeaking,

            self::SummarizeSpokenText, self::WriteFromDictation,
            self::MultipleChoiceListening, self::HighlightIncorrectWords,
            self::FillInBlanksListening, self::SelectMissingWord => ModuleKey::PteListening,

            self::ReorderParagraphs, self::FillInBlanksReading,
            self::FillInBlanksReadingWriting, self::MultipleChoiceReading => ModuleKey::PteReading,

            self::SummarizeWrittenText, self::Essay => ModuleKey::PteWriting,
        };
    }

    /**
     * True when scoring needs a language model.
     *
     * Everything else is graded by a deterministic scorer — cheaper, instant,
     * and not subject to model drift.
     */
    public function requiresAi(): bool
    {
        return in_array($this, [
            self::ReadAloud,
            self::RepeatSentence,
            self::DescribeImage,
            self::RetellLecture,
            self::SummarizeSpokenText,
            self::SummarizeWrittenText,
            self::Essay,
        ], true);
    }

    /** Speaking tasks need the audio pipeline (download → ffmpeg → ASR). */
    public function requiresAudioAnswer(): bool
    {
        return $this->answerKind() === AnswerKind::Voice;
    }

    public function answerKind(): AnswerKind
    {
        return match ($this) {
            self::ReadAloud, self::RepeatSentence, self::DescribeImage,
            self::RetellLecture, self::AnswerShortQuestion => AnswerKind::Voice,

            self::SummarizeSpokenText, self::WriteFromDictation,
            self::SummarizeWrittenText, self::Essay => AnswerKind::Text,

            self::MultipleChoiceListening, self::SelectMissingWord,
            self::MultipleChoiceReading => AnswerKind::SingleChoice,

            self::HighlightIncorrectWords => AnswerKind::MultipleChoice,

            self::FillInBlanksListening, self::FillInBlanksReading,
            self::FillInBlanksReadingWriting => AnswerKind::Blanks,

            self::ReorderParagraphs => AnswerKind::Ordering,
        };
    }

    /** Question media the student is prompted with. */
    public function hasPromptAudio(): bool
    {
        return in_array($this, [
            self::RepeatSentence,
            self::RetellLecture,
            self::AnswerShortQuestion,
            self::SummarizeSpokenText,
            self::WriteFromDictation,
            self::MultipleChoiceListening,
            self::HighlightIncorrectWords,
            self::FillInBlanksListening,
            self::SelectMissingWord,
        ], true);
    }

    public function hasPromptImage(): bool
    {
        return $this === self::DescribeImage;
    }

    /** Default maximum score on the PTE 0–90 scale. */
    public function defaultMaxScore(): int
    {
        return 90;
    }

    /**
     * @return array<int, self>
     */
    public static function forModule(ModuleKey $module): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->module() === $module
        ));
    }

    /**
     * @return array<int, self>
     */
    public static function aiScored(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t): bool => $t->requiresAi()));
    }

    /**
     * @return array<int, self>
     */
    public static function deterministicallyScored(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t): bool => ! $t->requiresAi()));
    }
}
