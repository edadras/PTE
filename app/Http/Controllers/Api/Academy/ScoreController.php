<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Assessment\Models\Score;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ScoreResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * `/api/v1/scores?from=&to=&module=` — docs/08 §3.
 */
final class ScoreController extends ApiController
{
    public function index(Request $request): ApiCollection
    {
        $scores = Score::query()
            ->when(
                $request->filled('module'),
                fn (Builder $query): Builder => $query->where('module_key', (string) $request->string('module'))
            )
            ->when(
                $request->filled('from'),
                fn (Builder $query): Builder => $query->where('created_at', '>=', $request->date('from'))
            )
            ->when(
                $request->filled('to'),
                fn (Builder $query): Builder => $query->where('created_at', '<=', $request->date('to'))
            )
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($scores, ScoreResource::class);
    }
}
