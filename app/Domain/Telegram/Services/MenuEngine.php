<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Data\VisibilityContext;
use App\Domain\Telegram\Enums\MenuType;
use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Telegram\Models\TelegramMenu;
use App\Domain\Telegram\Models\TelegramMenuItem;
use App\Domain\Telegram\Support\VisibilityRule;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Turns `telegram_menu_items` rows into Bot API keyboard markup.
 *
 * The item rows are cached per academy (the expensive part is the query, not
 * the rendering); visibility rules are evaluated *after* the cache, because
 * they depend on the student and would otherwise leak one student's view to
 * another.
 *
 * @see docs/04-telegram-layer.md §5
 */
final class MenuEngine
{
    private const CACHE_TTL = 3600;

    /**
     * Build the reply/inline markup for the academy's active menu of a type.
     *
     * @return array<string, mixed>|null null when the academy has no such menu
     */
    public function render(
        MenuType $type,
        VisibilityContext $context,
        ?int $parentItemId = null,
        ?int $academyId = null,
    ): ?array {
        $items = $this->visibleItems($type, $context, $parentItemId, $academyId);

        if ($items->isEmpty()) {
            return null;
        }

        return $type->isReplyKeyboard() && ! $this->needsInline($items)
            ? $this->replyKeyboard($items, $type)
            : $this->inlineKeyboard($items);
    }

    /**
     * Items of the active menu that this student may see, in grid order.
     *
     * @return Collection<int, TelegramMenuItem>
     */
    public function visibleItems(
        MenuType $type,
        VisibilityContext $context,
        ?int $parentItemId = null,
        ?int $academyId = null,
    ): Collection {
        return $this->cachedItems($type, $academyId)
            ->filter(fn (TelegramMenuItem $item): bool => $item->parent_id === $parentItemId)
            ->filter(fn (TelegramMenuItem $item): bool => $item->is_enabled)
            ->filter(fn (TelegramMenuItem $item): bool => VisibilityRule::passes($item->visibility_rule, $context))
            ->values();
    }

    /**
     * Match a plain text message against the labels of the active reply
     * keyboard. This is the "menu label match" branch of the router.
     */
    public function matchLabel(string $text, VisibilityContext $context, ?int $academyId = null): ?TelegramMenuItem
    {
        $needle = $this->normalise($text);

        if ($needle === '') {
            return null;
        }

        foreach ([MenuType::Main, MenuType::Persistent] as $type) {
            $match = $this->cachedItems($type, $academyId)
                ->first(function (TelegramMenuItem $item) use ($needle, $context): bool {
                    if (! $item->is_enabled) {
                        return false;
                    }

                    if (! VisibilityRule::passes($item->visibility_rule, $context)) {
                        return false;
                    }

                    return $this->normalise($item->buttonText()) === $needle
                        || $this->normalise($item->label) === $needle;
                });

            if ($match instanceof TelegramMenuItem) {
                return $match;
            }
        }

        return null;
    }

    public function findItem(int $itemId, ?int $academyId = null): ?TelegramMenuItem
    {
        foreach (MenuType::cases() as $type) {
            $item = $this->cachedItems($type, $academyId)->firstWhere('id', $itemId);

            if ($item instanceof TelegramMenuItem) {
                return $item;
            }
        }

        return TelegramMenuItem::query()->find($itemId);
    }

    public function activeMenu(MenuType $type, ?int $academyId = null): ?TelegramMenu
    {
        $menuId = $this->cachedMenuId($type, $academyId);

        return $menuId === null ? null : TelegramMenu::query()->find($menuId);
    }

    /**
     * Drop the rendered-menu cache for an academy.
     *
     * Called by PublishMenu; also safe to call after a module toggle, since
     * that changes which items are visible.
     */
    public function forget(?int $academyId = null): void
    {
        $academyId ??= TenantContext::id();

        foreach (MenuType::cases() as $type) {
            Cache::forget($this->cacheKey($type, $academyId));
        }
    }

    // -------------------------------------------------------------- rendering

