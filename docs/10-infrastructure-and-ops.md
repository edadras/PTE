# 10 — زیرساخت و عملیات

---

## ۱. توپولوژی استقرار

### فاز ۱–۲ (تا ~۲۰ آموزشگاه) — تک سرور

```
┌─────────────────────────────────────────────────┐
│  VPS  ·  8 vCPU · 16GB RAM · 200GB NVMe         │
│                                                 │
│  Nginx  →  PHP-FPM 8.4 (Laravel 12)             │
│  MySQL 8            (local)                     │
│  Redis 7            (local)                     │
│  Horizon            (4 worker process)          │
│  Supervisor · FFmpeg · Certbot                  │
└─────────────────────────────────────────────────┘
        │
    Cloudflare R2 (فایل‌ها)  ·  Cloudflare (CDN + WAF)
```
هزینه تقریبی: $60–100/ماه + هزینه AI.

### فاز ۳+ (۲۰ تا ۲۰۰ آموزشگاه) — تفکیک نقش

```
                    Cloudflare (DNS · WAF · CDN)
                              │
                       Load Balancer
                    ┌─────────┴─────────┐
              ┌─────▼─────┐       ┌─────▼─────┐
              │  Web #1   │       │  Web #2   │   (بدون state)
              │ Nginx+FPM │       │ Nginx+FPM │
              └─────┬─────┘       └─────┬─────┘
                    └─────────┬─────────┘
          ┌──────────┬────────┼────────┬──────────┐
          ▼          ▼        ▼        ▼          ▼
     ┌────────┐ ┌────────┐ ┌──────┐ ┌──────┐ ┌────────┐
     │Worker  │ │Worker  │ │MySQL │ │MySQL │ │ Redis  │
     │general │ │  AI    │ │Primary│ │Replica│ │Cluster│
     └────────┘ └────────┘ └──────┘ └──────┘ └────────┘
                                                   │
                                          Cloudflare R2
```

**نکته:** Worker مربوط به AI جدا نگه داشته می‌شود چون Jobهایش طولانی‌اند (۱۰–۶۰ ثانیه) و نباید صف پیام تلگرام را مسدود کنند.

### فاز ۵+ — کانتینری
Kubernetes یا Docker Swarm با HPA بر اساس طول صف. مهاجرت آسان است چون اپلیکیشن از ابتدا stateless طراحی شده.

---

## ۲. صف‌ها

| صف | اولویت | Worker | Timeout | Retry |
|----|--------|--------|---------|-------|
| `telegram-out` | بالاترین | ۴ | 30s | 5 |
| `telegram-in` | بالا | ۴ | 60s | 3 |
| `media` | متوسط | ۲ | 300s | 3 |
| `ai-scoring` | متوسط | ۶ | 180s | 3 |
| `notifications` | متوسط | ۲ | 60s | 3 |
| `reports` | پایین | ۱ | 600s | 2 |
| `maintenance` | پایین‌ترین | ۱ | 900s | 1 |

```php
// config/horizon.php
'production' => [
    'telegram' => [
        'connection' => 'redis',
        'queue' => ['telegram-out', 'telegram-in'],
        'balance' => 'auto', 'minProcesses' => 2, 'maxProcesses' => 12,
        'tries' => 3, 'timeout' => 60,
    ],
    'ai' => [
        'queue' => ['ai-scoring', 'media'],
        'balance' => 'auto', 'minProcesses' => 2, 'maxProcesses' => 8,
        'tries' => 3, 'timeout' => 300, 'memory' => 512,
    ],
    'default' => [
        'queue' => ['notifications', 'reports', 'maintenance'],
        'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 4,
    ],
],
```

### Job با آگاهی از مستاجر

```php
abstract class TenantAwareJob implements ShouldQueue
{
    public function __construct(public int $academyId) {}

    public function middleware(): array
    {
        return [new SetTenantContext($this->academyId)];
    }

    public function tags(): array
    {
        return ["academy:{$this->academyId}", static::class];
    }
}
```
`tags()` باعث می‌شود در Horizon بتوان Jobهای یک آموزشگاه خاص را فیلتر و عیب‌یابی کرد — بدون این، در ۱۰۰ آموزشگاه دیباگ عملاً غیرممکن است.

### Jobهای زمان‌بندی‌شده

