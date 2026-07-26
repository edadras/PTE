# 08 — API و یکپارچه‌سازی

API فقط یک قابلیت جانبی نیست — **پایه فاز ۴ (وب) و فاز ۵ (اپ موبایل)** است. اگر از روز اول ساخته نشود، آن فازها به بازنویسی کامل تبدیل می‌شوند.

---

## ۱. سه سطح API

```
┌──────────────────────────────────────────────────────────────┐
│ Platform API      /api/platform/v1/*                         │
│ فقط Super Admin · مدیریت آموزشگاه‌ها، پلن‌ها، آمار کل          │
├──────────────────────────────────────────────────────────────┤
│ Academy API       /api/v1/*                                  │
│ برای پنل، یکپارچه‌سازی با CRM/LMS آموزشگاه                     │
│ احراز هویت: API Key یا OAuth                                  │
├──────────────────────────────────────────────────────────────┤
│ Student API       /api/student/v1/*                          │
│ برای وب‌اپ، اپ موبایل، Telegram Mini App                      │
│ احراز هویت: JWT (Sanctum)                                     │
└──────────────────────────────────────────────────────────────┘
```

---

## ۲. احراز هویت

### Academy API — API Key
```http
GET /api/v1/students
Authorization: Bearer pte_live_ak_01HZXK3M9...
```
کلید با SHA-256 هش می‌شود. Scopeها: `students:read`, `students:write`, `exams:read`, `scores:read`, `questions:write`, `reports:read`, `telegram:send`.

### Student API — JWT
سه مسیر ورود:
1. **Telegram Mini App** — اعتبارسنجی `initData` با HMAC → صدور JWT
2. **کد یکبار مصرف** — دانشجو در ربات «ورود به وب» می‌زند → کد ۶ رقمی → وارد کردن در سایت
3. **ایمیل/رمز** (فاز ۴) — برای دانشجویانی که مستقیم در وب ثبت‌نام کرده‌اند

JWT claims:
```json
{ "sub": 4471, "aid": 12, "typ": "student", "scp": ["practice","exam"], "exp": … }
```
`aid` (academy_id) در توکن است اما **هرگز به آن اعتماد نمی‌شود** — Tenant از Host یا از رکورد دانشجو در DB بازخوانی و تطبیق داده می‌شود.

---

## ۳. نمونه Endpointها

### Academy API

```
GET    /api/v1/students                 ?status=&level=&search=&page=
POST   /api/v1/students
GET    /api/v1/students/{id}
PATCH  /api/v1/students/{id}
DELETE /api/v1/students/{id}
POST   /api/v1/students/import          (CSV/Excel → job)
GET    /api/v1/students/{id}/progress
GET    /api/v1/students/{id}/scores

GET    /api/v1/courses
POST   /api/v1/courses

GET    /api/v1/question-banks
GET    /api/v1/questions                ?module=&type=&difficulty=&status=
POST   /api/v1/questions
POST   /api/v1/questions/bulk-import

GET    /api/v1/exams
POST   /api/v1/exams
POST   /api/v1/exams/{id}/publish
GET    /api/v1/exams/{id}/sessions
GET    /api/v1/exams/{id}/results       ?format=json|xlsx

GET    /api/v1/scores                   ?from=&to=&module=
GET    /api/v1/reports/dashboard
GET    /api/v1/reports/ai-usage

POST   /api/v1/telegram/send            {student_id, text, buttons}
POST   /api/v1/telegram/broadcast       {audience, content}
GET    /api/v1/telegram/bot             وضعیت و سلامت

GET    /api/v1/ai/usage                 ?period=2026-07
```

### Student API

```
POST   /api/student/v1/auth/telegram    {init_data}
POST   /api/student/v1/auth/otp
GET    /api/student/v1/me
PATCH  /api/student/v1/me

GET    /api/student/v1/modules          ماژول‌های فعال آموزشگاه
POST   /api/student/v1/practice/start   {module, type, count}
GET    /api/student/v1/practice/{id}
POST   /api/student/v1/practice/{id}/answer
POST   /api/student/v1/practice/{id}/finish

GET    /api/student/v1/exams
POST   /api/student/v1/exams/{id}/start
GET    /api/student/v1/exam-sessions/{id}
POST   /api/student/v1/exam-sessions/{id}/answer
POST   /api/student/v1/exam-sessions/{id}/submit

GET    /api/student/v1/scores
GET    /api/student/v1/progress
GET    /api/student/v1/brand            برند برای رندر UI

POST   /api/student/v1/media/upload     آپلود مستقیم صوت (pre-signed)
POST   /api/student/v1/support/tickets
```

