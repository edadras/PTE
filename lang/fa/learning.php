<?php

declare(strict_types=1);

return [

    'question_status' => [
        'draft' => 'پیش‌نویس',
        'pending_review' => 'در انتظار بررسی',
        'approved' => 'تاییدشده',
        'published' => 'منتشرشده',
        'rejected' => 'ردشده',
    ],

    'difficulty' => [
        'easy' => 'آسان',
        'medium' => 'متوسط',
        'hard' => 'سخت',
    ],

    'media_kind' => [
        'audio' => 'صوت',
        'image' => 'تصویر',
        'video' => 'ویدیو',
    ],

    'course_status' => [
        'draft' => 'پیش‌نویس',
        'published' => 'منتشرشده',
        'archived' => 'بایگانی‌شده',
    ],

    'selection_mode' => [
        'random' => 'تصادفی',
        'sequential' => 'ترتیبی',
        'adaptive' => 'تطبیقی (بر اساس سطح)',
    ],

    'bank' => [
        'title' => 'بانک سوال',
        'default' => 'بانک پیش‌فرض',
        'question_count' => ':count سوال',
        'cloned' => 'بانک «:name» کپی شد.',
        'starter_pack' => 'بانک نمونه',
    ],

    'question' => [
        'difficulty_index' => 'دشواری اندازه‌گیری‌شده',
        'usage_count' => 'دفعات تمرین',
        'avg_score' => 'میانگین نمره',
        'approved_by' => 'تاییدشده توسط :name',
        'rejected_note' => 'دلیل رد شدن',
        'not_enough' => 'هنوز سوال منتشرشده کافی برای این نوع تمرین وجود ندارد.',
    ],

    'import' => [
        'title' => 'ورود گروهی سوال',
        'running' => 'در حال ورود…',
        'completed' => ':imported سطر از :total سطر وارد شد.',
        'failed' => 'ورود ناموفق بود. :failed سطر از :total سطر خطا دارد.',
        'rolled_back' => 'هیچ سطری وارد نشد — همه سطرها باید معتبر باشند.',
        'row_error' => 'سطر :line: :message',
        'unsupported_format' => 'فایل اکسل باینری پشتیبانی نمی‌شود. فایل را با فرمت CSV (UTF-8) ذخیره و سپس وارد کنید.',
        'rollback_done' => ':count سوال واردشده برگردانده شد.',
    ],

    'module' => [
        'enabled' => 'ماژول :name فعال شد.',
        'disabled' => 'ماژول :name غیرفعال شد. هیچ محتوایی حذف نشد.',
        'not_enabled' => 'ماژول :name برای این آموزشگاه فعال نیست.',
        'not_available' => 'ماژول :name هنوز عرضه نشده است.',
        'plan_required' => 'ماژول :name نیازمند پلن :plan است.',
    ],

    'validation' => [
        'invalid_content' => 'این سوال ذخیره نشد: :errors',
    ],

];
