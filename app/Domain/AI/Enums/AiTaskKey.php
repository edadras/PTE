<?php

declare(strict_types=1);

namespace App\Domain\AI\Enums;

use App\Domain\Learning\Enums\QuestionType;

/**
 * The unit of AI configuration: an academy picks a provider, a model, a prompt
 * and a rubric *per task key*, not per request.
 *
 * @see docs/06-ai-layer.md §3
 */
enum AiTaskKey: string
{
    case SpeakingReadAloud = 'speaking.read_aloud';
    case SpeakingRepeatSentence = 'speaking.repeat_sentence';
    case SpeakingDescribeImage = 'speaking.describe_image';
    case SpeakingRetellLecture = 'speaking.retell_lecture';
    case WritingEssay = 'writing.essay';
    case WritingSummarizeText = 'writing.summarize_text';
    case ListeningSummarizeSpoken = 'listening.summarize_spoken';
    case GrammarCheck = 'grammar.check';
    case VocabularyExplain = 'vocabulary.explain';
    case FeedbackOverall = 'feedback.overall';
    case ChatAssistant = 'chat.assistant';
    case ReportWeeklySummary = 'report.weekly_summary';
    case Transcription = 'transcription';

    public function label(): string
    {
        return __('ai.tasks.'.$this->value);
    }

    /** Tasks that produce a rubric-weighted score for an answer. */
    public function isScoring(): bool
    {
        return in_array($this, [
            self::SpeakingReadAloud,
            self::SpeakingRepeatSentence,
            self::SpeakingDescribeImage,
            self::SpeakingRetellLecture,
            self::WritingEssay,
            self::WritingSummarizeText,
            self::ListeningSummarizeSpoken,
        ], true);
    }

    public function isTranscription(): bool
    {
        return $this === self::Transcription;
    }

    /** Transcription is configured like a task but has no prompt template. */
    public function requiresPrompt(): bool
    {
        return ! $this->isTranscription();
    }

    /** Scoring tasks fed by the speaking pipeline also receive acoustic metrics. */
    public function usesAudioMetrics(): bool
    {
        return in_array($this, [
            self::SpeakingReadAloud,
            self::SpeakingRepeatSentence,
            self::SpeakingDescribeImage,
            self::SpeakingRetellLecture,
        ], true);
    }

    /** Tasks compared against a target text (word error rate is meaningful). */
    public function usesTargetTextComparison(): bool
    {
        return in_array($this, [self::SpeakingReadAloud, self::SpeakingRepeatSentence], true);
    }

    public static function forQuestionType(QuestionType $type): ?self
    {
        return match ($type) {
            QuestionType::ReadAloud => self::SpeakingReadAloud,
            QuestionType::RepeatSentence => self::SpeakingRepeatSentence,
            QuestionType::DescribeImage => self::SpeakingDescribeImage,
            QuestionType::RetellLecture => self::SpeakingRetellLecture,
            QuestionType::SummarizeSpokenText => self::ListeningSummarizeSpoken,
            QuestionType::SummarizeWrittenText => self::WritingSummarizeText,
            QuestionType::Essay => self::WritingEssay,
            default => null,
        };
    }

    /**
     * Variables the prompt editor offers for this task.
     *
     * @return array<int, string>
     */
    public function availableVariables(): array
    {
        $common = ['student_level', 'feedback_locale', 'academy_name', 'rubric_weights'];

        return match (true) {
            $this->usesAudioMetrics() => array_merge([
                'question_text', 'transcript', 'wpm', 'pause_count', 'pause_total_ms',
                'speech_ratio', 'asr_confidence', 'word_error_rate', 'missing_words',
                'extra_words', 'mispronounced_candidates',
            ], $common),

            $this === self::ListeningSummarizeSpoken => array_merge(
                ['source_text', 'student_text', 'word_count'], $common
            ),

            in_array($this, [self::WritingEssay, self::WritingSummarizeText], true) => array_merge(
                ['question_text', 'student_text', 'word_count', 'min_words', 'max_words'], $common
            ),

            $this === self::GrammarCheck => array_merge(['student_text'], $common),
            $this === self::VocabularyExplain => array_merge(['term', 'context_sentence'], $common),
            $this === self::FeedbackOverall => array_merge(['recent_scores', 'weak_areas'], $common),
            $this === self::ChatAssistant => array_merge(['student_message', 'conversation_summary'], $common),
            $this === self::ReportWeeklySummary => array_merge(['period', 'metrics_json'], $common),

            default => $common,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function scoringTasks(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t): bool => $t->isScoring()));
    }

    /**
     * @return array<int, self>
     */
    public static function promptedTasks(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t): bool => $t->requiresPrompt()));
    }
}
