<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Tenant isolation is handled by the global scope; this policy adds the second
 * boundary docs/02 requires: a teacher only reaches students in the class
 * groups assigned to them.
 *
 * @see docs/02-roles-and-rbac.md §2 (Teacher), §6
 */
final class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('students.view');
    }

    public function view(User $user, Student $student): bool
    {
        if (! $user->can('students.view')) {
            return false;
        }

        return $this->reaches($user, $student);
    }

    public function create(User $user): bool
    {
        return $user->can('students.create');
    }

    public function update(User $user, Student $student): bool
    {
        if (! $user->can('students.update')) {
            return false;
        }

        return $this->reaches($user, $student);
    }

    public function delete(User $user, Student $student): bool
    {
        if (! $user->can('students.delete')) {
            return false;
        }

        return $this->reaches($user, $student);
    }

    public function restore(User $user, Student $student): bool
    {
        return $this->delete($user, $student);
    }

    public function forceDelete(User $user, Student $student): bool
    {
        return $user->can('students.delete') && $this->isPrivileged($user);
    }

    public function import(User $user): bool
    {
        return $user->can('students.import');
    }

    public function export(User $user): bool
    {
        return $user->can('students.export');
    }

    /**
     * A student outside the caller's academy must look non-existent, so this
     * returns false rather than leaking through a 403 elsewhere.
     */
    private function reaches(User $user, Student $student): bool
    {
        $academyId = TenantContext::idOrNull();

        if ($academyId !== null && (int) $student->academy_id !== $academyId) {
            return false;
        }

        if (! $this->isTeacherOnly($user)) {
            return true;
        }

        return $student->isTaughtBy($user);
    }

    private function isTeacherOnly(User $user): bool
    {
        return $user->hasRole(SystemRole::Teacher->value) && ! $this->isPrivileged($user);
    }

    private function isPrivileged(User $user): bool
    {
        return $user->hasAnyRole([SystemRole::Owner->value, SystemRole::Manager->value]);
    }
}
