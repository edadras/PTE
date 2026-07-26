<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

/**
 * Draft/published lifecycle shared by menus and flows.
 *
 * Editing always happens on a draft; publishing flips the version live and
 * busts the rendered-menu cache.
 */
enum PublishStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return __('telegram.publish_status.'.$this->value);
    }

    public function isLive(): bool
    {
        return $this === self::Published;
    }
}
