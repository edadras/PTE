<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $academy_id
 * @property string $module_key
 * @property bool $is_enabled
 *
 * @see docs/07-database-schema.md §2
 */
final class AcademyModule extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
            'enabled_at' => 'datetime',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_key', 'key');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function key(): ?ModuleKey
    {
        return ModuleKey::tryFrom($this->module_key);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
