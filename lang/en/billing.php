<?php

declare(strict_types=1);

return [

    'plan' => [
        'trial' => 'Trial',
        'starter' => 'Starter',
        'professional' => 'Professional',
        'enterprise' => 'Enterprise',
    ],

    'cycle' => [
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
    ],

    'currency' => [
        'irr' => 'IRR',
        'irt' => 'Toman',
        'usd' => 'USD',
        'eur' => 'EUR',
    ],

    'subscription_status' => [
        'trialing' => 'Trial',
        'active' => 'Active',
        'past_due' => 'Payment overdue',
        'suspended' => 'Suspended',
        'canceled' => 'Canceled',
        'expired' => 'Expired',
    ],

    'payment_status' => [
        'pending' => 'Pending',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partially refunded',
        'canceled' => 'Canceled',
    ],

    'gateway' => [
        'zarinpal' => 'ZarinPal',
        'idpay' => 'IDPay',
        'nextpay' => 'NextPay',
        'zibal' => 'Zibal',
        'stripe' => 'Stripe',
        'telegram' => 'Telegram Payments',
    ],

    'metric' => [
        'ai_requests' => 'AI requests',
        'ai_tokens' => 'AI tokens',
        'ai_cost_usd' => 'AI spend',
        'asr_minutes' => 'Speech recognition minutes',
        'storage_mb' => 'Storage',
        'active_students' => 'Active students',
        'staff_users' => 'Staff users',
        'broadcasts' => 'Broadcasts',
        'questions' => 'Questions',
    ],

    'quota_outcome' => [
        'unlimited' => 'Unlimited',
        'ok' => 'Within limits',
        'warning' => 'Approaching limit',
        'critical' => 'Almost at limit',
        'exceeded' => 'Limit reached',
    ],

    'quota' => [
        'unlimited' => ':metric: unlimited on your plan.',
        'unlimited_value' => 'unlimited',
        'ok' => ':metric: :used of :limit used.',
        'warning' => ':metric: :used of :limit used — you are approaching your plan limit.',
        'critical' => ':metric: :used of :limit used. Upgrade soon to avoid interruption.',
        'exceeded' => ':metric: you have reached your plan limit of :limit. Upgrade your plan to continue.',
    ],

    'limit_behaviour' => [
        'block_new_enrollments' => 'New enrollments paused',
        'degrade_ai_scoring' => 'AI scoring paused',
        'disable_speaking' => 'Speaking practice paused',
        'block_uploads' => 'Uploads paused',
        'block_staff_invites' => 'Staff invitations paused',
        'block_broadcasts' => 'Broadcasts paused',
        'block_question_creation' => 'New questions paused',
    ],

    'limit_message' => [
        'block_new_enrollments' => 'Your plan\'s active student limit has been reached, so new sign-ups are paused. Everyone already enrolled keeps full access. Upgrade your plan to admit more students.',
        'degrade_ai_scoring' => 'Your monthly AI allowance is used up, so AI feedback is paused until next month. Algorithmic practice, answers and scores keep working exactly as before. Upgrade your plan to turn AI feedback back on.',
        'disable_speaking' => 'Your monthly speech recognition minutes are used up, so speaking tasks are paused. Reading, writing and listening practice are unaffected. Upgrade your plan to continue.',
        'block_uploads' => 'Your storage is full, so new uploads are paused. Existing files stay available. Free up space or upgrade your plan to continue.',
        'block_staff_invites' => 'Your plan\'s staff user limit has been reached, so new invitations are paused. Current staff keep full access. Upgrade your plan to add more colleagues.',
        'block_broadcasts' => 'Your monthly broadcast allowance is used up. One-to-one bot conversations are unaffected. Upgrade your plan to send more broadcasts.',
        'block_question_creation' => 'Your plan\'s question bank limit has been reached, so new questions cannot be added. Your existing bank stays fully usable. Upgrade your plan to keep growing it.',
    ],

    'discount' => [
        'applied' => 'Discount applied.',
        'invalid' => 'This discount code is not valid.',
        'not_found' => 'We could not find that discount code.',
        'inactive' => 'This discount code is no longer active.',
        'expired' => 'This discount code has expired.',
        'exhausted' => 'This discount code has been fully used.',
        'below_minimum' => 'This discount code needs a larger order.',
        'already_used' => 'You have already used this discount code.',
    ],

    'payment_error' => [
        'gateway_unavailable' => 'The payment gateway is not responding. Please try again in a moment.',
        'not_configured' => 'This payment method is not available yet.',
        'verification_failed' => 'We could not confirm your payment with the gateway. If money left your account it will be returned automatically.',
        'amount_mismatch' => 'The confirmed amount did not match the order. The payment was not accepted.',
        'canceled_by_user' => 'The payment was canceled.',
        'already_processed' => 'This payment has already been processed.',
        'refund_unsupported' => 'Refunds for this gateway have to be issued from the provider panel.',
        'unknown' => 'The payment could not be completed. Please try again.',
    ],

    'invoice' => [
        'title' => 'Invoice',
        'number' => 'Invoice number',
        'issued_at' => 'Issue date',
        'due_at' => 'Due date',
        'billed_to' => 'Billed to',
        'description' => 'Description',
        'period' => 'Period',
        'quantity' => 'Qty',
        'unit_price' => 'Unit price',
        'line_total' => 'Amount',
        'subtotal' => 'Subtotal',
        'tax' => 'VAT (:rate%)',
        'total' => 'Total',
        'status' => 'Status',
        'line_subscription' => ':plan plan — :cycle subscription',
        'line_payment' => 'Platform payment',
        'plan_fallback' => 'Subscription',
        'paid_notice' => 'Paid — thank you.',
        'footer' => 'Generated electronically; valid without a signature.',
    ],

    'status_banner' => [
        'past_due' => 'Your subscription payment is overdue. The panel is read-only until it is settled — your bot and your students are unaffected.',
        'suspended' => 'Your subscription is suspended. Settle the outstanding invoice to restore service.',
        'trial_ending' => 'Your trial ends in :days days.',
        'renewal_reminder' => 'Your subscription renews in :days days.',
    ],

    'bot' => [
        'suspended' => 'This service is temporarily unavailable. Please contact your academy.',
    ],

];
