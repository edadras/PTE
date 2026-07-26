<?php

declare(strict_types=1);

namespace App\Domain\AI\Enums;

enum PromptStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return __('ai.prompt_status.'.$this->value);
    }

    public function isUsable(): bool
    {
        return $this === self::Published;
    }
}
