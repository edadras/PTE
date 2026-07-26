<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Student;

use App\Domain\Identity\Actions\AuthenticateTelegramInitData;
use App\Domain\Identity\Actions\IssueStudentToken;
use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Support\StudentOtp;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Student\OtpLoginRequest;
use App\Http\Requests\Api\Student\TelegramAuthRequest;
use App\Http\Resources\StudentProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `/api/student/v1/auth/*` — docs/08 §2.
 *
 * Both routes resolve the tenant from the Host header before they run, and both
 * hand a Student to IssueStudentToken, which refuses to mint a token for a
 * student outside that tenant. There is no path here where an academy id
 * supplied by the caller decides anything.
 */
final class AuthController extends ApiController
{
    public function telegram(
        TelegramAuthRequest $request,
        AuthenticateTelegramInitData $authenticate,
        IssueStudentToken $issue,
    ): JsonResponse {
        $result = $authenticate->handle((string) $request->validated('init_data'));

        return $this->token($request, $issue->handle($result['student']));
    }

    /**
     * Second leg of the OTP flow: the student typed the code the bot gave them.
     *
     * A wrong code and an unknown student produce the same answer, so this
     * endpoint cannot be used to discover which student codes exist (T10).
     */
    public function otp(OtpLoginRequest $request, IssueStudentToken $issue): JsonResponse
    {
        $validated = $request->validated();

        $student = Student::query()
            ->where('student_code', (string) $validated['student_code'])
            ->first();

        if (! $student instanceof Student || ! StudentOtp::verify($student, (string) $validated['code'])) {
            throw ValidationException::withMessages(['code' => __('api.auth.otp_invalid')]);
        }

        return $this->token($request, $issue->handle($student));
    }

    /**
     * @param  array{token: string, token_type: string, expires_in: int, student: Student}  $issued
     */
    private function token(Request $request, array $issued): JsonResponse
    {
        return $this->payload($request, [
            'token' => $issued['token'],
            'token_type' => $issued['token_type'],
            'expires_in' => $issued['expires_in'],
            'student' => StudentProfileResource::make($issued['student'])->resolve($request),
        ]);
    }
}
