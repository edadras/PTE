<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\PlatformAuditLog;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Tenancy\Models\Academy;
use App\Filament\Support\Impersonation;
use App\Filament\Support\Notifications\AcademyImpersonated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/02 §2 allows impersonation only with all three of: a hard 30 minute
 * window, a permanent red banner, and an audit entry plus owner notification.
 * Each of the three is asserted here — if one regresses, the feature is not
 * allowed to ship.
 */
final class ImpersonationTest extends FilamentTestCase
{
    use RefreshDatabase;

    private Academy $academy;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academy = $this->makeAcademy('zeta');
        $this->forgetHostCache($this->academy);

        $this->owner = $this->makeStaff($this->academy, SystemRole::Owner);
        $this->academy->forceFill(['owner_user_id' => $this->owner->getKey()])->save();

        $this->admin = User::factory()->superAdmin()->create();
    }

    #[Test]
    public function the_handover_writes_an_audit_row_and_notifies_the_owner(): void
    {
        Notification::fake();

        Impersonation::handoverUrl($this->academy->refresh(), $this->admin);

        $this->assertDatabaseHas('platform_audit_logs', [
            'user_id' => $this->admin->getKey(),
            'action' => AuditAction::Impersonated->value,
            'target_academy_id' => $this->academy->getKey(),
        ]);

        Notification::assertSentTo($this->owner, AcademyImpersonated::class);
    }

    #[Test]
    public function following_the_signed_link_opens_the_panel_with_a_red_banner(): void
    {
        Notification::fake();

        $url = Impersonation::handoverUrl($this->academy->refresh(), $this->admin);

        $this->get($url)->assertRedirect();

        $this->get($this->panelUrl($this->academy))
            ->assertSuccessful()
            ->assertSee(__('panel.impersonation.stop'));
    }

    #[Test]
    public function the_handover_link_is_single_use(): void
    {
        Notification::fake();

        $url = Impersonation::handoverUrl($this->academy->refresh(), $this->admin);

        $this->get($url)->assertRedirect();

        // A fresh browser replaying the same link must be refused.
        $this->flushSession();

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function a_super_admin_without_an_active_session_cannot_reach_the_panel(): void
    {
        $this->actingAs($this->admin)
            ->get($this->panelUrl($this->academy))
            ->assertForbidden();
    }

    #[Test]
    public function the_session_closes_itself_after_thirty_minutes(): void
    {
        Notification::fake();

        $url = Impersonation::handoverUrl($this->academy->refresh(), $this->admin);
        $this->get($url)->assertRedirect();

        $this->travel(Impersonation::DURATION_MINUTES + 1)->minutes();

        $this->get($this->panelUrl($this->academy))->assertRedirect();

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::Impersonated->value,
            'target_academy_id' => $this->academy->getKey(),
        ]);

        $this->assertGreaterThanOrEqual(
            2,
            PlatformAuditLog::query()->where('action', AuditAction::Impersonated->value)->count(),
        );
    }
}
