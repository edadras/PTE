<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Student;

use App\Domain\Assessment\Models\Score;
use App\Domain\Identity\Models\StudentProgress;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ScoreResource;
use App\Http\Resources\StudentProgressResource;
use Illuminate\Http\Request;

/**
 * `/api/student/v1/scores` and `/progress`.
 *
 * Unpublished scores are withheld: an academy that reviews AI grades before
 * release must not have the raw number leak through the API in the meantime
 * (docs/05 §7).
 */
final class ProgressController extends ApiController
{
    public function scores(Request $request): ApiCollection
    {
        $scores = Score::query()
            ->where('student_id', $this->student($request)->getKey())
            ->published()
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($scores, ScoreResource::class);
    }

    public function progress(Request $request): ApiCollection
    {
        $progress = StudentProgress::query()
            ->where('student_id', $this->student($request)->getKey())
            ->orderBy('module_key')
            ->orderBy('question_type')
            ->get();

        return new ApiCollection($progress, StudentProgressResource::class);
    }
}
