# enggarasmoro/laravel-error-alert

Alert email ringan dengan delivery queue atau langsung untuk Laravel 6 sampai Laravel 13.

## Instalasi

```bash
composer require enggarasmoro/laravel-error-alert
php artisan vendor:publish --tag=error-alert-config
```

Package memakai Laravel auto-discovery dan dapat dipasang dari Packagist. Pada monorepo Patrol, tiga aplikasi memakai path repository lokal agar perubahan package dapat diuji sebelum rilis.

## Konfigurasi minimal

```dotenv
ERROR_ALERT_ENABLED=true
ERROR_ALERT_ENVIRONMENTS=production
ERROR_ALERT_SERVICE=inspection-api
ERROR_ALERT_RECIPIENTS=oncall@example.com
ERROR_ALERT_DELIVERY=queue
ERROR_ALERT_QUEUE=inspection-api.error-alerts
ERROR_ALERT_QUEUE_CONNECTION=redis
# Kompatibilitas konfigurasi lama yang memakai driver queue sync.
ERROR_ALERT_ALLOW_SYNC_QUEUE=false
# Enkripsi payload queue menggunakan APP_KEY (default true; false hanya jika boundary queue sudah dipercaya).
ERROR_ALERT_ENCRYPT_QUEUE_PAYLOAD=true
ERROR_ALERT_CACHE_STORE=redis
ERROR_ALERT_DETAIL_MAX_LENGTH=500
ERROR_ALERT_COOLDOWN=900
ERROR_ALERT_MAX_PER_HOUR=20
ERROR_ALERT_MAX_BACKLOG=100
ERROR_ALERT_BACKLOG_TTL=86400
```

`ERROR_ALERT_DELIVERY=queue` adalah default dan membutuhkan koneksi queue worker-backed (misalnya `redis`). `ERROR_ALERT_DELIVERY=sync` mengirim Mailable langsung saat alert diproses dan tidak membutuhkan queue worker; gunakan ini hanya jika latency mail pada jalur error dapat diterima. Mode `sync` tetap memakai cooldown, rate limit, backlog limit, sanitasi, dan error isolation yang sama.

Pada mode queue, package menolak konfigurasi kosong, koneksi yang tidak dikenal, dan driver `sync` pada environment non-local agar jalur error tidak menjalankan mail I/O secara inline. Jalankan worker khusus queue alert, misalnya:

```bash
php artisan queue:work redis --queue=inspection-api.error-alerts --tries=3 --timeout=30
```

Validasi tanpa mengirim email dengan `php artisan error-alert:check`. Command ini membuat key cache unik ber-TTL 60 detik lalu menguji `add`, `increment`, `put`, `decrement`, dan `get` yang dipakai untuk reservation alert; key probe dihapus sesudahnya dan otomatis kedaluwarsa bila cleanup gagal. Gunakan `php artisan error-alert:test` hanya ketika email uji memang diinginkan.

`ERROR_ALERT_MAILER` hanya berlaku pada versi/configurasi Laravel yang menyediakan named mailer. Pada instalasi Laravel 6 dengan satu mailer default, biarkan variabel ini kosong dan gunakan konfigurasi mailer bawaan Laravel; `error-alert:check` akan menandai konfigurasi named mailer yang tidak didukung.

`error-alert:check` juga membaca cache store yang dikonfigurasi untuk memastikan store dapat diakses. Pada production, command menolak cache process-local (`array`, `file`, atau `null`) dan store yang tidak menyediakan distributed lock (`LockProvider`); gunakan store bersama yang mendukung operasi lock (misalnya Redis, Memcached, database, DynamoDB, atau adapter custom yang memenuhi kontrak tersebut). Store process-local tetap berguna untuk testing/local, tetapi tidak aman untuk reservation lintas worker. Command juga akan memberi peringatan jika delivery queue memakai `ERROR_ALERT_ENCRYPT_QUEUE_PAYLOAD=false`; peringatan ini tidak menggagalkan preflight, tetapi payload queue berisi penerima dan detail diagnostik yang telah disanitasi dalam bentuk plaintext.

## Versi Laravel

- Laravel 8–13: provider memasang callback `reportable` otomatis.
- Laravel 6–7: panggil `ErrorAlert::report($exception)` dari `report()` pada exception handler aplikasi.

Laravel 6 dipertahankan sebagai compatibility-only lane. Framework dan dependency-nya sudah end-of-life, sehingga audit advisory pada lane tersebut bersifat informasional; gunakan Laravel yang masih didukung untuk posture keamanan produksi.

HTTP 5xx, exception console, dan permanently failed queue job dapat masuk alert. HTTP 4xx diabaikan. Payload email berisi metadata dan pesan exception yang sudah diringkas, di-escape, dibatasi `ERROR_ALERT_DETAIL_MAX_LENGTH` (default 500 karakter), dinormalisasi ke UTF-8, dan dibersihkan dari karakter kontrol. Pola credential umum (termasuk password, token, cookie, authorization, API key, private key, dan credential database/cloud) diganti dengan `[REDACTED]`; stack trace, request body, dan SQL tidak dikumpulkan. Hindari menaruh rahasia di pesan exception dan perlakukan backend queue sebagai trust boundary aplikasi: gunakan ACL/TLS, batasi retensi, dan jangan membagikan queue kepada tenant yang tidak berwenang. Set `ERROR_ALERT_DETAIL_MAX_LENGTH=0` untuk menonaktifkan detail pesan.

Alert memakai cooldown fingerprint, rate limit per jam, dan batas backlog. Counter backlog memiliki TTL terbatas (`ERROR_ALERT_BACKLOG_TTL`, default 86.400 detik) serta marker generasi per reservation; release marker dan decrement counter dilakukan di bawah lock store jika tersedia, idempotent untuk duplicate job, dan marker dipulihkan bila decrement gagal agar retry dapat menyelesaikan cleanup. Job lama yang marker-nya sudah kedaluwarsa tidak dapat mengurangi counter generasi baru. Atur TTL agar lebih panjang daripada waktu antre dan retry maksimum pada deployment Anda. Jika counter perlu direkonsiliasi, pastikan tidak ada alert job aktif lalu hapus key `enggarasmoro:error-alert:backlog:` ditambah SHA-1 dari `service|environment` melalui cache store yang sama, misalnya dari Tinker:

```php
$key = 'enggarasmoro:error-alert:backlog:'.sha1(config('error-alert.service').'|'.app()->environment());
Illuminate\Support\Facades\Cache::store(config('error-alert.cache_store') ?: null)->forget($key);
```

Mode queue dikirim di background; mode sync dikirim langsung. Jika event Laravel tersedia, package memancarkan `Enggarasmoro\LaravelErrorAlert\Events\AlertRequested` sebelum delivery dengan allowlist metadata operasional saja (`service`, `environment`, `source`, `status`, `error_code`, `type`, `operation`, `correlation_id`, `occurred_at`); detail exception, penerima, mailer, store cache, backlog key, dan fingerprint tidak disertakan. Kegagalan listener tidak menghentikan delivery.

Saat cache, queue, atau mail alert gagal, jalur request/error utama tetap tidak digagalkan.
