<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Integration\Enums\WebhookEvent;
use App\Domain\Integration\Models\Webhook;
use App\Domain\Integration\Support\WebhookSignature;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Academy\StoreWebhookRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\WebhookResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `/api/v1/webhooks` — management for the outbound hooks of docs/08 §5.
 */
final class WebhookController extends ApiController
{
    public function index(Request $request): ApiCollection
    {
        $webhooks = Webhook::query()
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($webhooks, WebhookResource::class);
    }

    public function store(StoreWebhookRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $secret = WebhookSignature::generateSecret();

        $webhook = Webhook::query()->create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'events' => array_values($validated['events']),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'secret' => $secret,
        ]);

        // The only moment the secret is ever readable — exactly like an API key.
        return WebhookResource::make($webhook)
            ->additional(['data' => ['secret' => $secret]])
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $webhook): WebhookResource
    {
        return WebhookResource::make(Webhook::query()->findOrFail($webhook));
    }

    public function update(Request $request, int $webhook): WebhookResource
    {
        $model = Webhook::query()->findOrFail($webhook);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => [Rule::enum(WebhookEvent::class)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Re-enabling clears the failure streak; otherwise the next single
        // failure would trip the auto-disable again immediately.
        if (($validated['is_active'] ?? false) === true) {
            $validated['consecutive_failures'] = 0;
            $validated['disabled_at'] = null;
            $validated['disabled_reason'] = null;
        }

        $model->forceFill($validated)->save();

        return WebhookResource::make($model->refresh());
    }

    public function destroy(int $webhook): JsonResponse
    {
        Webhook::query()->findOrFail($webhook)->delete();

        return new JsonResponse(null, 204);
    }
}
