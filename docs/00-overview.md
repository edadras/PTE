# 00 — معماری کلی

## ۱. تعریف محصول

یک پلتفرم **SaaS چندمستاجری White-Label** برای آموزشگاه‌های زبان.
هر آموزشگاه (Tenant) یک فضای کاملاً مستقل دارد شامل: برند، ربات تلگرام اختصاصی، کاربران، بانک سوال، آزمون‌ها، تنظیمات AI، گزارش‌ها و اشتراک.

سه گروه مصرف‌کننده:

| مخاطب | کانال | چه چیزی می‌بیند |
|-------|-------|------------------|
| Super Admin (شرکت شما) | پنل مرکزی | همه آموزشگاه‌ها، پلن‌ها، هزینه AI، سلامت سیستم |
| کارکنان آموزشگاه | پنل آموزشگاه (زیردامنه/دامنه اختصاصی) | فقط داده‌های آموزشگاه خودشان |
| زبان‌آموز | ربات تلگرام → بعداً وب‌اپ و موبایل | فقط تمرین، آزمون، نتیجه، خرید |

---

## ۲. لایه‌بندی سیستم

```
┌─────────────────────────────────────────────────────────────────────┐
│  CLIENTS                                                            │
│  Telegram Bot (فاز ۱)  │  Admin Panel (Filament)  │  Web PWA (فاز ۴)│
│                        │                          │  Mobile (فاز ۵) │
└───────────┬────────────┴─────────────┬────────────┴────────┬────────┘
            │                          │                     │
┌───────────▼──────────────────────────▼─────────────────────▼────────┐
│  EDGE / DELIVERY                                                    │
│  Nginx · TLS · Custom Domain Router · Rate Limit · WAF              │
└───────────┬─────────────────────────────────────────────────────────┘
            │
┌───────────▼─────────────────────────────────────────────────────────┐
│  TENANT RESOLUTION MIDDLEWARE                                       │
│  webhook secret → academy | host → academy | api key → academy      │
│  ↳ TenantContext::set(academy)  → Global Scope روی همه مدل‌ها       │
└───────────┬─────────────────────────────────────────────────────────┘
            │
┌───────────▼─────────────────────────────────────────────────────────┐
│  APPLICATION LAYER (Laravel 12)                                     │
│                                                                     │
│  ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌──────────────────┐  │
│  │ Telegram   │ │  Learning  │ │  Assessment│ │  Tenancy &       │  │
│  │ Domain     │ │  Domain    │ │  Domain    │ │  Billing Domain  │  │
│  │ ---------- │ │ ---------- │ │ ---------- │ │ ---------------- │  │
│  │ BotManager │ │ Course     │ │ Exam       │ │ Academy          │  │
│  │ MenuEngine │ │ Lesson     │ │ Practice   │ │ Plan / Sub       │  │
│  │ FlowEngine │ │ QuestionBk │ │ Attempt    │ │ Quota / Usage    │  │
│  │ Dispatcher │ │ Module     │ │ Scoring    │ │ Payment          │  │
│  └────────────┘ └────────────┘ └────────────┘ └──────────────────┘  │
│                                                                     │
│  ┌───────────────────────── AI GATEWAY ──────────────────────────┐  │
│  │ ProviderRegistry · PromptRenderer · RubricEngine · CostMeter  │  │
│  │ Gemini │ OpenAI │ Anthropic │ Whisper │ Google STT           │  │
│  └───────────────────────────────────────────────────────────────┘  │
└───────────┬─────────────────────────────────────────────────────────┘
            │
┌───────────▼─────────────────────────────────────────────────────────┐
│  ASYNC LAYER — Redis Queues + Horizon                               │
│  telegram-in │ telegram-out │ ai-scoring │ media │ reports │ mail   │
└───────────┬─────────────────────────────────────────────────────────┘
            │
┌───────────▼─────────────────────────────────────────────────────────┐
│  DATA LAYER                                                         │
│  MySQL 8 (academy_id در همه جداول مستاجری)                          │
│  Redis (session/state/cache — با prefix مستاجر)                     │
│  S3 / R2 (فایل‌ها — با prefix مستاجر)                               │
└─────────────────────────────────────────────────────────────────────┘
```