    /**
     * @param  Collection<int, TelegramMenuItem>  $items
     * @return array<string, mixed>
     */
    public function replyKeyboard(Collection $items, MenuType $type = MenuType::Main): array
    {
        $rows = [];

        foreach ($this->grid($items) as $row) {
            $rows[] = array_map(
                static fn (TelegramMenuItem $item): array => ['text' => $item->buttonText()],
                $row
            );
        }

        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'is_persistent' => $type === MenuType::Persistent,
            'one_time_keyboard' => false,
        ];
    }

    /**
     * @param  Collection<int, TelegramMenuItem>  $items
     * @return array<string, mixed>
     */
    public function inlineKeyboard(Collection $items): array
    {
        $rows = [];

        foreach ($this->grid($items) as $row) {
            $buttons = [];

            foreach ($row as $item) {
                $buttons[] = $this->inlineButton($item);
            }

            if ($buttons !== []) {
                $rows[] = $buttons;
            }
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function inlineButton(TelegramMenuItem $item): array
    {
        $text = $item->buttonText();

        if ($item->action_type->requiresInlineKeyboard()) {
            $url = $item->payloadValue('url');

            if (is_string($url) && $url !== '') {
                return ['text' => $text, 'url' => $url];
            }

            $path = $item->payloadValue('path');

            if (is_string($path) && $path !== '') {
                return ['text' => $text, 'web_app' => ['url' => $path]];
            }
        }

        return ['text' => $text, 'callback_data' => $item->callbackData()->encode()];
    }

    /**
     * Group items into keyboard rows.
     *
     * `row` is authoritative when the academy laid the grid out by hand; items
     * left at the default row 0 fall back to sort_order, one per row.
     *
     * @param  Collection<int, TelegramMenuItem>  $items
     * @return array<int, array<int, TelegramMenuItem>>
     */
    private function grid(Collection $items): array
    {
        $sorted = $items->sortBy([
            fn (TelegramMenuItem $a, TelegramMenuItem $b): int => $a->row <=> $b->row,
            fn (TelegramMenuItem $a, TelegramMenuItem $b): int => $a->column <=> $b->column,
            fn (TelegramMenuItem $a, TelegramMenuItem $b): int => $a->sort_order <=> $b->sort_order,
        ]);

        $rows = [];

        foreach ($sorted as $index => $item) {
            $key = $item->row > 0 ? 'r'.$item->row : 'auto'.$index;
            $rows[$key][] = $item;
        }

        return array_values($rows);
    }

    /** A single URL/web-app button forces the whole menu inline. */
    private function needsInline(Collection $items): bool
    {
        return $items->contains(
            fn (TelegramMenuItem $item): bool => $item->action_type->requiresInlineKeyboard()
        );
    }

    // ----------------------------------------------------------------- caching

    /**
     * @return Collection<int, TelegramMenuItem>
     */
    private function cachedItems(MenuType $type, ?int $academyId = null): Collection
    {
        $academyId ??= TenantContext::id();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Cache::remember(
            $this->cacheKey($type, $academyId),
            self::CACHE_TTL,
            function () use ($type): array {
                $menu = TelegramMenu::query()
                    ->where('type', $type)
                    ->where('is_active', true)
                    ->where('status', PublishStatus::Published)
                    ->orderByDesc('version')
                    ->first();

                if (! $menu instanceof TelegramMenu) {
                    return [];
                }

                return TelegramMenuItem::query()
                    ->where('menu_id', $menu->getKey())
                    ->orderBy('sort_order')
                    ->get()
                    // Raw attributes, not attributesToArray(): casts must be
                    // applied on rehydration, not baked into the cache.
                    ->map(static fn (TelegramMenuItem $item): array => $item->getAttributes())
                    ->all();
            }
        );

        return collect($rows)->map(function (array $row): TelegramMenuItem {
            $item = new TelegramMenuItem;
            $item->setRawAttributes($row, true);
            $item->exists = true;

            return $item;
        });
    }

    private function cachedMenuId(MenuType $type, ?int $academyId = null): ?int
    {
        $first = $this->cachedItems($type, $academyId)->first();

        return $first instanceof TelegramMenuItem ? (int) $first->menu_id : null;
    }

    /** Cache prefix is already tenant-scoped by TenantContext::set(). */
    private function cacheKey(MenuType $type, int $academyId): string
    {
        return "ac{$academyId}:menu:active:{$type->value}";
    }

    /**
     * Telegram echoes the button text back verbatim, but clients differ in the
     * whitespace around emoji — so labels are compared loosely.
     */
    private function normalise(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }
}
