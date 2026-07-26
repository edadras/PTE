# 02 — نقش‌ها و کنترل دسترسی (RBAC)

## ۱. مدل دسترسی

```
User ──< AcademyUserRole >── Role ──< RolePermission >── Permission
              │
              └── academy_id  (نقش همیشه در یک آموزشگاه معنی دارد)
```

**قاعده بنیادین:** نقش بدون آموزشگاه معنا ندارد. تنها استثنا `users.is_super_admin` است که یک پرچم سراسری روی جدول کاربران است، نه یک نقش مستاجری.

### چرا `is_super_admin` جدا از سیستم نقش‌هاست؟
اگر Super Admin هم یک ردیف در `academy_user_roles` باشد، هر باگی در Tenant Scope می‌تواند به ارتقای دسترسی منجر شود. جدا نگه داشتنش یعنی مسیر کد آن کاملاً متفاوت و قابل ممیزی است.

---

## ۲. نقش‌های سیستمی

هر آموزشگاه هنگام ساخت، این ۵ نقش را به‌صورت پیش‌فرض دریافت می‌کند (`is_system = true`، غیرقابل حذف اما قابل کپی).

### 🔴 Super Admin — پنل مرکزی شرکت
خارج از سیستم مستاجری. دسترسی کامل به پلتفرم.

| قابلیت | Permission |
|--------|-----------|
| ایجاد / حذف / تعلیق آموزشگاه | `platform.academies.manage` |
| مشاهده آمار همه آموزشگاه‌ها | `platform.analytics.view` |
| مدیریت پلن‌ها و قیمت‌گذاری | `platform.plans.manage` |
| مدیریت پرداخت و صورتحساب | `platform.billing.manage` |
| مشاهده لاگ‌های سیستم | `platform.logs.view` |
| مدیریت سرور و صف‌ها | `platform.infra.manage` |
| مدیریت مدل‌های AI و کلیدهای پلتفرم | `platform.ai.manage` |
| ورود موقت به آموزشگاه (Impersonate) | `platform.impersonate` |
| مدیریت ماژول‌ها (PTE/IELTS/…) | `platform.modules.manage` |

> **Impersonate:** ورود Super Admin به پنل آموزشگاه برای پشتیبانی، با بنر قرمز دائمی، مدت محدود (۳۰ دقیقه)، ثبت اجباری در `platform_audit_logs` و اطلاع‌رسانی به Owner. بدون این سه شرط، فعال نشود.

---

### 🟠 Academy Owner — مالک آموزشگاه
دسترسی کامل **درون آموزشگاه خودش**.

```
✅ برند: لوگو، رنگ، نام، دامنه، متن خوش‌آمد، فوتر
✅ ربات تلگرام: Token، منو، Flow، پیام‌ها
✅ کاربران: دعوت/حذف Manager، Teacher، Support
✅ نقش‌ها: ساخت نقش سفارشی از روی Permissionهای مجاز
✅ آزمون، تمرین، بانک سوال
✅ AI: انتخاب Provider، مدل، Prompt، وزن نمره‌دهی
✅ مالی: مشاهده و ارتقای پلن، فاکتورها، درگاه پرداخت
✅ گزارش‌ها و خروجی Excel
❌ نمی‌تواند: دیدن آموزشگاه دیگر، تغییر پلن دیگران، دسترسی به زیرساخت
```

---

### 🟡 Academy Manager — مدیر آموزشی
دسترسی محدود عملیاتی. Owner می‌تواند دقیقاً مشخص کند کدام Permission را دارد.

پیش‌فرض:
```
✅ مدیریت دانشجو (ثبت‌نام، ویرایش، غیرفعال‌سازی)
✅ ایجاد و ویرایش آزمون
✅ مشاهده نتایج و گزارش‌ها
✅ مدیریت بانک سوال
✅ ارسال اعلان به دانشجویان
❌ برند و دامنه
❌ Bot Token
❌ تنظیمات AI و Prompt
❌ مالی و پلن
❌ حذف کاربران Staff
```

---

### 🟢 Teacher — مدرس
```
✅ مشاهده دانشجویان کلاس‌های خودش
✅ بررسی پاسخ‌های ارسالی
✅ تصحیح دستی و بازنویسی نمره AI  ← با ثبت دلیل، Override قابل ممیزی
✅ ارسال تمرین و تکلیف
✅ ارسال بازخورد متنی/صوتی
❌ ساخت آزمون رسمی (مگر Owner اجازه دهد)
❌ مشاهده اطلاعات مالی
❌ مشاهده دانشجویان سایر مدرس‌ها
```

