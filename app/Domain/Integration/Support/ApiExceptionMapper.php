<?php

declare(strict_types=1);

namespace App\Domain\Integration\Support;

use App\Domain\AI\Exceptions\ProviderUnavailableException;
use App\Domain\AI\Exceptions\QuotaExceededException as AiQuotaExceededException;
use App\Domain\Assessment\Exceptions\DailyPracticeLimitReached;
use App\Domain\Assessment\Exceptions\ExamNotAvailable;
use App\Domain\Assessment\Exceptions\InvalidAnswerShape;
use App\Domain\Assessment\Exceptions\NoQuestionsAvailable;
use App\Domain\Assessment\Exceptions\OverrideReasonRequired;
use App\Domain\Assessment\Exceptions\SessionNotActive;
use App\Domain\Assessment\Exceptions\SubscriptionRequired;
use App\Domain\Commerce\Exceptions\InvalidDiscountCodeException;
use App\Domain\Commerce\Exceptions\PaymentGatewayException;
use App\Domain\Commerce\Exceptions\QuotaExceededException as CommerceQuotaExceededException;
use App\Domain\Integration\Exceptions\InsufficientScopeException;
use App\Domain\Learning\Exceptions\InvalidQuestionContentException;
use App\Domain\Learning\Exceptions\ModuleNotEnabledException;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * Turns any throwable into the error envelope of docs/08 §4.
 *
 * The `code` is the contract, not the HTTP status: a Flutter client switches on
 * `QUOTA_EXCEEDED` and shows the upgrade sheet without parsing a sentence in a
 * language it may not even render.
 *
 * Two deliberate choices:
 *  - a missing record is 404 and never 403 — a 403 confirms the row exists in
 *    someone else's academy, which is exactly the enumeration T10 forbids;
 *  - the message of an unmapped 500 is generic outside local debugging, because
 *    exception text has been known to carry credentials.
 *
 * @see docs/08-api-and-integrations.md §4 · docs/12-security-and-compliance.md §1
 */
