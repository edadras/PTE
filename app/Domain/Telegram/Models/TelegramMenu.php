<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Models;

use App\Domain\Telegram\Enums\MenuType;
use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\TelegramMenuFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $academy_id
 * @property MenuType $type
 * @property PublishStatus $status
 * @property int $version
 * @property bool $is_active
 */
final class TelegramMenu extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<TelegramMenuFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => MenuType::class,
            'status' => PublishStatus::class,
            'version' => 'integer',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TelegramMenuFactory
    {
        return TelegramMenuFactory::new();
    }

    /** @return HasMany<TelegramMenuItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TelegramMenuItem::class, 'menu_id')->orderBy('sort_order');
    }

    /** @return HasMany<TelegramMenuItem, $this> */
    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('status', PublishStatus::Published);
    }

    /** @param  Builder<self>  $query */
    public function scopeOfType(Builder $query, MenuType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function isLive(): bool
    {
        return $this->is_active && $this->status->isLive();
    }
}
