<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data;

use App\Domain\Learning\Data\Content\AnswerShortQuestionContent;
use App\Domain\Learning\Data\Content\DescribeImageContent;
use App\Domain\Learning\Data\Content\EssayContent;
use App\Domain\Learning\Data\Content\FillInBlanksContent;
use App\Domain\Learning\Data\Content\HighlightIncorrectWordsContent;
use App\Domain\Learning\Data\Content\MultipleChoiceContent;
use App\Domain\Learning\Data\Content\ReadAloudContent;
use App\Domain\Learning\Data\Content\ReorderParagraphsContent;
use App\Domain\Learning\Data\Content\RepeatSentenceContent;
use App\Domain\Learning\Data\Content\RetellLectureContent;
use App\Domain\Learning\Data\Content\SelectMissingWordContent;
use App\Domain\Learning\Data\Content\SummarizeSpokenTextContent;
use App\Domain\Learning\Data\Content\SummarizeWrittenTextContent;
use App\Domain\Learning\Data\Content\WriteFromDictationContent;
use App\Domain\Learning\Enums\QuestionType;

/**
 * Base for the typed views of `questions.content`.
 *
 * The JSON column stays flexible on disk, but nothing in the application should
 * reach into it with string keys — everything goes through one of these.
 *
 * @see docs/05-modules-exams-practice.md §3
 */
abstract readonly class QuestionContent
{
    /**
     * @param  array<string, mixed>  $data
     */
    abstract public static function fromArray(array $data): static;

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @return class-string<self>
     */
    public static function classFor(QuestionType $type): string
    {
        return match ($type) {
            QuestionType::ReadAloud => ReadAloudContent::class,
            QuestionType::RepeatSentence => RepeatSentenceContent::class,
            QuestionType::DescribeImage => DescribeImageContent::class,
            QuestionType::RetellLecture => RetellLectureContent::class,
            QuestionType::AnswerShortQuestion => AnswerShortQuestionContent::class,
            QuestionType::SummarizeSpokenText => SummarizeSpokenTextContent::class,
            QuestionType::WriteFromDictation => WriteFromDictationContent::class,
            QuestionType::MultipleChoiceListening,
            QuestionType::MultipleChoiceReading => MultipleChoiceContent::class,
            QuestionType::HighlightIncorrectWords => HighlightIncorrectWordsContent::class,
            QuestionType::FillInBlanksListening,
            QuestionType::FillInBlanksReading,
            QuestionType::FillInBlanksReadingWriting => FillInBlanksContent::class,
            QuestionType::SelectMissingWord => SelectMissingWordContent::class,
            QuestionType::ReorderParagraphs => ReorderParagraphsContent::class,
            QuestionType::SummarizeWrittenText => SummarizeWrittenTextContent::class,
            QuestionType::Essay => EssayContent::class,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(QuestionType $type, array $data): self
    {
        return self::classFor($type)::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    protected static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected static function stringList(array $data, string $key): array
    {
        return array_values(array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
            self::list($data, $key)
        ));
    }

    /**
     * Drop nulls so a stored content blob stays as small as its schema allows.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function withoutNulls(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }
}
