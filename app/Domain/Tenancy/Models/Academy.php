<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Identity\Models\AcademyUserRole;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Models\User;
use Database\Factories\AcademyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant itself. Not tenant-scoped — it *is* the scope.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property AcademyStatus $status
 * @property string|null $database_connection
 *
 * @see docs/01-multi-tenancy.md
 */
final class Academy extends Model
{
    /** @use HasFactory<AcademyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AcademyStatus::class,
            'trial_ends_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    |
    | The tenant-scoped children drop the `academy` global scope: the relation's
    | own foreign key already pins them to this academy, and keeping the scope
    | would make an academy invisible to itself whenever another tenant (or no
    | tenant at all) is active — for instance in the platform panel.
    |
    */

    public function settings(): HasOne
    {
        return $this->hasOne(AcademySettings::class)->withoutGlobalScope('academy');
    }

    public function brand(): HasOne
    {
        return $this->hasOne(AcademyBrand::class)->withoutGlobalScope('academy');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(AcademyDomain::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(AcademyModule::class)->withoutGlobalScope('academy');
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class)->withoutGlobalScope('academy');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class)->withoutGlobalScope('academy');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AcademyUserRole::class)->withoutGlobalScope('academy');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'academy_user_roles')
            ->withPivot(['role_id', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /** Telegram bots are owned by the Telegram context. */
    public function bots(): HasMany
    {
        return $this->hasMany(TelegramBot::class)
            ->withoutGlobalScope('academy');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AcademyStatus::Active->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', AcademyStatus::Suspended->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Behaviour
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === AcademyStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === AcademyStatus::Suspended;
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function hasModule(ModuleKey|string $module): bool
    {
        $key = $module instanceof ModuleKey ? $module->value : $module;

        if ($this->relationLoaded('modules')) {
            return $this->modules
                ->contains(fn (AcademyModule $m): bool => $m->module_key === $key && $m->is_enabled);
        }

        return $this->modules()
            ->where('module_key', $key)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function enabledModuleKeys(): array
    {
        return $this->modules()
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->all();
    }

    /**
     * The locale TenantContext installs for this request.
     */
    public function preferredLocale(): ?string
    {
        return $this->settings?->locale ?? $this->brand?->default_locale;
    }

    public function primaryDomain(): ?AcademyDomain
    {
        return $this->domains()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();
    }

    public function url(string $path = '/'): string
    {
        $host = $this->primaryDomain()?->hostname
            ?? $this->slug.'.'.config('pte.platform.root_domain');

        return rtrim('https://'.$host, '/').'/'.ltrim($path, '/');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Models live under app/Domain, so the default factory guesser misses. */
    protected static function newFactory(): AcademyFactory
    {
        return AcademyFactory::new();
    }
}
