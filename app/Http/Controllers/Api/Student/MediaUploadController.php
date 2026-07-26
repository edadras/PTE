<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Student\MediaUploadRequest;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * `POST /api/student/v1/media/upload` — hands back a short-lived pre-signed PUT.
 *
 * Audio never travels through the application server (docs/08 §6): a phone on a
 * slow connection would otherwise hold a PHP worker for the length of the
 * upload. The tenant disk's root is already rewritten to `academies/{id}`, so
 * the returned key cannot address another academy's objects.
 */
final class MediaUploadController extends ApiController
{
    private const URL_TTL_MINUTES = 15;

    public function __invoke(MediaUploadRequest $request): JsonResponse
    {
        $student = $this->student($request);
        $validated = $request->validated();

        $extension = (string) ($validated['extension'] ?? 'ogg');
        $path = sprintf('answers/%d/%s.%s', $student->getKey(), Str::ulid(), $extension);

        $disk = Storage::disk('tenant');

        if (! method_exists($disk, 'temporaryUploadUrl')) {
            throw new ServiceUnavailableHttpException(message: __('api.errors.uploads_unavailable'));
        }

        /** @var Filesystem $disk */
        $signed = $disk->temporaryUploadUrl(
            $path,
            now()->addMinutes(self::URL_TTL_MINUTES),
            ['ContentType' => (string) $validated['content_type']],
        );

        return $this->payload($request, [
            'path' => $path,
            'upload_url' => $signed['url'] ?? null,
            'headers' => $signed['headers'] ?? [],
            'expires_in' => self::URL_TTL_MINUTES * 60,
        ]);
    }
}
