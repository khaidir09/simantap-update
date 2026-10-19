# Patch lokal: Jenjang Sekolah Dinamis (SD/SMP/SMA/SMK)

- Spesifikasi: `docs/specs/jenjang-sekolah-dinamis.md`
- Versi bundle acuan: `assets/index-CGWL69P7-pkl2.js` (patch Bimbingan PKL iterasi 2)
- Tanggal patch: 2026-10-19
- Status upstream: **belum** ada fitur resmi. Patch ini sekaligus usulan ke BTIKP (`docs/usulan-btikp/RINGKASAN-JENJANG-SEKOLAH.md`).

## 1. Ringkasan

ADMIN memilih **Jenjang Sekolah** di Konfigurasi Sistem → tab Konfigurasi Sistem (di samping Semester Aktif).
Nilainya disimpan sebagai `system_config.school_level` dan menentukan:

| Jenjang | Level kelas | Bimbingan PKL |
|---|---|---|
| SD | I–VI | nonaktif (menu hilang, `pkl_*` 403) |
| SMP | VII–IX | nonaktif |
| SMA | X–XII | nonaktif |
| SMK | X–XII | aktif |

Selama `school_level` belum disimpan, jenjang **dideteksi** dari `kop_sekolah` (SMK/STM/SMEA/MAK → SMK,
SMP/SLTP/MTs → SMP, SD/SDN/SDIT/MI/MIN → SD, selain itu SMA). Ganti jenjang padahal masih ada kelas,
penugasan mapel, atau siswa aktif ber-level jenjang lama → `save_system_config` menjawab 409 dengan hitungan,
UI menampilkan modal peringatan, dan simpan ulang dengan `konfirmasi_jenjang: true`. Data lama tidak diubah;
di dropdown level, nilai lama tampil sebagai "Kelas X (di luar jenjang)".

