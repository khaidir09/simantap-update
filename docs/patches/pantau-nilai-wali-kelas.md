# Patch lokal: Pantau Nilai Kelas untuk Wali Kelas dan Guru BK

- Spesifikasi: `docs/specs/pantau-nilai-wali-kelas.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch hapus presensi pegawai (`assets/index-CGWL69P7-tanpapresensi1.js`)
- Tanggal patch: 2026-09-26
- Status upstream: **belum** ada fitur resmi. Patch ini sekaligus usulan ke BTIKP.

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/pantau_nilai.php` | baru: scope akses, pengumpulan data nilai, tiga handler action, renderer HTML cetak | `1e1928f51de4ac834a082e1cc2a6c67d3a98a9b6e4e313a71b97e36aeba23c42` |
| `api/index.php` | ubah: `require_once __DIR__ . '/pantau_nilai.php';` setelah `rekap_bulanan.php`, dan tiga `case` setelah `cetak_rekap_bulanan` | `cf80425b674fa5e3318b71186d26693c42acd3d02eeb05ee3c3a3b801621d28c` |
| `assets/index-CGWL69P7-tanpapresensi1.js` | **tidak diubah** (sumber patch) | `6b8ec613af1950f1b209a96ebc2d59b080dd03aa9b630c38e46e99da751fb545` |
| `assets/index-CGWL69P7-pantaunilai1.js` | baru: salinan `-tanpapresensi1.js` dengan patch P0–P5 | `743f21d36caf1c3ddbf1d17e661ac1036dcd8d87f033279660fba7f66fa870ee` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-pantaunilai1.js` | `cbe0dfee6d98dc92b72b6a2e570cc551656c7f417335a5f93101e664a1e7952d` |
| `docs/patches/apply-pantau-nilai-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-pantau-nilai-api.mjs` | baru: skrip uji API T1–T21 | – |
| `docs/patches/uji-pantau-nilai-parser.php` | baru: uji unit fungsi murni (termasuk presensi format array) | – |
| `docs/patches/uji-pantau-nilai-seed.sql`, `uji-pantau-nilai-cleanup.sql` | baru: data uji dan pembersihnya | – |

Hash `api/index.php` hanya berlaku bila file itu tidak berubah karena patch lain.

**Skema:** tidak ada perubahan skema dan tidak ada key `system_config` baru.

## 2. Perubahan backend

| Action | Metode | Isi |
|---|---|---|
| `get_class_grade_overview` | GET `class_id`, `semester` | Rekap semua mapel satu kelas, hitungan tertinggal, penanda perlu perhatian |
| `get_class_subject_grade_detail` | GET `class_id`, `subject_id`, `semester` | Nilai per pertemuan satu mapel |
| `cetak_rekap_nilai_siswa` | GET `student_id`, `semester`, `kota`, `tanggal` | Halaman HTML A4 siap cetak per siswa |

Akses (`pantauNilaiScope`, user dibaca ulang dari DB): ADMIN, KEPALA_SEKOLAH, dan GURU BK untuk
semua kelas; GURU wali kelas hanya untuk kelas perwaliannya; lainnya 403.

Endpoint nilai lama (`get_student_grades`, `get_daily_grades`, `get_monthly_grades`,
`get_ujian_scores`, `save_ujian_score`) dan `requireGradeAccess` **tidak diubah**.

Penyesuaian terhadap spesifikasi:
- Status presensi jurnal dinormalisasi dengan `pantauNilaiAttendanceStatus`, bukan
  `normalizeAttendanceStatus`, karena yang terakhir membalas JSON 400 untuk status asing.
- Halaman error cetak memakai `pantauNilaiHtmlError` (pola sama dengan `rekapHtmlError`,
  tetapi judulnya "Laporan nilai…", bukan "Rekap bulanan…").

## 3. Patch frontend

Terapkan dari root deploy, **setelah** patch hapus presensi pegawai:

```
node docs/patches/apply-pantau-nilai-bundle.js
```

Skrip ini:
1. memverifikasi hash `-tanpapresensi1.js` dan berhenti jika berbeda;
2. memastikan nama baru (`__pnkK`, `__pnkPantauNilaiKelas`, `"bk-grades"`) belum ada di bundle;
3. memastikan **semua class Tailwind** komponen (peta `KELAS` di skrip) ada di `assets/index-CC1PBzaO.css`;
4. memastikan setiap anchor muncul **tepat 1 kali**;
5. menulis `assets/index-CGWL69P7-pantaunilai1.js` dan mengarahkan `app.html` ke file itu.

Skrip aman dijalankan ulang.

| # | Anchor | Perubahan |
|---|---|---|
| P0 | `const K$=nr;function dot(` | Sisipkan peta class `__pnkK` dan komponen `__pnkPantauNilaiKelas` |
| P1 | array tab Dashboard Kelas Saya | Tambah `{id:"grades",label:"📝 Nilai"}` |
| P2 | `N==="overview"&&a.jsxs(` | Render komponen saat tab Nilai aktif |
| P3 | item sidebar "Insiden BK" | Tambah item "Pantau Nilai Kelas" (`bk-grades`) |
| P3b | `Y.title==="Kepegawaian"&&M&&…` | Blok sidebar BK dirender di grup menu pertama (`ne===0`) |
| P4 | `e==="bk-incidents"&&a.jsx(hot,{user:o}),` | Router: `bk-grades` → komponen dengan pemilih kelas |
| P5 | daftar halaman dikenal | Tambah `"bk-grades"` |

**P3b memperbaiki regresi patch sebelumnya.** Sejak patch hapus presensi pegawai, grup menu
"Kepegawaian" tidak ada lagi. Akibatnya, blok sidebar "Bimbingan & Konseling", termasuk menu
**"Insiden BK"** yang sudah ada, tidak tampil untuk guru BK. Halaman BK hanya bisa dibuka karena
menjadi halaman awal setelah login.

## 4. Pengujian

```
php -l api/pantau_nilai.php
php -l api/index.php
node --check assets/index-CGWL69P7-pantaunilai1.js
php docs/patches/uji-pantau-nilai-parser.php

mysql -uroot simantap < docs/patches/uji-pantau-nilai-seed.sql
BASE=http://localhost/simantap/api/index.php UJI_PASSWORD=uji12345 \
  UJI_MYSQL="C:/laragon/bin/mysql/mysql-5.7.33-winx64/bin/mysql.exe -uroot simantap" \
  node docs/patches/uji-pantau-nilai-api.mjs
mysql -uroot simantap < docs/patches/uji-pantau-nilai-cleanup.sql
```

- `UJI_MYSQL` hanya dibutuhkan untuk T21. Tanpa variabel itu, T21 dilewati.
- `UJI_KEEP=1` membiarkan jurnal uji untuk verifikasi manual di browser (spesifikasi §10.3).
  Jalankan cleanup + seed ulang sebelum menjalankan skrip lagi.
- Seed memakai `REPLACE INTO users` untuk akun `uji_*`, dan cleanup menghapus akun tersebut,
  termasuk akun yang dipakai skrip uji lain (`uji_admin`, `uji_ks`, `uji_tu`, `uji_guru`, `uji_wali`).

Hasil 2026-09-26 (PHP 8.2 built-in server + MySQL 5.7.33 Laragon):
- `uji-pantau-nilai-parser.php`: 14 lulus.
- `uji-pantau-nilai-api.mjs`: 44 lulus, 0 gagal.
- Browser (§10.3): tab Nilai wali kelas, detail mapel, catatan guru ter-escape, panel siswa,
  halaman cetak, menu BK + pemilih kelas, guru biasa tanpa menu baru, halaman KS normal,
  tanpa error console.

## 5. Rollback

1. Arahkan `app.html` kembali ke `./assets/index-CGWL69P7-tanpapresensi1.js`.
   Rollback ini juga mengembalikan regresi P3b (menu BK hilang).
2. Di `api/index.php`, hapus `require_once __DIR__ . '/pantau_nilai.php';` dan tiga `case`
   `get_class_grade_overview`, `get_class_subject_grade_detail`, `cetak_rekap_nilai_siswa`.
3. Hapus `api/pantau_nilai.php` dan `assets/index-CGWL69P7-pantaunilai1.js`.

## 6. Setelah update dari portal

Auto-updater dapat menimpa `api/index.php`, `app.html`, dan bundle. Setelah update:
- tambahkan kembali `require_once` dan tiga `case`;
- jalankan ulang skrip bundle dengan `SRC_NAME`/`SRC_SHA256` baru. Anchor mungkin berubah karena
  nama variabel hasil minify berbeda, jadi cari ulang secara manual bila skrip gagal.

`api/pantau_nilai.php` tidak tertimpa, kecuali upstream membuat file bernama sama.
