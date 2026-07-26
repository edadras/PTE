# CONVENTIONS — قراردادهای کدنویسی پروژه

هر کسی (انسان یا ایجنت) که روی این مخزن کار می‌کند باید این سند را رعایت کند.
مرجع طراحی: `docs/00-overview.md` تا `docs/13-adr.md`.

---

## ۱. زبان و سبک

- PHP 8.4 · Laravel 12 · `declare(strict_types=1);` در **هر** فایل
- Type hint کامل روی همه پارامترها، بازگشتی‌ها و پراپرتی‌ها
- `final` برای کلاس‌هایی که قرار نیست ارث‌بری شوند (Actionها، سرویس‌ها، DTOها)
- `readonly` برای DTOها
- Enum به‌جای رشته جادویی
- کامنت فقط برای **چرایی**، نه چگونگی. کد بدیهی کامنت نمی‌خواهد.
- نام‌ها انگلیسی؛ متن‌های کاربر از فایل زبان (`lang/fa`, `lang/en`)

---

## ۲. ساختار پوشه

```
app/Domain/{Context}/
    Models/         مدل‌های Eloquent
    Actions/        یک کلاس، یک کار، متد handle()
    Services/       سرویس‌های stateful یا هماهنگ‌کننده
    Data/           DTOها (readonly)
    Enums/
    Events/  Listeners/  Jobs/
    Exceptions/
    Concerns/       traitها
    Contracts/      اینترفیس‌ها
```

Contextها: `Tenancy` · `Identity` · `Telegram` · `Learning` · `Assessment` · `AI` · `Commerce` · `Support` · `Reporting` · `Shared`

---

## ۳. قواعد غیرقابل مذاکره چندمستاجری

1. **هر مدل مستاجری باید `BelongsToAcademy` داشته باشد.**
   ```php
   use App\Domain\Tenancy\Concerns\BelongsToAcademy;

   final class Question extends Model
   {
       use BelongsToAcademy;
   }
   ```
2. هرگز دستی `where('academy_id', ...)` ننویسید — Global Scope کار را می‌کند.
3. هرگز `academy_id` را دستی هنگام ساخت پر نکنید — trait این کار را می‌کند.
4. برای عبور آگاهانه: `Model::withoutTenantScope()` (خودش Gate چک می‌کند).
5. دسترسی مستقیم به Redis فقط با `TenantKey::make(...)`.
6. فایل‌ها فقط روی دیسک `tenant` (root آن خودکار به آموزشگاه فعال ست می‌شود).
7. هر Job مستاجری از `TenantAwareJob` ارث می‌برد و `academyId` می‌گیرد.

### جداول غیرمستاجری (بدون `academy_id`)
`users` · `plans` · `modules` · `permissions` · `ai_models` · `academies` · `academy_domains` · `platform_audit_logs` · جداول سیستمی Laravel

---

## ۴. Migrationها

- نام‌گذاری با پیشوند ثابت هر Context تا برخورد نداشته باشیم:

| بازه | Context |
|------|---------|
| `2026_01_01_0001xx` | Tenancy |
| `2026_01_01_0002xx` | Identity / RBAC |
| `2026_01_01_0003xx` | Students |
| `2026_01_01_0004xx` | Telegram |
| `2026_01_01_0005xx` | Learning |
| `2026_01_01_0006xx` | Assessment |
| `2026_01_01_0007xx` | AI |
| `2026_01_01_0008xx` | Commerce |
| `2026_01_01_0009xx` | Ops / Support / Reporting |

- `2026_01_01_000100_create_academies_table.php` از قبل وجود دارد — دوباره نسازید.
- **قابل حمل بمانید:** هدف تولید MySQL 8 است، اما تست‌ها روی SQLite اجرا می‌شوند.
  از `DB::statement` با DDL مخصوص MySQL، پارتیشن‌بندی و `fulltext` در migration
  استفاده نکنید. پارتیشن‌بندی در سند ۰۷ توضیح داده شده و کار DBA در محیط تولید است.
- ستون `academy_id`:
  ```php
  $table->foreignId('academy_id')->constrained('academies')->cascadeOnDelete();
  ```
- **هر ایندکس مرکب با `academy_id` شروع می‌شود.**
- طول ایندکس روی رشته: حداکثر `string('x', 191)` اگر ایندکس می‌شود.
- برای JSON از `$table->json(...)` استفاده کنید (روی SQLite به TEXT نگاشت می‌شود).

---

## ۵. مدل‌ها

```php
final class Question extends Model
{
    use BelongsToAcademy;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'status' => QuestionStatus::class,
        ];
    }
}
```

- `protected $guarded = ['id'];` (نه `$fillable`)
- فیلدهای حساس در `$hidden` **و** `encrypted` cast
- متد `casts()` (سبک Laravel 11+)، نه پراپرتی `$casts`

---

## ۶. Actionها

```php
final class SubmitAnswer
{
    public function __construct(private readonly ScoringDispatcher $scoring) {}

    public function handle(SubmitAnswerData $data): Answer { /* ... */ }
}
```

منطق کسب‌وکار **فقط** در Action یا Service. هرگز در Controller، Filament Resource
یا Job. هر سه فقط صدا می‌زنند. این تنها چیزی است که اجازه می‌دهد ربات تلگرام،
REST API و پنل روی یک منطق مشترک کار کنند (سند ۰۸).

---

## ۷. Enumها

همیشه `string` backed:

```php
enum QuestionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return __("question.status.{$this->value}");
    }
}
```

---

## ۸. تست

- PHPUnit/Pest، پایگاه داده SQLite در حافظه
- هر Context باید تست Feature داشته باشد
- تست‌های جداسازی مستاجر **blocking** هستند
- Factory برای هر مدل

---

## ۹. چیزهایی که از قبل ساخته شده — دوباره نسازید

```
app/Domain/Tenancy/TenantContext.php
app/Domain/Tenancy/Concerns/BelongsToAcademy.php
app/Domain/Tenancy/Exceptions/TenantNotResolvedException.php
app/Domain/Shared/Support/TenantKey.php
app/Domain/Shared/Jobs/TenantAwareJob.php
app/Domain/Shared/Jobs/Middleware/SetTenantContext.php
database/migrations/2026_01_01_000100_create_academies_table.php
config/pte.php
```

`App\Domain\Tenancy\Models\Academy` توسط Context مربوط به Tenancy ساخته می‌شود و
باید متد `preferredLocale(): ?string` و رابطه‌های `settings`, `brand`, `domains`,
`modules` را داشته باشد (TenantContext روی این‌ها حساب می‌کند).

---

## ۱۰. مالکیت فایل هنگام کار موازی

هر ایجنت **فقط** داخل مسیرهای تخصیص‌یافته خودش فایل می‌سازد یا ویرایش می‌کند.
فایل‌های مشترک (`composer.json`, `bootstrap/app.php`, `routes/web.php`,
`config/app.php`) را هیچ ایجنتی مستقیم ویرایش نمی‌کند — تغییرات لازم را در
گزارش نهایی خود اعلام می‌کند تا هماهنگ‌کننده اعمال کند.
