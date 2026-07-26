<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Tenancy\Actions\CreateAcademy;
use App\Domain\Tenancy\Actions\DeleteAcademy;
use App\Domain\Tenancy\Actions\ResumeAcademy;
use App\Domain\Tenancy\Actions\SuspendAcademy;
use App\Domain\Tenancy\Data\CreateAcademyData;
use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Models\Academy;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Platform\StoreAcademyRequest;
use App\Http\Requests\Api\Platform\UpdateAcademyRequest;
use App\Http\Resources\AcademyResource;
use App\Http\Resources\ApiCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `/api/platform/v1/academies` — super admin only (docs/08 §1).
 *
 * `academies` is not a tenant-scoped table, so no global scope protects it: the
 * gate is the only thing standing between this controller and every customer's
 * data, and it is therefore checked on every single action.
 */
final class AcademyController extends ApiController
{
    public function index(Request $request): ApiCollection
    {
        Gate::authorize('platform.academies.manage');

        $academies = Academy::query()
            ->when(
                $request->filled('status'),
                fn (Builder $query): Builder => $query->where('status', (string) $request->string('status'))
            )
            ->when($request->filled('search'), function (Builder $query) use ($request): Builder {
                $term = '%'.$request->string('search')->toString().'%';

                return $query->where(fn (Builder $inner): Builder => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('slug', 'like', $term));
            })
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($academies, AcademyResource::class);
    }

    public function store(StoreAcademyRequest $request, CreateAcademy $action): JsonResponse
    {
        Gate::authorize('platform.academies.manage');

        $academy = $action->handle(CreateAcademyData::fromArray($request->validated()));

        return AcademyResource::make($academy)->response()->setStatusCode(201);
    }

    public function show(int $academy): AcademyResource
    {
        Gate::authorize('platform.academies.manage');

        return AcademyResource::make(Academy::query()->findOrFail($academy));
    }

    public function update(UpdateAcademyRequest $request, int $academy): AcademyResource
    {
        Gate::authorize('platform.academies.manage');

        $model = Academy::query()->findOrFail($academy);
        $model->fill($request->validated())->save();

        return AcademyResource::make($model->refresh());
    }

    public function destroy(int $academy, DeleteAcademy $action): AcademyResource
    {
        Gate::authorize('platform.academies.manage');

        return AcademyResource::make($action->handle(Academy::query()->findOrFail($academy)));
    }

    public function suspend(
        Request $request,
        int $academy,
        SuspendAcademy $suspend,
        ResumeAcademy $resume,
    ): AcademyResource {
        Gate::authorize('platform.academies.manage');

        $validated = $request->validate([
            'suspended' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $model = Academy::query()->findOrFail($academy);

        $updated = $validated['suspended'] === true
            ? $suspend->handle($model, $validated['reason'] ?? null)
            : $resume->handle($model);

        return AcademyResource::make($updated);
    }

    public function statuses(Request $request): JsonResponse
    {
        Gate::authorize('platform.academies.manage');

        return $this->payload($request, [
            'statuses' => array_map(
                static fn (AcademyStatus $status): string => $status->value,
                AcademyStatus::cases(),
            ),
        ]);
    }
}
