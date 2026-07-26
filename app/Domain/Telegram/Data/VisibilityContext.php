<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Data;

use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Model;

/**
 * The facts a `visibility_rule` can be evaluated against.
 *
 * Flattened to primitives at construction time so the evaluator never hits the
 * database — a menu render evaluates one rule per button, and N+1 queries there
 * would be felt on every single tap.
 */
final readonly class VisibilityContext
{
    /**
     * @param  array<int, string>  $enabledModules
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public ?int $studentId = null,
        public string $subscriptionStatus = 'none',
        public ?string $level = null,
        public bool $trialExpired = false,
        public bool $isRegistered = false,
        public array $enabledModules = [],
        public array $extra = [],
    ) {}

    public static function forGuest(?Academy $academy = null): self
    {
        return new self(
            trialExpired: self::academyTrialExpired($academy),
            enabledModules: self::academyModules($academy),
        );
    }

    public static function make(?Academy $academy, ?Student $student): self
    {
        if (! $student instanceof Model) {
            return self::forGuest($academy);
        }

        $status = $student->getAttribute('subscription_status');
        $expiresAt = $student->getAttribute('subscription_expires_at');

        // A row can say "active" while the date has already passed; the date wins.
        $isActive = $status === 'active'
            && ($expiresAt === null || $expiresAt > now());

        return new self(
            studentId: (int) $student->getKey(),
            subscriptionStatus: $isActive ? 'active' : (is_string($status) ? $status : 'none'),
            level: is_string($level = $student->getAttribute('level')) ? mb_strtolower($level) : null,
            trialExpired: self::academyTrialExpired($academy),
            isRegistered: true,
            enabledModules: self::academyModules($academy),
        );
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscriptionStatus === 'active';
    }

    public function moduleEnabled(string $module): bool
    {
        return in_array(mb_strtolower($module), $this->enabledModules, true);
    }

    /**
     * @return array<int, string>
     */
    private static function academyModules(?Academy $academy): array
    {
        if (! $academy instanceof Model) {
            return [];
        }

        $enabled = [];

        foreach (ModuleKey::cases() as $module) {
            if ($academy->hasModule($module)) {
                $enabled[] = $module->value;
            }
        }

        return $enabled;
    }

    private static function academyTrialExpired(?Academy $academy): bool
    {
        if (! $academy instanceof Model) {
            return false;
        }

        $trialEndsAt = $academy->getAttribute('trial_ends_at');

        return $trialEndsAt !== null && $trialEndsAt < now();
    }
}
