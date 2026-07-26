<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Support\StudentToken;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Mints the bearer token the web app and the mobile app use.
 *
 * The tenant is asserted here as well as in the guard: a token must never be
 * issued for a student that does not belong to the academy the request was made
 * against, or a compromised host mapping would become a cross-tenant login.
 *
 * @see docs/08-api-and-integrations.md §2
 */
final class IssueStudentToken
{
    /**
     * @return array{token: string, token_type: string, expires_in: int, student: Student}
     *
     * @throws AuthorizationException when the student is not in the active academy
     */
    public function handle(Student $student, ?int $ttlMinutes = null): array
    {
        $academyId = TenantContext::id();

        if ((int) $student->academy_id !== $academyId) {
            throw new AuthorizationException('This student does not belong to the active academy.');
        }

        $token = StudentToken::issue($student, $ttlMinutes);
        $parsed = StudentToken::parse($token);

        $student->touchLastActive();

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $parsed?->expiresInSeconds() ?? 0,
            'student' => $student,
        ];
    }
}