---

## ۳. Bounded Contextها

پروژه به ۶ حوزه (Domain Module) تقسیم می‌شود. هر حوزه پوشه مستقل زیر `app/Domain/` دارد و فقط از طریق سرویس‌های عمومی با بقیه حرف می‌زند.

| حوزه | مسئولیت | نمونه کلاس‌ها |
|------|---------|----------------|
| **Tenancy** | آموزشگاه، برند، تنظیمات، Resolution | `Academy`, `AcademyBrand`, `TenantContext`, `ResolveTenant` |
| **Identity** | کاربر، نقش، Permission، لینک تلگرام | `User`, `Role`, `AcademyUserRole`, `TelegramIdentity` |
| **Telegram** | ربات، Webhook، منو، Flow، ارسال پیام | `TelegramBot`, `UpdateRouter`, `MenuEngine`, `FlowEngine`, `Sender` |
| **Learning** | دوره، درس، ماژول، بانک سوال | `Course`, `Lesson`, `Module`, `QuestionBank`, `Question` |
| **Assessment** | تمرین، آزمون، پاسخ، نمره | `PracticeSession`, `ExamSession`, `Answer`, `Score`, `Scorer` |
| **Commerce** | پلن، اشتراک، پرداخت، سهمیه | `Plan`, `Subscription`, `Payment`, `QuotaGuard` |

حوزه عرضی (Cross-cutting): **AI Gateway**, **Reporting**, **Audit**, **Notification**.

---

## ۴. جریان اصلی — از پیام تلگرام تا نمره

```
1. Telegram → POST /webhook/{bot_public_id}
                 header: X-Telegram-Bot-Api-Secret-Token
2. ResolveTenantFromWebhook → Academy پیدا شد → TenantContext::set()
3. اعتبارسنجی secret → اگر نامعتبر: 401 و لاگ امنیتی
4. Update خام در جدول telegram_updates ذخیره (idempotency با update_id)
5. return 200 OK  ← زیر ۲۰۰ms  (هیچ کار سنگینی اینجا انجام نمی‌شود)
6. ProcessTelegramUpdate job → صف telegram-in
     ├── شناسایی/ساخت Student از telegram_user_id
     ├── خواندن state از Redis: tenant:{id}:tg:{chat_id}
     ├── FlowEngine: کدام node؟ کدام action؟
     └── تولید پاسخ → صف telegram-out (با Rate Limiter اختصاصی هر بات)
7. اگر پاسخ کاربر «تمرین Speaking» بود:
     ├── دانلود voice → S3: academies/{id}/answers/{uuid}.ogg
     ├── ProcessAudio job (FFmpeg → wav 16kHz mono)
     ├── Transcribe job (Whisper / Google STT)
     └── ScoreAnswer job → AI Gateway
             ├── انتخاب Provider از academy_ai_settings (task=speaking)
             ├── رندر Prompt از ai_prompts (نسخه فعال آموزشگاه)
             ├── اعمال Rubric (وزن‌های آموزشگاه)
             ├── ثبت هزینه در ai_requests
             └── ذخیره Score + Feedback
8. NotifyStudent job → صف telegram-out → ارسال کارنامه
```

**نکته کلیدی:** مرحله ۵ قبل از هر پردازشی است. تلگرام اگر تا ۶۰ ثانیه پاسخ نگیرد Update را دوباره می‌فرستد؛ بدون این جداسازی، در ترافیک بالا سیستم دچار طوفان Retry می‌شود.

---

## ۵. اصول طراحی (Design Principles)

