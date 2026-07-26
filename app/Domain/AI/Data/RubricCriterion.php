<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

use App\Domain\AI\Exceptions\InvalidRubricException;

final readonly class RubricCriterion
{
    public function __construct(
        public string $key,
        public string $label,
        public int $weight,
        public string $guidance = '',
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $key = trim((string) ($payload['key'] ?? ''));

        if ($key === '') {
            throw InvalidRubricException::malformedCriterion('missing key');
        }

        if (! isset($payload['weight']) || ! is_numeric($payload['weight'])) {
            throw InvalidRubricException::malformedCriterion("criterion [{$key}] has a non-numeric weight");
        }

        $weight = (int) $payload['weight'];

        if ($weight < 0 || $weight > 100) {
            throw InvalidRubricException::malformedCriterion("criterion [{$key}] weight {$weight} is out of range");
        }

        return new self(
            key: $key,
            label: (string) ($payload['label'] ?? $key),
            weight: $weight,
            guidance: (string) ($payload['guidance'] ?? ''),
        );
    }

    /** Translated label, falling back to whatever the academy typed. */
    public function translatedLabel(): string
    {
        $line = 'ai.criteria.'.$this->key;
        $translated = __($line);

        return is_string($translated) && $translated !== $line ? $translated : $this->label;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'weight' => $this->weight,
            'guidance' => $this->guidance,
        ];
    }
}
