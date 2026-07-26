<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Student;

use App\Domain\Support\Actions\OpenTicket;
use App\Domain\Support\Data\OpenTicketData;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Student\StoreTicketRequest;
use App\Http\Resources\SupportTicketResource;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/student/v1/support/tickets` — docs/08 §3.
 *
 * The student id comes from the token, never from the body: a student may only
 * ever open a ticket as themselves.
 */
final class SupportTicketController extends ApiController
{
    public function store(StoreTicketRequest $request, OpenTicket $action): JsonResponse
    {
        $validated = $request->validated();

        $ticket = $action->handle(new OpenTicketData(
            subject: (string) ($validated['subject'] ?? ''),
            message: (string) $validated['message'],
            studentId: (int) $this->student($request)->getKey(),
            priority: TicketPriority::tryFrom((string) ($validated['priority'] ?? ''))
                ?? TicketPriority::Normal,
            source: TicketSource::Api,
        ));

        return SupportTicketResource::make($ticket)->response()->setStatusCode(201);
    }
}
