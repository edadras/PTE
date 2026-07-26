# 05 — ماژول‌ها، آزمون و تمرین

---

## ۱. سیستم ماژول (Module System)

هر ماژول یک بسته قابلیت است که آموزشگاه می‌تواند فعال یا غیرفعال کند. این همان چیزی است که اجازه می‌دهد یک پلتفرم هم‌زمان به آموزشگاه PTE و آموزشگاه IELTS خدمت دهد.

```
modules                (رجیستری پلتفرم — غیرمستاجری)
  key · name · version · description · icon
  requires_plan · question_types json · config_schema json

academy_modules        (فعال‌سازی به‌ازای مستاجر)
  academy_id · module_key · is_enabled · settings json · enabled_at
```

### ماژول‌های فاز ۱ و بعد

| ماژول | فاز | محتوا |
|-------|-----|-------|
| `pte_speaking` | ۱ | RA, RS, DI, RL, ASQ |
| `pte_listening` | ۱ | SST, WFD, MCQ, HIW, FIB, SMW |
| `pte_reading` | ۲ | MCQ, RO, FIB-R, FIB-RW |
| `pte_writing` | ۲ | SWT, Essay |
| `vocabulary` | ۲ | فلش‌کارت، تکرار فاصله‌دار (SRS) |
| `grammar` | ۳ | تمرین گرامر + تصحیح AI |
| `ielts` | ۳ | Speaking/Writing/Reading/Listening IELTS |
| `toefl` | ۴ | ماژول TOEFL |
| `general_english` | ۴ | مکالمه عمومی، سطح‌بندی CEFR |
| `mock_exam` | ۲ | آزمون کامل شبیه‌سازی‌شده |
| `placement_test` | ۲ | تعیین سطح |

### فعال‌سازی در پنل

```
ماژول‌های فعال
──────────────────────────────────────────────────────
🎙  Speaking          [ ● فعال ]   ۵ نوع تمرین · ۴۲۰ سوال
🎧  Listening         [ ● فعال ]   ۶ نوع تمرین · ۵۱۰ سوال
📖  Reading           [ ○ غیرفعال] ۴ نوع تمرین
✍   Writing          [ ○ غیرفعال] ۲ نوع تمرین
📚  Vocabulary        [ ● فعال ]   ۱٬۲۰۰ کلمه
📝  Mock Exam         [ ● فعال ]   ۳ آزمون کامل
🎯  Placement Test    [ 🔒 Pro ]   ارتقای پلن لازم است
```

اثر فعال/غیرفعال کردن:
- آیتم‌های منوی ربات مرتبط، خودکار مخفی می‌شوند
- سوالات آن ماژول از تمرین تصادفی حذف می‌شوند
- بخش‌های مربوطه در Exam Builder پنهان می‌شوند
- گزارش‌های آن ماژول از داشبورد حذف می‌شوند
- **داده حذف نمی‌شود** — با فعال‌سازی مجدد همه چیز برمی‌گردد

---

## ۲. انواع تمرین (Practice Types)

### PTE Speaking

| کد | نام | ورودی | نمره‌دهی |
|----|-----|-------|----------|
| `RA` | Read Aloud | صوت | ASR + AI (تلفظ، روانی، محتوا) |
| `RS` | Repeat Sentence | صوت | ASR + تطبیق رشته + AI |
| `DI` | Describe Image | صوت | ASR + AI (محتوا، سازماندهی) |
| `RL` | Re-tell Lecture | صوت | ASR + AI |
| `ASQ` | Answer Short Question | صوت | ASR + تطبیق دقیق کلیدواژه |

### PTE Listening

| کد | نام | ورودی | نمره‌دهی |
|----|-----|-------|----------|
| `SST` | Summarize Spoken Text | متن | AI (محتوا، فرم، گرامر، واژگان) |
| `WFD` | Write From Dictation | متن | الگوریتمی — تطبیق کلمه‌به‌کلمه |
| `MCQ-L` | Multiple Choice | انتخاب | الگوریتمی |
| `HIW` | Highlight Incorrect Words | انتخاب چندتایی | الگوریتمی |
| `FIB-L` | Fill in the Blanks | متن | الگوریتمی |
| `SMW` | Select Missing Word | انتخاب | الگوریتمی |

