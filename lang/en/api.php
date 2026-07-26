<?php

declare(strict_types=1);

return [

    'errors' => [
        'validation_failed' => 'The submitted data is invalid.',
        'not_found' => 'The requested resource was not found.',
        'unauthenticated' => 'Authentication is required.',
        'forbidden' => 'You are not allowed to perform this action.',
        'insufficient_scope' => 'This API key is missing the ":scope" scope.',
        'rate_limited' => 'Too many requests. Please slow down.',
        'method_not_allowed' => 'This method is not supported for this endpoint.',
        'server_error' => 'Something went wrong on our side.',
        'tenant_not_resolved' => 'The requested resource was not found.',
        'token_invalid' => 'The access token is invalid or has expired.',
        'token_tenant_mismatch' => 'The access token is invalid or has expired.',
        'student_blocked' => 'This account has been blocked.',
        'student_not_reachable' => 'This student has no reachable Telegram chat.',
        'no_bot_connected' => 'This academy has no connected Telegram bot.',
        'payment_gateway' => 'The payment gateway could not be reached.',
        'telegram_unavailable' => 'Telegram could not be reached right now.',
        'ai_unavailable' => 'The scoring service is temporarily unavailable.',
        'webhook_url_rejected' => 'The webhook URL must be an https address on a public host.',
        'idempotency_key_invalid' => 'The Idempotency-Key header is too long.',
        'idempotency_in_progress' => 'A request with this Idempotency-Key is still being processed.',
        'export_async_only' => 'Spreadsheet exports are produced asynchronously; request one from the panel.',
        'uploads_unavailable' => 'Direct uploads are not available on this deployment.',
        'import_file_unreadable' => 'The uploaded import file could not be read.',
        'import_header_invalid' => 'The import file must have a header row with a "first_name" column.',
    ],

    'auth' => [
        'telegram_bot_missing' => 'This academy has no active Telegram bot.',
        'telegram_not_linked' => 'This Telegram account is not linked to a student.',
        'init_data_invalid' => 'The Telegram sign-in data could not be verified.',
        'init_data_expired' => 'The Telegram sign-in data has expired. Please reopen the app.',
        'otp_invalid' => 'The code is incorrect or has expired.',
    ],

    'webhook_events' => [
        'student.created' => 'Student created',
        'student.updated' => 'Student updated',
        'practice.completed' => 'Practice session completed',
        'exam.submitted' => 'Exam submitted',
        'exam.scored' => 'Exam scored',
        'score.published' => 'Score published',
        'payment.succeeded' => 'Payment succeeded',
        'payment.failed' => 'Payment failed',
        'subscription.expiring' => 'Subscription expiring',
        'subscription.expired' => 'Subscription expired',
        'support.ticket.created' => 'Support ticket created',
    ],

    'webhook_delivery_status' => [
        'pending' => 'Pending',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'dead' => 'Given up',
    ],

];
