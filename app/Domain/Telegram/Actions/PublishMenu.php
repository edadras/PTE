<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Actions;

use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Telegram\Models\TelegramMenu;
use App\Domain\Telegram\Services\MenuEngine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Makes a draft menu the live one, and busts the rendered-menu cache.
 *
 * Publishing is a swap, not an edit: the previously live version stays in the
 * table as a published-but-inactive row, which is what makes one-click rollback
 * possible.
 *
 * @see docs/04-telegram-layer.md §5
 */
final class PublishMenu
{
    public function __construct(private readonly MenuEngine $menus) {}

    public function handle(TelegramMenu $menu, ?int $userId = null): TelegramMenu
    {
        if ($menu->items()->count() === 0) {
            throw new RuntimeException('A menu cannot be published with no items.');
        }

        DB::transaction(function () use ($menu, $userId): void {
            // Only one live menu per type, per academy.
            TelegramMenu::query()
                ->where('type', $menu->type)
                ->whereKeyNot($menu->getKey())
                ->update(['is_active' => false]);

            $menu->forceFill([
                'status' => PublishStatus::Published,
                'is_active' => true,
                'published_at' => now(),
                'published_by' => $userId,
            ])->save();
        });

        $this->menus->forget($menu->academy_id);

        return $menu->refresh();
    }

    /**
     * Roll back to a previously published version of the same type.
     */
    public function rollbackTo(TelegramMenu $menu): TelegramMenu
    {
        if ($menu->status !== PublishStatus::Published) {
            throw new RuntimeException('Only a previously published menu can be restored.');
        }

        return $this->handle($menu);
    }

    /**
     * Clone the live menu into a new draft version for editing.
     */
    public function draftFrom(TelegramMenu $menu): TelegramMenu
    {
        return DB::transaction(function () use ($menu): TelegramMenu {
            /** @var TelegramMenu $draft */
            $draft = $menu->replicate(['published_at', 'published_by']);
            $draft->status = PublishStatus::Draft;
            $draft->is_active = false;
            $draft->version = $menu->version + 1;
            $draft->save();

            // parent_id must point at the *copy*, not the original, so the
            // hierarchy is remapped as items are cloned.
            $idMap = [];

            foreach ($menu->items()->orderBy('id')->get() as $item) {
                $copy = $item->replicate();
                $copy->menu_id = $draft->getKey();
                $copy->parent_id = $item->parent_id === null ? null : ($idMap[$item->parent_id] ?? null);
                $copy->save();

                $idMap[$item->getKey()] = $copy->getKey();
            }

            return $draft;
        });
    }
}
