# 07 — طرح دیتابیس

MySQL 8 · InnoDB · `utf8mb4_unicode_ci` · همه کلیدها `BIGINT UNSIGNED`

---

## ۱. قواعد عمومی

1. هر جدول مستاجری ستون `academy_id BIGINT UNSIGNED NOT NULL` دارد.
2. **هر ایندکس مرکب با `academy_id` شروع می‌شود.** بدون این، با رشد داده، کوئری‌ها روی کل جدول اسکن می‌کنند.
3. کلید خارجی `academy_id` با `ON DELETE CASCADE`.
4. Soft Delete برای موجودیت‌های تجاری (academy, student, exam, question)، Hard Delete برای لاگ‌ها و داده حجیم.
5. زمان‌ها UTC ذخیره می‌شوند؛ تبدیل به timezone آموزشگاه در لایه نمایش.
6. فیلدهای حساس (توکن، کلید API) با Laravel `encrypted` cast.
7. جداول پرحجم لاگ‌مانند: پارتیشن ماهانه روی `created_at`.
8. JSON برای داده‌های با شکل متغیر (config, content, breakdown) — اما هر فیلدی که روی آن فیلتر می‌شود، ستون واقعی است.

---

## ۲. Tenancy

```sql
academies
  id · slug(unique) · name · legal_name · status(active|suspended|deleted)
  plan_id · trial_ends_at · timezone · country · database_connection(null)
  owner_user_id · created_at · updated_at · deleted_at
  INDEX (status), INDEX (plan_id)

academy_settings
  academy_id(unique FK) · locale · currency
  practice_config json · exam_config json · notification_config json
  data_retention_days · features json · updated_at

academy_brands
  academy_id(unique FK) · display_name · short_name · tagline
  logo_light_path · logo_dark_path · icon_path · welcome_image_path
  primary_color · secondary_color · accent_color · success_color · danger_color
  dark_mode · font_family · welcome_text · footer_text
  website_url · instagram_url · telegram_channel · whatsapp
  support_phone · support_email · address · working_hours
  default_locale · supported_locales json · terms_url · privacy_url · custom_css

academy_domains
  id · academy_id · hostname(unique) · type(subdomain|custom)
  is_primary · verified_at · verification_token · ssl_status
  INDEX (hostname)          ← قبل از Tenant Resolution خوانده می‌شود

academy_modules
  id · academy_id · module_key · is_enabled · settings json · enabled_at
  UNIQUE (academy_id, module_key)

modules                                  -- غیرمستاجری
  key(pk) · name · version · description · icon
  requires_plan · question_types json · config_schema json · is_beta
```

---

## ۳. Identity و RBAC

```sql
users                                    -- سراسری (بدون academy_id)
  id · name · email(unique) · phone · password
  is_super_admin · locale · avatar_path
  two_factor_secret · two_factor_confirmed_at
  last_login_at · email_verified_at · created_at · deleted_at

roles
  id · academy_id(nullable) · name · display_name · color
  is_system · level · created_at
  UNIQUE (academy_id, name)              -- academy_id = NULL برای نقش‌های پلتفرم

permissions                              -- غیرمستاجری
  id · name(unique) · group · display_name · description · scope(academy|platform)

role_permissions
  role_id · permission_id
  PRIMARY KEY (role_id, permission_id)

academy_user_roles
  id · academy_id · user_id · role_id
  status(active|invited|suspended) · invited_by · joined_at
  UNIQUE (academy_id, user_id, role_id)
  INDEX (academy_id, user_id), INDEX (user_id)

teacher_class_groups                     -- محدودسازی دامنه مدرس
  academy_id · user_id · class_group_id
```

---

## ۴. دانشجو

```sql
students
  id · academy_id · student_code · first_name · last_name
  email · phone · locale · level(cefr) · target_score
  status(active|inactive|blocked) · source(telegram|web|import|api)
  subscription_status · subscription_expires_at
  last_active_at · registered_at · created_at · deleted_at
  UNIQUE (academy_id, student_code)
  INDEX (academy_id, status), INDEX (academy_id, last_active_at)

telegram_identities
  id · academy_id · student_id · telegram_user_id · chat_id
  username · first_name · last_name · language_code
  is_blocked · blocked_at · linked_at
  UNIQUE (academy_id, telegram_user_id)
  INDEX (academy_id, chat_id)

class_groups
  id · academy_id · name · course_id · teacher_id
  starts_at · ends_at · capacity · status

class_group_students
  class_group_id · student_id · enrolled_at

student_acquisitions                     -- منبع جذب (deep link / کمپین)
  id · academy_id · student_id · source · campaign_id · referrer_student_id
  payload · created_at

student_progress                          -- Materialized برای سرعت داشبورد
  academy_id · student_id · module_key · question_type
  attempts · avg_score · best_score · last_score
  streak_days · total_time_seconds · updated_at
  PRIMARY KEY (academy_id, student_id, module_key, question_type)
```

