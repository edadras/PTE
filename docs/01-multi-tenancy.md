# 01 — چندمستاجری (Multi-Tenancy)

این سند قلب معماری است. اگر یک بخش از این پروژه را باید بی‌نقص پیاده کرد، همین است: **هیچ آموزشگاهی نباید هرگز حتی یک ردیف از داده آموزشگاه دیگر را ببیند.**

---

## ۱. انتخاب استراتژی

سه گزینه رایج:

| استراتژی | جداسازی | هزینه عملیات | مهاجرت DB | مناسب برای |
|----------|---------|--------------|-----------|-------------|
| **A. Shared DB + Discriminator Column** | منطقی (App-level) | کم | یک‌بار برای همه | ده‌ها تا صدها مستاجر |
| B. Schema per Tenant | متوسط | متوسط | N بار | ده‌ها مستاجر |
| C. Database per Tenant | فیزیکی | زیاد | N بار | Enterprise / الزام قانونی |

### تصمیم: **گزینه A** به‌عنوان پیش‌فرض + مسیر آماده به **گزینه C** برای پلن Enterprise.

**دلیل:** با ۱۰ تا ۵۰۰ آموزشگاه، گزینه A ساده‌ترین عملیات را دارد (یک migration، یک backup، یک connection pool). گزینه C فقط وقتی لازم می‌شود که مشتری بزرگی الزام قانونی/قراردادی برای جداسازی فیزیکی داشته باشد.

**شرط ورود به گزینه C (Trigger):**
- مشتری Enterprise با قرارداد جداسازی داده، یا
- یک آموزشگاه > ۲۰٪ کل حجم داده پلتفرم را اشغال کند، یا
- الزام محلی‌سازی داده (Data Residency) در کشوری دیگر

### چطور مسیر مهاجرت را باز نگه داریم

تمام دسترسی به DB از طریق یک لایه Tenant Context می‌گذرد. اگر `academy.database_connection` مقدار داشته باشد، اتصال عوض می‌شود؛ در غیر این صورت اتصال پیش‌فرض با Global Scope. یعنی کد اپلیکیشن **هرگز نمی‌داند** مستاجر روی DB مشترک است یا اختصاصی.

```php
// app/Domain/Tenancy/TenantContext.php
final class TenantContext
{
    private static ?Academy $academy = null;

    public static function set(Academy $academy): void
    {
        self::$academy = $academy;

        if ($academy->database_connection) {         // مسیر Enterprise
            config(['database.default' => $academy->database_connection]);
        }

        // prefix اختصاصی برای Redis و Storage
        config([
            'cache.prefix'        => "ac{$academy->id}:",
            'filesystems.disks.tenant.root' => "academies/{$academy->id}",
        ]);

        App::setLocale($academy->settings->locale ?? 'fa');
    }

    public static function id(): int  { return self::require()->id; }
    public static function get(): ?Academy { return self::$academy; }
    public static function require(): Academy
    {
        return self::$academy ?? throw new TenantNotResolvedException();
    }

    public static function forget(): void { self::$academy = null; }
}
```

---

## ۲. سه لایه جداسازی

جداسازی فقط در DB نیست. سه لایه باید همزمان رعایت شود:

### ۲.۱ داده (MySQL)
هر جدول مستاجری ستون `academy_id BIGINT UNSIGNED NOT NULL` دارد + ایندکس مرکب که **همیشه** با `academy_id` شروع می‌شود.

```sql
INDEX idx_answers_tenant_session (academy_id, exam_session_id),
INDEX idx_answers_tenant_created (academy_id, created_at)
```

### ۲.۲ حافظه (Redis)
همه کلیدها با prefix مستاجر:
```
ac{academy_id}:tg:state:{chat_id}       ← state مکالمه ربات
ac{academy_id}:menu:v{version}          ← کش منوی رندرشده
ac{academy_id}:quota:ai:{yyyy-mm}       ← شمارنده سهمیه
```
با `config(['cache.prefix' => ...])` این کار خودکار می‌شود، ولی برای دسترسی مستقیم به Redis باید از helper اجباری استفاده شود:
```php
TenantKey::make('tg:state', $chatId);   // ac12:tg:state:98765
```

