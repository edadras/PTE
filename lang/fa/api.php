<?php

declare(strict_types=1);

return [

    'errors' => [
        'validation_failed' => 'داده‌های ارسال‌شده معتبر نیست.',
        'not_found' => 'منبع درخواست‌شده یافت نشد.',
        'unauthenticated' => 'احراز هویت لازم است.',
        'forbidden' => 'اجازه انجام این کار را ندارید.',
        'insufficient_scope' => 'این کلید API دسترسی «:scope» را ندارد.',
        'rate_limited' => 'تعداد درخواست‌ها بیش از حد مجاز است. کمی صبر کنید.',
        'method_not_allowed' => 'این متد برای این مسیر پشتیبانی نمی‌شود.',
        'server_error' => 'خطایی از سمت ما رخ داد.',
        'tenant_not_resolved' => 'منبع درخواست‌شده یافت نشد.',
        'token_invalid' => 'توکن دسترسی نامعتبر است یا منقضی شده.',
        'token_tenant_mismatch' => 'توکن دسترسی نامعتبر است یا منقضی شده.',
        'student_blocked' => 'این حساب مسدود شده است.',
        'student_not_reachable' => 'برای این دانشجو گفتگوی تلگرامی فعالی وجود ندارد.',
        'no_bot_connected' => 'این آموزشگاه رباتی متصل ندارد.',
        'payment_gateway' => 'ارتباط با درگاه پرداخت برقرار نشد.',
        'telegram_unavailable' => 'در حال حاضر ارتباط با تلگرام برقرار نشد.',
        'ai_unavailable' => 'سرویس نمره‌دهی موقتاً در دسترس نیست.',
        'webhook_url_rejected' => 'آدرس وبهوک باید https و روی یک میزبان عمومی باشد.',
        'idempotency_key_invalid' => 'مقدار هدر Idempotency-Key بیش از حد طولانی است.',
        'idempotency_in_progress' => 'درخواستی با همین Idempotency-Key هنوز در حال پردازش است.',
        'export_async_only' => 'خروجی اکسل به‌صورت غیرهمزمان ساخته می‌شود؛ از پنل درخواست کنید.',
        'uploads_unavailable' => 'آپلود مستقیم در این محیط فعال نیست.',
    ],

    'auth' => [
        'telegram_bot_missing' => 'این آموزشگاه ربات فعالی ندارد.',
        'telegram_not_linked' => 'این حساب تلگرام به هیچ دانشجویی متصل نیست.',
        'init_data_invalid' => 'اطلاعات ورود تلگرام قابل اعتبارسنجی نبود.',
        'init_data_expired' => 'اطلاعات ورود تلگرام منقضی شده است. دوباره برنامه را باز کنید.',
        'otp_invalid' => 'کد نادرست است یا منقضی شده.',
    ],

    'webhook_events' => [
        'student.created' => 'ثبت دانشجوی جدید',
        'student.updated' => 'به‌روزرسانی دانشجو',
        'practice.completed' => 'پایان جلسه تمرین',
        'exam.submitted' => 'ارسال آزمون',
        'exam.scored' => 'نمره‌دهی آزمون',
        'score.published' => 'انتشار نمره',
        'payment.succeeded' => 'پرداخت موفق',
        'payment.failed' => 'پرداخت ناموفق',
        'subscription.expiring' => 'نزدیک شدن پایان اشتراک',
        'subscription.expired' => 'پایان اشتراک',
        'support.ticket.created' => 'ثبت تیکت پشتیبانی',
    ],

    'webhook_delivery_status' => [
        'pending' => 'در انتظار',
        'delivered' => 'تحویل شد',
        'failed' => 'ناموفق',
        'dead' => 'رها شده',
    ],

];
