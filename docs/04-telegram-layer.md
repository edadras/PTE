# 04 — لایه تلگرام

هسته فنی محصول در فاز ۱. یک کد، صدها ربات، هرکدام متعلق به یک آموزشگاه.

---

## ۱. اتصال ربات — تجربه آموزشگاه

آموزشگاه فقط **یک کار** انجام می‌دهد: چسباندن Token.

```
┌────────────────────────────────────────────────────────┐
│  اتصال ربات تلگرام                                      │
│                                                        │
│  ۱. در تلگرام به @BotFather بروید                       │
│  ۲. دستور /newbot را بفرستید                            │
│  ۳. نام و یوزرنیم ربات را انتخاب کنید                   │
│  ۴. توکنی که می‌دهد را اینجا بچسبانید:                   │
│                                                        │
│  ┌──────────────────────────────────────────────────┐  │
│  │ 8123456789:AAG_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx  │  │
│  └──────────────────────────────────────────────────┘  │
│                                                        │
│              [ اتصال و راه‌اندازی خودکار ]              │
└────────────────────────────────────────────────────────┘
```

### پشت صحنه (Job زنجیره‌ای `ConnectTelegramBot`)

```php
Bus::chain([
    new ValidateToken($bot),        // getMe → username, id, first_name
    new EnsureTokenNotUsedElsewhere($bot),
    new GenerateWebhookSecret($bot),
    new RegisterWebhook($bot),      // setWebhook
    new SyncBotIdentity($bot),      // setMyName / setMyDescription / setMyCommands
    new SendTestMessage($bot),      // به خودِ Owner
    new MarkBotActive($bot),
])->onQueue('telegram-setup')->dispatch();
```

```php
// setWebhook
$telegram->setWebhook([
    'url'                  => "https://api.pte-platform.com/webhook/{$bot->public_id}",
    'secret_token'         => $bot->webhook_secret,     // ۳۲ کاراکتر تصادفی
    'max_connections'      => 40,
    'allowed_updates'      => ['message','callback_query','pre_checkout_query',
                               'successful_payment','my_chat_member'],
    'drop_pending_updates' => true,
]);
```

### خطاهای رایج و پیام کاربرپسند

| خطای تلگرام | پیام به آموزشگاه |
|-------------|------------------|
| `401 Unauthorized` | توکن نامعتبر است. مطمئن شوید کل رشته را کپی کرده‌اید. |
| Token تکراری در پلتفرم | این ربات قبلاً به آموزشگاه دیگری متصل است. |
| `SSL error` | مشکل موقت سرور — به‌صورت خودکار تا ۵ بار تلاش مجدد می‌شود. |
| Webhook قبلی روی دامنه دیگر | هشدار: این ربات قبلاً به سرویس دیگری وصل بوده؛ ادامه می‌دهید؟ |

### چرخش توکن (Token Rotation)
اگر توکن لو رفت، Owner در پنل «بازتولید» می‌زند → راهنمای `/revoke` در BotFather → توکن جدید → همان فرایند بالا با حفظ کامل داده و تاریخچه.

---

## ۲. جدول `telegram_bots`

| ستون | نوع | توضیح |
|------|-----|-------|
| `academy_id` | FK | |
| `public_id` | ULID یکتا | در URL وبهوک — تصادفی و غیرقابل شمارش |
| `token` | text **encrypted** | هرگز در لاگ، خروجی API یا خطا ظاهر نشود |
| `token_last4` | char(4) | برای نمایش در UI: `••••••wEN` |
| `bot_user_id` | bigint | از `getMe` |
| `username` | string | `englishfirst_bot` |
| `webhook_secret` | string(64) encrypted | هدر `X-Telegram-Bot-Api-Secret-Token` |
| `webhook_registered_at` | timestamp | |
| `is_active` | boolean | |
| `health_status` | enum | `ok` \| `degraded` \| `failing` |
| `last_error` / `last_error_at` | text/ts | آخرین خطای getWebhookInfo |
| `payments_provider_token` | text encrypted | برای Telegram Payments (اختیاری) |

**قاعده امنیتی:** یک Middleware سراسری در Logger، هر رشته‌ای با الگوی `\d{8,10}:[A-Za-z0-9_-]{35}` را با `[REDACTED_BOT_TOKEN]` جایگزین می‌کند. این تور ایمنی نهایی است.

---

## ۳. پردازش Webhook

