<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `Idempotency-Key` on creating POSTs (docs/08 §4).
 */
final class IdempotencyTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function replaying_a_key_returns_the_first_response_without_creating_a_second_row(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['*']);

        $headers = $this->keyHeaders($token) + ['Idempotency-Key' => '01HZXK3M9ABCDEF'];
        $payload = ['first_name' => 'Sara', 'last_name' => 'Ahmadi'];

        $first = $this->postJson('/api/v1/students', $payload, $headers);
        $first->assertStatus(201);

        $second = $this->postJson('/api/v1/students', $payload, $headers);

        $second->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, TenantContext::runFor(
            $academy,
            static fn (): int => Student::query()->count()
        ));
    }

    #[Test]
    public function a_different_key_creates_a_new_record(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['*']);

        $payload = ['first_name' => 'Sara'];

        $this->postJson(
            '/api/v1/students',
            $payload,
            $this->keyHeaders($token) + ['Idempotency-Key' => 'key-one']
        )->assertStatus(201);

        $this->postJson(
            '/api/v1/students',
            $payload,
            $this->keyHeaders($token) + ['Idempotency-Key' => 'key-two']
        )->assertStatus(201);

        $this->assertSame(2, TenantContext::runFor(
            $academy,
            static fn (): int => Student::query()->count()
        ));
    }

    #[Test]
    public function the_same_key_in_another_academy_is_a_different_request(): void
    {
        $alpha = $this->academy('alpha');
        $beta = $this->academy('beta');

        ['plain_text' => $alphaToken] = $this->apiKeyFor($alpha, ['*']);
        ['plain_text' => $betaToken] = $this->apiKeyFor($beta, ['*']);

        $key = ['Idempotency-Key' => 'shared-key'];

        $this->postJson('/api/v1/students', ['first_name' => 'A'], $this->keyHeaders($alphaToken) + $key)
            ->assertStatus(201);

        $this->postJson('/api/v1/students', ['first_name' => 'B'], $this->keyHeaders($betaToken) + $key)
            ->assertStatus(201)
            ->assertJsonPath('data.first_name', 'B');
    }
}
