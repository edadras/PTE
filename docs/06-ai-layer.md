# 06 — لایه هوش مصنوعی

AI هم بزرگ‌ترین ارزش محصول است و هم بزرگ‌ترین ریسک هزینه‌ای آن. این لایه باید از روز اول **قابل تعویض، قابل اندازه‌گیری و قابل کنترل هزینه** باشد.

---

## ۱. معماری AI Gateway

```
                     ┌────────────────────────────────┐
   ScoreAnswer Job → │        AI GATEWAY              │
                     │                                │
                     │  1. TaskResolver               │  کدام task؟ (speaking/writing/grammar)
                     │  2. ProviderResolver           │  آموزشگاه چه چیزی انتخاب کرده؟
                     │  3. QuotaGuard                 │  سهمیه باقی مانده؟
                     │  4. PromptRenderer             │  Prompt نسخه فعال + متغیرها
                     │  5. ProviderClient             │  فراخوانی واقعی
                     │  6. ResponseValidator          │  JSON معتبر؟ در بازه؟
                     │  7. RubricEngine               │  اعمال وزن‌های آموزشگاه
                     │  8. CostMeter                  │  ثبت توکن و هزینه
                     │  9. FallbackChain              │  اگر خطا → provider بعدی
                     └────────────────┬───────────────┘
                                      │
              ┌───────────┬───────────┼───────────┬────────────┐
              ▼           ▼           ▼           ▼            ▼
          Gemini      OpenAI      Anthropic    Whisper    Google STT
       2.5 Pro/Flash   GPT-5      Claude       (ASR)       (ASR)
```

**قاعده:** هیچ کلاسی خارج از `app/Domain/AI/Providers/` نباید نام Gemini یا OpenAI را بشناسد. تست معماری این را تضمین می‌کند.

---

## ۲. پیکربندی توسط آموزشگاه

```
تنظیمات هوش مصنوعی
──────────────────────────────────────────────────────────
وظیفه              ارائه‌دهنده        مدل                  
──────────────────────────────────────────────────────────
🎙 Speaking        [ Gemini    ▾ ]  [ Gemini 2.5 Pro   ▾ ]
✍ Writing         [ OpenAI    ▾ ]  [ GPT-5            ▾ ]
📝 Grammar         [ Gemini    ▾ ]  [ Gemini 2.5 Flash ▾ ]
📊 Scoring         [ Gemini    ▾ ]  [ Gemini 2.5 Pro   ▾ ]
🗣 Transcription   [ Whisper   ▾ ]  [ whisper-large-v3 ▾ ]
💬 Chat Assistant  [ Anthropic ▾ ]  [ Claude Sonnet    ▾ ]

پیشرفته
  دما (Temperature):     [ 0.3 ]   ← پایین = پایدارتر برای نمره‌دهی
  حداکثر توکن خروجی:      [ 1200 ]
  زنجیره جایگزین:         Gemini Pro → Gemini Flash → GPT-5
  کش پاسخ‌های مشابه:      ☑ فعال (۷ روز)
  حالت اقتصادی:          ☐ استفاده از مدل ارزان برای تمرین، مدل قوی فقط برای آزمون

کلید API
  ● استفاده از کلید پلتفرم (هزینه از سهمیه پلن شما کم می‌شود)
  ○ کلید اختصاصی خودم (BYOK — پلن Professional به بالا)
```

### BYOK — Bring Your Own Key
آموزشگاه‌های بزرگ ترجیح می‌دهند کلید خودشان را بدهند و مستقیم به Google/OpenAI پول بدهند. این:
- ریسک هزینه شما را صفر می‌کند
- برای پلن Enterprise یک مزیت فروش است
- کلید با `encrypted` cast ذخیره و هرگز در UI بازخوانی نمی‌شود (فقط ۴ کاراکتر آخر)

---

## ۳. Prompt Builder

هر آموزشگاه می‌تواند Prompt خودش را بنویسد. Prompt نسخه‌دار است.

```
ai_prompts
  academy_id · key · version · status(draft|published|archived)
  system_prompt · user_template · output_schema json
  variables json · model_hint · published_at · created_by
```

### کلیدهای استاندارد

```
speaking.read_aloud        speaking.repeat_sentence      speaking.describe_image
speaking.retell_lecture    writing.essay                 writing.summarize_text
listening.summarize_spoken grammar.check                 vocabulary.explain
feedback.overall           chat.assistant                report.weekly_summary
```

### ویرایشگر در پنل

