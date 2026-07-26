<?php

declare(strict_types=1);

return [

    'channel' => [
        'telegram' => 'Telegram',
        'email' => 'Email',
        'sms' => 'SMS',
    ],

    'status' => [
        'pending' => 'Pending',
        'queued' => 'Scheduled',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
    ],

    'scheduled' => [
        'publish_exam' => 'Publish exam',
        'daily_practice' => 'Daily practice nudge',
        'exam_reminder' => 'Exam reminder',
        'weekly_progress' => 'Weekly progress report',
        're_engagement' => 'Re-engagement campaign',
        'broadcast' => 'Broadcast',
    ],

    'scheduled_status' => [
        'pending' => 'Pending',
        'running' => 'Running',
        'done' => 'Done',
        'failed' => 'Failed',
        'canceled' => 'Canceled',
    ],

    'weekly' => [
        'heading' => '📊 Your week in review',
        'overall' => 'Average :percentage% over :attempts attempt(s).',
        'strengths' => '✅ Strongest: :types',
        'weaknesses' => '📌 Worth practising: :types',
    ],

];