final class ApiExceptionMapper
{
    /** @return JsonResponse|null null when the request is not ours to answer */
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $this->handles($request)) {
            return null;
        }

        [$status, $code, $message, $details, $headers] = $this->describe($e, $request);

        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            Log::error('Unhandled API exception.', [
                'request_id' => $this->requestId($request),
                'url' => $request->fullUrl(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $this->envelope($status, $code, $message, $details, $request, $headers);
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @param  array<string, string>  $headers
     */
    public function envelope(
        int $status,
        string $code,
        string $message,
        ?array $details,
        Request $request,
        array $headers = [],
    ): JsonResponse {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => $this->requestId($request),
            ],
        ], $status, $headers);
    }

    public function handles(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>|null, 4: array<string, string>}
     */
    private function describe(Throwable $e, Request $request): array
    {
        return match (true) {
            $e instanceof ValidationException => [
                422, 'VALIDATION_FAILED', __('api.errors.validation_failed'), $e->errors(), [],
            ],

            $e instanceof InsufficientScopeException => [
                403, 'INSUFFICIENT_SCOPE', $e->getMessage(), ['required_scope' => $e->scope], [],
            ],

            $e instanceof CommerceQuotaExceededException => [
                429, 'QUOTA_EXCEEDED', $e->getMessage(), [
                    'metric' => $e->result->metric()?->value,
                    'limit' => $e->result->limit(),
                    'used' => $e->result->used(),
                ], [],
            ],

            $e instanceof AiQuotaExceededException => [
                429, 'QUOTA_EXCEEDED', $e->studentMessage(), [
                    'metric' => $e->metric,
                    'limit' => null,
                    'used' => null,
                ], [],
            ],

            $e instanceof TenantNotResolvedException => [
                404, 'TENANT_NOT_RESOLVED', __('api.errors.tenant_not_resolved'), null, [],
            ],

            $e instanceof ModelNotFoundException,
            $e instanceof BackedEnumCaseNotFoundException,
            $e instanceof NotFoundHttpException => [
                404, 'NOT_FOUND', __('api.errors.not_found'), null, [],
            ],

            $e instanceof AuthenticationException,
            $e instanceof UnauthorizedHttpException => [
                401, 'UNAUTHENTICATED', __('api.errors.unauthenticated'), null, [],
            ],

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => [
                403, 'FORBIDDEN', __('api.errors.forbidden'), null, [],
            ],

            $e instanceof TooManyRequestsHttpException => [
                429, 'RATE_LIMITED', __('api.errors.rate_limited'),
                ['retry_after' => $this->retryAfter($e)],
                $this->stringHeaders($e),
            ],

            $e instanceof MethodNotAllowedHttpException => [
                405, 'METHOD_NOT_ALLOWED', __('api.errors.method_not_allowed'), null, $this->stringHeaders($e),
            ],

            $e instanceof SessionNotActive => [
                409, 'SESSION_NOT_ACTIVE', $e->getMessage(), null, [],
            ],

            $e instanceof DailyPracticeLimitReached => [
                429, 'DAILY_PRACTICE_LIMIT', $e->getMessage(), null, [],
            ],

            $e instanceof SubscriptionRequired => [
                402, 'SUBSCRIPTION_REQUIRED', $e->getMessage(), null, [],
            ],

            $e instanceof NoQuestionsAvailable => [
                422, 'NO_QUESTIONS_AVAILABLE', $e->getMessage(), null, [],
            ],

            $e instanceof InvalidAnswerShape => [
                422, 'INVALID_ANSWER_SHAPE', $e->getMessage(), null, [],
            ],

            $e instanceof ExamNotAvailable => [
                409, 'EXAM_NOT_AVAILABLE', $e->getMessage(), null, [],
            ],

            $e instanceof OverrideReasonRequired => [
                422, 'OVERRIDE_REASON_REQUIRED', $e->getMessage(), null, [],
            ],

            $e instanceof InvalidQuestionContentException => [
                422, 'INVALID_QUESTION_CONTENT', $e->getMessage(), null, [],
            ],

            $e instanceof ModuleNotEnabledException => [
                403, 'MODULE_NOT_ENABLED', $e->getMessage(), null, [],
            ],

            $e instanceof InvalidDiscountCodeException => [
                422, 'INVALID_DISCOUNT_CODE', $e->getMessage(), null, [],
            ],

            $e instanceof PaymentGatewayException => [
                502, 'PAYMENT_GATEWAY_ERROR', __('api.errors.payment_gateway'), null, [],
            ],

            $e instanceof TelegramApiException => [
                502, 'TELEGRAM_API_ERROR', __('api.errors.telegram_unavailable'), null, [],
            ],

            $e instanceof ProviderUnavailableException => [
                503, 'AI_PROVIDER_UNAVAILABLE', __('api.errors.ai_unavailable'), null, [],
            ],

            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                $this->codeForStatus($e->getStatusCode()),
                $e->getMessage() !== '' ? $e->getMessage() : __('api.errors.server_error'),
                null,
                $this->stringHeaders($e),
            ],

            default => [
                500,
                'SERVER_ERROR',
                config('app.debug') === true ? $e->getMessage() : __('api.errors.server_error'),
                null,
                [],
            ],
        };
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            402 => 'PAYMENT_REQUIRED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            415 => 'UNSUPPORTED_MEDIA_TYPE',
            422 => 'VALIDATION_FAILED',
            429 => 'RATE_LIMITED',
            503 => 'SERVICE_UNAVAILABLE',
            default => $status >= 500 ? 'SERVER_ERROR' : 'REQUEST_FAILED',
        };
    }

    private function retryAfter(HttpExceptionInterface $e): ?int
    {
        $value = $e->getHeaders()['Retry-After'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function stringHeaders(HttpExceptionInterface $e): array
    {
        $headers = [];

        foreach ($e->getHeaders() as $name => $value) {
            if (is_scalar($value)) {
                $headers[(string) $name] = (string) $value;
            }
        }

        return $headers;
    }

    private function requestId(Request $request): string
    {
        $id = $request->attributes->get('request_id');

        return is_string($id) && $id !== '' ? $id : 'unknown';
    }
}
