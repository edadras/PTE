<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Assessment\Models\Score;
use App\Domain\Identity\Models\Student;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ScoreResource;
use Illuminate\Http\Request;

final class StudentScoreController extends ApiController
{
    public function __invoke(Request $request, int $student): ApiCollection
    {
        $model = Student::query()->findOrFail($student);

        $scores = Score::query()
            ->where('student_id', $model->getKey())
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($scores, ScoreResource::class);
    }
}
