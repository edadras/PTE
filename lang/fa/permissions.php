<?php

declare(strict_types=1);

return [

    'scopes' => [
        'academy' => 'آموزشگاه',
        'platform' => 'پلتفرم',
    ],

    'groups' => [
        'branding' => 'برند و تنظیمات',
        'telegram' => 'تلگرام',
        'users' => 'کاربران و نقش‌ها',
        'students' => 'دانشجویان',
        'content' => 'محتوا و بانک سوال',
        'assessment' => 'آزمون و تصحیح',
        'ai' => 'هوش مصنوعی',
        'reports' => 'گزارش‌ها',
        'billing' => 'مالی',
        'support' => 'پشتیبانی',
        'modules' => 'ماژول‌ها',
        'system' => 'سیستم',
        'platform' => 'مدیریت پلتفرم',
    ],

    'items' => [

        'academy.brand.view' => 'مشاهده برند',
        'academy.brand.update' => 'ویرایش برند',
        'academy.settings.view' => 'مشاهده تنظیمات',
        'academy.settings.update' => 'ویرایش تنظیمات',
        'academy.domain.manage' => 'مدیریت دامنه‌ها',

        'telegram.bot.view' => 'مشاهده تنظیمات ربات',
        'telegram.bot.update' => 'ویرایش تنظیمات ربات (شامل توکن)',
        'telegram.menu.view' => 'مشاهده منوی ربات',
        'telegram.menu.update' => 'ویرایش منوی ربات',
        'telegram.flow.view' => 'مشاهده Flowها',
        'telegram.flow.update' => 'ویرایش Flowها',
        'telegram.flow.publish' => 'انتشار Flowها',
        'telegram.broadcast.send' => 'ارسال پیام گروهی',

        'users.staff.view' => 'مشاهده کاربران',
        'users.staff.invite' => 'دعوت کاربر',
        'users.staff.remove' => 'حذف کاربر',
        'users.roles.view' => 'مشاهده نقش‌ها',
        'users.roles.manage' => 'مدیریت نقش‌ها',

        'students.view' => 'مشاهده دانشجویان',
        'students.create' => 'ثبت دانشجو',
        'students.update' => 'ویرایش دانشجو',
        'students.delete' => 'حذف دانشجو',
        'students.import' => 'ورود گروهی دانشجو',
        'students.export' => 'خروجی دانشجویان',

        'courses.view' => 'مشاهده دوره‌ها',
        'courses.manage' => 'مدیریت دوره‌ها',
        'lessons.view' => 'مشاهده درس‌ها',
        'lessons.manage' => 'مدیریت درس‌ها',
        'questions.view' => 'مشاهده سوال‌ها',
        'questions.create' => 'ایجاد سوال',
        'questions.update' => 'ویرایش سوال',
        'questions.delete' => 'حذف سوال',
        'questions.import' => 'ورود گروهی سوال',
        'questions.approve' => 'تایید سوال',
        'question_banks.manage' => 'مدیریت بانک سوال',

        'exams.view' => 'مشاهده آزمون‌ها',
        'exams.create' => 'ایجاد آزمون',
        'exams.update' => 'ویرایش آزمون',
        'exams.delete' => 'حذف آزمون',
        'exams.publish' => 'انتشار آزمون',
        'exams.schedule' => 'زمان‌بندی آزمون',
        'practice.configure' => 'پیکربندی تمرین',
        'answers.view' => 'مشاهده پاسخ‌ها',
        'answers.grade' => 'تصحیح پاسخ‌ها',
        'answers.override_ai_score' => 'بازنویسی نمره هوش مصنوعی',
        'scores.view' => 'مشاهده نمرات',
        'scores.publish' => 'انتشار نمرات',

        'ai.settings.view' => 'مشاهده تنظیمات هوش مصنوعی',
        'ai.settings.update' => 'ویرایش تنظیمات هوش مصنوعی',
        'ai.prompts.view' => 'مشاهده Promptها',
        'ai.prompts.update' => 'ویرایش Promptها',
        'ai.prompts.publish' => 'انتشار Promptها',
        'ai.rubrics.manage' => 'مدیریت Rubricها',
        'ai.usage.view' => 'مشاهده مصرف هوش مصنوعی',

        'reports.dashboard.view' => 'مشاهده داشبورد',
        'reports.detailed.view' => 'مشاهده گزارش‌های تفصیلی',
        'reports.export_excel' => 'خروجی اکسل',
        'reports.financial.view' => 'مشاهده گزارش مالی',

        'billing.view' => 'مشاهده صورتحساب',
        'billing.manage' => 'مدیریت صورتحساب',
        'billing.payment_methods' => 'مدیریت روش‌های پرداخت',
        'subscriptions.students.manage' => 'مدیریت اشتراک دانشجویان',

        'support.tickets.view' => 'مشاهده تیکت‌ها',
        'support.tickets.reply' => 'پاسخ به تیکت‌ها',
        'support.tickets.close' => 'بستن تیکت‌ها',
        'support.conversations.view' => 'مشاهده گفتگوهای ربات',

        'modules.view' => 'مشاهده ماژول‌ها',
        'modules.toggle' => 'فعال یا غیرفعال کردن ماژول‌ها',

        'audit.view' => 'مشاهده لاگ ممیزی',
        'api_keys.manage' => 'مدیریت کلیدهای API',
        'webhooks.manage' => 'مدیریت وبهوک‌ها',

        'platform.academies.manage' => 'ایجاد، تعلیق و حذف آموزشگاه',
        'platform.analytics.view' => 'مشاهده آمار پلتفرم',
        'platform.plans.manage' => 'مدیریت پلن‌ها و قیمت‌گذاری',
        'platform.billing.manage' => 'مدیریت مالی پلتفرم',
        'platform.logs.view' => 'مشاهده لاگ‌های سیستم',
        'platform.infra.manage' => 'مدیریت زیرساخت و صف‌ها',
        'platform.ai.manage' => 'مدیریت مدل‌های هوش مصنوعی و کلیدهای پلتفرم',
        'platform.impersonate' => 'ورود موقت به آموزشگاه',
        'platform.modules.manage' => 'مدیریت ماژول‌ها',
        'platform.view_all' => 'کوئری روی همه آموزشگاه‌ها',

    ],

];
