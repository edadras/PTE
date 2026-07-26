<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Actions\IssueStudentToken;
use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Support\StudentOtp;
use App\Domain\Identity\Support\StudentToken;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Student token issuance and the "never trust `aid`" rule of docs/08 §2.
 */
final class StudentAuthTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_token_authenticates_its_own_student_on_its_own_host(): void
    {
        $academy = $this->academy('alpha');
        $host = $this->hostFor($academy);

        [$student, $token] = $this->issueFor($academy);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('http://'.$host.'/api/student/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', (int) $student->getKey());
    }

    #[Test]
    public function a_token_from_another_academy_is_rejected_on_this_host(): void
    {
        $alpha = $this->academy('alpha');
        $beta = $this->academy('beta');

        $this->hostFor($alpha);
        $betaHost = $this->hostFor($beta);

        [, $alphaToken] = $this->issueFor($alpha);

        // Same valid signature, wrong tenant: 401, and deliberately not 403 —
        // a 403 would confirm the student exists somewhere.
        $this->withHeaders(['Authorization' => 'Bearer '.$alphaToken])
            ->getJson('http://'.$betaHost.'/api/student/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function a_tampered_token_is_rejected(): void
    {
        $academy = $this->academy('alpha');
        $host = $this->hostFor($academy);

        [, $token] = $this->issueFor($academy);

        $this->withHeaders(['Authorization' => 'Bearer '.$token.'x'])
            ->getJson('http://'.$host.'/api/student/v1/me')
            ->assertStatus(401);

        $this->assertNull(StudentToken::parse($token.'x'));
    }

    #[Test]
    public function an_expired_token_is_rejected(): void
    {
        $academy = $this->academy('alpha');

        $token = TenantContext::runFor($academy, static function (): string {
            $student = Student::factory()->create();

            return StudentToken::issue($student, ttlMinutes: 1);
        });

        $this->travel(2)->minutes();

        $this->assertNull(StudentToken::parse($token));
    }

    #[Test]
    public function a_token_cannot_be_issued_for_a_foreign_student(): void
    {
        $alpha = $this->academy('alpha');
        $beta = $this->academy('beta');

        $foreign = TenantContext::runFor($beta, static fn (): Student => Student::factory()->create());

        TenantContext::set($alpha);

        $this->expectException(AuthorizationException::class);

        app(IssueStudentToken::class)->handle($foreign);
    }

    #[Test]
    public function the_otp_flow_issues_a_token_and_burns_the_code(): void
    {
        $academy = $this->academy('alpha');
        $host = $this->hostFor($academy);

        [$student, $code] = TenantContext::runFor($academy, static function (): array {
            $student = Student::factory()->create();

            return [$student, StudentOtp::issue($student)];
        });

        $payload = ['student_code' => $student->student_code, 'code' => $code];

        $this->postJson('http://'.$host.'/api/student/v1/auth/otp', $payload)
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_in', 'student']]);

        $this->postJson('http://'.$host.'/api/student/v1/auth/otp', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function an_otp_gives_up_after_five_wrong_attempts(): void
    {
        $academy = $this->academy('alpha');

        TenantContext::set($academy);

        $student = Student::factory()->create();
        $code = StudentOtp::issue($student);

        for ($attempt = 0; $attempt < StudentOtp::MAX_ATTEMPTS; $attempt++) {
            $this->assertFalse(StudentOtp::verify($student, '000000'));
        }

        $this->assertFalse(StudentOtp::verify($student, $code));
    }

    /**
     * @return array{0: Student, 1: string}
     */
    private function issueFor(Academy $academy): array
    {
        return TenantContext::runFor($academy, static function (): array {
            $student = Student::factory()->create();

            return [$student, app(IssueStudentToken::class)->handle($student)['token']];
        });
    }
}
