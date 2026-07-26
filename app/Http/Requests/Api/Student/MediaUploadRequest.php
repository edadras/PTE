<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Student;

use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

final class MediaUploadRequest extends ApiFormRequest
{
    /** Audio only, and only the containers the pipeline can actually decode. */
    public const ALLOWED_MIME_TYPES = [
        'audio/ogg',
        'audio/mpeg',
        'audio/mp4',
        'audio/wav',
        'audio/webm',
        'audio/x-m4a',
    ];

    private const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content_type' => ['required', 'string', Rule::in(self::ALLOWED_MIME_TYPES)],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_BYTES],
            'extension' => ['nullable', 'string', 'alpha_num', 'max:5'],
        ];
    }
}
