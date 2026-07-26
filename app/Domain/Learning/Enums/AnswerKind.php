<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * The shape of what a student submits. Drives both the bot's input handling and
 * the scorer that will be selected for the answer.
 */
enum AnswerKind: string
{
    case Voice = 'voice';
    case Text = 'text';
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case Blanks = 'blanks';
    case Ordering = 'ordering';

    public function label(): string
    {
        return __('answer_kinds.'.$this->value);
    }
}