---

## ۵. تلگرام

```sql
telegram_bots
  id · academy_id · public_id(unique ULID) · token(encrypted) · token_last4
  bot_user_id · username · webhook_secret(encrypted) · webhook_registered_at
  is_active · health_status · last_error · last_error_at
  payments_provider_token(encrypted) · created_at
  UNIQUE (academy_id)                    -- فعلاً یک ربات به ازای هر آموزشگاه
  INDEX (public_id)

telegram_menus
  id · academy_id · name · type(main|inline|command|persistent)
  status(draft|published) · version · is_active · published_at

telegram_menu_items
  id · academy_id · menu_id · parent_id(nullable)
  label · icon · action_type · action_payload json
  visibility_rule json · row · column · sort_order · is_enabled
  INDEX (academy_id, menu_id, sort_order)

telegram_flows
  id · academy_id · name · description · trigger_type · trigger_config json
  status(draft|published|archived) · version · published_at · created_by

telegram_flow_nodes
  id · academy_id · flow_id · node_key · type
  config json · position_x · position_y
  UNIQUE (flow_id, node_key)

telegram_flow_edges
  id · academy_id · flow_id · from_node · to_node · condition json · label

telegram_updates                          -- خام، برای عیب‌یابی و بازپخش
  id · academy_id · telegram_bot_id · update_id · type
  payload json · processed_at · error · created_at
  UNIQUE (telegram_bot_id, update_id)     -- Idempotency
  INDEX (academy_id, created_at)
  PARTITION BY RANGE (ماهانه) · نگهداری ۳۰ روز

telegram_messages                         -- تاریخچه گفتگو (برای پشتیبانی)
  id · academy_id · student_id · chat_id · direction(in|out)
  message_type · content · telegram_message_id · status · created_at
  INDEX (academy_id, student_id, created_at)
  PARTITION BY RANGE (ماهانه) · نگهداری ۹۰ روز

broadcasts
  id · academy_id · title · content json · audience_filter json
  scheduled_at · started_at · finished_at
  total · sent · failed · blocked · status · created_by
```

---

## ۶. محتوا و بانک سوال

```sql
courses
  id · academy_id · title · slug · description · module_key
  cover_path · price · currency · duration_days
  status(draft|published) · sort_order · deleted_at

lessons
  id · academy_id · course_id · title · content json
  media_path · duration_minutes · sort_order · is_free · status

question_banks
  id · academy_id · name · module_key · description · is_default
  question_count(denormalized) · deleted_at

questions
  id · academy_id · bank_id · module_key · type · difficulty
  difficulty_index decimal(3,2) · title · content json
  correct_answer json · metadata json · tags json
  status(draft|pending_review|approved|published|rejected)
  usage_count · avg_score · created_by · approved_by · deleted_at
  INDEX (academy_id, module_key, type, status)
  INDEX (academy_id, bank_id, status)
  INDEX (academy_id, type, difficulty_index)

question_options
  id · academy_id · question_id · option_key · text
  is_correct · sort_order · explanation

question_media
  id · academy_id · question_id · kind(audio|image|video)
  s3_path · mime · size_bytes · duration_ms · transcript
  telegram_file_id · telegram_bot_id · cached_at
  INDEX (question_id)
```

---

## ۷. ارزیابی

