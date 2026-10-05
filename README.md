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

Validasi tanpa mengirim email dengan `php artisan error-alert:check`. Command ini membuat key cache unik ber-TTL 60 detik lalu menguji `add`, `increment`, `put`, `decrement`, dan `get` yang dipakai untuk reservation alert; key probe dihapus sesudahnya dan otomatis kedaluwarsa bila cleanup gagal.

Untuk memverifikasi email nyata di development atau production, jalankan command secara manual pada aplikasi yang ingin diuji:

```bash
# Mengikuti ERROR_ALERT_DELIVERY dan mengirim ke penerima yang dikonfigurasi.
php artisan error-alert:test

# Mengirim langsung tanpa queue, hanya untuk probe ini, dan hanya ke inbox uji.
php artisan error-alert:test --sync --to=alerts@example.com
```

Command membuat exception uji yang aman dan bertanda unik, lalu menjalankannya melalui pipeline delivery package. `--to` membatasi probe ke satu alamat valid dan tidak mengubah `.env`; `--sync` hanya mengubah delivery untuk proses command tersebut. Pastikan `ERROR_ALERT_ENABLED=true`, environment saat ini termasuk di `ERROR_ALERT_ENVIRONMENTS`, dan alamat tujuan sudah benar sebelum menjalankannya. Ini pengiriman sungguhan ke alamat yang dipilih, jadi jangan tambahkan command ini ke deploy atau CI otomatis.

Dengan delivery `queue`, output sukses hanya berarti job berhasil diantrekan; worker pada queue `ERROR_ALERT_QUEUE` tetap harus berjalan sebelum email dikirim. Gunakan `--sync` untuk memeriksa mailer tanpa bergantung pada worker. Output sukses mode sync berarti mailer menerima pengiriman, bukan jaminan pesan masuk inbox utama—periksa juga spam dan status provider email.

`ERROR_ALERT_MAILER` hanya berlaku pada versi/configurasi Laravel yang menyediakan named mailer. Pada instalasi Laravel 6 dengan satu mailer default, biarkan variabel ini kosong dan gunakan konfigurasi mailer bawaan Laravel; `error-alert:check` akan menandai konfigurasi named mailer yang tidak didukung.

`error-alert:check` juga membaca cache store yang dikonfigurasi untuk memastikan store dapat diakses. Pada production, command menolak cache process-local (`array`, `file`, atau `null`) dan store yang tidak menyediakan distributed lock (`LockProvider`). Gunakan Redis sebagai cache bersama untuk jaminan pelepasan backlog yang atomik; store lain yang menyediakan lock masih dapat dipakai tetapi belum memiliki jaminan atomik saat worker berhenti mendadak di tengah pelepasan. Store process-local tetap berguna untuk testing/local, tetapi tidak aman untuk reservation lintas worker. Command juga akan memberi peringatan jika delivery queue memakai `ERROR_ALERT_ENCRYPT_QUEUE_PAYLOAD=false`; peringatan ini tidak menggagalkan preflight, tetapi payload queue berisi penerima dan detail diagnostik yang telah disanitasi dalam bentuk plaintext.

## Versi Laravel

- Laravel 8–13: provider memasang callback `reportable` otomatis.
- Laravel 6–7: panggil `ErrorAlert::report($exception)` dari `report()` pada exception handler aplikasi.

Laravel 6 dipertahankan sebagai compatibility-only lane. Framework dan dependency-nya sudah end-of-life, sehingga audit advisory pada lane tersebut bersifat informasional; gunakan Laravel yang masih didukung untuk posture keamanan produksi.

HTTP 5xx, exception console, dan permanently failed queue job dapat masuk alert. HTTP 4xx diabaikan. Payload email berisi metadata dan pesan exception yang sudah diringkas, di-escape, dibatasi `ERROR_ALERT_DETAIL_MAX_LENGTH` (default 500 karakter), dinormalisasi ke UTF-8, dan dibersihkan dari karakter kontrol. Pola credential umum (termasuk password, token, cookie, authorization, API key, private key, dan credential database/cloud) diganti dengan `[REDACTED]`; stack trace, request body, dan SQL tidak dikumpulkan. Hindari menaruh rahasia di pesan exception dan perlakukan backend queue sebagai trust boundary aplikasi: gunakan ACL/TLS, batasi retensi, dan jangan membagikan queue kepada tenant yang tidak berwenang. Set `ERROR_ALERT_DETAIL_MAX_LENGTH=0` untuk menonaktifkan detail pesan.

Alert memakai cooldown fingerprint, rate limit per jam, dan batas backlog. Counter backlog memiliki TTL terbatas (`ERROR_ALERT_BACKLOG_TTL`, default 86.400 detik) serta marker generasi per reservation. Pada Redis, marker dan counter dilepas dengan satu operasi atomik yang aman terhadap duplicate job dan worker crash; keduanya memakai hash tag yang sama agar dapat diproses pada Redis Cluster. Pada store lain, pelepasan memakai lock store jika tersedia dan marker dipulihkan bila decrement gagal; proses yang mati tepat di antara dua operasi tetap dapat meninggalkan counter sampai TTL habis. Job lama yang marker-nya sudah kedaluwarsa tidak dapat mengurangi counter generasi baru. Atur TTL agar lebih panjang daripada waktu antre dan retry maksimum pada deployment Anda. Jika counter perlu direkonsiliasi, pastikan tidak ada alert job aktif lalu hapus key `enggarasmoro:error-alert:backlog:{` ditambah SHA-1 dari `service|environment` lalu `}` melalui cache store yang sama, misalnya dari Tinker:

Saat upgrade dari versi yang memakai key backlog tanpa kurung kurawal, hentikan sementara producer alert dan tuntaskan job yang sudah antre sebelum menjalankan versi baru. Selama rolling deploy, counter lama dan baru terpisah sehingga batas backlog gabungannya dapat terlampaui. Job lama tetap memakai jalur pelepasan berbasis lock; jaminan atomik Redis hanya berlaku untuk reservation baru dengan key bertanda kurung kurawal. Jalur Redis Cluster belum diuji secara end-to-end.

```php
$key = 'enggarasmoro:error-alert:backlog:{'.sha1(config('error-alert.service').'|'.app()->environment()).'}';
Illuminate\Support\Facades\Cache::store(config('error-alert.cache_store') ?: null)->forget($key);
```

Mode queue dikirim di background; mode sync dikirim langsung. Jika event Laravel tersedia, package memancarkan `Enggarasmoro\LaravelErrorAlert\Events\AlertRequested` sebelum delivery dengan allowlist metadata operasional saja (`service`, `environment`, `source`, `status`, `error_code`, `type`, `operation`, `correlation_id`, `occurred_at`); detail exception, penerima, mailer, store cache, backlog key, dan fingerprint tidak disertakan. Kegagalan listener tidak menghentikan delivery.

Saat cache, queue, atau mail alert gagal, jalur request/error utama tetap tidak digagalkan.
