<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Support\StudentToken;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the Student API.
 *
 * The rule of docs/08 §2 is implemented literally here: the `aid` claim inside
 * the token is read but never trusted. The tenant has already been resolved from
 * the Host header by `tenant.domain`; the student row is then loaded *inside*
 * that tenant scope and its own `academy_id` is compared. A token minted for
 * academy A therefore resolves to nothing on academy B's host — and the answer
 * is 401, not 403, so the token cannot be used as an oracle for "does this
 * student exist over there".
 */
final class StudentTokenGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            throw new AuthenticationException(__('api.errors.unauthenticated'));
        }

        $token = StudentToken::parse($bearer);

        if ($token === null) {
            throw new AuthenticationException(__('api.errors.token_invalid'));
        }

        $academyId = TenantContext::id();

        // Tenant-scoped by the global scope: a foreign id simply is not found.
        $student = Student::query()->find($token->studentId);

        if (! $student instanceof Student
            || (int) $student->academy_id !== $academyId
            || $token->academyId !== $academyId) {
            throw new AuthenticationException(__('api.errors.token_tenant_mismatch'));
        }

        if ($student->status === StudentStatus::Blocked) {
            throw new AuthenticationException(__('api.errors.student_blocked'));
        }

        $request->attributes->set('student', $student);
        $request->attributes->set('student_token', $token);

        return $next($request);
    }
}