```
Prompt: speaking.read_aloud            نسخه ۳ (منتشرشده)  [تاریخچه]

┌─ System ────────────────────────────────────────────────┐
│ You are an experienced PTE Academic examiner with 10    │
│ years of scoring experience. Score strictly according   │
│ to the official Pearson rubric. Never inflate scores.   │
│ Respond ONLY with valid JSON matching the given schema. │
└─────────────────────────────────────────────────────────┘

┌─ User Template ─────────────────────────────────────────┐
│ Target text:                                            │
│ {{question_text}}                                       │
│                                                         │
│ Student transcript (ASR):                               │
│ {{transcript}}                                          │
│                                                         │
│ Acoustic metrics:                                       │
│ - words per minute: {{wpm}}                             │
│ - pause count: {{pause_count}}                          │
│ - total pause duration: {{pause_total_ms}} ms           │
│ - ASR confidence: {{asr_confidence}}                    │
│                                                         │
│ Scoring weights: {{rubric_weights}}                     │
│ Feedback language: {{feedback_locale}}                  │
└─────────────────────────────────────────────────────────┘

متغیرهای در دسترس: question_text · transcript · wpm · pause_count ·
pause_total_ms · asr_confidence · rubric_weights · student_level ·
feedback_locale · academy_name

     [ آزمایش با نمونه ]   [ ذخیره پیش‌نویس ]   [ انتشار ]
```

### حفاظ‌ها (Guardrails)
- **Schema اجباری خروجی** — پاسخ باید JSON منطبق با `output_schema` باشد؛ در غیر این صورت یک بار retry، سپس fallback
- **آزمایش قبل از انتشار الزامی** — Prompt منتشر نمی‌شود مگر روی حداقل ۳ نمونه واقعی اجرا و نتیجه معتبر داده باشد
- **Rollback فوری** به نسخه قبلی
- **تزریق سیستمی غیرقابل حذف:** حتی اگر آموزشگاه system prompt را پاک کند، یک پیشوند اجباری اضافه می‌شود که فرمت خروجی و ممنوعیت افشای دستورالعمل را تضمین می‌کند
- **ضد Prompt Injection:** ورودی دانشجو (transcript، essay) همیشه در بخش user و داخل delimiter مشخص قرار می‌گیرد، هرگز داخل system

---

## ۴. موتور Rubric — وزن نمره‌دهی

```
ai_rubrics
  academy_id · task_key · name · version · is_active
  criteria json · scale_min · scale_max · rounding
```

### پنل

```
Rubric: Speaking — Read Aloud

معیار            وزن     توضیح برای AI
────────────────────────────────────────────────────────────
تلفظ             [30]%   دقت واج‌ها، تکیه کلمات، آهنگ جمله
روانی            [25]%   سرعت طبیعی، مکث‌های به‌جا، بدون تکرار
واژگان           [20]%   خواندن دقیق کلمات هدف
گرامر            [15]%   حفظ ساختار جمله هنگام خواندن
محتوا            [10]%   پوشش کامل متن
────────────────────────────────────────────────────────────
                 100% ✅

مقیاس: [0] تا [90]     گرد کردن: [نزدیک‌ترین عدد صحیح ▾]
```

اعتبارسنجی: مجموع وزن‌ها باید دقیقاً ۱۰۰ باشد؛ در غیر این صورت ذخیره ممکن نیست.

### محاسبه

```php
$final = 0;
foreach ($rubric->criteria as $c) {
    $raw = $aiResponse->scores[$c->key];              // 0..100 از AI
    $final += $raw * ($c->weight / 100);
}
$final = $rubric->round($final / 100 * $rubric->scale_max);
```

AI فقط نمره خام هر معیار را می‌دهد؛ **وزن‌دهی و مقیاس‌بندی در کد انجام می‌شود، نه در AI.** این تفاوت مهمی است: نتیجه قابل بازتولید، قابل ممیزی و قابل تغییر بدون فراخوانی مجدد AI می‌شود.

---

## ۵. پایپ‌لاین Speaking

