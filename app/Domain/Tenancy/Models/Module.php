<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Learning\Enums\ModuleKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-level registry of capability packs. Not tenant-scoped.
 *
 * The primary key is the module key itself (`pte_speaking`, …) so that
 * `academy_modules.module_key` stays readable in the database.
 *
 * @property string $key
 * @property string $name
 *
 * @see docs/07-database-schema.md §2
 */
final class Module extends Model
{
    use HasFactory;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'question_types' => 'array',
            'config_schema' => 'array',
            'is_beta' => 'boolean',
        ];
    }

    public function academyModules(): HasMany
    {
        return $this->hasMany(AcademyModule::class, 'module_key', 'key')
            ->withoutGlobalScope('academy');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStable(Builder $query): Builder
    {
        return $query->where('is_beta', false);
    }

    public function moduleKey(): ?ModuleKey
    {
        return ModuleKey::tryFrom($this->key);
    }

    public function label(): string
    {
        return $this->moduleKey()?->label() ?? $this->name;
    }
}