```php
$schedule->job(CheckBotHealth::class)->everyFiveMinutes();
$schedule->job(DispatchScheduledContents::class)->everyMinute();
$schedule->job(ExpireOverdueExamSessions::class)->everyMinute();
$schedule->job(SendDailyPracticeNudge::class)->hourly();      // با احترام به timezone آموزشگاه
$schedule->job(AggregateDailyStats::class)->dailyAt('02:00');
$schedule->job(CheckSubscriptionExpiry::class)->dailyAt('06:00');
$schedule->job(CheckAiCostAnomalies::class)->dailyAt('03:00');
$schedule->job(CleanupExpiredMedia::class)->dailyAt('04:00');
$schedule->job(RecalculateDifficultyIndex::class)->weekly();
$schedule->job(ScoringConsistencyAudit::class)->weekly();
$schedule->command('backup:run')->dailyAt('01:00');
$schedule->command('horizon:snapshot')->everyFiveMinutes();
```

---

## ۳. Redis — تفکیک کاربری

| DB | کاربرد | Eviction |
|----|--------|----------|
| 0 | Cache | `allkeys-lru` |
| 1 | Queue | `noeviction` ← حیاتی |
| 2 | Session | `volatile-lru` |
| 3 | State مکالمه تلگرام | `volatile-lru`, TTL 24h |
| 4 | Rate Limiter | `volatile-ttl` |

**خطای رایج:** استفاده از یک Redis با `allkeys-lru` برای همه چیز. زیر فشار حافظه، Redis می‌تواند Jobهای صف را حذف کند و پاسخ دانشجویان برای همیشه گم شود. صف باید `noeviction` باشد و ترجیحاً روی نمونه جدا.

---

## ۴. Storage

```
Cloudflare R2 (بدون هزینه egress — مهم برای فایل صوتی)
  academies/{id}/
    brand/          public-read از طریق CDN
    questions/      خصوصی، pre-signed
    answers/        خصوصی، pre-signed، چرخه حیات ۹۰ روز
    reports/        خصوصی، انقضای ۷ روز
    exports/        خصوصی، انقضای ۲۴ ساعت
```

قواعد چرخه حیات (Lifecycle):
- `answers/` → حذف پس از N روز (`academy_settings.data_retention_days`، پیش‌فرض ۹۰)
- `exports/` → حذف پس از ۲۴ ساعت
- `reports/` → حذف پس از ۷ روز
- نسخه‌بندی فعال روی `questions/` برای جلوگیری از حذف تصادفی محتوا

---

## ۵. مانیتورینگ

### متریک‌های کلیدی

**زیرساخت:** CPU · RAM · Disk · اتصالات MySQL · حافظه Redis
**اپلیکیشن:** درخواست/ثانیه · P95 latency · نرخ خطای ۵xx · طول صف · Jobهای شکست‌خورده
**کسب‌وکار (per-tenant):** Updateهای تلگرام · تمرین‌های تکمیل‌شده · درخواست AI · هزینه AI · دانشجوی فعال

### هشدارها

| شرط | شدت |
|-----|-----|
| صف `telegram-out` > ۱۰۰۰ Job | 🔴 بحرانی |
| نرخ خطای Webhook > ۵٪ | 🔴 بحرانی |
| ربات با `health_status = failing` | 🟠 هشدار |
| Jobهای شکست‌خورده > ۵۰ در ساعت | 🟠 هشدار |
| هزینه AI روزانه > ۱۵۰٪ میانگین ۷ روزه | 🟠 هشدار |
| دیسک > ۸۰٪ | 🟠 هشدار |
| تاخیر Replica > ۳۰ ثانیه | 🟠 هشدار |
| SSL تا ۷ روز دیگر منقضی می‌شود | 🟡 اطلاع |

### ابزارها
- **Laravel Pulse** — دید سریع درون‌برنامه‌ای
- **Horizon** — صف‌ها
- **Sentry** — خطاها با tag مستاجر (`academy_id` روی هر رویداد)
- **Uptime Kuma** — بررسی خارجی endpointها
- **Prometheus + Grafana** (فاز ۳) — متریک با label مستاجر

**قاعده حیاتی:** هر خطای Sentry باید tag `academy_id` داشته باشد. بدون آن، در ۱۰۰ مشتری نمی‌فهمید مشکل مال کیست.

---

## ۶. پشتیبان‌گیری و بازیابی

