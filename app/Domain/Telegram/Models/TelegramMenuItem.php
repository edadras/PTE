<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Models;

use App\Domain\Telegram\Enums\MenuActionType;
use App\Domain\Telegram\Support\CallbackData;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\TelegramMenuItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $academy_id
 * @property int $menu_id
 * @property int|null $parent_id
 * @property string $label
 * @property string|null $icon
 * @property MenuActionType $action_type
 * @property array<string, mixed>|null $action_payload
 * @property array<string, mixed>|null $visibility_rule
 * @property int $row
 * @property int $column
 * @property bool $is_enabled
 */
final class TelegramMenuItem extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<TelegramMenuItemFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action_type' => MenuActionType::class,
            'action_payload' => 'array',
            'visibility_rule' => 'array',
            'row' => 'integer',
            'column' => 'integer',
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): TelegramMenuItemFactory
    {
        return TelegramMenuItemFactory::new();
    }

    /** @return BelongsTo<TelegramMenu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(TelegramMenu::class, 'menu_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** Icon + label, exactly as it appears on the button. */
    public function buttonText(): string
    {
        return trim(((string) $this->icon).' '.$this->label);
    }

    public function payloadValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->action_payload, $key, $default);
    }

    public function callbackData(): CallbackData
    {
        return CallbackData::make(
            action: $this->action_type->callbackToken(),
            target: (string) $this->id,
            param: null,
        );
    }

    /** @param  Builder<self>  $query */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }
}
