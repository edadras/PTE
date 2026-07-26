<?php

declare(strict_types=1);

return [

    'status' => [
        'active' => 'فعال',
        'suspended' => 'تعلیق‌شده',
        'deleted' => 'حذف‌شده',
    ],

    'domain_type' => [
        'subdomain' => 'زیردامنه پلتفرم',
        'custom' => 'دامنه اختصاصی',
    ],

    'ssl_status' => [
        'pending' => 'در انتظار',
        'issued' => 'صادرشده',
        'failed' => 'ناموفق',
    ],

    'dark_mode' => [
        'light' => 'روشن',
        'dark' => 'تیره',
        'auto' => 'مطابق سیستم',
    ],

    'student_status' => [
        'active' => 'فعال',
        'inactive' => 'غیرفعال',
        'blocked' => 'مسدود',
    ],

    'student_source' => [
        'telegram' => 'تلگرام',
        'web' => 'وب',
        'import' => 'ورود گروهی',
        'api' => 'API',
    ],

    'membership_status' => [
        'active' => 'فعال',
        'invited' => 'دعوت‌شده',
        'suspended' => 'تعلیق‌شده',
    ],

    'class_group_status' => [
        'planned' => 'برنامه‌ریزی‌شده',
        'active' => 'در حال برگزاری',
        'completed' => 'پایان‌یافته',
        'archived' => 'بایگانی‌شده',
    ],

    'placeholder_groups' => [
        'student' => 'دانشجو',
        'academy' => 'آموزشگاه',
        'time' => 'تاریخ و زمان',
        'progress' => 'پیشرفت',
        'subscription' => 'اشتراک',
    ],

    /*
    |--------------------------------------------------------------------------
    | متن پیش‌فرض پیام‌های پلتفرم
    |--------------------------------------------------------------------------
    |
    | وقتی آموزشگاه متنی را سفارشی نکرده باشد، همین متن استفاده می‌شود.
    |
    | @see docs/03-white-label.md §6
    |
    */

    'templates' => [
        'welcome' => "سلام {first_name} عزیز 👋\nبه {academy_name} خوش اومدی. آماده‌ای تمرین امروزت رو شروع کنی؟",
        'menu_header' => 'چه کاری برات انجام بدم؟',
        'practice_started' => 'شروع کنیم! با حوصله جواب بده.',
        'practice_completed' => 'آفرین {first_name}! پاسخت ثبت شد.',
        'score_ready' => 'نمره‌ات آماده شد: {last_score}. برای دیدن جزئیات ضربه بزن.',
        'exam_reminder' => 'یادآوری: آزمون تو ساعت {time} روز {today} شروع می‌شود.',
        'daily_nudge' => 'چند دقیقه تمرین امروز، زنجیره {streak_days} روزه‌ات را حفظ می‌کند {first_name}.',
        'subscription_expiring' => 'اشتراک تو {days_remaining} روز دیگر ({expires_at}) تمام می‌شود.',
        'subscription_expired' => 'اشتراک تو تمام شده است. برای ادامه تمرین آن را تمدید کن.',
        'payment_success' => 'پرداخت با موفقیت انجام شد. پلن {plan_name} فعال شد.',
        'support_greeting' => 'سلام {first_name}، چطور می‌تونیم کمکت کنیم؟ سوالت را بفرست، تیم پشتیبانی پاسخ می‌دهد.',
        'error_generic' => 'مشکلی پیش آمد. لطفاً کمی بعد دوباره تلاش کن.',
        'quota_exceeded' => 'سهمیه تمرین فعلی‌ات تمام شده. کمی بعد دوباره تلاش کن.',
    ],

];
