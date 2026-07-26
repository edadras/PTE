<?php

declare(strict_types=1);

return [

    'tasks' => [
        'speaking' => [
            'read_aloud' => 'اسپیکینگ — خواندن متن (Read Aloud)',
            'repeat_sentence' => 'اسپیکینگ — تکرار جمله (Repeat Sentence)',
            'describe_image' => 'اسپیکینگ — توصیف تصویر (Describe Image)',
            'retell_lecture' => 'اسپیکینگ — بازگویی سخنرانی (Re-tell Lecture)',
        ],
        'writing' => [
            'essay' => 'رایتینگ — مقاله (Essay)',
            'summarize_text' => 'رایتینگ — خلاصه متن (Summarize Written Text)',
        ],
        'listening' => [
            'summarize_spoken' => 'لیسنینگ — خلاصه متن شنیداری (Summarize Spoken Text)',
        ],
        'grammar' => [
            'check' => 'بررسی گرامر',
        ],
        'vocabulary' => [
            'explain' => 'توضیح واژه',
        ],
        'feedback' => [
            'overall' => 'بازخورد کلی',
        ],
        'chat' => [
            'assistant' => 'دستیار گفتگو',
        ],
        'report' => [
            'weekly_summary' => 'خلاصه هفتگی',
        ],
        'transcription' => 'تبدیل گفتار به متن',
    ],

    'criteria' => [
        'pronunciation' => 'تلفظ',
        'fluency' => 'روانی گفتار',
        'vocabulary' => 'واژگان',
        'grammar' => 'گرامر',
        'content' => 'محتوا',
        'form' => 'قالب',
        'spelling' => 'املا',
        'development' => 'پرورش ایده، ساختار و انسجام',
        'linguistic_range' => 'گستره زبانی',
    ],

    'providers' => [
        'gemini' => 'جمینای گوگل',
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'whisper' => 'ویسپر (تشخیص گفتار)',
        'google_stt' => 'تبدیل گفتار به متن گوگل',
    ],

    'prompt_status' => [
        'draft' => 'پیش‌نویس',
        'published' => 'منتشرشده',
        'archived' => 'بایگانی‌شده',
    ],

    'request_status' => [
        'success' => 'موفق',
        'failed' => 'ناموفق',
        'timeout' => 'اتمام زمان',
        'rate_limited' => 'محدودیت نرخ',
    ],

    'messages' => [
        'result_delayed' => 'نتیجه شما در حال آماده‌سازی است و به‌زودی آماده می‌شود.',
        'scoring_in_progress' => 'در حال نمره‌دهی به پاسخ شما هستیم؛ معمولاً کمتر از یک دقیقه طول می‌کشد.',
        'manual_review' => 'مدرس شما در حال بررسی این پاسخ است و نمره را تأیید خواهد کرد.',
        'ai_generated_disclaimer' => 'این نمره توسط هوش مصنوعی تولید شده و ممکن است با نمره رسمی آزمون تفاوت داشته باشد.',
        'low_confidence_note' => 'این نمره موقت است و توسط مدرس تأیید می‌شود.',
        'noisy_warning' => 'پاسخ شما نمره‌گذاری شد، اما صدای ضبط‌شده نویز داشت. ضبط در محیط آرام‌تر نتیجه دقیق‌تری می‌دهد.',

        'audio' => [
            'too_short' => 'صدای شما خیلی کوتاه بود. لطفاً دوباره ضبط کنید و چند ثانیه صحبت کنید.',
            'too_long' => 'مدت صدای شما از حد مجاز این تمرین بیشتر بود. لطفاً پاسخ کوتاه‌تری ضبط کنید.',
            'silent' => 'صدایی شنیده نشد. لطفاً میکروفون را بررسی کنید و دوباره تلاش کنید.',
            'unreadable' => 'فایل صوتی شما باز نشد. لطفاً دوباره ارسال کنید.',
        ],
    ],

    'admin' => [
        'quota_exhausted' => 'سهمیه هوش مصنوعی شما در این دوره تمام شده است. تمرین‌های الگوریتمی فعال می‌مانند؛ نمره‌دهی هوش مصنوعی با تمدید یا خرید بسته اضافی برمی‌گردد.',
        'byok_hint' => 'با کلید اختصاصی خودتان، هزینه را مستقیم به ارائه‌دهنده می‌پردازید و مصرف هوش مصنوعی از سهمیه پلن شما کم نمی‌شود.',
        'rubric_weight_error' => 'مجموع وزن معیارها باید دقیقاً ۱۰۰ باشد.',
        'prompt_untested' => 'این Prompt پیش از انتشار باید روی حداقل سه نمونه واقعی آزمایش شود.',
        'cost_anomaly' => 'هزینه هوش مصنوعی :academy نسبت به ماه گذشته :percent درصد رشد کرده است.',
        'scoring_drift' => 'نمره‌دهی مجدد پاسخ‌های هفته گذشته برای :task به‌طور میانگین :deviation نمره اختلاف داشت.',
    ],

];
