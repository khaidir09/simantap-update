# Patch lokal: Portal Nilai untuk Siswa dan Orang Tua

- Spesifikasi: `docs/specs/portal-nilai-orang-tua.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch Pantau Nilai Kelas (`assets/index-CGWL69P7-pantaunilai1.js`, commit `bb33d9c`)
- Tanggal patch: 2026-09-26
- Status upstream: **belum** ada fitur resmi. Patch ini sekaligus usulan ke BTIKP.

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/portal_nilai.php` | baru: PIN, throttle, cookie bertanda tangan, tiga handler staf, halaman kartu | `86acd32e8596346aa381d04663a76ac6b8f4a7b9d11af35d4b4fcf45178f1002` |
| `nilai/index.php` | baru: halaman publik (gerbang, masuk/keluar, halaman nilai) | `2e1381e84e26a2b567229ec33d8307f45a0f2d9b5724b08edc6b8f94240a3e92` |
| `api/index.php` | ubah: `require_once __DIR__ . '/portal_nilai.php';` setelah `pantau_nilai.php`, dan tiga `case` setelah `cetak_rekap_nilai_siswa` | `7c68c7056ece230624eb3eff2f0ca41296d89e07348e13aeb8c0c2f13486337b` |
| `assets/index-CGWL69P7-pantaunilai1.js` | **tidak diubah** (sumber patch) | `743f21d36caf1c3ddbf1d17e661ac1036dcd8d87f033279660fba7f66fa870ee` |
| `assets/index-CGWL69P7-portalnilai1.js` | baru: salinan `-pantaunilai1.js` dengan patch P0–P5 | `964781e9bbba2d446f2f861560d780dc234bec48754d80817c9fc8c42bf57377` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-portalnilai1.js` | `a0aca132096b42a2707ce2d4b5780f3bdc168da473f51912d64db46507bf67ca` |
| `docs/patches/apply-portal-nilai-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-portal-nilai-api.mjs`, `uji-portal-nilai-unit.php` | baru: uji API P1–P23 dan uji unit | – |
| `docs/patches/uji-portal-nilai-seed.sql`, `uji-portal-nilai-cleanup.sql` | baru: data uji dan pembersihnya | – |

Hash `api/index.php` hanya berlaku bila file itu tidak berubah karena patch lain.

**Skema:** tabel `portal_nilai_akses` dan `portal_nilai_percobaan` dibuat otomatis
(`CREATE TABLE IF NOT EXISTS`) saat pertama dipakai. DDL ada di spesifikasi §4.2.
Key `system_config.portal_nilai_aktif` dibuat saat Admin pertama kali mengubah saklar;
tanpa key itu portal **nonaktif**. Tabel lama tidak diubah.

## 2. Perubahan backend

| Action / halaman | Metode | Akses | Isi |
|---|---|---|---|
| `portal_nilai_status` | GET | ADMIN semua kelas; GURU wali kelas perwaliannya | Status PIN siswa, saklar, alamat portal |
| `cetak_kartu_akses` | POST form | sama | Buat/reset PIN dan tampilkan kartu A4 (PIN hanya di halaman ini) |
| `portal_nilai_set_aktif` | POST JSON | ADMIN | Saklar portal |
| `nilai/` | GET/POST | publik (NISN + PIN) | Form masuk, halaman nilai, keluar |

Penyesuaian terhadap spesifikasi awal (spesifikasi sudah diperbarui):
- `students.nisn` ternyata **UNIQUE** (`install/schema.sql:727`, `idx_nisn`). Status `NISN_GANDA`
  tetap ada sebagai pengaman, tetapi tidak bisa terjadi lewat data; kasus uji ganda dihapus.
- Reset PIN dan login berhasil **tidak menghapus** catatan gagal, tetapi mengubah `berhasil` 0 → 2.
  Kunci per NISN hanya menghitung 0; batas per IP menghitung 0 dan 2. Versi awal yang menghapus
  baris membuat login berhasil ikut "mereset" hitungan per IP (ditemukan uji P21).

## 3. Patch frontend

Terapkan dari root deploy, **setelah** patch Pantau Nilai Kelas:

```
node docs/patches/apply-portal-nilai-bundle.js
```

Skrip memverifikasi hash sumber, memastikan nama baru (`__ponK`, `__ponPanelAksesOrtu`,
`__ponHalamanAdmin`, `"portal-nilai"`) belum ada, memvalidasi semua class Tailwind terhadap
`assets/index-CC1PBzaO.css`, memastikan setiap anchor muncul tepat 1 kali, lalu menulis
`index-CGWL69P7-portalnilai1.js` dan mengarahkan `app.html`. Aman dijalankan ulang.

| # | Anchor | Perubahan |
|---|---|---|
| P0 | `function __pnkPantauNilaiKelas(` | Sisipkan `__ponK`, `__ponPanelAksesOrtu`, `__ponHalamanAdmin` |
| P1 | tombol "Perbarui Nilai" di komponen tahap 1 | Tambah tombol "🔑 Akses Orang Tua" (mode wali) |
| P2 | `} else if (memuat && !data) {` | View `akses` → panel, sebelum pemeriksaan data nilai |
| P3 | `roles:["ADMIN","KEPALA_SEKOLAH"]}]}],M` (item terakhir "Manajemen Data") | Item ADMIN "Portal Nilai Ortu" |
| P4 | router `bk-grades` | `portal-nilai` → `__ponHalamanAdmin` |
| P5 | daftar halaman dikenal | Tambah `"portal-nilai"` |

P1 dan P2 menempel pada kode yang disisipkan patch tahap 1. Jika patch tahap 1 diubah,
anchor P1/P2 perlu disesuaikan.

## 4. Pengujian

```
php -l api/portal_nilai.php
php -l nilai/index.php
php -l api/index.php
node --check assets/index-CGWL69P7-portalnilai1.js
php docs/patches/uji-portal-nilai-unit.php

