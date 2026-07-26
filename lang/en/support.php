<?php

declare(strict_types=1);

return [

    'status' => [
        'open' => 'Open',
        'pending' => 'Awaiting student',
        'closed' => 'Closed',
    ],

    'priority' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],

    'sender' => [
        'student' => 'Student',
        'staff' => 'Staff',
        'system' => 'System',
    ],

    'source' => [
        'telegram' => 'Telegram',
        'panel' => 'Panel',
        'api' => 'API',
        'email' => 'Email',
    ],

    'conversation' => [
        'title' => 'Bot conversation',
        'empty' => 'No retained messages for this student.',
        'retention_note' => 'Messages older than :days days are not retained.',
        'showing' => 'Showing :shown of :total retained messages.',
    ],

    'ticket' => [
        'opened' => 'Ticket opened.',
        'replied' => 'Reply sent.',
        'closed' => 'Ticket closed.',
        'assigned' => 'Ticket assigned.',
        'internal_note' => 'Internal note',
    ],

    'audit' => [
        'bot.connected' => 'Bot connected',
        'bot.token_rotated' => 'Bot token rotated',
        'bot.webhook_reset' => 'Bot webhook reset',
        'ai.prompt_published' => 'Prompt published',
        'ai.rubric_published' => 'Rubric published',
        'ai.answer_rescored' => 'Answer rescored',
        'assessment.score_overridden' => 'Score overridden',
        'assessment.exam_published' => 'Exam published',
        'assessment.exam_deleted' => 'Exam deleted',
        'identity.staff_invited' => 'Staff member invited',
        'identity.staff_removed' => 'Staff member removed',
        'identity.role_changed' => 'Role changed',
        'identity.student_deleted' => 'Student deleted',
        'commerce.plan_changed' => 'Plan changed',
        'commerce.subscription_status_changed' => 'Subscription status changed',
        'commerce.payment_recorded' => 'Payment recorded',
        'reporting.data_exported' => 'Data exported',
        'reporting.report_generated' => 'Report generated',
        'support.ticket_opened' => 'Ticket opened',
        'support.ticket_replied' => 'Ticket replied',
        'support.ticket_closed' => 'Ticket closed',
        'support.ticket_assigned' => 'Ticket assigned',
        'platform.academy_created' => 'Academy created',
        'platform.academy_suspended' => 'Academy suspended',
        'platform.academy_resumed' => 'Academy resumed',
        'platform.academy_deleted' => 'Academy deleted',
        'platform.academy_cloned' => 'Academy cloned',
        'platform.academy_exported' => 'Academy exported',
        'platform.impersonated' => 'Impersonation',
        'platform.tenant_command_run' => 'Command run in tenant',
        'platform.retention_sweep' => 'Retention sweep',
        'model.created' => 'Created',
        'model.updated' => 'Updated',
        'model.deleted' => 'Deleted',
    ],

];
