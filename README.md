# Daily Checklist Student API

## Transisi dari Firebase ke Laravel 12

Dokumen ini berisi langkah-langkah untuk melakukan transisi backend dari Firebase ke Laravel 12.

## Daftar Isi
1. [Analisis Struktur Aplikasi Firebase](#1-analisis-struktur-aplikasi-firebase)
2. [Persiapan Laravel API](#2-persiapan-laravel-api)
3. [Migrasi Database](#3-migrasi-database)
4. [Implementasi Model dan Controller](#4-implementasi-model-dan-controller)
5. [Implementasi Autentikasi API](#5-implementasi-autentikasi-api)
6. [Update Provider di Flutter](#6-update-provider-di-flutter)
7. [Testing dan Debugging](#7-testing-dan-debugging)
8. [Kesimpulan](#8-kesimpulan)

## 1. Analisis Struktur Aplikasi Firebase

Struktur data Firebase saat ini terdiri dari koleksi:
- **users**: Menyimpan data pengguna (guru dan orang tua)
- **children**: Menyimpan data anak/siswa
- **activities**: Menyimpan data aktivitas
- **checklists**: Menyimpan data checklist harian
- **notifications**: Menyimpan notifikasi
- **plans**: Menyimpan rencana aktivitas mingguan/harian

Role pengguna:
- **teacher**: Guru yang dapat membuat akun parent dan mengelola anak/siswa
- **parent**: Orang tua yang dapat melihat aktivitas anak

## 2. Persiapan Laravel API

- ✅ Setup Laravel 12
- ✅ Konfigurasi database
- ✅ Implementasi autentikasi Sanctum
- ✅ Konfigurasi CORS

## 3. Migrasi Database

- ✅ Membuat migrasi untuk tabel users (dengan field tambahan untuk role)
- ✅ Membuat migrasi untuk tabel children
- ✅ Membuat migrasi untuk tabel activities
- ✅ Membuat migrasi untuk tabel checklists
- ✅ Membuat migrasi untuk tabel notifications
- ✅ Membuat migrasi untuk tabel personal_access_tokens (Sanctum)
- ✅ Membuat migrasi untuk tabel plans dan planned_activities
- ✅ Menjalankan migrasi database

## 4. Implementasi Model dan Controller

- ✅ Membuat model yang sesuai dengan struktur data Firebase
  - ✅ User
  - ✅ Child
  - ✅ Activity dan ActivityStep
  - ✅ Checklist, HomeObservation, dan SchoolObservation
  - ✅ Notification
  - ✅ Plan dan PlannedActivity
- ✅ Membuat controller untuk menangani operasi CRUD
  - ✅ AuthController
  - ✅ UserController
  - ✅ ChildController
  - ✅ ActivityController
  - ✅ ChecklistController
  - ✅ NotificationController
  - ✅ PlanController
- ✅ Implementasi relasi antar model

## 5. Implementasi Autentikasi API

- ✅ Mengimplementasikan sistem autentikasi dengan Laravel Sanctum
- ✅ Membuat endpoint untuk login, register, dan refresh token
- ✅ Implementasi middleware untuk validasi token
- ✅ Konfigurasi RouteServiceProvider untuk routing API

## 6. Update Provider di Flutter

- ✅ Membuat provider API baru di Flutter untuk berkomunikasi dengan Laravel API
  - ✅ ApiProvider (base provider)
  - ✅ AuthProvider
  - ✅ ChildProvider
  - ✅ ActivityProvider
  - ✅ ChecklistProvider
  - ✅ NotificationProvider
  - ✅ PlanningProvider
  - ✅ UserProvider
- ✅ Migrasi dari Firebase provider ke Laravel API provider
- ✅ Update logika autentikasi di Flutter
- ✅ Implementasi config untuk beralih antara Firebase dan Laravel API

## 7. Testing dan Debugging

- ✅ Pengujian endpoint API
- 🔄 Pengujian integrasi dengan aplikasi Flutter
- 🔄 Debugging dan perbaikan masalah

## 8. Kesimpulan

Proses transisi dari Firebase ke Laravel 12 telah berjalan dengan sangat baik. Berikut adalah pencapaian yang telah dilakukan:

1. **Analisis struktur Firebase** untuk memahami kebutuhan migrasi
2. **Persiapan Laravel API** dengan setup Laravel 12, konfigurasi autentikasi Sanctum, dan konfigurasi CORS
3. **Migrasi Database** dengan membuat skema yang sesuai dengan struktur data Firebase
4. **Implementasi Model** yang sesuai dengan model di Firebase
5. **Implementasi Autentikasi API** dengan Laravel Sanctum
6. **Pembuatan Provider di Flutter** untuk berkomunikasi dengan API Laravel
7. **Implementasi Controller** untuk menangani operasi CRUD (selesai)
8. **Routing API** untuk endpoint yang diperlukan
9. **Dokumentasi API** dengan Postman collection untuk pengujian API
10. **Pengujian API** dengan Postman untuk memvalidasi endpoint

## Catatan Penting

### Konfigurasi Server API

- Aplikasi menggunakan URL API: `http://127.0.0.1:8000/api` (sesuai dengan konfigurasi APP_URL di .env)
- Server dapat dijalankan dengan perintah `php artisan serve`
- Jangan mengubah APP_URL untuk menghindari konflik dengan Flutter

### Registrasi User

- Registrasi guru: endpoint publik `/api/register`
- Registrasi orang tua: endpoint protected `/api/register-parent` (hanya bisa diakses oleh guru yang sudah login)
- Saat registrasi orang tua, wajib menambahkan token guru di header `Authorization: Bearer {token}`

### Penambahan Model Plan dan PlannedActivity

- Model `Plan` dan `PlannedActivity` telah ditambahkan untuk menggantikan koleksi `plans` di Firebase
- `Plan` berisi data perencanaan aktivitas mingguan/harian yang dibuat oleh guru
- `PlannedActivity` berisi detail aktivitas yang dijadwalkan dalam suatu plan
- API endpoint tersedia di `/api/plans` dan `/api/planned-activities/{id}/status`

### Troubleshooting

- Jika terjadi error "Table personal_access_tokens doesn't exist", jalankan perintah berikut:
  ```bash
  php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
  php artisan migrate
  ```
- Jika route API tidak terdaftar, pastikan file `app/Providers/RouteServiceProvider.php` telah dibuat dan terdaftar di `bootstrap/providers.php`

## Progress Implementasi

### Status: API Laravel Siap Digunakan dan Teruji

Semua controller dan endpoint API telah selesai diimplementasikan dan teruji:
- Dokumentasi API dalam bentuk Postman collection tersedia di `daily_checklist_api.postman_collection.json`
- Petunjuk pengujian API tersedia di `POSTMAN_TESTING.md`
- Status implementasi API tersedia di `API_IMPLEMENTATION_STATUS.md`
- API Activity sudah diupdate untuk sesuai dengan model Flutter (field title, environment, difficulty, min_age, max_age dan steps)
- Pengujian seluruh endpoint API dengan Postman telah selesai dengan sukses ✅

### Integrasi Flutter dengan API Laravel

Proses integrasi aplikasi Flutter dengan API Laravel:
- ✅ Provider-provider API Laravel sudah dibuat
- 🔄 Integrasi Provider dengan UI:
 
  - ✅ Activity
    - ✅ Pembaruan teacher_activities_screen.dart untuk mendukung kedua API
    - ✅ Pembaruan activity_detail_screen.dart untuk mendukung kedua API
    - ✅ Pembaruan add_activity_screen.dart untuk mendukung kedua API
  - 🔄 Notification
    - ✅ Pembaruan notification_screen.dart untuk mendukung kedua API
  - 🔄 Plan
    - 🔄 Pembaruan parent_planning_screen.dart (sedang implementasi)
    - 🔄 Pembaruan teacher_planning_screen.dart (sedang implementasi)
    - ⬜ Pembaruan add_plan_screen.dart
    - ⬜ Pembaruan planning_detail_screen.dart
    - ⬜ Pembaruan parent_planning_detail_screen.dart
- ✅ Implementasi konfigurasi untuk toggle antara Firebase dan Laravel API
- 🔄 Finalisasi migrasi dari Firebase ke Laravel

Langkah selanjutnya: 
1. ✅ Menyelesaikan integrasi Auth dan User Provider dengan UI Flutter
2. ✅ Menyelesaikan integrasi Child Provider dengan UI Flutter 
3. ✅ Memperbaiki masalah Provider pada berbagai layar (TeacherHomeScreen, NotificationBadge, dll)
4. ✅ Menyelesaikan integrasi Activity Provider dengan UI Flutter
5. ✅ Menghapus kode Firebase dari file-file yang sudah dimigrasi
   - ✅ teacher_home_screen.dart
   - ✅ notification_badge.dart
   - ✅ teacher_register_screen.dart
   - ✅ add_child_screen.dart
   - ✅ parent_home_screen.dart
   - ✅ teacher_activities_screen.dart
   - ✅ activity_detail_screen.dart
   - ✅ add_activity_screen.dart
   - ✅ main.dart
6. 🔄 Pengujian integrasi Auth, User, Child, dan Activity
7. ⬜ Melanjutkan integrasi provider Notification secara bertahap
8. ⬜ Melanjutkan integrasi provider Plan secara bertahap