## 2. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/jenjang_sekolah.php` | baru: helper jenjang (daftar level, deteksi, parser, hitungan tidak cocok, guard PKL) | `9ac9b2aebf7a25bd9144f904a20bcef1f56bcc4168ccbd34c83c27fed1c36aee` |
| `api/index.php` | ubah: `require_once`, injeksi `school_level` di `get_system_config`, validasi + 409 di `save_system_config`, `jenjangWajibPkl()` di 16 `case 'pkl_*'` | `9a78ef843e807d9ba54d257d2a867ce9e25b1f8e96242120abef6cfe085076ca` |
| `api/endpoints_v2.php` | ubah: `get_config` menambah `school_level` + `school_level_sumber` | `a74571c7ba000e87aae80b06ececcd0fc86bf0caa8c9feb0ec5f341f548050db` |
| `api/endpoints_v3.php` | ubah: seeding `get_classes` memakai parser level | `bad601ef02273a20a1c77caaa0a9650faeb9f27d977be41a7ad73e5ad9dfb224` |
| `api/endpoints_tu.php` | ubah: `gtk_update_atribut` menurunkan `class_level` dengan parser (regex lama tidak mengenali I–VI/angka) | `d758b70f9ded69b07169536e2b9717f1b09928cf2c6d000d255ee9fc053ef802` |
| `assets/index-CGWL69P7-pkl2.js` | **tidak diubah** (sumber patch) | `65f184be84a2086aed68d06f227657e6996cf0e586a28153280f89d7c770ddb8` |
| `assets/index-CGWL69P7-jenjang1.js` | baru: salinan dengan patch J0–J10 | `154b1e2ca04be709387a43464f46f912648a884df2e9b1f577abb9927d80f123` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-jenjang1.js` | `3566ff8d7e0b0713e760992706ce2b295dce540809bfb72455e11ccff4b7a9c9` |
| `docs/patches/apply-jenjang-sekolah-bundle.js` | baru: skrip penerap patch bundle (bisa di-`require` untuk uji parser) | – |
| `docs/patches/uji-jenjang-sekolah-kasus.json` | baru: tabel kasus parser & deteksi bersama PHP/JS | – |
| `docs/patches/uji-jenjang-sekolah-unit.php`, `uji-jenjang-sekolah-parser.mjs` | baru: uji unit PHP (51 kasus) & paritas JS (34 kasus) | – |
| `docs/patches/uji-jenjang-sekolah-api.mjs`, `-seed.sql`, `-cleanup.sql` | baru: uji API (27 uji), data uji, pembersih + pemulihan konfigurasi | – |

Hash file PHP/`app.html` hanya berlaku bila file itu tidak berubah karena patch lain.
**Skema:** tidak ada DDL; hanya baris baru `system_config.school_level` saat ADMIN menyimpan.

## 3. Patch bundle (J0–J10)

| Id | Isi |
|---|---|
| J0 | Helper `__jj*` sebelum `const __dspK=`; render root menunggu `get_config` (maks. 5 detik, gagal → SMA tanpa PKL) agar `__jjNow()` sinkron |
| J1–J4 | 4 dropdown level (Kelola Kelas, Tambah Penugasan Mapel, Mode Edit Parameter Guru, Profil guru) → `...__jjOpsiLevel(value)`; default level form Kelola Kelas = level pertama jenjang |
| J5 | Fallback `["X","XI","XII"]` penugasan mapel → level jenjang |
| J6 | Form jurnal: `startsWith("XII"/"XI"/"X")` → `__jjParseLevel(id) || __jjParseLevel(name)` |
| J7 | 6 placeholder yang menyebut level → `__jjContoh(jenis)` (SMA tidak berubah; SMK hanya nama kelas "X TKJ 1") |
| J8 | Menu Bimbingan PKL: `roles` kosong bila bukan SMK |
| J9 | Router `bimbingan-pkl` bukan SMK → alihkan ke dashboard |
| J10 | Pengaturan: state modal, field Jenjang Sekolah, simpan dengan penanganan 409 dan reload bila jenjang berubah |

## 4. Menerapkan / memverifikasi ulang

```bash
php -l api/jenjang_sekolah.php && php -l api/index.php && php -l api/endpoints_v2.php && php -l api/endpoints_v3.php && php -l api/endpoints_tu.php
node docs/patches/apply-jenjang-sekolah-bundle.js && node --check assets/index-CGWL69P7-jenjang1.js
php docs/patches/uji-jenjang-sekolah-unit.php && node docs/patches/uji-jenjang-sekolah-parser.mjs
# dev/staging saja:
mysql simantap < docs/patches/uji-jenjang-sekolah-seed.sql
BASE=http://localhost/simantap/api/index.php node docs/patches/uji-jenjang-sekolah-api.mjs
mysql simantap < docs/patches/uji-jenjang-sekolah-cleanup.sql
```

Hasil 2026-10-19 (Laragon lokal, salinan DB SMKN 1 Amuntai): unit PHP 51/51, paritas JS 34/34, API 27/27.
Browser: field jenjang tampil, SMK→SMP memunculkan modal (36 kelas, 5 penugasan, 968 siswa), setelah Lanjutkan
menu PKL hilang, dropdown Kelola Kelas berisi VII–IX dengan placeholder "Contoh: VII A", kelas lama tampil
"Kelas X (di luar jenjang)", console bersih. Cleanup memulihkan konfigurasi semula.

Setelah auto-update menimpa file: jalankan ulang patch backend dari `docs/usulan-btikp/backend-jenjang-sekolah.diff`
dan patch bundle (perbarui `SRC_NAME`/`SRC_SHA256` bila bundle sumber berubah).

## 5. Rollback

`app.html` → `./assets/index-CGWL69P7-pkl2.js`; balikkan diff backend (`git apply -R docs/usulan-btikp/backend-jenjang-sekolah.diff`)
lalu hapus `api/jenjang_sekolah.php`. Baris `school_level` boleh dibiarkan (tidak dibaca kode lama).
