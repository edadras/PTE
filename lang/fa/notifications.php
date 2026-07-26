<?php

declare(strict_types=1);

return [

    'channel' => [
        'telegram' => 'تلگرام',
        'email' => 'ایمیل',
        'sms' => 'پیامک',
    ],

    'status' => [
        'pending' => 'در انتظار',
        'queued' => 'زمان‌بندی‌شده',
        'sent' => 'ارسال‌شده',
        'failed' => 'ناموفق',
        'skipped' => 'ارسال نشد',
    ],

    'scheduled' => [
        'publish_exam' => 'انتشار آزمون',
        'daily_practice' => 'یادآوری تمرین روزانه',
        'exam_reminder' => 'یادآوری آزمون',
        'weekly_progress' => 'گزارش هفتگی پیشرفت',
        're_engagement' => 'کمپین بازگشت',
        'broadcast' => 'پیام همگانی',
    ],

    'scheduled_status' => [
        'pending' => 'در انتظار',
        'running' => 'در حال اجرا',
        'done' => 'انجام‌شده',
        'failed' => 'ناموفق',
        'canceled' => 'لغوشده',
    ],

    'weekly' => [
        'heading' => '📊 مرور هفته شما',
        'overall' => 'میانگین :percentage٪ در :attempts تمرین.',
        'strengths' => '✅ قوی‌ترین: :types',
        'weaknesses' => '📌 نیازمند تمرین: :types',
    ],

];
