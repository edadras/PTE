<?php

declare(strict_types=1);

return [

    'status' => [
        'open' => 'باز',
        'pending' => 'در انتظار دانشجو',
        'closed' => 'بسته',
    ],

    'priority' => [
        'low' => 'کم',
        'normal' => 'عادی',
        'high' => 'زیاد',
        'urgent' => 'فوری',
    ],

    'sender' => [
        'student' => 'دانشجو',
        'staff' => 'کارشناس',
        'system' => 'سیستم',
    ],

    'source' => [
        'telegram' => 'تلگرام',
        'panel' => 'پنل',
        'api' => 'API',
        'email' => 'ایمیل',
    ],

    'conversation' => [
        'title' => 'گفتگوی ربات',
        'empty' => 'پیام نگهداری‌شده‌ای برای این دانشجو وجود ندارد.',
        'retention_note' => 'پیام‌های قدیمی‌تر از :days روز نگهداری نمی‌شوند.',
        'showing' => 'نمایش :shown از :total پیام نگهداری‌شده.',
    ],

    'ticket' => [
        'opened' => 'تیکت باز شد.',
        'replied' => 'پاسخ ارسال شد.',
        'closed' => 'تیکت بسته شد.',
        'assigned' => 'تیکت ارجاع شد.',
        'internal_note' => 'یادداشت داخلی',
    ],

    'audit' => [
        'bot.connected' => 'اتصال ربات',
        'bot.token_rotated' => 'چرخش توکن ربات',
        'bot.webhook_reset' => 'بازنشانی وب‌هوک ربات',
        'ai.prompt_published' => 'انتشار Prompt',
        'ai.rubric_published' => 'انتشار Rubric',
        'ai.answer_rescored' => 'نمره‌دهی مجدد پاسخ',
        'assessment.score_overridden' => 'بازنویسی نمره',
        'assessment.exam_published' => 'انتشار آزمون',
        'assessment.exam_deleted' => 'حذف آزمون',
        'identity.staff_invited' => 'دعوت کارمند',
        'identity.staff_removed' => 'حذف کارمند',
        'identity.role_changed' => 'تغییر نقش',
        'identity.student_deleted' => 'حذف دانشجو',
        'commerce.plan_changed' => 'تغییر پلن',
        'commerce.subscription_status_changed' => 'تغییر وضعیت اشتراک',
        'commerce.payment_recorded' => 'ثبت پرداخت',
        'reporting.data_exported' => 'خروجی داده',
        'reporting.report_generated' => 'تولید گزارش',
        'support.ticket_opened' => 'باز شدن تیکت',
        'support.ticket_replied' => 'پاسخ تیکت',
        'support.ticket_closed' => 'بسته شدن تیکت',
        'support.ticket_assigned' => 'ارجاع تیکت',
        'platform.academy_created' => 'ایجاد آموزشگاه',
        'platform.academy_suspended' => 'تعلیق آموزشگاه',
        'platform.academy_resumed' => 'رفع تعلیق آموزشگاه',
        'platform.academy_deleted' => 'حذف آموزشگاه',
        'platform.academy_cloned' => 'کپی آموزشگاه',
        'platform.academy_exported' => 'خروجی کامل آموزشگاه',
        'platform.impersonated' => 'ورود به‌جای کاربر',
        'platform.tenant_command_run' => 'اجرای دستور در آموزشگاه',
        'platform.retention_sweep' => 'پاک‌سازی نگهداری داده',
        'model.created' => 'ایجاد',
        'model.updated' => 'ویرایش',
        'model.deleted' => 'حذف',
    ],

];
