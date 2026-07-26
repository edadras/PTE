<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

enum ExamStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return __('assessment.exam_status.'.$this->value);
    }

    /** Only a published exam may be started by a student. */
    public function isStartable(): bool
    {
        return $this === self::Published;
    }
}
