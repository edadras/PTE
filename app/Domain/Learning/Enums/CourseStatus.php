<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * Shared by courses and lessons — both are simply draft or published.
 */
enum CourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return __('learning.course_status.'.$this->value);
    }

    public function isVisibleToStudents(): bool
    {
        return $this === self::Published;
    }
}