```
voice (ogg/opus)
   │
   ├─▶ [1] دانلود از تلگرام → S3
   │
   ├─▶ [2] FFmpeg
   │        ffmpeg -i in.ogg -ar 16000 -ac 1 -c:a pcm_s16le out.wav
   │        استخراج: مدت، RMS، تعداد و طول سکوت‌ها
   │
   ├─▶ [3] بررسی کیفیت (قبل از خرج کردن پول AI)
   │        - مدت < ۲ ثانیه؟         → «صدا خیلی کوتاه بود»
   │        - سکوت کامل / RMS پایین؟  → «صدایی شنیده نشد»
   │        - نویز بیش از حد؟         → هشدار ولی ادامه
   │
   ├─▶ [4] ASR (Whisper یا Google STT)
   │        خروجی: متن + زمان‌بندی کلمات + confidence
   │
   ├─▶ [5] متریک‌های عینی (کد، نه AI — رایگان و قطعی)
   │        WPM · تعداد مکث · طول مکث · نسبت گفتار به سکوت
   │        تطبیق با متن هدف (WER, برای RA و RS)
   │        کلمات جاافتاده / اضافه / اشتباه
   │
   ├─▶ [6] AI Scoring
   │        transcript + متریک‌ها + rubric → JSON نمره و بازخورد
   │
   ├─▶ [7] ترکیب
   │        نمره نهایی = ترکیب متریک عینی و قضاوت AI
   │
   └─▶ [8] ذخیره + اطلاع به دانشجو
```

**دلیل مرحله ۵:** WPM و مکث را می‌شود دقیق و رایگان محاسبه کرد. دادن این اعداد به AI به‌جای انتظار حدس زدنشان، هم دقت را بالا می‌برد و هم مدل ارزان‌تر را کافی می‌کند.

**دلیل مرحله ۳:** جلوگیری از هزینه AI برای صوت خالی. در عمل ۵ تا ۱۰ درصد ارسال‌ها بی‌کیفیت‌اند.

### خروجی استاندارد AI

```jsonc
{
  "scores": {
    "pronunciation": 82, "fluency": 74, "vocabulary": 88,
    "grammar": 85, "content": 90
  },
  "overall_raw": 83,
  "feedback": {
    "summary_fa": "تلفظ کلی خوب است اما مکث‌های میان جمله زیاد است.",
    "strengths": ["پوشش کامل متن", "تکیه صحیح کلمات کلیدی"],
    "improvements": ["کاهش مکث بین عبارات", "تمرین واج /θ/"]
  },
  "problem_words": [
    { "word": "unprecedented", "issue": "stress", "hint": "un-PRE-ce-dent-ed" }
  ],
  "confidence": 0.87
}
```

اگر `confidence < 0.6` → پرچم برای بازبینی دستی مدرس و پیام محتاطانه‌تر به دانشجو.

---

## ۶. پایپ‌لاین Writing

```
متن دانشجو
   ├─ بررسی طول (کمتر از حداقل کلمات؟ → نمره صفر بدون فراخوانی AI)
   ├─ تشخیص زبان (اگر انگلیسی نیست → خطا)
   ├─ بررسی کپی (شباهت با پاسخ‌های قبلی همان سوال — cosine روی embedding)
   ├─ AI Scoring با rubric
   ├─ تصحیح درون‌متنی: لیست خطاها با موقعیت کاراکتر
   └─ رندر نسخه اصلاح‌شده با highlight
```

خروجی برای تلگرام: تصویر یا HTML رندرشده با خطاها رنگی + توضیح هر خطا.

---

## ۷. کنترل هزینه — مهم‌ترین بخش

### ۷.۱ اندازه‌گیری

```
ai_requests
  academy_id · student_id · task_key · provider · model
  prompt_tokens · completion_tokens · total_tokens
  cost_usd · cost_local · latency_ms · status
  cache_hit · fallback_used · request_id · created_at

INDEX (academy_id, created_at)
PARTITION BY RANGE (به ماه)
```

هر فراخوانی — حتی ناموفق — ثبت می‌شود.

### ۷.۲ سهمیه (Quota)

```
usage_counters
  academy_id · period (2026-07) · metric · value · limit
```

متریک‌ها: `ai_requests`, `ai_tokens`, `ai_cost_usd`, `asr_minutes`, `storage_mb`, `active_students`, `broadcasts`

رفتار در نزدیکی سقف:
```
۸۰٪  → ایمیل هشدار به Owner + بنر در پنل
۹۵٪  → هشدار روزانه
۱۰۰٪ → طبق تنظیم آموزشگاه:
        ● توقف نمره‌دهی AI (تمرین‌های الگوریتمی کار می‌کنند)
        ○ سقوط خودکار به مدل ارزان‌تر
        ○ خرید خودکار بسته اضافی (اگر فعال باشد)
```

**نکته:** هرگز سرویس را کامل قطع نکنید. تمرین‌های الگوریتمی (WFD، MCQ، RO، HIW) بدون AI کار می‌کنند و باید فعال بمانند. کاربری که با «سهمیه تمام شد» روبرو شود و هیچ کاری نتواند بکند، آموزشگاه را از شما دلسرد می‌کند.

