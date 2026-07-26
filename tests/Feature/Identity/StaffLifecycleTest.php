<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Actions\DeleteStudent;
use App\Domain\Identity\Actions\InviteStaffMember;
use App\Domain\Identity\Actions\RemoveStaffMember;
use App\Domain\Identity\Actions\SeedAcademyRoles;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\AcademyUserRole;
use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StaffLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        $this->academy = Academy::factory()->configured()->create();
        app(SeedAcademyRoles::class)->handle($this->academy);
        TenantContext::set($this->academy);
    }

    #[Test]
    public function inviting_a_staff_member_is_audited(): void
    {
        $inviter = User::factory()->create();

        $membership = app(InviteStaffMember::class)->handle(
            $this->academy,
            'teacher@example.com',
            SystemRole::Teacher,
            'New Teacher',
            $inviter,
        );

        $this->assertSame(MembershipStatus::Invited, $membership->status);

        $entry = ActivityLog::query()
            ->forAction(AuditAction::StaffInvited->value)
            ->forSubject($membership)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(SystemRole::Teacher->value, $entry->new_values['role']);
        $this->assertSame((int) $inviter->getKey(), (int) $entry->new_values['invited_by']);
        $this->assertSame((int) $this->academy->getKey(), (int) $entry->academy_id);
    }

    #[Test]
    public function an_invitation_grants_nothing_until_accepted_and_acceptance_is_a_role_change(): void
    {
        $membership = app(InviteStaffMember::class)->handle(
            $this->academy,
            'manager@example.com',
            SystemRole::Manager,
        );

        // Pending invitation: not yet a role change.
        $this->assertFalse(
            ActivityLog::query()->forAction(AuditAction::RoleChanged->value)->exists(),
        );

        $accepted = $membership->accept();

        $this->assertSame(MembershipStatus::Active, $accepted->status);

        $entry = ActivityLog::query()->forAction(AuditAction::RoleChanged->value)->first();

        $this->assertNotNull($entry);
        $this->assertSame(SystemRole::Manager->value, $entry->new_values['role']);
        $this->assertSame((int) $membership->user_id, (int) $entry->new_values['user_id']);
    }

    #[Test]
    public function removing_a_staff_member_drops_membership_roles_and_is_audited(): void
    {
        $remover = User::factory()->create();

        $membership = app(InviteStaffMember::class)
            ->handle($this->academy, 'leaver@example.com', SystemRole::Teacher)
            ->accept();

        $user = $membership->user;

        $this->assertTrue(
            TenantContext::runFor($this->academy, static fn (): bool => $user->hasRole(SystemRole::Teacher->value)),
        );

        app(RemoveStaffMember::class)->handle($user, $this->academy, $remover);

        $this->assertSame(
            0,
            AcademyUserRole::query()->where('user_id', $user->getKey())->count(),
        );
        $this->assertFalse(
            TenantContext::runFor($this->academy, static fn (): bool => $user->fresh()->hasRole(SystemRole::Teacher->value)),
        );

        $entry = ActivityLog::query()->forAction(AuditAction::StaffRemoved->value)->first();

        $this->assertNotNull($entry);
        $this->assertSame([SystemRole::Teacher->value], $entry->old_values['roles']);
        $this->assertSame((int) $remover->getKey(), (int) $entry->new_values['removed_by']);
    }

    #[Test]
    public function deleting_a_student_soft_deletes_and_writes_an_audit_entry_without_personal_data(): void
    {
        $actor = User::factory()->create();
        $student = Student::factory()->create(['first_name' => 'Sara']);

        app(DeleteStudent::class)->handle($student, (int) $actor->getKey());

        $this->assertSoftDeleted('students', ['id' => $student->getKey()]);

        $entry = ActivityLog::query()
            ->forAction(AuditAction::StudentDeleted->value)
            ->forSubject($student)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($student->student_code, $entry->new_values['student_code']);
        $this->assertSame((int) $actor->getKey(), (int) $entry->new_values['deleted_by']);

        // The soft-deleted row keeps the personal data; the append-only trail
        // must not duplicate it.
        $this->assertArrayNotHasKey('first_name', $entry->new_values);
        $this->assertStringNotContainsString('Sara', (string) json_encode($entry->new_values));
    }
}
