# allclearly/monitor (PHP / Laravel)

ส่ง security event (login, request, error) เข้า [AllClearly Monitoring](https://allclearly.com) เพื่อแจ้งเตือนทาง LINE เมื่อมีคนเดารหัสผ่าน บอทหาช่องโหว่ traffic พุ่ง หรือผู้ดูแล login จากประเทศใหม่

- PHP 8.1+ ไม่มี dependency (ใช้ cURL ถ้ามี ไม่มีใช้ stream ของ PHP)
- เก็บ event ไว้แล้วส่งครั้งเดียวตอนจบ request ส่งไม่ได้ก็ไม่ทำให้เว็บพัง
- ไม่ส่งรหัสผ่าน query string หรือ message ของ exception

```bash
composer require allclearly/monitor
```

ตั้ง token จากแท็บ **Monitoring** ใน AllClearly (ขึ้นต้นด้วย `acm_`) ใน `.env`:

```dotenv
ALLCLEARLY_MONITOR_TOKEN=acm_xxxxxxxx
```

ไม่ตั้ง token = SDK ไม่ส่งอะไรเลย

## Laravel (10, 11, 12)

ติดตั้งแล้วใช้ได้ทันทีผ่าน package discovery:

- ทุก request (รวม 404) ถูกส่งเป็น `http.request` หลังตอบผู้ใช้แล้ว
- `Illuminate\Auth\Events\Failed` / `Login` / `Registered` ถูกส่งเป็น `auth.login_failed` / `auth.login_succeeded` / `auth.signup` (ใช้ได้กับ Breeze, Jetstream, Fortify และ `Auth::attempt()`)
- exception ที่ไม่ใช่ HTTP 4xx ถูกส่งเป็น `app.error` (ชื่อ class เท่านั้น)

บอกว่าบัญชีไหนเป็นผู้ดูแล (ใช้ตรวจผู้ดูแล login จากประเทศใหม่) ด้วยชื่อ Gate ability:

```dotenv
ALLCLEARLY_ADMIN_ABILITY=admin
```

ปรับค่าอื่น: `php artisan vendor:publish --tag=allclearly-config` (ปิดบางส่วน, path ที่ไม่ต้องส่ง, การเชื่อ header ของ proxy)

ส่ง event เอง เช่นตอนเปลี่ยนสิทธิ์ผู้ใช้:

```php
use AllClearly\Monitor\Client;

app(Client::class)->roleChanged(['user' => $user->email, 'meta' => ['role' => 'admin']]);
```

## PHP ทั่วไป

```php
use AllClearly\Monitor\Client;
use AllClearly\Monitor\RequestInfo;

$monitor = new Client(); // อ่าน ALLCLEARLY_MONITOR_TOKEN, ส่งอัตโนมัติตอนจบ script

if (! password_verify($password, $hash)) {
    $monitor->loginFailed(RequestInfo::fromGlobals() + ['user' => $email, 'status' => 401]);
}
```

`RequestInfo::fromGlobals()` อ่าน IP จาก `CF-Connecting-IP` / `X-Real-IP` / `X-Forwarded-For` ถ้าเว็บไม่ได้อยู่หลัง proxy ให้ใช้ `RequestInfo::fromGlobals(trustProxy: false)` เพราะ header เหล่านี้ปลอมได้

## พฤติกรรมเมื่อส่งไม่สำเร็จ

PHP ไม่มี process ค้างไว้ส่งซ้ำ: เน็ตหลุด, 429, 5xx = ทิ้งชุดนั้น; 401/402 = หยุดส่ง (token ผิดหรือแพ็กเกจไม่มี Monitoring) ทุกกรณีแจ้งผ่าน `onError` (Laravel: เขียน log warning ครั้งเดียว) ไม่ throw

ฟิลด์ทั้งหมดของ event: https://allclearly.com/docs/monitoring-ingest.md