```php
Route::post('/webhook/{botPublicId}', function (Request $r, TelegramBot $bot) {
    $updateId = data_get($r->all(), 'update_id');

    // Idempotency — تلگرام در صورت timeout دوباره می‌فرستد
    $key = "ac{$bot->academy_id}:tg:upd:{$updateId}";
    if (! Redis::set($key, 1, 'EX', 3600, 'NX')) {
        return response()->noContent();          // تکراری
    }

    TelegramUpdate::create([
        'academy_id'       => $bot->academy_id,
        'telegram_bot_id'  => $bot->id,
        'update_id'        => $updateId,
        'type'             => UpdateType::detect($r->all()),
        'payload'          => $r->all(),
    ]);

    ProcessTelegramUpdate::dispatch($bot->id, $updateId)->onQueue('telegram-in');

    return response()->noContent();              // 204، زیر ۲۰۰ms
})->middleware(['resolve-tenant-bot']);
```

**چرا این ترتیب مهم است:** تلگرام تا ۶۰ ثانیه منتظر پاسخ می‌ماند و در صورت عدم پاسخ Update را دوباره می‌فرستد. هر پردازش سنگین داخل Controller = طوفان Retry و پیام تکراری برای کاربر.

---

## ۴. موتور مکالمه (Conversation Engine)

### وضعیت (State) در Redis

```json
// کلید: ac12:tg:state:987654321  — TTL 24h
{
  "flow_id": 7,
  "node_id": "speaking_read_aloud_record",
  "step": 3,
  "context": {
    "practice_session_id": 8842,
    "question_id": 331,
    "started_at": "2026-07-26T09:12:00Z",
    "attempts": 1
  },
  "history": ["main_menu", "practice_menu", "speaking_menu"],
  "updated_at": "2026-07-26T09:14:11Z"
}
```

### مسیر تصمیم

```
Update دریافت شد
    │
    ├─ /start [payload]?        → DeepLinkHandler → ثبت‌نام/ارجاع → منوی اصلی
    ├─ دستور سراسری (/help, /menu, /cancel, /support)?  → همیشه اولویت دارد
    ├─ CallbackQuery?           → data را parse کن → Action اجرا کن
    ├─ State فعال دارد؟         → FlowEngine::resume(state, update)
    └─ در غیر این صورت          → تطبیق متن با منوی فعال → یا fallback
```

**نکته UX مهم:** `/cancel` و دکمه «🏠 بازگشت به منو» باید در **هر** وضعیتی کار کنند. کاربری که در مکالمه گیر کند، ربات را رها می‌کند.

### قالب `callback_data`

تلگرام حداکثر ۶۴ بایت می‌دهد. قالب فشرده:
```
a:{action}|t:{target}|p:{param}
مثال:  a:prc|t:sp|p:331
```
اگر داده بیشتر لازم بود، در Redis ذخیره و فقط یک token کوتاه در callback_data قرار می‌گیرد:
```
cb:{token}   →  ac12:tg:cb:{token} = {...json...}   TTL 1h
```

---

## ۵. منوساز (Menu Builder)

### ساختار داده

```
telegram_menus       (academy_id, name, type, is_active, version)
telegram_menu_items  (menu_id, parent_id, label, icon, action_type,
                      action_payload json, sort_order, is_enabled,
                      visibility_rule json, row, column)
```

`type`: `main` | `inline` | `command` | `persistent`

### پنل — Drag & Drop

```
منوی اصلی ربات                                    [ پیش‌نمایش زنده ]
────────────────────────────────────────         ┌──────────────────┐
 ⠿ ☑ 🏠  خانه            → منوی اصلی             │ 🏠 خانه          │
 ⠿ ☑ 📚  تمرین           → باز کردن ماژول         │ 📚 تمرین         │
 ⠿ ☑ 📝  آزمون آزمایشی    → شروع آزمون           ├──────────────────┤
 ⠿ ☑ 🎙  اسپیکینگ         → شروع تمرین           │ 📝 آزمون آزمایشی │
 ⠿ ☐ 🎧  لیسنینگ          → شروع تمرین           │ 🎙 اسپیکینگ      │
 ⠿ ☐ 📖  ریدینگ           → شروع تمرین           ├──────────────────┤
 ⠿ ☐ ✍  رایتینگ          → شروع تمرین           │ 👤 پروفایل       │
 ⠿ ☑ 👤  پروفایل          → باز کردن ماژول        │ 💳 خرید دوره     │
 ⠿ ☑ 💳  خرید دوره        → باز کردن دوره         │ 📞 پشتیبانی      │
 ⠿ ☑ 📞  پشتیبانی         → تماس با پشتیبانی      └──────────────────┘
 ⠿ ☑ 🎁  کلاس رایگان      → باز کردن لینک
 ⠿ ☑ 📺  یوتیوب           → باز کردن لینک

              [ + آیتم جدید ]   [ ذخیره پیش‌نویس ]   [ انتشار ]
```