### ۲.۳ فایل (S3 / R2)
```
s3://pte-platform/
  academies/{academy_id}/
    brand/logo.png
    questions/{question_id}/audio.mp3
    answers/{answer_id}/voice.ogg
    exports/{report_id}.xlsx
```
دسترسی همیشه از طریق **Pre-signed URL با انقضای کوتاه (۱۵ دقیقه)**. باکت هرگز public نیست.

---

## ۳. Global Scope خودکار

```php
// app/Domain/Tenancy/Concerns/BelongsToAcademy.php
trait BelongsToAcademy
{
    protected static function bootBelongsToAcademy(): void
    {
        static::addGlobalScope('academy', function (Builder $q) {
            if ($academy = TenantContext::get()) {
                $q->where($q->getModel()->getTable().'.academy_id', $academy->id);
            } elseif (! app()->runningInConsole()) {
                // در وب، نبودِ Tenant یعنی باگ — نه اینکه «همه را نشان بده»
                throw new TenantNotResolvedException();
            }
        });

        static::creating(function (Model $model) {
            $model->academy_id ??= TenantContext::id();
        });
    }

    public function academy(): BelongsTo
    {
        return $this->belongsTo(Academy::class);
    }
}
```

**نکته حیاتی — رفتار در نبود Tenant:**
اکثر پیاده‌سازی‌های ضعیف وقتی Tenant پیدا نشود، Scope را اعمال نمی‌کنند و نتیجه‌اش نشت کل داده‌هاست. اینجا برعکس: **نبودِ Tenant در Runtime وب = Exception**. فقط در CLI (migration، seeder، job سراسری Super Admin) اجازه عبور با فراخوانی صریح داریم:

```php
Academy::query()->each(fn ($a) => TenantContext::runFor($a, function () {
    // کار روی همان مستاجر
}));
```

### ابزار عبور کنترل‌شده برای Super Admin

```php
// فقط جایی که واقعاً باید همه مستاجرها را دید (پنل Super Admin)
Student::withoutTenantScope()->count();   // ← نیازمند Permission: platform.view_all
```
این متد داخل خودش `Gate::authorize('platform.view_all')` را صدا می‌زند؛ یعنی حتی اگر توسعه‌دهنده اشتباهی صدایش بزند، بدون دسترسی کار نمی‌کند.

---

## ۴. Tenant Resolution — چهار مسیر ورود

```
┌─────────────────────┬──────────────────────────────┬─────────────────────────┐
│ مسیر                │ کلید تشخیص                    │ Middleware              │
├─────────────────────┼──────────────────────────────┼─────────────────────────┤
│ Webhook تلگرام      │ bot_public_id در URL          │ ResolveTenantFromBot    │
│ پنل مدیریت          │ Host (زیردامنه یا دامنه اختصاصی) │ ResolveTenantFromDomain │
│ REST API            │ API Key / OAuth client        │ ResolveTenantFromApiKey │
│ وب‌اپ دانشجو (فاز ۴) │ Host + JWT claim `aid`        │ ResolveTenantFromDomain │
└─────────────────────┴──────────────────────────────┴─────────────────────────┘
```

### ۴.۱ Webhook

```php
Route::post('/webhook/{botPublicId}', TelegramWebhookController::class)
     ->middleware(['resolve-tenant-bot', 'throttle:telegram']);
```

