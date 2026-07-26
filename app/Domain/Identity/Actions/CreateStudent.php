<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\CreateStudentData;
use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Models\StudentAcquisition;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Registers a learner inside the active academy.
 *
 * @see docs/07-database-schema.md §4
 */
final class CreateStudent
{
    private const CODE_ATTEMPTS = 10;

    public function handle(CreateStudentData $data, ?Academy $academy = null): Student
    {
        $create = fn (): Student => DB::transaction(function () use ($data): Student {
            /** @var Student $student */
            $student = Student::query()->create([
                ...$data->toAttributes(),
                'student_code' => $data->studentCode ?? $this->generateCode(),
                'registered_at' => now(),
                'last_active_at' => now(),
            ]);

            if ($data->classGroupId !== null) {
                $student->classGroups()->syncWithoutDetaching([
                    $data->classGroupId => [
                        'academy_id' => $student->academy_id,
                        'enrolled_at' => now(),
                    ],
                ]);
            }

            StudentAcquisition::query()->create([
                'student_id' => $student->getKey(),
                'source' => $data->source->value,
                'campaign_id' => $data->campaignId,
                'referrer_student_id' => $data->referrerStudentId,
                'payload' => $data->acquisitionPayload,
            ]);

            return $student;
        });

        return $academy instanceof Academy
            ? TenantContext::runFor($academy, $create)
            : $create();
    }

    /** Short, human-quotable and unique inside the academy. */
    private function generateCode(): string
    {
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $code = 'ST'.Str::upper(Str::random(6));

            $taken = Student::query()
                ->withTrashed()
                ->where('student_code', $code)
                ->exists();

            if (! $taken) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique student code.');
    }
}