### PTE Reading

| کد | نام | ورودی | نمره‌دهی |
|----|-----|-------|----------|
| `RO` | Re-order Paragraphs | ترتیب‌دهی | الگوریتمی (جفت‌های مجاور) |
| `FIB-R` | Fill in Blanks (Reading) | Dropdown | الگوریتمی |
| `FIB-RW` | Fill in Blanks (R&W) | Drag & Drop | الگوریتمی |
| `MCQ-R` | Multiple Choice | انتخاب | الگوریتمی |

### PTE Writing

| کد | نام | ورودی | نمره‌دهی |
|----|-----|-------|----------|
| `SWT` | Summarize Written Text | متن | AI |
| `ESSAY` | Write Essay | متن | AI |

**تفکیک کلیدی هزینه:** از ۱۷ نوع تمرین، فقط ۷ نوع نیاز به AI دارند. بقیه با الگوریتم قطعی نمره می‌گیرند — سریع‌تر، رایگان، و قابل اتکاتر. این تفکیک تفاوت بین محصول سودده و زیان‌ده است.

---

## ۳. بانک سوال

هر آموزشگاه بانک کاملاً مستقل خودش را دارد.

```
question_banks   (academy_id, name, module_key, description, is_default)
questions        (academy_id, bank_id, module_key, type, difficulty,
                  title, content json, correct_answer json,
                  metadata json, status, created_by, approved_by, tags json)
question_options (question_id, key, text, is_correct, sort_order)
question_media   (question_id, kind, s3_path, duration_ms, transcript,
                  telegram_file_id, bot_id)
```

### ساختار `content` بر اساس نوع

```jsonc
// Read Aloud
{ "text": "The rapid growth of urban populations...",
  "prep_seconds": 40, "record_seconds": 40, "word_count": 58 }

// Write From Dictation
{ "audio_key": "q/331/audio.mp3",
  "transcript": "The university library will be closed on Monday.",
  "play_count": 1 }

// Re-order Paragraphs
{ "paragraphs": [
    {"key":"A","text":"..."}, {"key":"B","text":"..."} ],
  "correct_order": ["C","A","D","B"] }

// Essay
{ "prompt": "Some people believe that...",
  "min_words": 200, "max_words": 300, "duration_minutes": 20 }
```

### سطح دشواری
`easy` | `medium` | `hard` — و علاوه بر آن یک `difficulty_index` عددی (۰ تا ۱) که **خودکار از داده واقعی** محاسبه می‌شود:

```
difficulty_index = 1 − (میانگین نمره ۵۰ پاسخ اخیر / نمره کامل)
```

این عدد به موتور تمرین اجازه می‌دهد سوالات متناسب با سطح دانشجو انتخاب کند و ارزش آموزشی محصول را جدی‌تر می‌کند.

### گردش کار محتوا
```
draft → pending_review → approved → published
                      ↘ rejected (با یادداشت)
```
فقط سوالات `published` به دانشجو نمایش داده می‌شوند.

### ورود گروهی (Import)
- Excel/CSV با قالب استاندارد قابل دانلود از پنل
- فایل‌های صوتی در ZIP، تطبیق با نام فایل
- اعتبارسنجی سطر‌به‌سطر با گزارش خطای دقیق
- پردازش در صف با نوار پیشرفت
- امکان Rollback کل Import

### بانک نمونه (Starter Pack)
آموزشگاه جدید می‌تواند بانک نمونه ۵۰ سوالی رایگان را کپی کند (کپی واقعی، نه اشتراک) — برای اینکه از دقیقه اول ربات چیزی برای نشان دادن داشته باشد.

---

## ۴. تمرین (Practice)

### پیکربندی توسط آموزشگاه

