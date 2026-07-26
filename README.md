# PTE Platform — پلتفرم SaaS چندمستاجری آموزش زبان

پلتفرمی White-Label و Multi-Tenant که به هر آموزشگاه زبان اجازه می‌دهد با **برند خودش** و **ربات تلگرام اختصاصی خودش** آزمون، تمرین و تصحیح هوشمند (AI) ارائه دهد — و در فازهای بعد همان هسته، اپلیکیشن موبایل و وب‌اپ دانشجویی را هم تغذیه می‌کند.

> وضعیت فعلی مخزن: **پیاده‌سازی شده**. اسناد معماری در `docs/` و کد در `app/Domain/`.
> ۲۸۵ تست سبز · ۶۸ migration · ۱۵۵ route · ۱۵ دستور Artisan · ۱۷ کار زمان‌بندی‌شده.

---

## راه‌اندازی سریع

```bash
composer install
cp .env.example .env
php artisan key:generate

docker compose up -d          # MySQL · Redis ×2 · MinIO · Mailpit

php artisan migrate
php artisan db:seed           # پلن‌ها، ماژول‌ها، Permissionها، مدل‌های AI، Promptهای پیش‌فرض

php artisan academy:create "English First" owner@example.com
php artisan telegram:register-webhook 1

php artisan serve             # پنل: /panel   ·   Super Admin: /platform
php artisan horizon           # صف‌ها
```

```bash
php artisan test                      # کل تست‌ها
php artisan test --testsuite=Tenancy  # تست‌های جداسازی مستاجر (blocking در CI)
./vendor/bin/pint --test              # سبک کد
./vendor/bin/phpstan analyse          # تحلیل ایستا
```

---

## ساختار کد

```
app/
  Domain/
    Tenancy/        آموزشگاه، برند، دامنه، ماژول‌ها · TenantContext · BelongsToAcademy
    Identity/       کاربر، نقش، Permission، دانشجو، کلاس، API Key
    Telegram/       Multi-Bot · Webhook · MenuEngine · FlowEngine · MessageSender
    Learning/       بانک سوال · ۱۷ نوع تمرین PTE · انتخاب تطبیقی · Import
    Assessment/     تمرین، آزمون، پاسخ · ۶ Scorer الگوریتمی · Override مدرس
    AI/             Gateway · ۵ Provider · Prompt/Rubric نسخه‌دار · CostMeter
    Commerce/       پلن، اشتراک، Quota، ۵ درگاه پرداخت، فاکتور
    Integration/    REST API · Webhook خروجی
    Support/        تیکت · تاریخچه گفتگو
    Reporting/      داشبورد · Export · کارنامه · Archive
    Audit/          ActivityLog · PlatformAuditLog · Retention
    Notification/   چندکاناله · محتوای زمان‌بندی‌شده (در timezone آموزشگاه)
    Shared/         TenantAwareJob · TenantKey
  Filament/         پنل Platform و پنل Academy
  Http/             Controllerها و Resourceهای API
  Console/Commands/ ۱۵ دستور عملیاتی
```

قراردادهای کدنویسی و قواعد غیرقابل‌مذاکره چندمستاجری در [`CONVENTIONS.md`](CONVENTIONS.md).

---

## نگاه کلی

```
                     ┌──────────────────────────────────────────┐
                     │            Platform (SaaS Core)          │
                     │  Super Admin · Billing · Plans · Modules │
                     └──────────────────┬───────────────────────┘
                                        │
        ┌───────────────────────────────┼───────────────────────────────┐
        │                               │                               │
   ┌────▼─────┐                    ┌────▼─────┐                    ┌────▼─────┐
   │ Academy A│                    │ Academy B│                    │ Academy C│
   ├──────────┤                    ├──────────┤                    ├──────────┤
   │ Brand    │                    │ Brand    │                    │ Brand    │
   │ Bot      │  @englishfirst_bot │ Bot      │  @ielts_academy_bot│ Bot      │
   │ Managers │                    │ Managers │                    │ Managers │
   │ Teachers │                    │ Teachers │                    │ Teachers │
   │ Students │                    │ Students │                    │ Students │
   │ Exams    │                    │ Exams    │                    │ Exams    │
   │ Q-Bank   │                    │ Q-Bank   │                    │ Q-Bank   │
   │ AI Config│                    │ AI Config│                    │ AI Config│
   └──────────┘                    └──────────┘                    └──────────┘

           هیچ داده‌ای بین آموزشگاه‌ها مشترک نیست (Hard Isolation)
```

