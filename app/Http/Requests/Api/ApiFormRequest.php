<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base form request for the API tiers.
 *
 * Authorisation is *not* done here: an API key's scopes are checked by the
 * `api.scope` middleware and a platform ability by an explicit
 * `Gate::authorize()` in the controller, so that a reader can see the whole
 * access decision in one place instead of two.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