**Scope دوگانه:** Teacher علاوه بر `academy_id`، محدودیت دوم روی «کلاس‌های تخصیص‌یافته» دارد:
```php
Student::query()
    ->whereHas('classGroups', fn ($q) => $q->whereIn('id', $teacher->assignedClassGroupIds()))
```
این محدودیت با یک Query Scope اضافی (`ScopedToTeacher`) اعمال می‌شود، نه با فراموش‌شدنی‌های دستی.

---

### 🔵 Support — پشتیبانی
```
✅ مشاهده و پاسخ به تیکت‌ها
✅ مشاهده تاریخچه پیام‌های ربات یک دانشجو (برای عیب‌یابی)
✅ ارسال پیام به دانشجو
✅ مشاهده وضعیت اشتراک دانشجو (فقط خواندن)
❌ تغییر نمره
❌ تغییر تنظیمات
❌ مشاهده شماره کارت/اطلاعات پرداخت کامل
```

---

### ⚪ Student — زبان‌آموز
کاربر نهایی. **در سیستم `users` نیست** — رکورد جداگانه در `students`.

```
کانال دسترسی: Telegram (فاز ۱) → Web PWA (فاز ۴) → Mobile (فاز ۵)

✅ انجام تمرین
✅ شرکت در آزمون
✅ مشاهده نتایج و کارنامه خودش
✅ مشاهده پیشرفت
✅ خرید دوره/اشتراک
✅ ارسال تیکت پشتیبانی
❌ هیچ دسترسی به پنل مدیریت
```

---

## ۳. فهرست کامل Permissionها

Permissionها با الگوی `{domain}.{resource}.{action}` نام‌گذاری می‌شوند.

### مستاجری (Academy-scoped)

```
# برند و تنظیمات
academy.brand.view            academy.brand.update
academy.settings.view         academy.settings.update
academy.domain.manage

# تلگرام
telegram.bot.view             telegram.bot.update        ← شامل Token
telegram.menu.view            telegram.menu.update
telegram.flow.view            telegram.flow.update       telegram.flow.publish
telegram.broadcast.send

# کاربران و نقش‌ها
users.staff.view              users.staff.invite         users.staff.remove
users.roles.view              users.roles.manage
students.view                 students.create            students.update
students.delete               students.import            students.export

# محتوا
courses.view                  courses.manage
lessons.view                  lessons.manage
questions.view                questions.create           questions.update
questions.delete              questions.import           questions.approve
question_banks.manage

# ارزیابی
exams.view                    exams.create               exams.update
exams.delete                  exams.publish              exams.schedule
practice.configure
answers.view                  answers.grade              answers.override_ai_score
scores.view                   scores.publish

# هوش مصنوعی
ai.settings.view              ai.settings.update
ai.prompts.view               ai.prompts.update          ai.prompts.publish
ai.rubrics.manage
ai.usage.view

# گزارش
reports.dashboard.view        reports.detailed.view
reports.export_excel          reports.financial.view

# مالی
billing.view                  billing.manage             billing.payment_methods
subscriptions.students.manage

# پشتیبانی
support.tickets.view          support.tickets.reply      support.tickets.close
support.conversations.view

# ماژول‌ها
modules.view                  modules.toggle

# سیستم
audit.view                    api_keys.manage            webhooks.manage
```

### سطح پلتفرم (Super Admin)

```
platform.academies.manage     platform.analytics.view    platform.plans.manage
platform.billing.manage       platform.logs.view         platform.infra.manage
platform.ai.manage            platform.impersonate       platform.modules.manage
platform.view_all
```

---

## ۴. ماتریس دسترسی پیش‌فرض

