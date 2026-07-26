<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Platform;

use App\Domain\Learning\Enums\ModuleKey;
use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

final class StoreAcademyRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:60', 'alpha_dash', 'unique:academies,slug'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'owner_email' => ['nullable', 'email', 'max:191'],
            'owner_name' => ['nullable', 'string', 'max:150'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'locale' => ['nullable', 'string', 'max:5'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'size:2'],
            'modules' => ['nullable', 'array'],
            'modules.*' => [Rule::enum(ModuleKey::class)],
        ];
    }
}
