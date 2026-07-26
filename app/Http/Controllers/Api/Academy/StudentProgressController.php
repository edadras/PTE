<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Models\StudentProgress;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\StudentProgressResource;

final class StudentProgressController extends ApiController
{
    public function __invoke(int $student): ApiCollection
    {
        $model = Student::query()->findOrFail($student);

        $progress = StudentProgress::query()
            ->where('student_id', $model->getKey())
            ->orderBy('module_key')
            ->orderBy('question_type')
            ->get();

        return new ApiCollection($progress, StudentProgressResource::class);
    }
}
