# Panduan Deployment Dual-Database (MySQL cPanel Jagoanhosting & PostgreSQL)

Dokumen ini menjelaskan arsitektur kompatibilitas database ganda pada backend Laravel **SMART PKL / Monitoring PKL** (`monitoring-pkl-app`) serta langkah deployment awal menggunakan **MySQL** di **cPanel Jagoanhosting** dan langkah perpindahan ke **PostgreSQL** pada tahap pengembangan atau deployment lanjutan.

---

## 1. Ringkasan Kompatibilitas Database Ganda

Backend ini menggunakan **satu basis kode** dan **satu set migration Laravel** untuk **MySQL/MariaDB** dan **PostgreSQL**, yang dikendalikan sepenuhnya melalui file `.env`:

1. **Panjang Index String Aman (`AppServiceProvider`)**
   - `Schema::defaultStringLength(191)` di [app/Providers/AppServiceProvider.php](file:///D:/Code%20Project/SKRIPSI-main/monitoring-pkl-app/app/Providers/AppServiceProvider.php) mencegah error `1071 Specified key was too long` pada shared hosting MySQL/MariaDB dengan charset `utf8mb4`.
2. **Pencarian Case-Insensitive Kondisional per Driver**
   - Makro query `whereLikeInsensitive` dan `orWhereLikeInsensitive` pada [AppServiceProvider.php](file:///D:/Code%20Project/SKRIPSI-main/monitoring-pkl-app/app/Providers/AppServiceProvider.php) secara otomatis memeriksa `$query->getConnection()->getDriverName()`:
     - Pada `pgsql`: menggunakan operator native `ILIKE`.
     - Pada `mysql`, `mariadb`, dan `sqlite`: menggunakan `LOWER(...) LIKE ?` agar selalu *case-insensitive*.
   - Digunakan oleh seluruh controller pencarian Admin (`FieldSupervisorController`, `LocationController`, `PeriodController`, `StudentController`, `TeacherController`, `UserController`).
3. **Isolasi Konfigurasi Charset & Timezone per Driver**
   - [config/database.php](file:///D:/Code%20Project/SKRIPSI-main/monitoring-pkl-app/config/database.php) memisahkan `DB_MYSQL_CHARSET` (`utf8mb4`) dan `DB_MYSQL_COLLATION` (`utf8mb4_unicode_ci`) dari `DB_PGSQL_CHARSET` (`utf8`) agar tidak terjadi konflik parameter `client_encoding`.
   - Koneksi `mysql` dan `mariadb` menggunakan `DB_MYSQL_TIMEZONE=+07:00` (aman di shared hosting meskipun tabel timezone bernama MySQL tidak terpasang), sedangkan `pgsql` tetap menggunakan `DB_TIMEZONE=Asia/Jakarta`.
4. **Migration Schema Builder Portabel**
   - Seluruh migration di [database/migrations/](file:///D:/Code%20Project/SKRIPSI-main/monitoring-pkl-app/database/migrations) memakai Laravel Schema Builder tanpa raw SQL database-specific.
   - Pada [2026_10_19_010005_evolve_journals_to_logbooks_table.php](file:///D:/Code%20Project/SKRIPSI-main/monitoring-pkl-app/database/migrations/2026_10_19_010005_evolve_journals_to_logbooks_table.php), operasi `renameColumn` dipisahkan sebelum penambahan kolom baru agar klausa `after(...)` di MySQL/MariaDB selalu valid.

---

## 2. Langkah Deployment Awal dengan MySQL di Jagoanhosting (cPanel)

### A. Persiapan PHP di cPanel
1. Login ke **cPanel Jagoanhosting**.
2. Buka menu **Select PHP Version** (atau **MultiPHP Manager**):
   - Pilih versi **PHP 8.2** atau **PHP 8.3** (minimal `>= 8.2`).
   - Pastikan ekstensi berikut aktif: `pdo_mysql`, `mysqli`, `mbstring`, `openssl`, `fileinfo`, `gd`, `zip`, `bcmath`, `intl`, `tokenizer`, `xml`, `ctype`.

### B. Membuat Database MySQL di cPanel
1. Buka **MySQL® Database Wizard** di cPanel.
2. Buat database baru, misalnya `cpaneluser_monitoring_pkl`.
3. Buat user database baru, misalnya `cpaneluser_pkladmin`, beserta password yang kuat.
4. Centang **ALL PRIVILEGES** untuk menghubungkan user ke database tersebut, lalu simpan.

### C. Upload Kode Backend ke Server
Disarankan meletakkan proyek Laravel **di luar `public_html`** (misalnya `/home/cpaneluser/monitoring-pkl-app`) agar file `.env` tidak bisa diakses dari web:
1. Melalui **Git™ Version Control** atau **Terminal / SSH** cPanel:
   - Clone repo atau upload isi folder `monitoring-pkl-app/` ke `/home/cpaneluser/monitoring-pkl-app`.
2. Hubungkan folder `public` Laravel ke document root domain/subdomain Anda:
   - **Jika memakai Subdomain (disarankan, mis. `api.domainanda.com`):** saat membuat subdomain di menu **Domains**, arahkan *Document Root* langsung ke `monitoring-pkl-app/public`.
   - **Jika memakai Domain utama (`public_html`):** pindahkan isi `monitoring-pkl-app/public` atau ganti `public_html` dengan symlink (via Terminal SSH) ke `/home/cpaneluser/monitoring-pkl-app/public`:
     ```bash
     mv ~/public_html ~/public_html_backup
     ln -s ~/monitoring-pkl-app/public ~/public_html
     ```

### D. Install Dependensi & Konfigurasi `.env`
Buka **Terminal** di cPanel (atau SSH):
```bash
cd ~/monitoring-pkl-app

# 1. Install package PHP produksi (tanpa dev dependencies)
composer install --no-dev --optimize-autoloader

# 2. Salin .env.example menjadi .env
cp .env.example .env
```

Edit file `~/monitoring-pkl-app/.env` melalui **File Manager** cPanel atau `nano .env` dan sesuaikan bagian berikut:
```dotenv
APP_NAME="Monitoring PKL"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://api.domainanda.com
APP_TIMEZONE=Asia/Jakarta

APP_LOCALE=id
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=daily
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cpaneluser_monitoring_pkl
DB_USERNAME=cpaneluser_pkladmin
DB_PASSWORD=password_mysql_anda
DB_MYSQL_CHARSET=utf8mb4
DB_MYSQL_COLLATION=utf8mb4_unicode_ci
DB_MYSQL_TIMEZONE=+07:00

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```
> **Catatan:** Pada shared hosting cPanel, menggunakan `SESSION_DRIVER=file` atau `SESSION_DRIVER=database` sama-sama didukung karena tabel `sessions` sudah dibuat oleh migration. Karena API mobile menggunakan Laravel Sanctum token, `SESSION_DRIVER=file` lebih ringan untuk I/O database.

### E. Generate Key, Migrasi Database, Seeder & Storage Link
Jalankan di Terminal cPanel:
```bash
cd ~/monitoring-pkl-app

# Generate APP_KEY baru
php artisan key:generate --force

# Jalankan seluruh migration ke MySQL
php artisan migrate --force

# (Opsional untuk data awal / demo) Jalankan seeder
php artisan db:seed --force

# Atau buat akun admin saja secara manual untuk produksi bersih:
# php artisan admin:create --username=admin --email=admin@domainanda.com

# Buat symlink public/storage -> storage/app/public (untuk foto presensi, jurnal, izin)
php artisan storage:link

# Pastikan izin tulis folder storage & bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Optimasi cache konfigurasi & routing produksi
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### F. Menjadwalkan Cron Job Laravel di cPanel
Aplikasi memiliki jadwal harian (`attendance:mark-absent` pukul 17:00 WIB) di [routes/console.php](file:///D:/Code%20Project/SKRIPSI-main/monitoring-pkl-app/routes/console.php).
1. Buka menu **Cron Jobs** di cPanel.
2. Pilih preset **Once Per Minute (`* * * * *`)**.
3. Isi perintah berikut (sesuaikan path PHP cPanel jika diperlukan, mis. `php` atau `/usr/local/bin/ea-php83`):
   ```bash
   cd /home/cpaneluser/monitoring-pkl-app && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
   ```

### G. Menghubungkan APK Android ke Jagoanhosting
Setelah `https://api.domainanda.com/api/login` merespons normal:
- Pada aplikasi Android yang sudah terpasang, buka menu **`Pengaturan Server / URL API`** di halaman login dan masukkan `https://api.domainanda.com/api`, **atau** publikasikan URL tersebut ke channel auto-discovery agar semua perangkat langsung menggunakannya.

---

## 3. Langkah Perpindahan Database ke PostgreSQL pada Tahap Berikutnya

Karena kode aplikasi dan migration sudah agnostik database, perpindahan dari MySQL ke PostgreSQL **tidak memerlukan perubahan kode** maupun pembuatan migration baru.

### A. Persiapan Lingkungan PostgreSQL
1. Pastikan PHP di server target mengaktifkan ekstensi `pdo_pgsql` dan `pgsql`.
2. Siapkan database PostgreSQL kosong (misalnya PostgreSQL di VPS, Render, Neon, Supabase, atau cPanel PostgreSQL) beserta host, port (`5432`), nama database, username, password, dan kebijakan SSL (`prefer` atau `require`).
3. **Backup database MySQL yang sedang berjalan terlebih dahulu:**
   ```bash
   mysqldump -u cpaneluser_pkladmin -p cpaneluser_monitoring_pkl > backup_mysql_$(date +%F).sql
   ```

### B. Memperbarui Konfigurasi `.env` ke PostgreSQL
Edit file `.env`: beri tanda `#` pada konfigurasi `mysql` dan aktifkan blok `pgsql`:
```dotenv
# DB_CONNECTION=mysql
# ...

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=monitoring_pkl
DB_USERNAME=postgres
DB_PASSWORD=password_postgresql_anda
DB_PGSQL_CHARSET=utf8
DB_SSLMODE=prefer
DB_TIMEZONE=Asia/Jakarta
```

### C. Migrasi Skema & Sinkronisasi Data ke PostgreSQL
1. Bersihkan cache konfigurasi lama agar `.env` baru terbaca:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```
2. Jalankan seluruh migration pada PostgreSQL:
   ```bash
   php artisan migrate --force
   ```
3. Pilih salah satu untuk pengisian data:
   - **Jika memulai data baru:**
     ```bash
     php artisan db:seed --force
     ```
   - **Jika memindahkan data lama dari MySQL ke PostgreSQL:**
     - Gunakan tool migrasi skema/data seperti `pgloader` (hanya menyalin data karena tabel sudah dibuat oleh `php artisan migrate --force` via opsi `--with "data only"`) **atau** ekspor JSON/CSV per tabel mengikuti urutan dependensi foreign key:
       1. `users`, `activities`, `periods`, `locations`
       2. `students`, `teachers`, `field_supervisors`, `location_field_supervisor`
       3. `placements`
       4. `attendances`, `leave_requests`, `logbooks`
       5. `logbook_comments`, `logbook_status_histories`, `project_submissions`, `project_reviews`, `assessments`, `personal_access_tokens`
     - Setelah impor data dengan `id` eksplisit di PostgreSQL, sinkronkan ulang sequence `SERIAL/BIGSERIAL` agar `INSERT` baru tidak bentrok ID:
       ```sql
       SELECT setval(pg_get_serial_sequence('users', 'id'), COALESCE((SELECT MAX(id) FROM users), 1));
       SELECT setval(pg_get_serial_sequence('students', 'id'), COALESCE((SELECT MAX(id) FROM students), 1));
       SELECT setval(pg_get_serial_sequence('teachers', 'id'), COALESCE((SELECT MAX(id) FROM teachers), 1));
       SELECT setval(pg_get_serial_sequence('field_supervisors', 'id'), COALESCE((SELECT MAX(id) FROM field_supervisors), 1));
       SELECT setval(pg_get_serial_sequence('locations', 'id'), COALESCE((SELECT MAX(id) FROM locations), 1));
       SELECT setval(pg_get_serial_sequence('periods', 'id'), COALESCE((SELECT MAX(id) FROM periods), 1));
       SELECT setval(pg_get_serial_sequence('placements', 'id'), COALESCE((SELECT MAX(id) FROM placements), 1));
       SELECT setval(pg_get_serial_sequence('attendances', 'id'), COALESCE((SELECT MAX(id) FROM attendances), 1));
       SELECT setval(pg_get_serial_sequence('activities', 'id'), COALESCE((SELECT MAX(id) FROM activities), 1));
       SELECT setval(pg_get_serial_sequence('logbooks', 'id'), COALESCE((SELECT MAX(id) FROM logbooks), 1));
       SELECT setval(pg_get_serial_sequence('logbook_comments', 'id'), COALESCE((SELECT MAX(id) FROM logbook_comments), 1));
       SELECT setval(pg_get_serial_sequence('logbook_status_histories', 'id'), COALESCE((SELECT MAX(id) FROM logbook_status_histories), 1));
       SELECT setval(pg_get_serial_sequence('leave_requests', 'id'), COALESCE((SELECT MAX(id) FROM leave_requests), 1));
       SELECT setval(pg_get_serial_sequence('project_submissions', 'id'), COALESCE((SELECT MAX(id) FROM project_submissions), 1));
       SELECT setval(pg_get_serial_sequence('project_reviews', 'id'), COALESCE((SELECT MAX(id) FROM project_reviews), 1));
       SELECT setval(pg_get_serial_sequence('assessments', 'id'), COALESCE((SELECT MAX(id) FROM assessments), 1));
       ```
4. Cache ulang konfigurasi untuk produksi:
   ```bash
   php artisan config:cache
   ```

### D. Verifikasi & Rollback Cepat
* **Verifikasi koneksi & fitur:**
  - Uji endpoint `/api/login` serta pencarian `/api/admin/students?search=...`.
  - Di lingkungan pengembangan/CI, jalankan:
    ```bash
    php artisan test
    ```
* **Rollback cepat jika terjadi kendala saat cutover:**
  - Kembalikan `.env` ke blok `DB_CONNECTION=mysql` semula dan jalankan `php artisan config:clear && php artisan config:cache`.