### ۵.۱ Tenant-Safe by Default
هیچ توسعه‌دهنده‌ای نباید «یادش بماند» `where academy_id = ?` بنویسد. مکانیزم:
- `BelongsToAcademy` trait + Global Scope خودکار
- `academy_id` در `creating` event خودکار پر می‌شود
- تست معماری (Architecture Test) که fail می‌شود اگر مدلی زیر `app/Domain/` بدون این trait ساخته شود

### ۵.۲ API-First
هر قابلیتی که ربات ارائه می‌دهد، ابتدا به‌صورت **Service/Action Class** نوشته می‌شود، سپس هم Controller ربات و هم Controller API از آن استفاده می‌کنند. این تنها راهی است که فاز ۴ (وب) و فاز ۵ (اپ) بدون بازنویسی ممکن شود.

```php
// درست
class SubmitAnswer { public function handle(SubmitAnswerData $data): Answer {} }
TelegramController → SubmitAnswer
Api\AnswerController → SubmitAnswer

// غلط: منطق داخل TelegramController
```

### ۵.۳ Configuration over Code
هر چیزی که ممکن است بین آموزشگاه‌ها فرق کند باید **داده** باشد نه کد:
منو، Flow، Prompt، وزن‌های نمره‌دهی، متن پیام‌ها، ماژول‌های فعال، رنگ‌ها.

### ۵.۴ Async by Default
همه چیزی که > ۳۰۰ms طول می‌کشد → Queue. صف‌ها با اولویت جدا:
`telegram-out` (حیاتی) > `ai-scoring` (سنگین) > `reports` (کم‌اولویت)

### ۵.۵ Everything Metered
هر فراخوانی AI، هر مگابایت Storage، هر دانشجوی فعال → در `usage_counters` ثبت می‌شود. بدون این، قیمت‌گذاری SaaS غیرممکن است.

### ۵.۶ Provider Agnostic
`AiProviderInterface` تنها نقطه تماس با Gemini/GPT/Claude. اضافه کردن Provider جدید = یک کلاس + یک ردیف در `ai_models`.

---

## ۶. تصمیمات کلیدی در یک نگاه

| موضوع | تصمیم | چرا |
|-------|-------|-----|
| مدل Multi-Tenancy | Shared DB + `academy_id` (با مسیر مهاجرت به DB-per-tenant) | سادگی عملیات تا ~۵۰۰ آموزشگاه؛ Enterprise بعداً جدا می‌شود |
| ذخیره Bot Token | رمزنگاری‌شده (`encrypted` cast) + هرگز در لاگ | نشت توکن = تصاحب کامل ربات آموزشگاه |
| مسیر Webhook | `/webhook/{bot_public_id}` + Secret Token هدر | جلوگیری از جعل Update |
| State مکالمه | Redis با TTL، نه DB | سرعت + عدم تورم جدول |
| Flow Builder | گراف JSON نسخه‌دار (draft/published) | ویرایش بدون قطعی سرویس + امکان Rollback |
| نمره‌دهی | Deterministic Scorer برای سوالات بسته + AI برای باز | هزینه AI فقط جایی که واقعاً لازم است |
| Frontend پنل | FilamentPHP | سرعت ساخت CRUD؛ زمان تیم صرف موتور ربات و AI شود |
| اپ موبایل | فاز ۵، Flutter روی همان API | جلوگیری از دوباره‌کاری، بعد از تثبیت API |

---

## ۷. آنچه در فاز ۱ ساخته **نمی‌شود** (Out of Scope عمدی)

برای اینکه محصول به مرداب تبدیل نشود، این‌ها آگاهانه به تعویق می‌افتند:

- ویدیو کنفرانس / کلاس آنلاین زنده
- شبکه اجتماعی و چت دانشجو-دانشجو
- Marketplace محتوا بین آموزشگاه‌ها
- اپلیکیشن موبایل Native
- گزارش‌ساز دلخواه (Custom Report Builder)
- چند‌ارزی و مالیات بین‌المللی

هر کدام در نقشه راه ([سند ۱۱](11-roadmap.md)) جای مشخص خودشان را دارند.
