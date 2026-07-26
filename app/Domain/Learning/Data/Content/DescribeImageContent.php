<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Describe Image — the student describes a chart, map or photo aloud.
 */
final readonly class DescribeImageContent extends QuestionContent
{
    /**
     * @param  array<int, string>  $keyPoints  Content points a good answer mentions; feeds the AI rubric.
     */
    public function __construct(
        public string $imageKey,
        public int $prepSeconds = 25,
        public int $recordSeconds = 40,
        public ?string $imageType = null,
        public array $keyPoints = [],
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            imageKey: self::string($data, 'image_key'),
            prepSeconds: self::int($data, 'prep_seconds', 25),
            recordSeconds: self::int($data, 'record_seconds', 40),
            imageType: self::nullableString($data, 'image_type'),
            keyPoints: self::stringList($data, 'key_points'),
        );
    }

    public function toArray(): array
    {
        return self::withoutNulls([
            'image_key' => $this->imageKey,
            'prep_seconds' => $this->prepSeconds,
            'record_seconds' => $this->recordSeconds,
            'image_type' => $this->imageType,
            'key_points' => $this->keyPoints,
        ]);
    }
}