```
تنظیمات تمرین — Speaking
────────────────────────────────────────
انواع فعال:      ☑ RA  ☑ RS  ☑ DI  ☑ RL  ☐ ASQ
تعداد در جلسه:   [ 5 ]
انتخاب سوال:     ● تصادفی  ○ ترتیبی  ○ تطبیقی (بر اساس سطح)
تکرار سوال:      ○ مجاز    ● فقط پس از ۳۰ روز
نمایش پاسخ صحیح: ● بله     ○ خیر
بازخورد AI:      ● کامل    ○ فقط نمره
سقف روزانه:      [ 20 ] تمرین  (۰ = نامحدود)
دسترسی:          ○ همه  ● فقط اشتراک فعال  ○ ۳ تمرین رایگان سپس اشتراک
```

### جریان در ربات

```
📚 تمرین → 🎙 Speaking → Read Aloud

┌──────────────────────────────────────────┐
│ 📖 Read Aloud — سوال ۱ از ۵              │
│                                          │
│ متن زیر را با صدای بلند بخوانید:          │
│                                          │
│ "The rapid growth of urban populations   │
│  has placed unprecedented pressure on    │
│  city infrastructure worldwide."         │
│                                          │
│ ⏱ آماده‌سازی: ۴۰ ثانیه                    │
│ 🎙 ضبط: ۴۰ ثانیه                          │
│                                          │
│ [ 🎙 شروع ضبط ]  [ ⏭ رد کردن ]           │
└──────────────────────────────────────────┘
        ↓ کاربر voice می‌فرستد
┌──────────────────────────────────────────┐
│ ✅ دریافت شد. در حال بررسی... ⏳          │
└──────────────────────────────────────────┘
        ↓ ۱۵-۳۰ ثانیه بعد
┌──────────────────────────────────────────┐
│ 📊 نتیجه Read Aloud                       │
│                                          │
│ نمره کل: 78/90  ⭐⭐⭐⭐                    │
│                                          │
│ تلفظ        ████████░░  82                │
│ روانی       ███████░░░  74                │
│ محتوا       █████████░  88                │
│                                          │
│ 💡 بازخورد:                               │
│ تلفظ کلمه "unprecedented" نیاز به تمرین   │
│ دارد. سعی کنید مکث‌های میان جملات را       │
│ کوتاه‌تر کنید.                             │
│                                          │
│ 🔴 کلمات مشکل‌دار: unprecedented,          │
│    infrastructure                        │
│                                          │
│ [ 🔁 تلاش دوباره ]  [ ➡️ سوال بعدی ]      │
└──────────────────────────────────────────┘
```

**نکته UX حیاتی:** پیام «در حال بررسی» ضروری است. ۱۵ تا ۳۰ ثانیه سکوت در تلگرام برای کاربر یعنی «خراب شد».

### انتخاب تطبیقی سوال (Adaptive)
```
سطح فعلی دانشجو = میانگین وزنی ۱۰ نمره اخیر همان نوع
هدف: انتخاب سوالی با difficulty_index در بازه [سطح − 0.1 , سطح + 0.15]
با ۲۰٪ احتمال، یک سوال سخت‌تر برای چالش
```

---

## ۵. Exam Builder

### ساخت آزمون

```
ساخت آزمون جدید
────────────────────────────────────────────
عنوان:       PTE Mock Exam — July 2026
توضیح:       آزمون کامل شبیه‌سازی‌شده
مدت کل:      [ 120 ] دقیقه
نمره کل:     [ 90 ]
نمره قبولی:  [ 65 ]

بخش‌ها (Drag & Drop برای ترتیب)
┌────────────────────────────────────────────┐
│ ⠿ 1. Speaking      ۳۵ دقیقه   ۳۰ نمره      │
│      RA ×5  ·  RS ×10  ·  DI ×5  ·  RL ×3  │
│                                            │
│ ⠿ 2. Writing       ۲۵ دقیقه   ۲۰ نمره      │
│      SWT ×2  ·  Essay ×1                   │
│                                            │
│ ⠿ 3. Reading       ۳۰ دقیقه   ۲۰ نمره      │
│      FIB-RW ×5  ·  MCQ ×3  ·  RO ×3        │
│                                            │
│ ⠿ 4. Listening     ۳۰ دقیقه   ۲۰ نمره      │
│      SST ×2  ·  WFD ×3  ·  MCQ ×4          │
└────────────────────────────────────────────┘
                                [ + بخش جدید ]

قوانین
  ☑ ترتیب بخش‌ها اجباری
  ☐ امکان بازگشت به سوال قبلی
  ☑ ترتیب تصادفی سوالات
  ☑ نمایش تایمر
  ☑ ذخیره خودکار (بازیابی پس از قطعی اینترنت)
  ☐ نمایش نتیجه بلافاصله
  ☑ نیاز به تایید مدرس قبل از انتشار نمره

دسترسی
  ● همه دانشجویان   ○ کلاس مشخص   ○ لیست دستی
  زمان‌بندی: از [1405/05/10 09:00] تا [1405/05/15 23:59]
  تعداد تلاش مجاز: [ 1 ]

           [ ذخیره پیش‌نویس ]   [ پیش‌نمایش ]   [ انتشار ]
```

