<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Exceptions\InvalidQuestionContentException;

/**
 * The single gate every question passes through before it can be stored.
 *
 * A malformed question is worse than a missing one: it reaches a student mid
 * practice session, cannot be answered, and is scored as a failure. So this
 * runs on create, on update and on every imported row.
 */
final class QuestionContentValidator
{
    /**
     * @param  array<string, mixed>  $content
     *
     * @throws InvalidQuestionContentException
     */
    public function validate(QuestionType $type, array $content): void
    {
        $errors = $this->errorsFor($type, $content);

        if ($errors !== []) {
            throw InvalidQuestionContentException::for($type, $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function passes(QuestionType $type, array $content): bool
    {
        return $this->errorsFor($type, $content) === [];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    public function errorsFor(QuestionType $type, array $content): array
    {
        $errors = match ($type) {
            QuestionType::ReadAloud => $this->readAloud($content),
            QuestionType::RepeatSentence => $this->repeatSentence($content),
            QuestionType::DescribeImage => $this->describeImage($content),
            QuestionType::RetellLecture => $this->retellLecture($content),
            QuestionType::AnswerShortQuestion => $this->answerShortQuestion($content),
            QuestionType::SummarizeSpokenText => $this->summarizeSpokenText($content),
            QuestionType::WriteFromDictation => $this->writeFromDictation($content),
            QuestionType::MultipleChoiceListening,
            QuestionType::MultipleChoiceReading => $this->multipleChoice($type, $content),
            QuestionType::HighlightIncorrectWords => $this->highlightIncorrectWords($content),
            QuestionType::FillInBlanksListening,
            QuestionType::FillInBlanksReading,
            QuestionType::FillInBlanksReadingWriting => $this->fillInBlanks($type, $content),
            QuestionType::SelectMissingWord => $this->selectMissingWord($content),
            QuestionType::ReorderParagraphs => $this->reorderParagraphs($content),
            QuestionType::SummarizeWrittenText => $this->summarizeWrittenText($content),
            QuestionType::Essay => $this->essay($content),
        };

        return array_filter($errors, static fn (array $messages): bool => $messages !== []);
    }

    /**
     * Options are a sibling table, so they are validated separately — but the
     * rules belong here with the rest of the schema knowledge.
     *
     * @param  array<int, array<string, mixed>>  $options
     * @param  array<string, mixed>  $content  Read for the `multiple` flag.
     * @return array<string, array<int, string>>
     */
    public function errorsForOptions(QuestionType $type, array $options, array $content = []): array
    {
        if (! $this->requiresOptions($type)) {
            return [];
        }

        $errors = [];
        $keys = [];
        $correct = 0;

        foreach ($options as $index => $option) {
            $key = (string) ($option['key'] ?? $option['option_key'] ?? '');
            $text = trim((string) ($option['text'] ?? ''));

            if ($key === '') {
                $errors['options'][] = sprintf('Option #%d has no key.', $index + 1);
            } elseif (in_array($key, $keys, true)) {
                $errors['options'][] = sprintf('Duplicate option key [%s].', $key);
            } else {
                $keys[] = $key;
            }

            if ($text === '') {
                $errors['options'][] = sprintf('Option [%s] has no text.', $key !== '' ? $key : (string) ($index + 1));
            }

            if (! empty($option['is_correct'])) {
                $correct++;
            }
        }

        if (count($options) < 2) {
            $errors['options'][] = 'At least two options are required.';
        }

        if ($correct === 0) {
            $errors['options'][] = 'At least one option must be marked correct.';
        }

        if ($correct > 1 && ! $this->allowsMultipleCorrect($type, $content)) {
            $errors['options'][] = 'Only one option may be correct for a single-answer question.';
        }

        return $errors;
    }

    public function requiresOptions(QuestionType $type): bool
    {
        return in_array($type, [
            QuestionType::MultipleChoiceListening,
            QuestionType::MultipleChoiceReading,
            QuestionType::SelectMissingWord,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function readAloud(array $content): array
    {
        $text = $this->text($content, 'text');

        return [
            'text' => $this->requireText($text, 'text', 20, 500),
            'prep_seconds' => $this->requirePositiveInt($content, 'prep_seconds', 5, 300, required: false),
            'record_seconds' => $this->requirePositiveInt($content, 'record_seconds', 5, 300, required: false),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function repeatSentence(array $content): array
    {
        return [
            'audio_key' => $this->requireText($this->text($content, 'audio_key'), 'audio_key', 1, 255),
            'transcript' => $this->requireText($this->text($content, 'transcript'), 'transcript', 5, 500),
            'record_seconds' => $this->requirePositiveInt($content, 'record_seconds', 3, 120, required: false),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function describeImage(array $content): array
    {
        return [
            'image_key' => $this->requireText($this->text($content, 'image_key'), 'image_key', 1, 255),
            'prep_seconds' => $this->requirePositiveInt($content, 'prep_seconds', 5, 300, required: false),
            'record_seconds' => $this->requirePositiveInt($content, 'record_seconds', 5, 300, required: false),
            'key_points' => $this->requireStringList($content, 'key_points', required: false),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function retellLecture(array $content): array
    {
        return [
            'audio_key' => $this->requireText($this->text($content, 'audio_key'), 'audio_key', 1, 255),
            'transcript' => $this->requireText($this->text($content, 'transcript'), 'transcript', 20, 10000),
            'record_seconds' => $this->requirePositiveInt($content, 'record_seconds', 5, 300, required: false),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function answerShortQuestion(array $content): array
    {
        $errors = [
            'audio_key' => $this->requireText($this->text($content, 'audio_key'), 'audio_key', 1, 255),
            'transcript' => $this->requireText($this->text($content, 'transcript'), 'transcript', 3, 500),
            'accepted_answers' => $this->requireStringList($content, 'accepted_answers'),
        ];

        // Scoring is exact keyword matching, so a blank alternative would make
        // any answer correct.
        foreach ($this->stringList($content, 'accepted_answers') as $answer) {
            if (trim($answer) === '') {
                $errors['accepted_answers'][] = 'Accepted answers may not be blank.';
                break;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function summarizeSpokenText(array $content): array
    {
        return [
            'audio_key' => $this->requireText($this->text($content, 'audio_key'), 'audio_key', 1, 255),
            'transcript' => $this->requireText($this->text($content, 'transcript'), 'transcript', 50, 20000),
            'words' => $this->requireWordRange($content, 5, 500),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function writeFromDictation(array $content): array
    {
        $transcript = $this->text($content, 'transcript');
        $errors = ['transcript' => $this->requireText($transcript, 'transcript', 10, 300)];

        $words = preg_split('/\s+/u', trim($transcript), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($errors['transcript'] === [] && count($words) < 3) {
            $errors['transcript'][] = 'A dictation sentence must have at least three words.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function multipleChoice(QuestionType $type, array $content): array
    {
        $errors = ['prompt' => $this->requireText($this->text($content, 'prompt'), 'prompt', 5, 1000)];

        if ($type === QuestionType::MultipleChoiceListening) {
            $errors['audio_key'] = $this->requireText($this->text($content, 'audio_key'), 'audio_key', 1, 255);
        } else {
            $errors['passage'] = $this->requireText($this->text($content, 'passage'), 'passage', 50, 20000);
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function highlightIncorrectWords(array $content): array
    {
        $errors = [
            'audio_key' => $this->requireText($this->text($content, 'audio_key'), 'audio_key', 1, 255),
            'display_text' => $this->requireText($this->text($content, 'display_text'), 'display_text', 50, 20000),
            'spoken_text' => $this->requireText($this->text($content, 'spoken_text'), 'spoken_text', 50, 20000),
        ];

        $words = preg_split('/\s+/u', trim($this->text($content, 'display_text')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rawErrors = $content['errors'] ?? null;

        if (! is_array($rawErrors) || $rawErrors === []) {
            $errors['errors'][] = 'At least one incorrect word must be declared.';

            return $errors;
        }

        $seen = [];

        foreach ($rawErrors as $position => $error) {
            $label = 'errors.'.$position;

            if (! is_array($error)) {
                $errors['errors'][] = $label.' must be an object.';

                continue;
            }

            $index = $error['index'] ?? null;

            if (! is_numeric($index)) {
                $errors['errors'][] = $label.' is missing a numeric word index.';

                continue;
            }

            $index = (int) $index;

            if ($index < 0 || $index >= count($words)) {
                $errors['errors'][] = sprintf('%s points at word #%d but the text has %d words.', $label, $index, count($words));
            }

            if (in_array($index, $seen, true)) {
                $errors['errors'][] = sprintf('Word #%d is declared incorrect twice.', $index);
            }

            $seen[] = $index;

            if (trim((string) ($error['spoken'] ?? '')) === '') {
                $errors['errors'][] = $label.' is missing the word that was actually spoken.';
            }

            $shown = trim((string) ($error['shown'] ?? ''));

            if ($shown !== '' && isset($words[$index]) && ! $this->looselyEqual($shown, $words[$index])) {
                $errors['errors'][] = sprintf('%s says the shown word is "%s" but word #%d is "%s".', $label, $shown, $index, $words[$index]);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function fillInBlanks(QuestionType $type, array $content): array
    {
        $passage = $this->text($content, 'passage');
        $errors = ['passage' => $this->requireText($passage, 'passage', 30, 20000)];

        if ($type === QuestionType::FillInBlanksListening) {
            $errors['audio_key'] = $this->requireText($this->text($content, 'audio_key'), 'audio_key', 1, 255);
        }

        $placeholders = preg_match_all('/\{\{\s*(\d+)\s*\}\}/u', $passage, $matches);
        $blanks = $content['blanks'] ?? null;

        if (! is_array($blanks) || $blanks === []) {
            $errors['blanks'][] = 'At least one blank must be defined.';

            return $errors;
        }

        if ($placeholders !== count($blanks)) {
            $errors['blanks'][] = sprintf(
                'The passage has %d {{n}} placeholders but %d blanks are defined.',
                (int) $placeholders,
                count($blanks)
            );
        }

        foreach ($blanks as $position => $blank) {
            $label = 'blanks.'.$position;

            if (! is_array($blank)) {
                $errors['blanks'][] = $label.' must be an object.';

                continue;
            }

            $answers = $blank['answers'] ?? (isset($blank['answer']) ? [$blank['answer']] : []);

            if (! is_array($answers) || $answers === []) {
                $errors['blanks'][] = $label.' has no accepted answer.';

                continue;
            }

            foreach ($answers as $answer) {
                if (! is_scalar($answer) || trim((string) $answer) === '') {
                    $errors['blanks'][] = $label.' has a blank accepted answer.';
                    break;
                }
            }

            // Dropdown and drag & drop variants need distractors to choose from,
            // and every correct answer has to appear among them.
            if ($type !== QuestionType::FillInBlanksListening) {
                $options = $blank['options'] ?? [];

                if (! is_array($options) || count($options) < 2) {
                    $errors['blanks'][] = $label.' needs at least two options to choose from.';

                    continue;
                }

                $normalisedOptions = array_map(
                    static fn (mixed $option): string => mb_strtolower(trim((string) $option)),
                    $options
                );

                foreach ($answers as $answer) {
                    if (! in_array(mb_strtolower(trim((string) $answer)), $normalisedOptions, true)) {
                        $errors['blanks'][] = sprintf('%s: correct answer "%s" is not among its options.', $label, (string) $answer);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function selectMissingWord(array $content): array
    {
        return [
            'audio_key' => $this->requireText($this->text($content, 'audio_key'), 'audio_key', 1, 255),
            'transcript' => $this->requireText($this->text($content, 'transcript'), 'transcript', 20, 20000),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function reorderParagraphs(array $content): array
    {
        $errors = [];
        $paragraphs = $content['paragraphs'] ?? null;

        if (! is_array($paragraphs) || count($paragraphs) < 2) {
            $errors['paragraphs'][] = 'At least two paragraphs are required.';

            return $errors;
        }

        $keys = [];

        foreach ($paragraphs as $position => $paragraph) {
            $label = 'paragraphs.'.$position;

            if (! is_array($paragraph)) {
                $errors['paragraphs'][] = $label.' must be an object.';

                continue;
            }

            $key = trim((string) ($paragraph['key'] ?? ''));
            $text = trim((string) ($paragraph['text'] ?? ''));

            if ($key === '') {
                $errors['paragraphs'][] = $label.' is missing its key.';
            } elseif (in_array($key, $keys, true)) {
                $errors['paragraphs'][] = sprintf('Paragraph key [%s] is used twice.', $key);
            } else {
                $keys[] = $key;
            }

            if ($text === '') {
                $errors['paragraphs'][] = $label.' is empty.';
            }
        }

        $order = $content['correct_order'] ?? null;

        if (! is_array($order) || $order === []) {
            $errors['correct_order'][] = 'The correct order is required.';

            return $errors;
        }

        $order = array_map(static fn (mixed $key): string => trim((string) $key), $order);

        if (count($order) !== count($paragraphs)) {
            $errors['correct_order'][] = sprintf(
                'The correct order lists %d entries for %d paragraphs.',
                count($order),
                count($paragraphs)
            );
        }

        if (count(array_unique($order)) !== count($order)) {
            $errors['correct_order'][] = 'The correct order repeats a paragraph key.';
        }

        foreach ($order as $key) {
            if (! in_array($key, $keys, true)) {
                $errors['correct_order'][] = sprintf('The correct order references unknown paragraph [%s].', $key);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function summarizeWrittenText(array $content): array
    {
        return [
            'passage' => $this->requireText($this->text($content, 'passage'), 'passage', 100, 20000),
            'words' => $this->requireWordRange($content, 5, 200),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, string>>
     */
    private function essay(array $content): array
    {
        return [
            'prompt' => $this->requireText($this->text($content, 'prompt'), 'prompt', 30, 5000),
            'words' => $this->requireWordRange($content, 50, 1000),
            'duration_minutes' => $this->requirePositiveInt($content, 'duration_minutes', 1, 180, required: false),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function text(array $content, string $key): string
    {
        $value = $content[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, string>
     */
    private function stringList(array $content, string $key): array
    {
        $value = $content[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
    }

    /**
     * @return array<int, string>
     */
    private function requireText(string $value, string $field, int $min, int $max): array
    {
        if ($value === '') {
            return [sprintf('[%s] is required.', $field)];
        }

        $length = mb_strlen($value);

        if ($length < $min) {
            return [sprintf('[%s] must be at least %d characters, got %d.', $field, $min, $length)];
        }

        if ($length > $max) {
            return [sprintf('[%s] must be at most %d characters, got %d.', $field, $max, $length)];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, string>
     */
    private function requirePositiveInt(array $content, string $field, int $min, int $max, bool $required = true): array
    {
        if (! array_key_exists($field, $content) || $content[$field] === null) {
            return $required ? [sprintf('[%s] is required.', $field)] : [];
        }

        if (! is_numeric($content[$field])) {
            return [sprintf('[%s] must be a number.', $field)];
        }

        $value = (int) $content[$field];

        if ($value < $min || $value > $max) {
            return [sprintf('[%s] must be between %d and %d.', $field, $min, $max)];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, string>
     */
    private function requireStringList(array $content, string $field, bool $required = true): array
    {
        $value = $content[$field] ?? null;

        if ($value === null) {
            return $required ? [sprintf('[%s] is required.', $field)] : [];
        }

        if (! is_array($value)) {
            return [sprintf('[%s] must be a list.', $field)];
        }

        if ($required && $value === []) {
            return [sprintf('[%s] must contain at least one entry.', $field)];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, string>
     */
    private function requireWordRange(array $content, int $floor, int $ceiling): array
    {
        $errors = [];
        $min = $content['min_words'] ?? null;
        $max = $content['max_words'] ?? null;

        if ($min !== null && ! is_numeric($min)) {
            $errors[] = '[min_words] must be a number.';
        }

        if ($max !== null && ! is_numeric($max)) {
            $errors[] = '[max_words] must be a number.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $min = $min === null ? $floor : (int) $min;
        $max = $max === null ? $ceiling : (int) $max;

        if ($min < $floor || $max > $ceiling) {
            $errors[] = sprintf('The word range must sit between %d and %d.', $floor, $ceiling);
        }

        if ($min > $max) {
            $errors[] = '[min_words] cannot exceed [max_words].';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function allowsMultipleCorrect(QuestionType $type, array $content): bool
    {
        if ($type === QuestionType::SelectMissingWord) {
            return false;
        }

        return (bool) ($content['multiple'] ?? false);
    }

    /** Punctuation attached to a word should not make the reference fail. */
    private function looselyEqual(string $a, string $b): bool
    {
        $normalise = static fn (string $value): string => mb_strtolower(
            preg_replace('/[^\p{L}\p{N}\']+/u', '', $value) ?? $value
        );

        return $normalise($a) === $normalise($b);
    }
}
