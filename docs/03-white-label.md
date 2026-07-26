# 03 — White Label و برندینگ

هدف: زبان‌آموز آموزشگاه «English First» هرگز نباید بفهمد پشت این ربات و پنل، پلتفرم مشترکی وجود دارد.

---

## ۱. دامنه برندینگ

```
┌───────────────── چیزی که آموزشگاه کنترل می‌کند ─────────────────┐
│                                                                 │
│  هویت بصری          محتوای متنی           کانال‌های ارتباطی      │
│  ─────────          ───────────           ────────────────      │
│  لوگو (روشن/تیره)   نام برند              وب‌سایت                │
│  آیکون / Favicon    شعار                  اینستاگرام             │
│  رنگ اصلی           متن خوش‌آمدگویی        کانال تلگرام           │
│  رنگ فرعی           تصویر خوش‌آمدگویی      شماره پشتیبانی         │
│  رنگ موفقیت/خطا     متن فوتر              ایمیل پشتیبانی         │
│  حالت تیره          قالب پیام‌های ربات      واتساپ                │
│  فونت               متن کارنامه            آدرس فیزیکی           │
│  دامنه اختصاصی      شرایط و قوانین         ساعات کاری             │
│                     زبان پیش‌فرض                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## ۲. جدول `academy_brands`

| ستون | نوع | توضیح |
|------|-----|-------|
| `academy_id` | FK یکتا | |
| `display_name` | string(100) | «آموزشگاه زبان English First» |
| `short_name` | string(40) | «English First» — برای فضاهای تنگ |
| `tagline` | string(160) | شعار |
| `logo_light_path` | string | لوگو روی پس‌زمینه روشن |
| `logo_dark_path` | string | لوگو روی پس‌زمینه تیره |
| `icon_path` | string | مربعی، برای Favicon و آواتار |
| `welcome_image_path` | string | تصویر پیام /start |
| `primary_color` | char(7) | `#2563EB` |
| `secondary_color` | char(7) | `#7C3AED` |
| `accent_color` | char(7) | |
| `success_color` / `danger_color` | char(7) | |
| `dark_mode` | enum | `light` \| `dark` \| `auto` |
| `font_family` | string | `Vazirmatn` \| `IRANSans` \| `Inter` |
| `welcome_text` | text | پشتیبانی از placeholder |
| `footer_text` | text | زیر پیام‌های ربات |
| `website_url` `instagram_url` `telegram_channel` `whatsapp` | string | |
| `support_phone` `support_email` | string | |
| `address` `working_hours` | text | |
| `default_locale` | char(5) | `fa` \| `en` \| `ar` |
| `supported_locales` | json | `["fa","en"]` |
| `terms_url` `privacy_url` | string | |
| `custom_css` | text | فقط پلن Enterprise — Sanitize اجباری |

---

## ۳. نمونه‌های واقعی

**English First**
```
نام: English First
لوگو: ef-logo.svg
رنگ اصلی: #1D4ED8 (آبی)
رنگ فرعی: #93C5FD
حالت: روشن
دامنه: panel.englishfirst.ir
ربات: @englishfirst_academy_bot
خوش‌آمد: «سلام {first_name} عزیز 👋
به English First خوش اومدی. آماده‌ای تمرین امروزت رو شروع کنی؟»
```

**IELTS Academy**
```
نام: IELTS Academy
لوگو: ielts-academy.svg
رنگ اصلی: #DC2626 (قرمز)
رنگ فرعی: #FCA5A5
حالت: تیره
دامنه: my.ieltsacademy.com
ربات: @ielts_academy_official_bot
خوش‌آمد: «Welcome to IELTS Academy, {first_name}!
Your Band 7 journey starts here 🎯»
```

هر دو روی یک کد اجرا می‌شوند. تفاوت فقط در داده است.

---

## ۴. اعمال برند در سه کانال

### ۴.۱ پنل مدیریت (Filament)

رنگ‌ها در زمان اجرا به CSS Variables تزریق می‌شوند:

```php
// app/Providers/Filament/AcademyPanelProvider.php
$panel->colors([
    'primary'   => Color::hex(TenantContext::require()->brand->primary_color),
    'secondary' => Color::hex(TenantContext::require()->brand->secondary_color),
])
->brandName(fn () => TenantContext::require()->brand->display_name)
->brandLogo(fn () => TenantContext::require()->brand->logoUrl())
->favicon(fn () => TenantContext::require()->brand->iconUrl())
->darkMode(TenantContext::require()->brand->dark_mode !== 'light');
```

```blade
<style>
  :root {
    --brand-primary: {{ $brand->primary_color }};
    --brand-secondary: {{ $brand->secondary_color }};
    --brand-font: '{{ $brand->font_family }}';
  }
</style>
```

**کش:** برند در Redis با کلید `ac{id}:brand` کش می‌شود و با هر ذخیره Invalidate می‌شود.

### ۴.۲ ربات تلگرام

هویت خود ربات:
- نام و توضیحات ربات از طریق API تلگرام تنظیم می‌شود: `setMyName`, `setMyDescription`, `setMyShortDescription`
- عکس پروفایل ربات باید توسط آموزشگاه در BotFather تنظیم شود (API ندارد) — راهنمای تصویری در پنل نمایش داده شود
- `setChatMenuButton` برای دکمه منوی کنار فیلد تایپ
- `setMyCommands` بر اساس منوی فعال آموزشگاه

پیام‌ها:
```
{welcome_image}

سلام علی عزیز 👋
به English First خوش اومدی. آماده‌ای تمرین امروزت رو شروع کنی؟

━━━━━━━━━━━━━━━━━━
📞 پشتیبانی: 021-12345678
🌐 englishfirst.ir
```
(دو خط آخر از `footer_text` ساخته می‌شود و در همه پیام‌های سطح اول تکرار می‌شود.)

