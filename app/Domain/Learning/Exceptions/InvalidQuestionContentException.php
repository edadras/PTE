<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use App\Domain\Learning\Enums\QuestionType;
use RuntimeException;

/**
 * Raised when a question's content JSON does not satisfy its type's schema.
 *
 * Carries the per-field errors so an import report or a Filament form can show
 * exactly what is wrong instead of "invalid content".
 */
final class InvalidQuestionContentException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        public readonly QuestionType $type,
        public readonly array $errors,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : self::summarise($type, $errors));
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function for(QuestionType $type, array $errors): self
    {
        return new self($type, $errors);
    }

    /**
     * @return array<int, string>
     */
    public function flatErrors(): array
    {
        $flat = [];

        foreach ($this->errors as $field => $messages) {
            foreach ($messages as $message) {
                $flat[] = $field.': '.$message;
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private static function summarise(QuestionType $type, array $errors): string
    {
        $parts = [];

        foreach ($errors as $field => $messages) {
            $parts[] = $field.' ('.implode('; ', $messages).')';
        }

        return sprintf('Invalid content for %s question: %s', $type->value, implode(', ', $parts));
    }
}
