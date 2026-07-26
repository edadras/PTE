<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Academy;

use App\Domain\Integration\Enums\WebhookEvent;
use App\Domain\Telegram\Support\SafeUrl;
use App\Http\Requests\Api\ApiFormRequest;
use Closure;
use Illuminate\Validation\Rule;

final class StoreWebhookRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:2048', $this->safeUrlRule()],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::enum(WebhookEvent::class)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The SSRF guard runs again at delivery time (DNS rebinding, docs/12 §6);
     * rejecting here is purely so the academy gets a useful error while it is
     * still looking at the form.
     */
    private function safeUrlRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! SafeUrl::isSafe($value)) {
                $fail(__('api.errors.webhook_url_rejected'));
            }
        };
    }
}