### ۴.۳ کارنامه و PDF/تصویر خروجی

کارنامه‌ای که ربات ارسال می‌کند یک تصویر رندرشده با برند آموزشگاه است:
- لوگو در بالا، رنگ اصلی برای هدر و نمودارها
- نام آموزشگاه در فوتر
- تولید با Browsershot (HTML → PNG/PDF) در صف `reports`
- ذخیره در `academies/{id}/reports/{uuid}.png`

---

## ۵. سیستم Placeholder

هر متن قابل تنظیم می‌تواند این متغیرها را داشته باشد:

| گروه | Placeholder |
|------|-------------|
| دانشجو | `{first_name}` `{last_name}` `{full_name}` `{student_code}` `{level}` |
| آموزشگاه | `{academy_name}` `{support_phone}` `{website}` `{instagram}` |
| زمان | `{today}` `{time}` `{weekday}` |
| پیشرفت | `{total_practices}` `{avg_score}` `{streak_days}` `{last_score}` |
| اشتراک | `{plan_name}` `{days_remaining}` `{expires_at}` |

```php
final class PlaceholderRenderer
{
    public function render(string $template, PlaceholderContext $ctx): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($ctx) {
            return $ctx->has($m[1]) ? e($ctx->get($m[1])) : $m[0];
        }, $template);
    }
}
```

پنل باید هنگام تایپ، پیش‌نمایش زنده با داده نمونه نشان دهد و placeholder ناشناخته را با هشدار زرد علامت بزند.

---

## ۶. قالب پیام‌ها (Message Templates)

آموزشگاه می‌تواند متن همه پیام‌های سیستمی را بازنویسی کند. جدول `message_templates`:

```
academy_id · key · locale · channel · content · is_customized
```

| کلید | کاربرد |
|------|--------|
| `welcome` | پیام /start |
| `menu_header` | بالای منوی اصلی |
| `practice_started` | شروع تمرین |
| `practice_completed` | پایان تمرین |
| `score_ready` | آماده شدن نمره |
| `exam_reminder` | یادآوری آزمون |
| `daily_nudge` | تشویق تمرین روزانه |
| `subscription_expiring` | نزدیک انقضای اشتراک |
| `subscription_expired` | انقضا |
| `payment_success` | پرداخت موفق |
| `support_greeting` | شروع گفتگو با پشتیبانی |
| `error_generic` | خطای عمومی |
| `quota_exceeded` | اتمام سهمیه |

اگر `is_customized = false`، متن پیش‌فرض پلتفرم (از فایل زبان) استفاده می‌شود. این یعنی به‌روزرسانی متون پیش‌فرض توسط شما به‌طور خودکار به همه آموزشگاه‌هایی که سفارشی‌سازی نکرده‌اند می‌رسد.

---

## ۷. چندزبانگی

دو سطح مستقل:

1. **زبان پنل** — انتخاب کاربر Staff (فارسی / انگلیسی)
2. **زبان ربات** — انتخاب دانشجو از میان `supported_locales` آموزشگاه

```php
// اولویت تشخیص زبان دانشجو
$locale = $student->locale
       ?? $telegramUser->language_code   // از خود تلگرام
       ?? $brand->default_locale;
```

زبان‌های فاز ۱: `fa`, `en`. آماده برای `ar`, `tr`.
RTL/LTR به‌صورت خودکار از روی locale تعیین می‌شود.

---

## ۸. کنترل‌های امنیتی برندینگ

| ریسک | کنترل |
|------|-------|
| آپلود SVG آلوده (XSS) | Sanitize با `enshrined/svg-sanitize` یا تبدیل اجباری به PNG |
| `custom_css` مخرب | فقط Enterprise + Whitelist ویژگی‌ها + بدون `url()`, `@import`, `expression()` |
| جعل برند دیگران | بررسی دستی Super Admin هنگام تنظیم دامنه اختصاصی |
| فایل بیش از حد بزرگ | حداکثر ۲MB لوگو، ۵MB تصویر خوش‌آمد، Resize خودکار |
| نوع فایل جعلی | اعتبارسنجی MIME واقعی (نه پسوند) + بازسازی تصویر با Intervention |
| Placeholder Injection | خروجی `PlaceholderRenderer` همیشه `e()` می‌شود؛ در تلگرام escape مخصوص MarkdownV2 |

---

## ۹. Onboarding — ۱۰ دقیقه تا اولین پیام

جادوی محصول در سرعت راه‌اندازی است. Wizard شش‌مرحله‌ای:

```
1  اطلاعات آموزشگاه       نام، شهر، حوزه (PTE/IELTS/عمومی)
2  برند                   آپلود لوگو → استخراج خودکار رنگ غالب → پیشنهاد پالت
3  ربات تلگرام            راهنمای تصویری BotFather → چسباندن Token
                          → اعتبارسنجی getMe → ثبت خودکار Webhook ✅
4  ماژول‌ها                تیک زدن: Speaking ☑ Listening ☑ Reading ☐ Writing ☐
5  منو                     منوی پیش‌فرض بر اساس ماژول‌ها ساخته می‌شود (قابل ویرایش)
6  محتوا                   «شروع با بانک سوال نمونه (۵۰ سوال)» یا «بانک خالی»
                          ↓
                 «ربات شما آماده است 🎉»
                 دکمه: باز کردن @your_bot در تلگرام
```

هدف قابل اندازه‌گیری: **Time-to-First-Message زیر ۱۰ دقیقه.** این متریک در داشبورد Super Admin ردیابی می‌شود.
