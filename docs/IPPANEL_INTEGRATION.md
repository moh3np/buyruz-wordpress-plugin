# 📱 راهنمای جامع و مرجع یکپارچه‌سازی وب‌سرویس پیامکی آی‌پی‌پنل (IPPanel Master Guide)

این سند مرجع رسمی و دائمی کلیه ایجنت‌ها، توسعه‌دهندگان و سیستم‌های خودکار برای هرگونه تعامل با وب‌سرویس پیامکی **IPPanel** در سراسر اکوسیستم و پروژه‌های بایروز است.

---

## ۱. منابع رسمی و بالادستی (Official Sources & Documentation)

همواره پیش از هر تغییر یا پیاده‌سازی متد جدید، به منابع زیر مراجعه کنید و از هرگونه حدس و گمان پرهیز نمایید:

* **مستندات مدرن لبه (IPPanel Edge API Documentation):**  
  👉 [https://ippanelcom.github.io/Edge-Document/](https://ippanelcom.github.io/Edge-Document/)  
  *شامل مستندات ساخت پترن، ارسال سریع، مدیریت خطوط، گزارش‌ها و دفترچه تلفن.*
* **مخزن مرجع رسمی SDK در گیت‌هاب:**  
  👉 [https://github.com/ippanel/php-rest-sdk](https://github.com/ippanel/php-rest-sdk) (PHP)  
  👉 [https://github.com/ippanel/python-rest-sdk](https://github.com/ippanel/python-rest-sdk) (Python)  
  👉 [https://github.com/ippanelcom](https://github.com/ippanelcom) (سازمان رسمی جدید)
* **داشبورد کلیدهای دسترسی:**  
  👉 `https://ippanel.com/developers/api-keys`
* **نشانی پایه وب‌سرویس پایدار REST (Base URL):**  
  `https://api2.ippanel.com/api/v1`

---

## ۲. خط قرمز امنیتی: عدم هاردکد کلید (Zero Hardcoded Secrets)

> [!CAUTION]
> **ممنوعیت مطلق:** هیچ کلید API یا توکنی نباید مستقیماً درون کدهای مخزن گیت نوشته شود!
> هرگونه دسترسی به کلید باید از طریق سلسله‌مراتب استاندارد پروژه‌ها خوانده شود:

### سلسله‌مراتب خواندن کلید دسترسی:
1. **در سطح سرور وردپرس (WordPress):**
   - اولویت ۱: ثابت `BRZ_IPPANEL_API_KEY` در `wp-config.php` سرور.
   - اولویت ۲: متغیر محیطی سرور `IPPANEL_API_KEY`.
   - اولویت ۳: آپشن امن دیتابیس وردپرس در جدول `wp_options` با کلید `buyruz_ippanel_settings` (قابل مدیریت در پیشخوان افزونه بایروز).
2. **در سطح پنل عملیات (`panel`):**
   - فایل محلی `panel/config.php` (که در `.gitignore` است) زیر کلید `sms['ippanel_api_key']`.
3. **در سطح اسکریپت‌های زیرساخت و سرور (`scripts/infrastructure`):**
   - فایل محیطی محلی و سرور `.env.infrastructure` زیر متغیر `IPPANEL_API_KEY`.

---

## ۳. شماره خطوط فرستنده (Sender Numbers)

* **خط خدماتی اشتراکی (پیش‌فرض سیستم):** `3000505`  
  *این خط به عنوان فرستنده پیامک‌های خدماتی و متغیردار (Pattern) استفاده می‌شود و بلک‌لیست مخابرات را رد می‌کند.*

---

## ۴. 🛑 قانون آهنین و شماره اختصاصی تست‌های پیامکی (Dedicated Test Number)

> [!CAUTION]
> **خط قرمز مطلق عدم ارسال پیامک تستی به مشتریان:**
> هر زمان کاربر یا ایجنتی هر نوع تستی در سیستم انجام دهد (تست پترن، تست قالب، تست سفارشات ووکامرس، تست ارسال تاپین یا پست، تست دیدگاه‌های مجله، تست لاگین، تست API، تست اسکریپت‌ها و اپ‌ها)، **منحصراً و بدون هیچ استثنایی باید از شماره موبایل زیر به عنوان گیرنده استفاده شود**:
> 
> ### 🎯 شماره رسمی تست بایروز: `09127166487`
> 
> هیچ ایجنتی تحت هیچ شرایطی، حتی اگر کاربر مستقیماً شماره‌ای ذکر نکرده باشد، حق ندارد از شماره موبایل واقعی مشتریان در پایگاه داده یا شماره دیگری برای تست استفاده کند.

### ۵ پروتکل تضمینی و اجباری برای ایجنت‌ها در تست سناریوهای مختلف:
1. **تست الگو و پیامک مستقیم (`BRZ_IPPanel`):**
   همیشه پارامتر گیرنده (`$recipient`) را مقدار ثابت `BRZ_IPPanel::TEST_PHONE` یا `'09127166487'` قرار دهید:
   ```php
   BRZ_IPPanel::send_pattern( $pattern_code, BRZ_IPPanel::TEST_PHONE, $vars );
   ```
2. **تست تغییر وضعیت سفارشات ووکامرس (Order Status Changes):**
   اگر قرار است رویداد تغییر وضعیت سفارش (مثلاً تغییر به تکمیل شده، در حال انجام یا بارکد پستی) تست شود تا ارسال پیامک بررسی گردد:
   - **روش اول (توصیه‌شده):** ایجاد یک سفارش تستی مستقل با شماره خریدار `09127166487`.
   - **روش دوم (روی سفارش موجود):** اگر از روی سفارش موجود تست انجام می‌شود، ایجنت **موظف است پیش از تغییر وضعیت**، شماره صورت‌حساب سفارش (`billing_phone`) را با متد `$order->set_billing_phone('09127166487'); $order->save();` موقتاً تغییر دهد تا پیامک تحت هیچ شرایطی به مشتری واقعی ارسال نشود.
3. **تست ماژول دیدگاه‌های هوشمند مگ (`BRZ_Smart_Comments`):**
   شماره موبایل نویسنده دیدگاه تستی را منحصراً `09127166487` وارد کنید.
4. **سپر سندباکس خودکار (`BRZ_IPPanel::is_sandbox`):**
   در صورت تعریف ثابت `define('BRZ_SMS_SANDBOX_MODE', true);` یا متغیر محیطی `SMS_SANDBOX=1`، متد `guard_recipient` کلیه پیامک‌های خروجی و فیلترهای ووکامرس فارسی را به طور خودکار به `09127166487` تغییر مسیر می‌دهد.
5. **اسکریپت‌های پایتون و دسکتاپ:**
   متغیر محیطی `BUYRUZ_TEST_PHONE="09127166487"` را به عنوان شماره مقصد آزمون‌ها فراخوانی کنید.

---

## ۵. نحوه استفاده در افزونه وردپرس بایروز (`BRZ_IPPanel`)

کلاس یکپارچه `BRZ_IPPanel` در هسته افزونه بایروز (`buyruz-wordpress-plugin`) پیاده‌سازی شده و از طریق اتولودر به صورت خودکار در هر دو سایت (فروشگاه اصلی و مجله) در دسترس است:

### الف) استعلام مانده اعتبار پنل پیامک:
```php
if ( class_exists( 'BRZ_IPPanel' ) ) {
    $credit = BRZ_IPPanel::get_credit(); // خروجی: عدد اعشاری به ریال یا null
    if ( null !== $credit ) {
        // مانده حساب موجود است (مثلاً 737109 ریال)
    }
}
```

### ب) ارسال پیامک بر اساس الگو/پترن (Pattern / OTP):
*مناسب‌ترین روش برای ارسال کدهای اعتبارسنجی، تایید ورود و تغییر وضعیت سفارش:*
```php
$pattern_code = '12345'; // کد پترن تایید شده در آی‌پی‌پنل
$mobile       = '09123456789';
$variables    = array(
    'code' => '54321',
    'name' => 'محسن',
);

$message_id = BRZ_IPPanel::send_pattern( $pattern_code, $mobile, $variables );
if ( $message_id ) {
    // پیامک با موفقیت به صف تحویل مخابرات سپرده شد
}
```

### ج) ثبت، ویرایش و مدیریت چرخه حیات پترن‌ها از طریق API (Pattern Lifecycle & Management):
بر خلاف تصورات سنتی، آی‌پی‌پنل دارای API کامل جهت ثبت، ویرایش و استعلام پترن‌ها است:

1. **ایجاد پترن جدید (Create Pattern):**
   - **اندپوینت:** `POST https://api2.ippanel.com/api/v1/sms/pattern/normal/store`
   - **هدرها:** `apikey: <KEY>` و `Content-Type: application/json`
   - **بدنه:** `message` (شامل متغیرهای `%var%`)، `description`، `is_share: false`، `variable: [{"name": "var", "type": "string"}]`.
   - **پاسخ:** شناسه کد پترن (مانند `t2cfmnyo0c`).

2. **ویرایش پترن موجود (Update Pattern):**
   - **اندپوینت:** `PUT https://edge.ippanel.com/v1/api/patterns/normal/{pattern_code}`
   - **هدرها:** `Authorization: <KEY>` و `Content-Type: application/json`
   - **نکته بسیار مهم:** به محض ارسال ویرایش یا تغییر متن در پنل وب، وضعیت پترن به `pending` برمی‌گردد تا مجدداً توسط ناظر تایید شود.

3. **استعلام وضعیت زنده و متن تاییدشده (Get Pattern Status):**
   - **اندپوینت:** `GET https://edge.ippanel.com/v1/api/patterns/{pattern_code}`
   - **خروجی کلیدی:** `pattern_status` (مقادیر: `active`، `pending`، `rejected`)، `pattern_message` (متن دقیق تاییدشده)، `admin_comment` (دلیل تایید یا رد ناظر).

4. **لیست کردن پترن‌ها (List Patterns):**
   - **اندپوینت:** `GET https://edge.ippanel.com/v1/api/patterns?page=1&per_page=20`

### د) ارسال پیامک متنی مستقیم (Standard SMS):
```php
$mobile  = '09123456789';
$message = 'سفارش شما با موفقیت ثبت شد. فروشگاه بایروز';

$message_id = BRZ_IPPanel::send_sms( $mobile, $message );
```

### د) نرمال‌سازی شماره‌های موبایل ایرانی:
```php
$clean_mobile = BRZ_IPPanel::normalize_mobile( '۰۹۱۲-۳۴۵ ۶۷۸۹' );
// خروجی: 09123456789
```

---

## ۵. نحوه استفاده در پایتون (Python SDK / Scripts)

در صورت نیاز به ارسال پیامک از طریق اسکریپت‌های پایتون یا `buyruz-app`:
```python
import os
import requests

API_KEY = os.getenv("IPPANEL_API_KEY", "")
BASE_URL = "https://api2.ippanel.com/api/v1"

def get_credit():
    headers = {"apikey": API_KEY, "Content-Type": "application/json"}
    resp = requests.get(f"{BASE_URL}/sms/accounting/credit/show", headers=headers, timeout=8)
    if resp.status_code == 200:
        return resp.json().get("data", {}).get("credit")
    return None

def send_pattern(pattern_code: str, recipient: str, variables: dict, sender: str = "3000505"):
    headers = {"apikey": API_KEY, "Content-Type": "application/json"}
    payload = {
        "code": pattern_code,
        "sender": sender,
        "recipient": recipient,
        "variable": variables
    }
    resp = requests.post(f"{BASE_URL}/sms/pattern/normal/send", json=payload, headers=headers, timeout=10)
    if resp.status_code in (200, 201):
        return resp.json().get("data", {}).get("message_id")
    return None
```

---

## ۶. ضوابط رگولاتوری و چرخه تغییر پترن‌ها
1. **تاییدیه ناظر:** هر پترن جدید یا ویرایش‌شده نیازمند تایید توسط سامانه هوشمند/اپراتور آی‌پی‌پنل است.
2. **ویرایش و به‌روزرسانی پترن:** پترن‌ها از طریق اندپوینت لبه (`PUT /v1/api/patterns/normal/{code}`) قابل ویرایش هستند، اما به محض اعمال ویرایش، وضعیت پترن موقتاً به `pending` برمی‌گردد تا بازبینی شود؛ لذا متد `sync_or_register_pattern` و سیستم `BRZ_Smart_Comments` به صورت خودکار تا زمان تایید مجدد، پیامک‌ها را بدون فوت وقت از خط خدماتی کمکی ۳۰۰۰۵۰۵ ارسال می‌کنند.
3. **نام برند:** متن تمامی پترن‌ها باید با نام تجاری فروشگاه یا مجله (`بایروز` / `بایروز مگ`) خاتمه یابد.
4. **سقف طول متغیرها:** حداکثر طول مجاز برای متغیرهای رشته‌ای ۴۰ کاراکتر است (`len: 40`). متغیرهای طولانی‌تر در هسته درگاه به صورت خودکار کوتاه می‌شوند تا از خطای ۴۲۲ جلوگیری شود.
