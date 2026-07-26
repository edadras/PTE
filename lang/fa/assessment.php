<?php

declare(strict_types=1);

return [

    'session_type' => [
        'practice' => 'تمرین',
        'exam' => 'آزمون',
    ],

    'session_status' => [
        'in_progress' => 'در حال انجام',
        'completed' => 'تکمیل‌شده',
        'abandoned' => 'رهاشده',
        'submitted' => 'ارسال‌شده',
        'scoring' => 'در حال نمره‌دهی',
        'scored' => 'نمره‌دهی‌شده',
        'expired' => 'منقضی‌شده',
    ],

    'scoring_status' => [
        'pending' => 'در انتظار نمره‌دهی',
        'scoring' => 'در حال نمره‌دهی',
        'scored' => 'نمره‌دهی‌شده',
        'failed' => 'نمره‌دهی ناموفق',
        'manual_review' => 'در انتظار بررسی مدرس',
    ],

    'scored_by' => [
        'ai' => 'هوش مصنوعی',
        'teacher' => 'مدرس',
        'system' => 'خودکار',
    ],

    'exam_status' => [
        'draft' => 'پیش‌نویس',
        'published' => 'منتشرشده',
        'archived' => 'بایگانی‌شده',
    ],

    'selection_mode' => [
        'manual' => 'انتخاب دستی',
        'random' => 'تصادفی با فیلتر',
        'pool' => 'تصادفی از مخزن',
    ],

    'practice_access' => [
        'all' => 'همه دانشجویان',
        'subscribers' => 'فقط اشتراک فعال',
        'free_then_subscribe' => 'چند تمرین رایگان، سپس اشتراک',
    ],

    'practice_selection' => [
        'random' => 'تصادفی',
        'sequential' => 'ترتیبی',
        'adaptive' => 'تطبیقی بر اساس سطح',
    ],

    'feedback' => [
        'perfect' => 'عالی — همه بخش‌ها درست بود.',
        'strong' => 'خیلی نزدیک بود؛ فقط چند جزئیات جا افتاد.',
        'correct' => 'درست است.',
        'partial' => 'تا حدی درست. بخش‌های مشخص‌شده را مرور کنید.',
        'incorrect' => 'این بار درست نبود.',
        'weak' => 'بخش زیادی از پاسخ جا افتاد — بهتر است دوباره تمرین کنید.',
        'no_answer' => 'پاسخی ثبت نشد.',
        'no_speech' => 'در فایل صوتی گفتاری تشخیص داده نشد.',
        'skipped' => 'این سوال را رد کردید.',
        'over_selected' => 'کلمات زیادی انتخاب شد؛ انتخاب اشتباه در این تمرین نمره منفی دارد.',
        'needs_review' => 'این پاسخ نیاز به بررسی مدرس دارد.',
        'scoring_failed' => 'نمره‌دهی انجام نشد. به مدرس اطلاع داده شد.',
    ],

    'report' => [
        'title' => 'کارنامه',
        'total' => 'نمره کل',
        'passed' => 'قبول',
        'failed' => 'مردود',
        'sections' => 'بخش‌ها',
        'strengths' => 'نقاط قوت',
        'weaknesses' => 'نیاز به تمرین',
        'pending' => 'نمره :count پاسخ هنوز در حال محاسبه است.',
    ],

    'errors' => [
        'daily_limit_reached' => 'به سقف روزانه تمرین (:limit) رسیده‌اید. فردا دوباره تلاش کنید.',
        'subscription_required' => 'برای ادامه تمرین، اشتراک فعال لازم است.',
        'no_questions' => 'در حال حاضر سوالی برای این تمرین موجود نیست.',
        'invalid_answer' => 'قالب پاسخ با چیزی که این سوال انتظار دارد هم‌خوان نیست.',
        'session_not_active' => 'این جلسه دیگر پاسخ نمی‌پذیرد.',
        'exam_unavailable' => 'این آزمون در دسترس نیست.',
        'exam_not_published' => 'این آزمون هنوز منتشر نشده است.',
        'exam_window_closed' => 'اکنون خارج از بازه زمانی این آزمون هستیم.',
        'exam_attempts_exhausted' => 'همه :allowed تلاش مجاز این آزمون را استفاده کرده‌اید.',
        'exam_empty' => 'این آزمون هنوز سوالی ندارد.',
        'override_reason_required' => 'برای تغییر نمره، ثبت دلیل الزامی است.',
    ],

];
