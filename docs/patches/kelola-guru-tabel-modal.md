# Patch lokal: Kelola Guru — tabel penuh, edit via modal, ubah email, status mapel/jadwal, login terakhir

- Tanggal patch: 2026-09-29
- Spesifikasi: `docs/specs/kelola-guru-tabel-modal.md`
- Status upstream: **belum** ada perbaikan resmi.

## 1. Perubahan

**Frontend** (`docs/patches/apply-kelola-guru-bundle.js`: `index-CGWL69P7-badgesanksi.js` → `index-CGWL69P7-kelolaguru.js`)
- **Layout:** komponen `lot` (menu `teacher-management`) diganti seluruhnya. Tabel lebar penuh tampil mulai `md` (768px) dan berubah jadi kartu di layar kecil. CSS precompiled tidak memuat `lg:hidden`/`lg:block`, jadi breakpoint-nya `md`.
- **Kolom tabel:** Pengguna (badge role/Wali/BK), Email/NIP, Mapel, Jadwal KBM, Login Terakhir, Aksi.
- **Toolbar:** cari nama/email/NIP, filter role (termasuk TATA USAHA), filter *Belum lengkap* / *Tidak aktif >30 hari*, dan urutan nama / login terbaru / login terlama.
- **Modal edit:** ditutup lewat X, Esc, klik backdrop, atau Batal. Bila ada perubahan belum tersimpan, muncul konfirmasi.
- **Field Email Login:** hanya ADMIN yang bisa mengubahnya. Muncul peringatan amber bila email berubah.
- **KEPALA_SEKOLAH read-only:** field disabled, tanpa form password, tanpa tambah/hapus penugasan, hanya tombol Tutup.
- **Simpan:** memanggil `save_user_v2`, lalu `save_teacher_subjects_bulk`.
  - Gagal di profil: modal tetap terbuka dengan pesan server; tidak ada data yang tersimpan.
  - Gagal di penugasan: muncul pesan "Profil tersimpan, tetapi penugasan mapel gagal…".
- **Validasi kelas Tailwind:** skrip patch mengecek setiap kelas Tailwind yang dipakai ada utuh di `assets/index-CC1PBzaO.css`, dan setiap identifier bundle yang dipakai ada.

**Backend**
- `api/kelola_guru.php` (baru): `kgPastikanKolomLogin`, `kgCatatLogin`, `kgHariSejak`, `kgStatusGuru`, `kgValidasiEmail`, `kgSetelahEmailBerubah`.
- `api/index.php`:
  - `require_once` helper baru;
  - `login` mencatat `users.last_login_at`;
  - `get_users` menambah `mapel_count`, `jadwal_count`, `last_login_at`, `last_login_hari`;
  - `save_user` memakai guard email dan memindahkan `teacher_registry`.
- `api/endpoints_v3.php`: `save_user_v2` memakai guard email, memindahkan `teacher_registry`, memperbarui email di sesi, dan mengembalikan `email_berubah`.

## 2. Aturan

| Kasus | Hasil |
|---|---|
| Email akun lama diubah oleh non-ADMIN (role dibaca ulang dari DB) | 403 `Hanya ADMIN yang dapat mengubah email pengguna` |
| Email dipakai akun lain (tidak peka huruf besar/kecil) | 409 `Email sudah dipakai oleh pengguna lain` (juga saat membuat akun baru) |
| Format email salah | 400 `Format email tidak valid` |
| Email sama, hanya beda kapital/spasi | Bukan perubahan; KS/TU tetap bisa menyimpan |

Semua pemeriksaan berjalan sebelum `UPDATE`/`INSERT`.

**Login terakhir**
- Disimpan di kolom baru `users.last_login_at DATETIME NULL`. Kolom dibuat otomatis saat login pertama atau saat `get_users` dipanggil.
- Bila `ALTER` gagal, error hanya di-log dan login tetap berjalan.
- Kolom lama `lastLogin` (ber-`ON UPDATE`) tidak dipakai. Nilainya tidak berubah saat login.
- Belum ada data sebelum patch, jadi semua akun tampil "Belum tercatat" sampai mereka login lagi.

## 3. Pengujian

```
php -l api/kelola_guru.php && php -l api/index.php && php -l api/endpoints_v3.php
node docs/patches/apply-kelola-guru-bundle.js && node --check assets/index-CGWL69P7-kelolaguru.js
mysql -uroot simantap < docs/patches/uji-kelola-guru-cleanup.sql
mysql -uroot simantap < docs/patches/uji-kelola-guru-seed.sql
BASE=http://localhost/simantap/api/index.php node docs/patches/uji-kelola-guru-api.mjs
mysql -uroot simantap < docs/patches/uji-kelola-guru-cleanup.sql
```

Skenario browser ada di spesifikasi §9.4.

## 4. Checklist pasca auto-update

1. Apakah `app.html` masih menunjuk `index-CGWL69P7-kelolaguru.js`? Bila bundle upstream berubah, hash sumber di skrip patch tidak akan cocok. Cari ulang anchor `function lot({user:e}){` … `}const Ym={MIPA:` pada bundle baru.
2. Pastikan hasil `grep -n "kelola_guru.php\|kgValidasiEmail\|kgCatatLogin\|kgStatusGuru" api/index.php api/endpoints_v3.php` masih ada. Bila hilang, terapkan ulang perubahan §1.
3. Jalankan ulang uji API (A1, A5, A6 minimal).

## 5. Rollback

- `app.html` → `./assets/index-CGWL69P7-badgesanksi.js`.
- Hapus `require_once __DIR__ . '/kelola_guru.php';` beserta pemanggilan `kgCatatLogin`, `kgStatusGuru`, `kgValidasiEmail`, `kgSetelahEmailBerubah` di `api/index.php` dan `api/endpoints_v3.php`.
- Kolom `users.last_login_at` boleh dibiarkan karena tidak dibaca kode lama.
