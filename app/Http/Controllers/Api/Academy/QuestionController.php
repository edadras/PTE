<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Learning\Actions\CreateQuestion;
use App\Domain\Learning\Data\QuestionData;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Models\Question;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Academy\StoreQuestionRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\QuestionResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/v1/questions` — docs/08 §3.
 */
final class QuestionController extends ApiController
{
    public function index(Request $request): ApiCollection
    {
        $questions = Question::query()
            ->when(
                $request->filled('module'),
                fn (Builder $query): Builder => $query->where('module_key', (string) $request->string('module'))
            )
            ->when(
                $request->filled('type'),
                fn (Builder $query): Builder => $query->where('type', (string) $request->string('type'))
            )
            ->when(
                $request->filled('difficulty'),
                fn (Builder $query): Builder => $query->where('difficulty', (string) $request->string('difficulty'))
            )
            ->when(
                $request->filled('status'),
                fn (Builder $query): Builder => $query->where('status', (string) $request->string('status'))
            )
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($questions, QuestionResource::class);
    }

    public function store(StoreQuestionRequest $request, CreateQuestion $action): JsonResponse
    {
        $validated = $request->validated();

        $question = $action->handle(
            QuestionData::fromArray($validated),
            ($validated['publish'] ?? false) === true ? QuestionStatus::Published : QuestionStatus::Draft,
        );

        return QuestionResource::make($question)->response()->setStatusCode(201);
    }
}