```sql
exams
  id · academy_id · title · description · duration_minutes
  total_score · passing_score · rules json · availability json
  status(draft|published|archived) · published_at · created_by · deleted_at
  INDEX (academy_id, status)

exam_sections
  id · academy_id · exam_id · title · module_key
  duration_minutes · score · sort_order
  selection_mode(manual|random|pool) · selection_config json

exam_questions
  id · academy_id · exam_section_id · question_id · sort_order · score

practice_sessions
  id · academy_id · student_id · module_key · question_type
  status(in_progress|completed|abandoned)
  total_questions · answered · total_score · max_score
  started_at · completed_at · duration_seconds
  INDEX (academy_id, student_id, created_at)
  INDEX (academy_id, module_key, created_at)

exam_sessions
  id · academy_id · exam_id · student_id · attempt_number
  status(in_progress|submitted|scoring|scored|expired)
  current_section_id · snapshot json
  started_at · submitted_at · expires_at · scored_at
  total_score · section_scores json · passed
  INDEX (academy_id, exam_id, student_id)
  INDEX (academy_id, status, expires_at)   -- برای Job بستن آزمون‌های منقضی

answers
  id · academy_id · session_type(practice|exam) · session_id
  question_id · student_id
  answer_data json · media_path · transcript · transcript_meta json
  score decimal(6,2) · max_score decimal(6,2) · breakdown json
  feedback json · ai_request_id · confidence
  scoring_status(pending|scoring|scored|failed|manual_review)
  scored_by(ai|teacher|system) · scored_at
  graded_manually · original_ai_score · override_reason · overridden_by
  created_at
  INDEX (academy_id, session_type, session_id)
  INDEX (academy_id, student_id, created_at)
  INDEX (academy_id, scoring_status)       -- صف نمره‌دهی
  INDEX (academy_id, question_id)          -- برای محاسبه difficulty_index

scores                                     -- خلاصه نهایی هر جلسه
  id · academy_id · student_id · session_type · session_id
  module_key · raw_score · scaled_score · percentage
  breakdown json · published_at · created_at
```

---

## ۸. هوش مصنوعی

```sql
ai_models                                  -- غیرمستاجری
  id · provider(gemini|openai|anthropic|whisper|google_stt)
  model_key(unique) · display_name · capabilities json
  input_price_per_1m · output_price_per_1m · currency
  max_input_tokens · max_output_tokens · is_active · is_default_for json

academy_ai_settings
  id · academy_id · task_key · provider · model_key
  temperature · max_output_tokens · fallback_chain json
  use_own_key · api_key(encrypted) · api_key_last4
  cache_enabled · economy_mode · updated_at
  UNIQUE (academy_id, task_key)

ai_prompts
  id · academy_id · key · version · status(draft|published|archived)
  system_prompt · user_template · output_schema json
  variables json · model_hint · tested_at · published_at · created_by
  UNIQUE (academy_id, key, version)
  INDEX (academy_id, key, status)

ai_rubrics
  id · academy_id · task_key · name · version · is_active
  criteria json · scale_min · scale_max · rounding
  UNIQUE (academy_id, task_key, version)

ai_requests
  id · academy_id · student_id(nullable) · answer_id(nullable)
  task_key · provider · model_key · prompt_version
  prompt_tokens · completion_tokens · total_tokens
  cost_usd decimal(10,6) · latency_ms
  status(success|failed|timeout|rate_limited)
  cache_hit · fallback_used · error_code · request_id · created_at
  INDEX (academy_id, created_at)
  INDEX (academy_id, task_key, created_at)
  PARTITION BY RANGE (ماهانه) · نگهداری ۱۲ ماه سپس آرشیو

ai_logs                                    -- محتوای کامل، فقط برای عیب‌یابی
  id · academy_id · ai_request_id
  rendered_prompt · raw_response · created_at
  نگهداری ۷ روز · دسترسی فقط Super Admin
```

> `ai_requests` و `ai_logs` عمداً جدا هستند: اولی سبک و همیشگی برای گزارش و صورتحساب، دومی سنگین و کوتاه‌مدت برای دیباگ.

---

## ۹. مالی

