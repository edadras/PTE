<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Commerce\Models\Plan;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\PlanResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PlanController extends ApiController
{
    public function index(Request $request): ApiCollection
    {
        Gate::authorize('platform.plans.manage');

        $plans = Plan::query()
            ->orderBy('sort_order')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($plans, PlanResource::class);
    }

    public function show(int $plan): PlanResource
    {
        Gate::authorize('platform.plans.manage');

        return PlanResource::make(Plan::query()->findOrFail($plan));
    }
}