---

## فهرست اسناد

| # | سند | موضوع |
|---|-----|-------|
| 00 | [معماری کلی](docs/00-overview.md) | دامنه محصول، لایه‌ها، مرزهای سیستم، اصول طراحی |
| 01 | [چندمستاجری (Multi-Tenancy)](docs/01-multi-tenancy.md) | استراتژی جداسازی داده، Tenant Resolution، امنیت مرز مستاجر |
| 02 | [نقش‌ها و دسترسی (RBAC)](docs/02-roles-and-rbac.md) | Super Admin، Owner، Manager، Teacher، Support، Student + ماتریس Permission |
| 03 | [White Label و برندینگ](docs/03-white-label.md) | لوگو، رنگ، دامنه اختصاصی، قالب پیام‌ها، چندزبانگی |
| 04 | [لایه تلگرام](docs/04-telegram-layer.md) | Multi-Bot، Webhook، منوساز، Flow Builder، Rate Limiting، Deep Link |
| 05 | [ماژول‌ها، آزمون و تمرین](docs/05-modules-exams-practice.md) | Module System، Exam Builder، انواع سوال PTE/IELTS، Question Bank |
| 06 | [لایه هوش مصنوعی](docs/06-ai-layer.md) | Provider Abstraction، Prompt Builder، Rubric، پایپ‌لاین Speaking/Writing، کنترل هزینه |
| 07 | [طرح دیتابیس](docs/07-database-schema.md) | جداول، فیلدهای کلیدی، ایندکس‌ها، Partitioning |
| 08 | [API و یکپارچه‌سازی](docs/08-api-and-integrations.md) | REST API، احراز هویت، Webhook خروجی، آمادگی برای اپ و وب |
| 09 | [Billing و پلن‌ها](docs/09-billing-and-plans.md) | Starter/Pro/Enterprise، Quota، درگاه پرداخت، فروش داخل ربات |
| 10 | [زیرساخت و عملیات](docs/10-infrastructure-and-ops.md) | استقرار، Queue، Redis، Storage، مانیتورینگ، Backup |
| 11 | [نقشه راه ساخت](docs/11-roadmap.md) | فازبندی، تخمین زمان، Milestoneها، تیم، معیار پذیرش |
| 12 | [امنیت و انطباق](docs/12-security-and-compliance.md) | رمزنگاری توکن‌ها، حریم خصوصی، Audit Log، Threat Model |
| 13 | [تصمیمات معماری (ADR)](docs/13-adr.md) | تصمیم‌های کلیدی و دلایل انتخاب/رد گزینه‌ها |

---

## خلاصه تکنولوژی

| لایه | انتخاب |
|------|--------|
| Backend | Laravel 12 · PHP 8.4 |
| DB | MySQL 8 (InnoDB, utf8mb4) |
| Cache / State | Redis 7 |
| Queue | Redis + Laravel Horizon |
| Admin Panel | FilamentPHP 3 |
| Student Web (فاز ۴) | Vue 3 + Tailwind (PWA) |
| Mobile (فاز ۵) | Flutter — روی همان REST API |
| AI | Gemini 2.5 Pro/Flash · GPT-5 · Claude Sonnet (Pluggable) |
| ASR | Whisper / Google Speech-to-Text |
| Media | FFmpeg · S3 / Cloudflare R2 |
| Telegram | Webhook · Multi-Bot · Inline & Reply Keyboard · Deep Link |

---

## اصول غیرقابل مذاکره پروژه

1. **هیچ Query بدون Tenant Scope** — نشت داده بین آموزشگاه‌ها = باگ بحرانی.
2. **API-First** — ربات تلگرام فقط یکی از کلاینت‌هاست؛ وب و اپ بعداً روی همان API سوار می‌شوند.
3. **همه چیز قابل تنظیم توسط آموزشگاه، بدون کدنویسی** — منو، Flow، Prompt، Rubric، برند.
4. **پردازش سنگین همیشه Async** — Webhook تلگرام باید زیر ۲۰۰ms پاسخ ۲۰۰ بدهد.
5. **AI قابل تعویض** — هیچ‌جا نام Provider هاردکد نشود.
6. **هر هزینه AI به مستاجر نسبت داده شود** — بدون Metering، SaaS ضرر می‌دهد.
