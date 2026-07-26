<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * API key authentication and scope enforcement (docs/08 §2).
 */
final class AcademyApiAuthTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_request_without_a_key_is_rejected(): void
    {
        $this->getJson('/api/v1/students')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function an_unknown_key_is_rejected(): void
    {
        $this->getJson('/api/v1/students', $this->keyHeaders('pte_live_ak_nope'))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function a_valid_key_resolves_its_own_academy(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['students:read']);

        TenantContext::runFor($academy, static function (): void {
            Student::factory()->count(3)->create();
        });

        $this->getJson('/api/v1/students', $this->keyHeaders($token))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function a_key_without_the_scope_is_refused(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['reports:read']);

        $this->getJson('/api/v1/students', $this->keyHeaders($token))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE')
            ->assertJsonPath('error.details.required_scope', 'students:read');
    }

    #[Test]
    public function a_read_scope_cannot_write(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['students:read']);

        $this->postJson('/api/v1/students', ['first_name' => 'Sara'], $this->keyHeaders($token))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');
    }

    #[Test]
    public function a_revoked_key_stops_working(): void
    {
        $academy = $this->academy('alpha');
        ['key' => $key, 'plain_text' => $token] = $this->apiKeyFor($academy, ['*']);

        TenantContext::runFor($academy, static function () use ($key): void {
            $key->forceFill(['revoked_at' => now()])->save();
        });

        $this->getJson('/api/v1/students', $this->keyHeaders($token))
            ->assertStatus(401);
    }
}