### انتخاب سوال در هر بخش
سه حالت:
1. **دستی** — انتخاب تک‌تک از بانک
2. **تصادفی با فیلتر** — «۵ سوال RA با دشواری medium از بانک اصلی»
3. **مخزن (Pool)** — ۵۰ سوال کاندید، سیستم برای هر دانشجو ۱۰ تای تصادفی انتخاب می‌کند (ضد تقلب)

### ساختار داده

```
exams          (academy_id, title, duration_minutes, total_score, passing_score,
                rules json, availability json, status, published_at, created_by)
exam_sections  (exam_id, title, module_key, duration_minutes, score,
                sort_order, selection_mode, selection_config json)
exam_questions (exam_section_id, question_id, sort_order, score)
exam_sessions  (academy_id, exam_id, student_id, status, started_at,
                submitted_at, expires_at, total_score, section_scores json,
                snapshot json)
answers        (academy_id, session_type, session_id, question_id,
                answer_data json, media_path, transcript,
                score, max_score, breakdown json, feedback,
                scored_by, scored_at, graded_manually, override_reason)
```

فیلد `snapshot` نسخه‌ای از ساختار آزمون در لحظه شروع را نگه می‌دارد — اگر آموزشگاه بعداً آزمون را ویرایش کند، نتیجه دانشجو دست‌نخورده و قابل دفاع باقی می‌ماند.

### اجرای آزمون در تلگرام
- شروع → ساخت `exam_session` با `expires_at`
- هر بخش تایمر جدا؛ Job زمان‌بندی‌شده در پایان زمان، بخش را می‌بندد
- **بازیابی از قطعی:** با هر پاسخ، وضعیت ذخیره می‌شود. بازگشت کاربر → «آزمون شما در سوال ۷ متوقف شده، ادامه می‌دهید؟»
- پایان → همه پاسخ‌ها به صف `ai-scoring` → کارنامه

### کارنامه

```
📊 کارنامه — PTE Mock Exam July 2026

نمره کل: 71/90  ✅ قبول

Speaking   ███████░░░  73/90
Writing    ██████░░░░  68/90
Reading    ████████░░  76/90
Listening  ███████░░░  70/90

💪 نقاط قوت: Reading — Re-order Paragraphs
📉 نیاز به تمرین: Writing — Essay (سازماندهی)

[ 📄 دانلود PDF ]  [ 📈 مقایسه با آزمون قبل ]
```

---

## ۶. زمان‌بندی انتشار محتوا

```
scheduled_contents
  academy_id · type · target_id · audience json
  scheduled_at · repeat_rule · status · executed_at
```

موارد قابل زمان‌بندی:
- انتشار آزمون در تاریخ مشخص
- ارسال «تمرین روز» هر روز ساعت ۹ صبح
- یادآوری آزمون ۲۴ ساعت و ۱ ساعت قبل
- گزارش هفتگی پیشرفت هر جمعه
- کمپین بازگرداندن کاربران غیرفعال (۷ روز بدون تمرین)

پیاده‌سازی با Laravel Scheduler + جدول بالا؛ هر دقیقه یک Job سبک ردیف‌های سررسیدشده را برمی‌دارد و به صف مناسب می‌فرستد. **زمان‌بندی همیشه در timezone آموزشگاه محاسبه می‌شود**، نه UTC سرور.