- تیک زدن = فعال/غیرفعال بدون حذف
- کشیدن = تغییر ترتیب و چیدمان سطر/ستون
- غیرفعال کردن ماژول در تنظیمات، آیتم مرتبط را خودکار خاکستری می‌کند

### انواع Action

| Action | Payload | رفتار |
|--------|---------|-------|
| `open_url` | `{url}` | دکمه لینک (inline) |
| `send_message` | `{template_key \| text, parse_mode}` | ارسال متن دلخواه |
| `open_module` | `{module: "speaking"}` | ورود به منوی ماژول |
| `start_practice` | `{type: "RA", count: 5, difficulty}` | شروع تمرین |
| `start_exam` | `{exam_id}` | شروع آزمون |
| `open_course` | `{course_id}` | صفحه دوره |
| `buy_plan` | `{plan_id}` | جریان پرداخت |
| `contact_support` | `{}` | ساخت تیکت |
| `run_flow` | `{flow_id, node_id}` | ورود به Flow سفارشی |
| `open_webapp` | `{path}` | Telegram Mini App (فاز ۴) |
| `run_command` | `{command}` | دستورات داخلی: `my_scores`, `my_progress`, `leaderboard` |

### قواعد نمایش شرطی (`visibility_rule`)

```json
{
  "all": [
    { "subscription": "active" },
    { "module_enabled": "speaking" },
    { "student_level_in": ["intermediate", "advanced"] },
    { "not": { "trial_expired": true } }
  ]
}
```
مثلاً «💳 خرید دوره» فقط به کسی که اشتراک فعال ندارد نشان داده شود.

### نسخه‌بندی و انتشار
منو `draft` / `published` دارد. ویرایش روی draft انجام می‌شود؛ «انتشار» نسخه را فعال و کش (`ac{id}:menu:active`) را باطل می‌کند. امکان Rollback به نسخه قبل با یک کلیک.

---

## ۶. Flow Builder (فاز ۳)

ویرایشگر گراف بصری برای ساخت مسیرهای بدون کد.

### انواع Node

```
┌──────────────┬────────────────────────────────────────────┐
│ trigger      │ /start · کلیک منو · دستور · زمان‌بندی        │
│ message      │ ارسال متن/عکس/ویدیو/صوت + دکمه              │
│ question     │ پرسش و انتظار پاسخ (متن/صوت/عدد/انتخاب)     │
│ condition    │ if/else روی متغیرها                        │
│ action       │ ثبت‌نام، افزودن تگ، شروع تمرین، ارسال ایمیل  │
│ ai           │ فراخوانی AI با Prompt مشخص                  │
│ delay        │ تاخیر (دقیقه/ساعت/روز)                      │
│ handoff      │ انتقال به اپراتور انسانی                    │
│ webhook      │ فراخوانی سرویس بیرونی (CRM آموزشگاه)        │
│ end          │ پایان                                      │
└──────────────┴────────────────────────────────────────────┘
```

### مثال — جریان جذب لید

```
[/start]
   ↓
[پیام: خوش‌آمد + تصویر برند]
   ↓
[پرسش: سطح فعلی زبانت چیه؟]  ← دکمه‌ها: مبتدی / متوسط / پیشرفته
   ↓
[شرط: سطح == مبتدی؟]
   ├── بله → [پیام: دوره Foundation] → [اکشن: تگ "lead-beginner"]
   └── خیر → [پرسش: هدفت چه نمره‌ایه؟]
                ↓
             [اکشن: شروع تمرین تعیین سطح]
                ↓
             [AI: تحلیل پاسخ و پیشنهاد دوره]
                ↓
             [تاخیر: ۱ روز]
                ↓
             [پیام: کد تخفیف ۲۰٪ 🎁]
```

### ذخیره‌سازی

```
telegram_flows       (academy_id, name, trigger_type, status, version, published_at)
telegram_flow_nodes  (flow_id, node_key, type, config json, position_x, position_y)
telegram_flow_edges  (flow_id, from_node, to_node, condition json, label)
```

### محافظت‌های اجرایی (اجباری)
- **سقف پرش (Hop Limit):** حداکثر ۵۰ node در یک اجرا → جلوگیری از حلقه بی‌نهایت
- **تشخیص حلقه** در زمان انتشار، نه اجرا
- **Timeout انتظار پاسخ:** پیش‌فرض ۳۰ دقیقه، سپس پاکسازی state
- **مصرف AI داخل Flow** روی سهمیه ماهانه آموزشگاه حساب می‌شود
- **Sandbox برای node وبهوک:** فقط HTTPS، بدون IP داخلی (SSRF Guard)، timeout ۵ ثانیه

---

## ۷. ارسال پیام و Rate Limiting