| مورد | تناوب | نگهداری | مقصد |
|------|-------|---------|------|
| MySQL کامل | روزانه ۰۱:۰۰ | ۳۰ روز | R2 + خارج از سایت |
| MySQL افزایشی (binlog) | پیوسته | ۷ روز | R2 |
| فایل‌های R2 | نسخه‌بندی | ۳۰ روز | همان |
| کانفیگ و `.env` | با هر تغییر | ۹۰ روز | مخزن رمزنگاری‌شده |

**اهداف:** RPO ≤ ۱ ساعت · RTO ≤ ۴ ساعت

**تمرین بازیابی ماهانه اجباری.** پشتیبانی که تست نشده، پشتیبان نیست. سناریوهای تمرین:
1. بازیابی کامل روی سرور تازه
2. بازیابی نقطه‌ای (PITR) به ۲ ساعت قبل
3. بازیابی داده **یک آموزشگاه** بدون دست زدن به بقیه ← این سخت‌ترین و محتمل‌ترین سناریوی واقعی است

برای سناریوی ۳، اسکریپت `academy:restore {id} {backup}` باید از قبل نوشته و تست شده باشد.

---

## ۷. استقرار (Deployment)

```
Push → GitHub Actions
        ├─ Pint (سبک کد)
        ├─ PHPStan level 8
        ├─ Pest (unit + feature + tenant-isolation)
        ├─ ساخت asset
        └─ Deploy (Deployer / Envoyer)
              ├─ نسخه جدید در پوشه جدید
              ├─ php artisan down --render=maintenance
              ├─ migrate --force
              ├─ config:cache · route:cache · view:cache · event:cache
              ├─ سوییچ symlink
              ├─ php artisan up
              ├─ horizon:terminate  (workerها با کد جدید بالا می‌آیند)
              └─ opcache:clear
```

**تست‌های blocking در CI:**
- تست‌های جداسازی مستاجر (سند ۰۱ بخش ۷)
- تست معماری: هر مدل دامنه باید `BelongsToAcademy` داشته باشد
- تست معماری: هیچ نام Provider خارج از `Domain/AI/Providers` نباشد
- پوشش تست حداقل ۷۰٪ روی `app/Domain`

### Migration بدون قطعی
- ابتدا ستون nullable اضافه شود، سپس backfill با Job، سپس NOT NULL
- هرگز `ALTER TABLE` روی جدول بزرگ در ساعت اوج (از `pt-online-schema-change` استفاده شود)
- حذف ستون همیشه دو استقرار بعد از توقف استفاده از آن

---

## ۸. عملیات روزمره

### دستورات Artisan اختصاصی

```bash
php artisan academy:create {name} {owner-email}
php artisan academy:suspend {id} --reason=
php artisan academy:export {id}              # ZIP کامل داده
php artisan academy:restore {id} {backup}
php artisan academy:clone {from} {to} --content

php artisan telegram:register-webhook {academy}
php artisan telegram:health-check --all
php artisan telegram:reset-webhook {academy}

php artisan ai:test-prompt {academy} {key}
php artisan ai:cost-report --period=2026-07
php artisan ai:rescore {answer_id}

php artisan tenant:run {academy} "{command}"   # اجرای هر دستور در context مستاجر
```

### Runbookها (باید نوشته شوند)
1. ربات یک آموزشگاه پاسخ نمی‌دهد
2. صف عقب افتاده / انباشت Job
3. Provider AI از کار افتاده
4. هزینه AI به‌طور غیرعادی جهش کرده
5. یک آموزشگاه ادعای نشت داده دارد
6. بازیابی داده یک آموزشگاه
7. چرخش توکن لو رفته
8. مهاجرت آموزشگاه به DB اختصاصی

---

## ۹. امنیت زیرساخت
- MySQL و Redis فقط روی شبکه خصوصی، بدون IP عمومی
- SSH با کلید، بدون رمز، پورت غیراستاندارد، fail2ban
- Cloudflare WAF + قواعد Rate Limit در لبه
- HTTPS اجباری، HSTS، TLS 1.2+
- `.env` با دسترسی ۶۰۰، خارج از webroot
- چرخش `APP_KEY` هرگز بدون بازرمزنگاری مقادیر encrypted (فرایند مکتوب لازم است)
- به‌روزرسانی امنیتی خودکار سیستم‌عامل
- اسکن وابستگی‌ها (`composer audit`) در CI