```php
class ResolveTenantFromBot
{
    public function handle(Request $request, Closure $next)
    {
        $bot = Cache::remember(
            "bot:{$request->route('botPublicId')}",
            3600,
            fn () => TelegramBot::withoutTenantScope()
                ->where('public_id', $request->route('botPublicId'))
                ->where('is_active', true)
                ->first()
        );

        abort_if(! $bot, 404);

        // اعتبارسنجی Secret Token (تلگرام آن را در هدر می‌فرستد)
        abort_unless(
            hash_equals($bot->webhook_secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            401
        );

        TenantContext::set($bot->academy);
        $request->attributes->set('telegram_bot', $bot);

        return $next($request);
    }
}
```

`public_id` یک ULID تصادفی است، **نه** `academy_id`. دلیل: URL وبهوک نباید ساختار داخلی و تعداد مشتری‌ها را لو بدهد و نباید قابل شمارش (enumerable) باشد.

### ۴.۲ دامنه

```
englishfirst.pte-platform.com   → زیردامنه پیش‌فرض (رایگان، همه پلن‌ها)
panel.englishfirst.ir           → دامنه اختصاصی (پلن Professional به بالا)
```

جدول `academy_domains`:

| ستون | توضیح |
|------|-------|
| `academy_id` | مالک |
| `hostname` | یکتا در کل پلتفرم |
| `type` | `subdomain` \| `custom` |
| `is_primary` | دامنه‌ای که برای لینک‌سازی استفاده می‌شود |
| `verified_at` | تایید مالکیت با رکورد TXT |
| `ssl_status` | `pending` \| `issued` \| `failed` (Let's Encrypt) |

فرایند دامنه اختصاصی:
1. آموزشگاه `panel.englishfirst.ir` را وارد می‌کند
2. سیستم رکورد `_pte-verify.panel.englishfirst.ir TXT = <token>` می‌دهد
3. Job دوره‌ای DNS را چک می‌کند → `verified_at`
4. صدور SSL خودکار (Caddy on-demand TLS یا acme.sh + Nginx)
5. فعال‌سازی

### ۴.۳ API Key

```
Authorization: Bearer pte_live_ak_01HZX...   → academy_id + scopes
```
کلید هش‌شده (SHA-256) ذخیره می‌شود؛ متن اصلی فقط یک‌بار هنگام ساخت نمایش داده می‌شود.

---

## ۵. کاربر در چند آموزشگاه

یک شخص می‌تواند در آموزشگاه A مدرس و در آموزشگاه B پشتیبان باشد. بنابراین:

- جدول `users` **سراسری** است (بدون `academy_id`) — چون هویت شخص است.
- جدول `academy_user_roles` عضویت و نقش را در هر آموزشگاه مشخص می‌کند.
- بعد از ورود، اگر کاربر عضو چند آموزشگاه باشد، صفحه «انتخاب آموزشگاه» می‌بیند.

```
users (id, name, email, password, is_super_admin)
academy_user_roles (academy_id, user_id, role_id, status, joined_at)
```

**اما دانشجویان فرق دارند:** جدول `students` **مستاجری** است (`academy_id` دارد). یک نفر که در دو آموزشگاه ثبت‌نام کند، دو رکورد `students` مجزا دارد — چون پیشرفت تحصیلی، خرید و نمرات او در هر آموزشگاه مستقل است و نباید نشت کند.

اتصال تلگرام:
```
telegram_identities (academy_id, telegram_user_id, student_id, ...)
UNIQUE (academy_id, telegram_user_id)
```
یعنی همان تلگرام‌آیدی می‌تواند در دو آموزشگاه دو دانشجوی متفاوت باشد — و این دقیقاً رفتار درست است.

---

## ۶. جداول غیرمستاجری (Platform-level)

این‌ها `academy_id` **ندارند**:

```
users                 هویت سراسری
plans                 پلن‌های فروش
modules               رجیستری ماژول‌ها (PTE, IELTS, TOEFL, Vocabulary)
ai_models             مدل‌های AI موجود در پلتفرم
countries / locales   داده مرجع
academies             خودِ مستاجر
academy_domains       نگاشت دامنه → مستاجر (باید قبل از Resolution خوانده شود)
telegram_bots         (academy_id دارد ولی با withoutTenantScope خوانده می‌شود)
platform_audit_logs   لاگ اقدامات Super Admin
```

قاعده: هر جدولی که برای **پیدا کردن** مستاجر لازم است، نمی‌تواند خودش Scope مستاجری داشته باشد.

---

## ۷. تست‌های اجباری مرز مستاجر

بدون این تست‌ها، این معماری فقط یک ادعاست. حداقل مجموعه:

```php
/** @test */
public function queries_never_leak_across_academies(): void
{
    $a = Academy::factory()->has(Student::factory()->count(3))->create();
    $b = Academy::factory()->has(Student::factory()->count(5))->create();

    TenantContext::set($a);
    $this->assertSame(3, Student::count());

    TenantContext::set($b);
    $this->assertSame(5, Student::count());
}

/** @test */
public function direct_id_access_from_another_academy_returns_404(): void
{
    $other = Student::factory()->for($academyB)->create();
    TenantContext::set($academyA);

    $this->actingAs($ownerOfA)
         ->get("/students/{$other->id}")
         ->assertNotFound();          // نه 403 — وجودش هم نباید لو برود
}

/** @test */
public function every_domain_model_uses_the_tenant_trait(): void
{
    // Architecture test — از نشت‌های آینده جلوگیری می‌کند
    foreach (ModelFinder::in('app/Domain') as $model) {
        if (in_array($model, PlatformModels::LIST)) continue;
        $this->assertContains(BelongsToAcademy::class, class_uses_recursive($model), $model);
    }
}

/** @test */
public function webhook_with_wrong_secret_is_rejected(): void
{
    $this->postJson("/webhook/{$bot->public_id}", $update, [
        'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
    ])->assertUnauthorized();
}
```

این تست‌ها در CI **blocking** هستند: اگر رد شوند، merge ممکن نیست.

---

## ۸. عملیات مستاجر

| عملیات | رفتار |
|--------|-------|
| **ایجاد** | Job زنجیره‌ای: ساخت رکورد → Seed نقش‌ها و Permissionها → Seed منوی پیش‌فرض → Seed Promptهای پیش‌فرض → ساخت Owner → ارسال ایمیل دعوت |
| **تعلیق (Suspend)** | ربات پاسخ نمی‌دهد (پیام «سرویس موقتاً غیرفعال است»)، پنل read-only، داده دست‌نخورده |
| **حذف نرم** | `deleted_at` + حذف Webhook تلگرام + توقف همه Jobها |
| **حذف قطعی** | بعد از ۳۰ روز نگهداری: حذف ردیف‌های DB (ترتیب FK) + حذف پوشه S3 + حذف کلیدهای Redis + یک رکورد tombstone در `platform_audit_logs` |
| **خروجی داده (Export)** | ZIP شامل CSV همه جداول مستاجر + فایل‌های S3 — الزام GDPR/حق قابلیت انتقال |
| **کلون (Clone)** | کپی منو، Flow، Prompt، Rubric و بانک سوال به آموزشگاه جدید — برای onboarding سریع |

---

## ۹. Noisy Neighbor — جلوگیری از تداخل بار

در DB مشترک، یک آموزشگاه پرترافیک می‌تواند بقیه را کند کند. کنترل‌ها:

1. **Rate Limit به‌ازای مستاجر** روی Webhook و API (`throttle:academy`)
2. **سهمیه AI ماهانه** — با رسیدن به سقف، صف `ai-scoring` آن مستاجر متوقف می‌شود نه کل صف
3. **صف‌های وزنی** — Horizon با `balance: auto`؛ Jobهای سنگین یک مستاجر با tag جدا قابل شناسایی و throttle هستند
4. **Query Timeout** — `max_execution_time` روی اتصال read
5. **مانیتورینگ per-tenant** — متریک‌های Prometheus با label `academy_id` برای شناسایی زودهنگام