| Permission | Owner | Manager | Teacher | Support |
|-----------|:-----:|:-------:|:-------:|:-------:|
| academy.brand.update | ✅ | ❌ | ❌ | ❌ |
| academy.domain.manage | ✅ | ❌ | ❌ | ❌ |
| telegram.bot.update | ✅ | ❌ | ❌ | ❌ |
| telegram.menu.update | ✅ | ⚙️ | ❌ | ❌ |
| telegram.flow.publish | ✅ | ⚙️ | ❌ | ❌ |
| telegram.broadcast.send | ✅ | ✅ | ❌ | ⚙️ |
| users.staff.invite | ✅ | ❌ | ❌ | ❌ |
| users.roles.manage | ✅ | ❌ | ❌ | ❌ |
| students.view | ✅ | ✅ | 🔸 | ✅ |
| students.create | ✅ | ✅ | ❌ | ❌ |
| students.delete | ✅ | ⚙️ | ❌ | ❌ |
| students.export | ✅ | ✅ | ❌ | ❌ |
| questions.manage | ✅ | ✅ | ⚙️ | ❌ |
| questions.approve | ✅ | ✅ | ❌ | ❌ |
| exams.create | ✅ | ✅ | ⚙️ | ❌ |
| exams.publish | ✅ | ✅ | ❌ | ❌ |
| answers.grade | ✅ | ✅ | ✅ | ❌ |
| answers.override_ai_score | ✅ | ✅ | ✅ | ❌ |
| ai.settings.update | ✅ | ❌ | ❌ | ❌ |
| ai.prompts.update | ✅ | ⚙️ | ❌ | ❌ |
| ai.usage.view | ✅ | ⚙️ | ❌ | ❌ |
| reports.dashboard.view | ✅ | ✅ | 🔸 | ⚙️ |
| reports.export_excel | ✅ | ✅ | ❌ | ❌ |
| reports.financial.view | ✅ | ❌ | ❌ | ❌ |
| billing.manage | ✅ | ❌ | ❌ | ❌ |
| support.tickets.reply | ✅ | ✅ | ❌ | ✅ |
| modules.toggle | ✅ | ❌ | ❌ | ❌ |
| audit.view | ✅ | ❌ | ❌ | ❌ |

```
✅ پیش‌فرض فعال      ❌ پیش‌فرض غیرفعال
⚙️ قابل فعال‌سازی توسط Owner      🔸 محدود به دامنه خودش (کلاس‌های مدرس)
```

---

## ۵. نقش سفارشی

Owner می‌تواند نقش بسازد، مثلاً «مسئول محتوا»:

```
نام: Content Editor
رنگ: بنفش
Permissionها:
  ☑ questions.view      ☑ questions.create     ☑ questions.update
  ☑ question_banks.manage
  ☑ courses.manage
  ☐ students.view       ☐ exams.publish
```

محدودیت‌ها:
- Owner نمی‌تواند Permission ای بدهد که خودش ندارد (**Privilege Escalation Guard**)
- Permissionهای سطح `platform.*` هرگز در لیست انتخاب آموزشگاه ظاهر نمی‌شوند
- تعداد نقش‌های سفارشی به پلن وابسته است (Starter: ۰، Professional: ۵، Enterprise: نامحدود)

---

## ۶. پیاده‌سازی

پکیج پیشنهادی: **spatie/laravel-permission** با Team Mode فعال، که `team_id` آن به `academy_id` نگاشت می‌شود.

```php
// config/permission.php
'teams' => true,
'column_names' => ['team_foreign_key' => 'academy_id'],
```

```php
// در Middleware بعد از Tenant Resolution
app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::id());
```

استفاده:
```php
// Controller / Filament Resource
Gate::authorize('exams.create');

// Blade / Livewire
@can('reports.financial.view') ... @endcan

// Policy با محدودیت مدرس
class AnswerPolicy
{
    public function grade(User $user, Answer $answer): bool
    {
        if (! $user->can('answers.grade')) return false;
        if ($user->hasRole('teacher')) {
            return $answer->student->isTaughtBy($user);
        }
        return true;
    }
}
```

**تست اجباری:**
```php
/** @test */
public function manager_cannot_read_bot_token(): void
{
    TenantContext::set($academy);
    $this->actingAs($manager)
         ->get(route('telegram.settings'))
         ->assertForbidden();
}

/** @test */
public function owner_cannot_grant_permission_they_lack(): void
{
    $this->actingAs($owner)
         ->post(route('roles.store'), ['permissions' => ['platform.impersonate']])
         ->assertUnprocessable();
}
```

---

## ۷. Audit Trail

هر اقدام حساس در `activity_logs` (مستاجری) و اقدامات Super Admin در `platform_audit_logs` ثبت می‌شود:

```
academy_id · actor_type · actor_id · action · subject_type · subject_id
old_values(json) · new_values(json) · ip · user_agent · created_at
```

اقدامات با ثبت اجباری:
- تغییر Bot Token
- تغییر یا انتشار Prompt و Rubric
- Override نمره AI توسط مدرس (با فیلد `reason` اجباری)
- دعوت/حذف کاربر Staff، تغییر نقش
- حذف دانشجو یا آزمون
- خروجی Excel از داده دانشجویان
- Impersonate توسط Super Admin
- تغییر پلن یا اطلاعات پرداخت

نگهداری: ۱۲ ماه در MySQL (پارتیشن ماهانه)، سپس آرشیو در S3.
