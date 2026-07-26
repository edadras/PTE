<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Student;

use App\Http\Requests\Api\ApiFormRequest;

final class OtpLoginRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_code' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'size:6'],
        ];
    }
}
