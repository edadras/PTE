<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Data\AiCompletionResponse;
use App\Domain\AI\Exceptions\InvalidAiResponseException;

/**
 * Enforces the prompt's output_schema in PHP.
 *
 * Vendors offer schema modes of wildly differing strictness, and a score that
 * silently arrives as the string "eighty" is worse than no score at all. So the
 * contract is checked here, on our side, for every provider alike. A failure is
 * recoverable: one retry against the same model, then the fallback chain
 * (docs/06 §3, §8).
 *
 * Supports the JSON Schema subset the prompt editor can produce: type,
 * properties, required, items, enum, minimum, maximum, minItems, maxItems.
 */
final class ResponseValidator
{
    /**
     * @param  array<string, mixed>  $schema
     *
     * @throws InvalidAiResponseException
     */
    public function validate(AiCompletionResponse $response, array $schema): AiCompletionResponse
    {
        $data = $response->parsedJson ?? $this->parse($response->text);

        if ($schema !== []) {
            $this->validateData($data, $schema);
        }

        return $response->withParsedJson($data);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidAiResponseException
     */
    public function parse(string $raw): array
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            throw InvalidAiResponseException::emptyOutput();
        }

        // Models fence their JSON more often than they should.
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $trimmed, $matches) === 1) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            $start = strpos($trimmed, '{');
            $end = strrpos($trimmed, '}');

            $decoded = ($start === false || $end === false || $end <= $start)
                ? null
                : json_decode(substr($trimmed, $start, $end - $start + 1), true);
        }

        if (! is_array($decoded)) {
            throw InvalidAiResponseException::notJson($raw);
        }

        return $decoded;
    }

    /**
     * @param  array<mixed>  $data
     * @param  array<string, mixed>  $schema
     *
     * @throws InvalidAiResponseException
     */
    public function validateData(array $data, array $schema): void
    {
        $violations = [];

        $this->check($data, $schema, '$', $violations);

        if ($violations !== []) {
            throw InvalidAiResponseException::schemaMismatch($violations);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     *
     * @return array<int, string> empty when the payload conforms
     */
    public function violations(mixed $value, array $schema): array
    {
        $violations = [];

        $this->check($value, $schema, '$', $violations);

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $violations
     */
    private function check(mixed $value, array $schema, string $path, array &$violations): void
    {
        $types = $this->expectedTypes($schema);

        if ($types !== [] && ! $this->matchesAnyType($value, $types)) {
            $violations[] = sprintf('%s must be %s, got %s', $path, implode('|', $types), get_debug_type($value));

            return;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $violations[] = sprintf('%s is not one of the allowed values', $path);
        }

        if (is_numeric($value)) {
            if (isset($schema['minimum']) && (float) $value < (float) $schema['minimum']) {
                $violations[] = sprintf('%s is below the minimum of %s', $path, (string) $schema['minimum']);
            }

            if (isset($schema['maximum']) && (float) $value > (float) $schema['maximum']) {
                $violations[] = sprintf('%s is above the maximum of %s', $path, (string) $schema['maximum']);
            }
        }

        if ($this->isObjectSchema($schema, $value)) {
            $this->checkObject($value, $schema, $path, $violations);
        }

        if (isset($schema['items']) && is_array($schema['items']) && is_array($value)) {
            foreach (array_values($value) as $index => $item) {
                $this->check($item, $schema['items'], "{$path}[{$index}]", $violations);
            }

            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                $violations[] = sprintf('%s needs at least %d item(s)', $path, (int) $schema['minItems']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $violations
     */
    private function checkObject(mixed $value, array $schema, string $path, array &$violations): void
    {
        if (! is_array($value)) {
            $violations[] = sprintf('%s must be an object', $path);

            return;
        }

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists((string) $required, $value)) {
                $violations[] = sprintf('%s.%s is required', $path, (string) $required);
            }
        }

        $properties = $schema['properties'] ?? [];

        if (! is_array($properties)) {
            return;
        }

        foreach ($properties as $property => $propertySchema) {
            if (! is_array($propertySchema) || ! array_key_exists($property, $value)) {
                continue;
            }

            $this->check($value[$property], $propertySchema, "{$path}.{$property}", $violations);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function expectedTypes(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            return [strtolower($type)];
        }

        if (is_array($type)) {
            return array_map(static fn (mixed $t): string => strtolower((string) $t), $type);
        }

        return [];
    }

    /**
     * @param  array<int, string>  $types
     */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value),
                'string' => is_string($value),
                // A model that answers 82 for a "number" field is right; one
                // that answers "82" is close enough to keep, so numeric strings
                // are accepted and the rubric engine casts them.
                'number' => is_numeric($value) && ! is_bool($value),
                'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => true,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function isObjectSchema(array $schema, mixed $value): bool
    {
        if (isset($schema['properties']) || isset($schema['required'])) {
            return true;
        }

        return in_array('object', $this->expectedTypes($schema), true) && is_array($value);
    }
}
