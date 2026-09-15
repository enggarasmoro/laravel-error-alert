# enggarasmoro/laravel-error-alert

Alert email asynchronous dan ringan untuk Laravel 6 sampai Laravel 13.

## Instalasi

```bash
composer require enggarasmoro/laravel-error-alert
php artisan vendor:publish --tag=error-alert-config
```

Package memakai Laravel auto-discovery. Untuk repository private, gunakan VCS/private Composer repository milik organisasi Anda. Pada monorepo Patrol, tiga aplikasi memakai path repository lokal.

## Konfigurasi minimal

```dotenv
ERROR_ALERT_ENABLED=true
ERROR_ALERT_ENVIRONMENTS=production
ERROR_ALERT_SERVICE=inspection-api
ERROR_ALERT_RECIPIENTS=oncall@example.com
ERROR_ALERT_QUEUE=inspection-api.error-alerts
ERROR_ALERT_QUEUE_CONNECTION=redis
ERROR_ALERT_CACHE_STORE=redis
```

Konfigurasikan mailer Laravel dan Redis queue seperti biasa. Jalankan worker khusus queue alert, misalnya:

```bash
php artisan queue:work redis --queue=inspection-api.error-alerts --tries=3 --timeout=30
```

Validasi tanpa mengirim email dengan `php artisan error-alert:check`. Gunakan `php artisan error-alert:test` hanya ketika email uji memang diinginkan.

## Versi Laravel

- Laravel 8–13: provider memasang callback `reportable` otomatis.
- Laravel 6–7: panggil `ErrorAlert::report($exception)` dari `report()` pada exception handler aplikasi.

HTTP 5xx, exception console, dan permanently failed queue job dapat masuk alert. HTTP 4xx diabaikan. Payload email hanya metadata yang dibatasi dan disanitasi; stack trace, request body, token, cookie, SQL, serta credential tidak dikirim.

Alert dikirim di background, memakai cooldown fingerprint, rate limit per jam, dan batas backlog. Saat cache/queue/mail alert gagal, jalur request utama tetap tidak digagalkan.
