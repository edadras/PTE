<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * REST API credential. Only the SHA-256 hash is persisted — the plaintext is
 * returned once, by ApiKey::issue(), and is unrecoverable afterwards.
 *
 * @property int $academy_id
 * @property string $token_hash
 * @property array<int, string>|null $scopes
 *
 * @see docs/01-multi-tenancy.md §4.3
 */
final class ApiKey extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    public const PREFIX = 'pte_live_ak_';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return $scopes === [] || in_array($scope, $scopes, true) || in_array('*', $scopes, true);
    }

    public function markUsed(): void
    {
        // Quiet + direct update: touching this on every API call must not fire
        // model events or bump updated_at.
        self::query()
            ->withoutGlobalScope('academy')
            ->whereKey($this->getKey())
            ->update(['last_used_at' => now()]);
    }

    public static function hashToken(string $plainText): string
    {
        return hash('sha256', trim($plainText));
    }

    /**
     * @param  array<int, string>  $scopes
     * @return array{key: self, plain_text: string}
     */
    public static function issue(string $name, array $scopes = [], ?User $creator = null): array
    {
        $plain = self::PREFIX.Str::lower((string) Str::ulid()).Str::random(16);

        $key = new self([
            'name' => $name,
            'prefix' => self::PREFIX,
            'token_hash' => self::hashToken($plain),
            'last_four' => substr($plain, -4),
            'scopes' => $scopes,
            'created_by' => $creator?->getKey(),
        ]);

        $key->save();

        return ['key' => $key, 'plain_text' => $plain];
    }
}