mysql -uroot simantap < docs/patches/uji-portal-nilai-seed.sql
BASE=http://localhost/simantap/api/index.php UJI_PASSWORD=uji12345 node docs/patches/uji-portal-nilai-api.mjs
mysql -uroot simantap < docs/patches/uji-portal-nilai-cleanup.sql
```

- Skrip API mengubah saklar portal dan mengembalikan nilai semula di akhir.
- P21 (batas per IP) memblokir IP mesin uji selama 15 menit. `uji-portal-nilai-cleanup.sql` dan
  seed menghapus catatan percobaan dari `127.0.0.1`/`::1` dan NISN `990000%`.
- P24 (regresi tahap 1) = `uji-pantau-nilai-api.mjs` dengan seed-nya sendiri; jalankan cleanup
  portal lebih dulu karena kedua seed memakai ID siswa `s_uji_*`.

Hasil 2026-09-26 (PHP 8.2 built-in server + MySQL 5.7.33 Laragon):
- `uji-portal-nilai-unit.php`: 19 lulus.
- `uji-portal-nilai-api.mjs`: 33 lulus, 0 gagal (setelah perbaikan throttle dan penyesuaian NISN unik).
- Regresi tahap 1: `uji-pantau-nilai-api.mjs` 44 lulus; `uji-pantau-nilai-parser.php` 14 lulus.
- Browser: menu ADMIN + saklar, panel wali (status, NISN kosong tidak bisa dipilih), halaman kartu,
  masuk portal (PIN huruf kecil + spasi diterima), halaman nilai tanpa catatan guru, tanpa siswa lain,
  tanpa JavaScript, status login di panel wali terbarui, tanpa error console.
- **Belum terverifikasi:** tampilan portal di lebar HP (±390px). Resize jendela browser uji tidak berlaku.
- Tombol "Reset PIN Terpilih" dan "Nonaktif" memakai `window.confirm`, sehingga tidak diklik di uji
  browser otomatis. Alur reset dan nonaktif diuji lewat API (P14, P17, P18).

## 5. Rollback

1. Arahkan `app.html` kembali ke `./assets/index-CGWL69P7-pantaunilai1.js`.
2. Di `api/index.php`, hapus `require_once __DIR__ . '/portal_nilai.php';` dan tiga `case`
   `portal_nilai_status`, `cetak_kartu_akses`, `portal_nilai_set_aktif`.
3. Hapus folder `nilai/`, `api/portal_nilai.php`, dan `assets/index-CGWL69P7-portalnilai1.js`.
4. Opsional: `DROP TABLE portal_nilai_akses, portal_nilai_percobaan;` dan hapus key `portal_nilai_aktif`.

Cara cepat menutup portal tanpa rollback: Admin → Portal Nilai Ortu → **Nonaktif**.

## 6. Setelah update dari portal

Auto-updater dapat menimpa `api/index.php`, `app.html`, dan bundle. Setelah update:
- tambahkan kembali `require_once` dan tiga `case`;
- jalankan ulang patch bundle tahap 1 lalu tahap 2 dengan `SRC_NAME`/`SRC_SHA256` baru.

`nilai/` dan `api/portal_nilai.php` tidak tertimpa, kecuali upstream membuat file bernama sama.
