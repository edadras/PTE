<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

use RuntimeException;

/**
 * Base for every domain failure in Assessment.
 *
 * Each carries a translation key so the Telegram layer and the REST API can
 * render the same failure in the student's language without re-deriving it from
 * an exception class name.
 */
abstract class AssessmentException extends RuntimeException
{
    /** @var array<string, scalar|null> */
    protected array $context = [];

    abstract public function translationKey(): string;

    /** @return array<string, scalar|null> */
    public function context(): array
    {
        return $this->context;
    }

    public function userMessage(): string
    {
        return __($this->translationKey(), array_map(
            static fn (mixed $value): string => (string) $value,
            $this->context
        ));
    }
}