### ۷.۳ تکنیک‌های کاهش هزینه

| تکنیک | صرفه‌جویی تقریبی |
|-------|------------------|
| مسیریابی هوشمند: تمرین → Flash، آزمون → Pro | ۶۰-۷۰٪ |
| نمره‌دهی الگوریتمی برای ۱۰ نوع از ۱۷ نوع | ۵۰٪ کل حجم |
| بررسی کیفیت صوت قبل از ASR | ۵-۱۰٪ |
| کش پاسخ برای متن یکسان (hash محتوا) | ۱۰-۱۵٪ |
| ارسال متریک عینی به‌جای انتظار حدس AI | امکان استفاده از مدل کوچک‌تر |
| Prompt Caching (بخش system ثابت) | ۲۰-۳۰٪ توکن ورودی |
| دسته‌ای کردن تصحیح غیرفوری | تعرفه Batch |
| محدودیت `max_output_tokens` | جلوگیری از خروجی پرگو |

### ۷.۴ داشبورد هزینه (Super Admin)

```
هزینه AI — تیر ۱۴۰۵

کل: $1,847            بودجه ماه: $2,500   [██████████░░░] 74%

بر اساس آموزشگاه            بر اساس مدل
────────────────────       ─────────────────────────
English First   $612       Gemini 2.5 Flash   $498
IELTS Academy   $423       Gemini 2.5 Pro     $701
Speak Up        $287       GPT-5              $512
سایر (۱۲ تا)     $525       Whisper            $136

⚠️ هشدار: IELTS Academy رشد ۳۴۰٪ نسبت به ماه قبل
    → پلن Professional · هزینه از درآمد ماهانه‌اش بیشتر است
```

این هشدار آخر حیاتی است: باید بدانید کدام مشتری برایتان زیان‌ده است، **قبل از** اینکه پایان ماه بفهمید.

---

## ۸. Fallback و پایداری

```php
$chain = ['gemini-2.5-pro', 'gemini-2.5-flash', 'gpt-5'];

foreach ($chain as $model) {
    try {
        return $this->call($model, $prompt, timeout: 30);
    } catch (RateLimitException|ProviderDownException $e) {
        $this->log($e, $model);
        continue;
    } catch (InvalidResponseException $e) {
        if ($retried) continue;
        $retried = true;                  // یک بار تلاش مجدد با همان مدل
    }
}

// همه شکست خوردند
$answer->markPendingManualReview();
$this->notifyStudent('نتیجه شما تا ساعاتی دیگر آماده می‌شود.');
$this->alertAcademy();
```

**Circuit Breaker:** اگر یک Provider در ۵ دقیقه اخیر بیش از ۳۰٪ خطا داد، برای ۵ دقیقه از زنجیره حذف می‌شود.

**هرگز به دانشجو خطای فنی نشان ندهید.** پیام همیشه: «نتیجه به‌زودی آماده می‌شود» + Job تلاش مجدد.

---

## ۹. کیفیت و انصاف نمره‌دهی

### پایداری (Consistency)
Job هفتگی: ۲۰ پاسخ تصادفی از هفته گذشته دوباره نمره‌گذاری می‌شوند. اگر انحراف میانگین > ۵ نمره باشد → هشدار به Super Admin (نشانه drift مدل یا تغییر Prompt).

### همبستگی با نمره انسانی
هر Override مدرس ثبت می‌شود. گزارش ماهانه: میانگین اختلاف AI با مدرس، به تفکیک نوع تمرین. اگر برای نوعی به‌طور سیستماتیک AI بالاتر یا پایین‌تر نمره می‌دهد، Prompt یا Rubric باید اصلاح شود.

### شفافیت با دانشجو
هر کارنامه AI باید برچسب داشته باشد: «این نمره توسط هوش مصنوعی تولید شده و ممکن است با نمره رسمی آزمون تفاوت داشته باشد.» — هم اخلاقی است و هم از ادعای حقوقی جلوگیری می‌کند.

### حریم خصوصی
- فایل صوتی خام پس از ۹۰ روز حذف می‌شود (قابل تنظیم توسط آموزشگاه)
- در Prompt هرگز نام واقعی، شماره تلفن یا ایمیل دانشجو فرستاده نمی‌شود — فقط `student_level`
- اگر Provider گزینه «عدم استفاده برای آموزش» دارد، به‌صورت پیش‌فرض فعال است
- سیاست نگهداری داده هر Provider در پنل به آموزشگاه نمایش داده می‌شود