```sql
plans                                      -- غیرمستاجری
  id · key(unique) · name · description · price_monthly · price_yearly
  currency · limits json · features json
  is_public · sort_order · is_active

subscriptions                              -- اشتراک آموزشگاه از شما
  id · academy_id · plan_id · status(trialing|active|past_due|canceled|expired)
  billing_cycle(monthly|yearly) · price · currency
  started_at · current_period_start · current_period_end
  canceled_at · gateway · gateway_subscription_id
  INDEX (academy_id, status), INDEX (current_period_end)

student_subscriptions                      -- اشتراک دانشجو از آموزشگاه
  id · academy_id · student_id · course_id(nullable) · plan_name
  status · price · currency · starts_at · expires_at
  payment_id · created_at
  INDEX (academy_id, student_id, status)

payments
  id · academy_id · payable_type · payable_id
  amount · currency · gateway(zarinpal|idpay|stripe|telegram)
  gateway_ref · authority · status(pending|paid|failed|refunded)
  paid_at · refunded_at · meta json · created_at
  INDEX (academy_id, status, created_at), INDEX (gateway_ref)

invoices
  id · academy_id · number(unique) · subscription_id
  amount · tax · total · currency
  status(draft|issued|paid|void) · issued_at · due_at · paid_at · pdf_path

usage_counters
  id · academy_id · period(char 7) · metric · value · limit_value · updated_at
  UNIQUE (academy_id, period, metric)
```

---

## ۱۰. عملیات

```sql
activity_logs                              -- مستاجری
  id · academy_id · actor_type · actor_id · action
  subject_type · subject_id · old_values json · new_values json
  ip · user_agent · created_at
  INDEX (academy_id, created_at), INDEX (academy_id, subject_type, subject_id)
  PARTITION BY RANGE (ماهانه) · ۱۲ ماه

platform_audit_logs                        -- اقدامات Super Admin
  id · user_id · action · target_academy_id
  payload json · ip · created_at

notifications
  id · academy_id · notifiable_type · notifiable_id · channel(telegram|email|sms)
  type · data json · scheduled_at · sent_at · read_at · status · error

scheduled_contents
  id · academy_id · type · target_id · audience json
  scheduled_at · repeat_rule · timezone · status · executed_at · created_by
  INDEX (status, scheduled_at)

webhooks                                   -- خروجی به سرویس آموزشگاه
  id · academy_id · url · events json · secret(encrypted)
  is_active · last_delivery_at · failure_count

webhook_deliveries
  id · academy_id · webhook_id · event · payload json
  response_code · response_body · attempt · delivered_at
  نگهداری ۳۰ روز

api_keys
  id · academy_id · name · key_hash(unique) · key_last4
  scopes json · last_used_at · expires_at · revoked_at · created_by

support_tickets
  id · academy_id · student_id · subject · status(open|pending|closed)
  priority · assigned_to · last_reply_at · closed_at · created_at

support_ticket_messages
  id · academy_id · ticket_id · sender_type(student|staff) · sender_id
  content · attachments json · created_at

reports                                    -- گزارش‌های تولیدشده
  id · academy_id · type · params json · file_path
  status · requested_by · generated_at · expires_at
```

---

## ۱۱. ملاحظات مقیاس‌پذیری

### جداول پرحجم و راهکار

| جدول | رشد تقریبی (۱۰۰ آموزشگاه فعال) | راهکار |
|------|-------------------------------|--------|
| `answers` | ~۵M ردیف/سال | ایندکس دقیق + آرشیو سالانه به جدول تاریخی |
| `ai_requests` | ~۳M/سال | پارتیشن ماهانه + آرشیو به S3 (Parquet) |
| `telegram_updates` | ~۲۰M/سال | پارتیشن ماهانه + نگهداری فقط ۳۰ روز |
| `telegram_messages` | ~۱۵M/سال | پارتیشن ماهانه + نگهداری ۹۰ روز |
| `activity_logs` | ~۲M/سال | پارتیشن ماهانه |

### Read Replica
گزارش‌ها و داشبورد از Replica خوانده می‌شوند:
```php
DB::connection('mysql_read')->table('answers')...
```
Laravel با `read`/`write` در `config/database.php` این را خودکار مدیریت می‌کند. صف‌ها و نوشتن همیشه روی Primary.

### داده‌های مشتق (Denormalized)
برای اینکه داشبورد در هر بار بارگذاری کل جدول `answers` را جمع نزند:
- `student_progress` — به‌روزرسانی با هر پاسخ (Event Listener)
- `daily_stats` — Job شبانه، ردیف به‌ازای (academy_id, date, metric)
- `question_banks.question_count` — با Observer

### نکته Charset
`utf8mb4` = ۴ بایت/کاراکتر. سقف ایندکس InnoDB ۳۰۷۲ بایت است. برای ستون‌های `VARCHAR(255)` که ایندکس می‌شوند مشکلی نیست، اما برای ایندکس مرکب روی چند ستون رشته‌ای باید طول محدود شود:
```sql
INDEX idx_hostname (hostname(191))
```
