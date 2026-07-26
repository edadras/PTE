<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The wire format of docs/08 §4. These assertions are the published contract:
 * a mobile client is written against them, so breaking one is a major version.
 */
final class ResponseContractTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_single_resource_is_wrapped_in_data_with_a_request_id(): void
    {
        $academy = $this->academy('alpha');
        $student = TenantContext::runFor($academy, static fn (): Student => Student::factory()->create());

        ['plain_text' => $token] = $this->apiKeyFor($academy, ['*']);

        $response = $this->getJson('/api/v1/students/'.$student->getKey(), $this->keyHeaders($token));

        $response->assertOk()
            ->assertJsonStructure(['data' => ['id', 'student_code'], 'meta' => ['request_id']]);

        $this->assertSame(
            $response->headers->get('X-Request-Id'),
            $response->json('meta.request_id'),
        );
    }

    #[Test]
    public function a_list_carries_exactly_the_documented_pagination_shape(): void
    {
        $academy = $this->academy('alpha');

        TenantContext::runFor($academy, static function (): void {
            Student::factory()->count(30)->create();
        });

        ['plain_text' => $token] = $this->apiKeyFor($academy, ['*']);

        $response = $this->getJson('/api/v1/students?per_page=25', $this->keyHeaders($token));

        $response->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('links.prev', null);

        $this->assertNotNull($response->json('links.next'));
        $this->assertSame(['next', 'prev'], array_keys((array) $response->json('links')));
    }

    #[Test]
    public function the_error_envelope_has_a_stable_code_and_details(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['*']);

        $response = $this->postJson('/api/v1/students', ['last_name' => 'Only'], $this->keyHeaders($token));

        $response->assertStatus(422)
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']])
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertArrayHasKey('first_name', (array) $response->json('error.details'));
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function a_supplied_request_id_is_echoed_back(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['*']);

        $this->getJson('/api/v1/students', $this->keyHeaders($token) + ['X-Request-Id' => 'client-123'])
            ->assertOk()
            ->assertHeader('X-Request-Id', 'client-123')
            ->assertJsonPath('meta.request_id', 'client-123');
    }

    #[Test]
    public function an_unknown_endpoint_still_answers_with_the_envelope(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['*']);

        $this->getJson('/api/v1/students/999999', $this->keyHeaders($token))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