---

## ۴. قراردادهای طراحی

### پاسخ استاندارد
```jsonc
// موفق
{ "data": {...}, "meta": { "request_id": "01HZX..." } }

// لیست
{ "data": [...],
  "meta": { "current_page":1, "per_page":25, "total":142, "last_page":6 },
  "links": { "next": "...", "prev": null } }

// خطا
{ "error": {
    "code": "QUOTA_EXCEEDED",
    "message": "سهمیه ماهانه هوش مصنوعی به پایان رسیده است.",
    "details": { "metric": "ai_requests", "limit": 5000, "used": 5000 },
    "request_id": "01HZX..."
} }
```

کدهای خطا **رشته‌ای و پایدار** هستند (نه فقط HTTP status)، تا کلاینت موبایل بتواند بدون parse کردن متن، رفتار مناسب نشان دهد.

### نسخه‌بندی
مسیر (`/v1/`). نسخه قدیمی حداقل ۶ ماه پس از انتشار نسخه جدید پشتیبانی می‌شود. تغییرات شکننده فقط در نسخه جدید.

### Rate Limiting
```
Academy API   → 600 req/min به‌ازای API Key
Student API   → 120 req/min به‌ازای دانشجو
Media upload  → 20 req/min
```
هدرهای `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` همیشه برگردانده می‌شوند.

### Idempotency
برای POSTهای مالی و ایجاد جلسه:
```http
Idempotency-Key: 01HZXK3M9...
```
پاسخ اولیه ۲۴ ساعت کش می‌شود.

---

## ۵. Webhook خروجی

آموزشگاه می‌تواند رویدادها را به سیستم خودش (CRM، LMS، Google Sheets) بفرستد.

```
student.created            student.updated
practice.completed         exam.submitted        exam.scored
score.published            payment.succeeded     payment.failed
subscription.expiring      subscription.expired
support.ticket.created
```

```http
POST https://crm.englishfirst.ir/hooks/pte
X-PTE-Event: exam.scored
X-PTE-Delivery: 01HZXK...
X-PTE-Signature: sha256=abc123...        ← HMAC(secret, body)
```

- تلاش مجدد: ۵ بار با backoff نمایی (۱m, ۵m, ۳۰m, ۲h, ۶h)
- پس از ۲۰ شکست متوالی → غیرفعال‌سازی خودکار + اطلاع به Owner
- **SSRF Guard:** فقط HTTPS، رد کردن IPهای خصوصی (10.x، 172.16-31.x، 192.168.x، 127.x، ::1) و metadata endpointها (169.254.169.254)
- Timeout ۵ ثانیه

---

## ۶. آمادگی برای فازهای بعد

### وب‌اپ (فاز ۴)
Vue 3 + Tailwind، PWA. کاملاً روی `Student API`. برند از `/brand` خوانده و به CSS Variables تزریق می‌شود. دامنه: `app.{academy-domain}`.

قابلیت‌هایی که ذاتاً در وب بهترند و انگیزه ساخت فاز ۴ هستند:
- Reading با متن بلند و Drag & Drop واقعی
- Writing با ویرایشگر و شمارنده کلمه زنده
- داشبورد پیشرفت با نمودار
- مرور کارنامه‌های قدیمی

### اپ موبایل (فاز ۵)
Flutter، همان `Student API`. مزیت‌های اختصاصی موبایل:
- ضبط صدای باکیفیت‌تر با کنترل کامل
- حالت آفلاین برای تمرین‌های متنی + همگام‌سازی
- Push Notification
- ویجت «تمرین روز»

**آنچه باید از فاز ۱ رعایت شود تا فاز ۵ ممکن باشد:**
- هیچ منطق کسب‌وکاری داخل Controller تلگرام نباشد
- همه پاسخ‌ها JSON-serializable باشند
- آپلود مدیا از طریق pre-signed URL (نه multipart از سرور)
- احراز هویت stateless (JWT) نه session

---

## ۷. ابزارها

- **مستندات:** OpenAPI 3.1 تولیدشده از کد (`scramble` یا `l5-swagger`) + صفحه تعاملی در پنل آموزشگاه
- **Postman Collection** قابل دانلود از پنل با API Key پیش‌پرشده
- **Sandbox:** آموزشگاه می‌تواند یک محیط آزمایشی با داده ساختگی و ربات تست بسازد
- **SDK:** فاز ۵ — PHP و JS
