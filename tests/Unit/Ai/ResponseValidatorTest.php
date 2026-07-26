<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Domain\AI\Data\AiCompletionResponse;
use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Exceptions\InvalidAiResponseException;
use App\Domain\AI\Services\ResponseValidator;
use PHPUnit\Framework\TestCase;

/**
 * The validator is the only thing standing between a hallucinated response and
 * a student's transcript, so both halves are tested: what it must accept
 * (fenced JSON, numeric strings) and what it must refuse (missing keys, out of
 * range scores).
 */
final class ResponseValidatorTest extends TestCase
{
    private ResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ResponseValidator;
    }

    public function test_it_parses_plain_json(): void
    {
        $this->assertSame(['a' => 1], $this->validator->parse('{"a":1}'));
    }

    public function test_it_parses_json_wrapped_in_a_markdown_fence(): void
    {
        $raw = "Here you go:\n```json\n{\"scores\": {\"fluency\": 70}}\n```\n";

        $this->assertSame(['scores' => ['fluency' => 70]], $this->validator->parse($raw));
    }

    public function test_it_parses_json_surrounded_by_prose(): void
    {
        $raw = 'Sure! {"confidence": 0.9} Hope that helps.';

        $this->assertSame(['confidence' => 0.9], $this->validator->parse($raw));
    }

    public function test_it_rejects_output_that_is_not_json(): void
    {
        $this->expectException(InvalidAiResponseException::class);

        $this->validator->parse('The candidate spoke well and deserves about 70.');
    }

    public function test_it_rejects_empty_output(): void
    {
        $this->expectException(InvalidAiResponseException::class);

        $this->validator->parse('   ');
    }

    public function test_a_conforming_payload_passes(): void
    {
        $this->validator->validateData($this->payload(), $this->schema());

        $this->assertSame([], $this->validator->violations($this->payload(), $this->schema()));
    }

    public function test_a_missing_required_key_is_a_violation(): void
    {
        $payload = $this->payload();
        unset($payload['confidence']);

        $violations = $this->validator->violations($payload, $this->schema());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('confidence', $violations[0]);
    }

    public function test_a_missing_required_criterion_is_a_violation(): void
    {
        $payload = $this->payload();
        unset($payload['scores']['fluency']);

        $violations = $this->validator->violations($payload, $this->schema());

        $this->assertStringContainsString('fluency', implode(' ', $violations));
    }

    public function test_a_score_out_of_range_is_a_violation(): void
    {
        $payload = $this->payload();
        $payload['scores']['fluency'] = 140;

        $violations = $this->validator->violations($payload, $this->schema());

        $this->assertStringContainsString('maximum', implode(' ', $violations));
    }

    public function test_a_wrong_type_is_a_violation(): void
    {
        $payload = $this->payload();
        $payload['feedback']['strengths'] = 'clear pronunciation';

        $violations = $this->validator->violations($payload, $this->schema());

        $this->assertStringContainsString('$.feedback.strengths', implode(' ', $violations));
    }

    public function test_numeric_strings_are_tolerated_for_numbers(): void
    {
        $payload = $this->payload();
        $payload['scores']['fluency'] = '70';

        $this->assertSame([], $this->validator->violations($payload, $this->schema()));
    }

    public function test_an_enum_value_outside_the_list_is_a_violation(): void
    {
        $schema = ['type' => 'string', 'enum' => ['stress', 'phoneme']];

        $this->assertSame([], $this->validator->violations('stress', $schema));
        $this->assertNotSame([], $this->validator->violations('vibes', $schema));
    }

    public function test_validating_a_response_object_attaches_the_parsed_json(): void
    {
        $response = new AiCompletionResponse(
            text: json_encode($this->payload()) ?: '{}',
            parsedJson: null,
            promptTokens: 100,
            completionTokens: 50,
            latencyMs: 900,
            modelKey: 'gemini-2.5-pro',
            provider: AiProvider::Gemini,
        );

        $validated = $this->validator->validate($response, $this->schema());

        $this->assertIsArray($validated->parsedJson);
        $this->assertSame(70, $validated->scores()['fluency']);
        $this->assertSame(0.87, $validated->confidence());
    }

    public function test_validation_failure_throws_with_the_violations_attached(): void
    {
        $response = new AiCompletionResponse(
            text: '{"scores":{"fluency":70}}',
            parsedJson: null,
            promptTokens: 10,
            completionTokens: 5,
            latencyMs: 100,
            modelKey: 'gemini-2.5-pro',
            provider: AiProvider::Gemini,
        );

        try {
            $this->validator->validate($response, $this->schema());
            $this->fail('Expected InvalidAiResponseException.');
        } catch (InvalidAiResponseException $e) {
            $this->assertNotEmpty($e->violations);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'scores' => ['pronunciation' => 82, 'fluency' => 70],
            'overall_raw' => 78,
            'feedback' => [
                'summary' => 'Clear delivery with frequent mid-phrase pauses.',
                'strengths' => ['full coverage of the text'],
                'improvements' => ['reduce pausing between phrases'],
            ],
            'confidence' => 0.87,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['scores', 'overall_raw', 'feedback', 'confidence'],
            'properties' => [
                'scores' => [
                    'type' => 'object',
                    'required' => ['pronunciation', 'fluency'],
                    'properties' => [
                        'pronunciation' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        'fluency' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    ],
                ],
                'overall_raw' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'feedback' => [
                    'type' => 'object',
                    'required' => ['summary', 'strengths', 'improvements'],
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'improvements' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
        ];
    }
}