محدودیت‌های تلگرام:
```
~30 پیام/ثانیه   کل ربات
1 پیام/ثانیه     در هر چت خصوصی
20 پیام/دقیقه    در هر گروه
```

معماری ارسال:

```
Job → TelegramSender
        ├── RateLimiter::perBot($botId, 30/sec)
        ├── RateLimiter::perChat($chatId, 1/sec)
        ├── ارسال درخواست
        └── خطا؟
             ├── 429 → خواندن retry_after → release($seconds)
             ├── 403 (بلاک شده) → علامت‌گذاری student.telegram_blocked_at
             ├── 400 chat not found → غیرفعال‌سازی مخاطب
             └── 5xx → backoff نمایی، حداکثر ۵ تلاش
```

### Broadcast

ارسال به ۱۰٬۰۰۰ دانشجو با ۳۰ پیام/ثانیه ≈ ۶ دقیقه. بنابراین:
- Job به دسته‌های ۱۰۰تایی شکسته می‌شود (`Bus::batch`)
- نوار پیشرفت زنده در پنل: ارسال‌شده / ناموفق / بلاک‌شده
- امکان توقف نیمه‌کاره
- **بدون Rate Limit مشترک بین آموزشگاه‌ها** — چون هر آموزشگاه ربات جدا دارد، سقف‌ها مستقل‌اند (مزیت مهم معماری Multi-Bot)
- محدودیت پلن: Starter ماهی ۱ broadcast، Professional ۱۰، Enterprise نامحدود

---

## ۸. Deep Linking

```
https://t.me/englishfirst_bot?start=<payload>
```

| Payload | کاربرد |
|---------|--------|
| `ref_<student_code>` | معرفی دوستان (Referral) |
| `inv_<token>` | دعوت‌نامه آموزشگاه به دانشجوی مشخص |
| `exam_<exam_id>` | ورود مستقیم به آزمون |
| `course_<course_id>` | صفحه دوره از تبلیغات اینستاگرام |
| `cmp_<campaign_id>` | ردیابی کمپین تبلیغاتی (منبع جذب) |

Payload حداکثر ۶۴ کاراکتر، فقط `A-Za-z0-9_-`. برای payloadهای بلند، توکن کوتاه در DB نگاشت می‌شود.

هر ورود از deep link در `student_acquisitions` ثبت می‌شود تا آموزشگاه بداند هر دانشجو از کدام کانال آمده — این یکی از پرارزش‌ترین گزارش‌ها برای فروش است.

---

## ۹. مدیریت رسانه

### دریافت (صدای دانشجو)
```
voice (ogg/opus)
  → getFile → دانلود
  → S3: academies/{id}/answers/{uuid}.ogg
  → FFmpeg: → wav 16kHz mono PCM
  → ASR
  → حذف فایل موقت
```
محدودیت‌ها: حداکثر ۲۰MB (سقف Bot API برای دانلود)، حداکثر مدت ۳ دقیقه، فرمت‌های مجاز `voice`, `audio`, `video_note`.

### ارسال (فایل سوال)
`file_id` تلگرام بعد از اولین ارسال کش می‌شود:
```
question_media (question_id, s3_path, telegram_file_id, bot_id, cached_at)
```
ارسال بعدی همان فایل با `file_id` انجام می‌شود — بدون آپلود مجدد. **نکته:** `file_id` مختص هر ربات است، پس باید per-bot کش شود.

---

## ۱۰. سلامت ربات (Bot Health)

Job زمان‌بندی‌شده هر ۵ دقیقه برای همه رباتهای فعال:

```php
$info = $telegram->getWebhookInfo();

if ($info->url !== $expectedUrl)              → ثبت مجدد خودکار
if ($info->pending_update_count > 100)        → هشدار «کندی پردازش»
if ($info->last_error_date > now()->subHour()) → health_status = 'degraded'
if (سه بار متوالی ناموفق)                      → 'failing' + ایمیل به Owner + هشدار Super Admin
```

داشبورد Super Admin: جدول همه رباتها با وضعیت، صف عقب‌افتاده، آخرین خطا و زمان آخرین پیام موفق.

---

## ۱۱. Telegram Mini App (فاز ۴)

برای تجربه‌های غنی که چت محدودشان می‌کند:
- آزمون Reading با متن بلند و اسکرول
- Writing با ویرایشگر متن واقعی
- داشبورد پیشرفت با نمودار

```javascript
Telegram.WebApp.initData   // احراز هویت امضاشده
```
اعتبارسنجی سمت سرور با HMAC-SHA256 روی `initData` با کلید مشتق از bot token. Mini App همان REST API را مصرف می‌کند — یعنی زیربنای وب‌اپ فاز ۴ همین‌جا ساخته می‌شود.
