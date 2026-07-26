<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\StudentSource;
use App\Domain\Identity\Enums\StudentStatus;

/**
 * @see docs/07-database-schema.md §4
 */
final readonly class CreateStudentData
{
    /**
     * @param  array<string, mixed>|null  $acquisitionPayload
     */
    public function __construct(
        public string $firstName,
        public ?string $lastName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public string $locale = 'fa',
        public ?string $level = null,
        public ?float $targetScore = null,
        public StudentStatus $status = StudentStatus::Active,
        public StudentSource $source = StudentSource::Telegram,
        public ?string $studentCode = null,
        public ?int $classGroupId = null,
        public ?string $campaignId = null,
        public ?int $referrerStudentId = null,
        public ?array $acquisitionPayload = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            firstName: (string) ($attributes['first_name'] ?? $attributes['firstName'] ?? ''),
            lastName: $attributes['last_name'] ?? $attributes['lastName'] ?? null,
            email: $attributes['email'] ?? null,
            phone: $attributes['phone'] ?? null,
            locale: (string) ($attributes['locale'] ?? 'fa'),
            level: $attributes['level'] ?? null,
            targetScore: isset($attributes['target_score']) ? (float) $attributes['target_score'] : null,
            status: self::enum($attributes['status'] ?? null, StudentStatus::Active),
            source: self::source($attributes['source'] ?? null),
            studentCode: $attributes['student_code'] ?? null,
            classGroupId: isset($attributes['class_group_id']) ? (int) $attributes['class_group_id'] : null,
            campaignId: $attributes['campaign_id'] ?? null,
            referrerStudentId: isset($attributes['referrer_student_id']) ? (int) $attributes['referrer_student_id'] : null,
            acquisitionPayload: $attributes['payload'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'level' => $this->level,
            'target_score' => $this->targetScore,
            'status' => $this->status,
            'source' => $this->source,
        ];
    }

    private static function enum(mixed $value, StudentStatus $default): StudentStatus
    {
        return match (true) {
            $value instanceof StudentStatus => $value,
            is_string($value) => StudentStatus::tryFrom($value) ?? $default,
            default => $default,
        };
    }

    private static function source(mixed $value): StudentSource
    {
        return match (true) {
            $value instanceof StudentSource => $value,
            is_string($value) => StudentSource::tryFrom($value) ?? StudentSource::Telegram,
            default => StudentSource::Telegram,
        };
    }
}
