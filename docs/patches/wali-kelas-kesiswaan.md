# Patch lokal: Menu Kesiswaan untuk Guru Wali Kelas

- Spesifikasi: `docs/specs/menu-kesiswaan-wali-kelas.md`
- Versi bundle acuan: SIMANTAP **1.0.2**
- Tanggal patch: 2026-09-13
- Status upstream: **belum** ada perbaikan resmi. Dokumen ini sekaligus menjadi lampiran usulan ke BTIKP.

> **Catatan (2026-09-14):** patch rekap bulanan (`docs/patches/rekap-bulanan-wali-kelas.md`) dibangun di atas patch ini.
> - `app.html` kini mereferensikan `index-CGWL69P7-wali2.js`, bukan `-wali1.js`, sehingga hash `app.html` di tabel bawah tidak lagi berlaku.
> - Rollback penuh harus membatalkan patch rekap bulanan lebih dulu.
> - Setelah auto-update, terapkan ulang patch ini lebih dulu, lalu patch rekap bulanan.

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/kesiswaan_access.php` | baru: helper otorisasi Kesiswaan dan `resolveClassRef` | `784b157b32d0d76aca8183228f1c9f76e61b96125f29ab054fe456927b0abfd6` |
| `api/index.php` | ubah: `require_once` helper, `login`/`session` (tambah `class_homeroom_name`), `get_incidents`, `save_incident`, `delete_incident` | `9c76abd44c6df1dfdfe809aae731ec29d10a108a8a579aba81f9d9024ea6f62d` |
| `api/endpoints_v2.php` | ubah: `get_attendance_records`, `save_attendance_records`, `get_attendance_summary`, `get_parent_calls`, `update_parent_call`, `save_parent_call_manual` | `346dba5f479e99ec8ffb790d1e6a677a8f24132e074cdf93bef63698d5c89197` |
| `api/endpoints_v3.php` | ubah: `update_incident_status` (copy panggilan ortu yang tertimpa **tidak** diubah); `get_homeroom_dashboard` dan `get_class_attendance_summary` (kelas dicocokkan via `resolveClassRef`, tahun ajaran/semester default dari `system_config`, poin dicocokkan via id atau nama kelas) | `43b0d8d38853e386159eba8feb69ca8e3db18e6e67fad3d78e643b6c04437465` |
| `assets/index-CGWL69P7.js` | **tidak diubah** (bundle asli) | `ba957b9bc049c2e95b1f7ca74cd4e993d0eff662c9bd5c7e9e0b3eb514cb04bd` |
| `assets/index-CGWL69P7-wali1.js` | baru: salinan bundle dengan patch F1–F9 | `8e153c7fd69ee8cf789e0f7c8be89f2b06d564f8b4e3b8b799e71eecb01f0d97` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-wali1.js` | `1b862eeefa7770b5fc71ba0e50fe681bd1a2bb89a305b62fd5df3b3e115b034c` |
| `docs/patches/apply-wali-kelas-bundle.js` | baru: skrip penerap patch bundle | – |

Hash file PHP hanya berlaku untuk bundle 1.0.2 tanpa modifikasi lain. Jika file PHP berubah karena perubahan lain, bandingkan isi handler dengan §3.3 spesifikasi, bukan dengan hash.

## 2. Patch frontend (F1–F4)

Terapkan dari root deploy:

```
node docs/patches/apply-wali-kelas-bundle.js
```

Skrip ini:
1. memverifikasi hash bundle asli dan berhenti jika berbeda;
2. memastikan setiap anchor muncul **tepat 1 kali**;
3. menulis `assets/index-CGWL69P7-wali1.js`;
4. mengarahkan `app.html` ke file tersebut.

Skrip aman dijalankan ulang.

| # | Anchor | Tujuan |
|---|---|---|
| F1 | `else if(Y.title==="Kesiswaan"\|\|Y.title==="Umum & Ekskul")return!1` | Grup Kesiswaan tampil untuk GURU non-BK yang punya `class_homeroom` |
| F2 | `o(me),me.length>0&&n(me[0].id)` | Presensi Kelas: daftar kelas GURU difilter ke kelas perwalian |
| F3 | `ue.useState("X-IPA-1")` | Presensi Kelas: GURU mulai tanpa kelas default (menghindari fetch kelas salah) |
| F4 | `G(Se),Se.length>0&&Ee(wt=>({...wt,class:Se[0].id}))` | Form insiden/panggilan ortu: kelas GURU non-BK difilter ke kelas perwalian |
| F5 | ``label:`Dashboard ${s.class_homeroom}` `` | Sidebar Kelas Saya: tampilkan nama kelas |
| F6–F8 | awal `function dot(...)`, judul/deskripsi halaman, judul tab, `class:r,hadir:me.hadir` | Dashboard Kelas: tampilkan nama kelas |
| F9 | banner `"Wali Kelas "` dan keterangan `— Insiden ini dari kelas:` | Halaman insiden: tampilkan nama kelas perwalian |

F5–F9 hanya mengubah **tampilan**, dengan fallback ke id bila nama kosong. Request API tetap memakai `class_homeroom` (id kelas). Backend mengisi `class_homeroom_name` pada respons `login` dan `session`, jadi cukup refresh halaman tanpa login ulang.

Setelah deploy, minta pengguna melakukan *hard refresh*. Nama file bundle yang baru sudah memaksa cache lama tidak dipakai.

## 3. Re-apply setelah auto-update

1. Catat `api/version.php` dan file `assets/index-*.js` yang direferensikan `app.html`.
2. Jika versi atau bundle berubah, cek dulu apakah upstream sudah memperbaiki masalah ini: login sebagai wali kelas lalu jalankan E1, E6, E7, dan E10 (spesifikasi §9.3). Jika sudah diperbaiki, patch ini tidak diperlukan lagi.
3. Jika belum diperbaiki:
   - **Backend:** salin `api/kesiswaan_access.php` (bila hilang), tambahkan `require_once __DIR__ . '/kesiswaan_access.php';` setelah `require_once __DIR__ . '/db.php';` di `api/index.php`, lalu terapkan ulang pengecekan pada handler di tabel §1 sesuai §3.3 spesifikasi. Periksa dulu dengan grep bahwa handler masih di file yang sama dan tidak tertimpa copy lain.
   - **Frontend:** perbarui `SRC_NAME`/`SRC_SHA256` di skrip ke bundle baru **hanya setelah** memverifikasi manual bahwa semua anchor masih bermakna sama, lalu jalankan skrip.
4. `php -l` untuk semua file PHP yang diubah.
5. Jalankan subset regresi: E1, E2, E6, E7, E10, E11, E20, E22, E23.

## 4. Rollback

1. Di `app.html`, kembalikan `./assets/index-CGWL69P7-wali1.js` menjadi `./assets/index-CGWL69P7.js`.
2. Pulihkan `api/index.php`, `api/endpoints_v2.php`, dan `api/endpoints_v3.php` dari backup atau commit baseline.
3. Hapus `api/kesiswaan_access.php` (hanya **setelah** langkah 2, karena `index.php` yang ter-patch me-require file ini).
4. Tidak ada perubahan skema. Data presensi yang dibuat selama patch aktif tetap valid.

## 5. Hasil verifikasi (lingkungan dev lokal, 2026-09-13)

Lingkungan: Laragon, PHP 8.2 built-in server, MySQL 5.7.33 dengan `STRICT_TRANS_TABLES`, data sekolah lokal (35 kelas, 968 siswa, `active_academic_year` = 2026/2027). Tanggal uji `2099-12-31`; semua data uji dibersihkan dan poin siswa dikembalikan.

- `php -l`: keempat file PHP lolos. `node --check` bundle hasil patch lolos.
- **Backend: 60/60 lulus.** Mencakup E6–E12, E14–E24, penolakan anonim/user terhapus/GURU/TU, status tidak valid (400), dan ADMIN.
- **UI (Chrome headless): 14/14 lulus, tanpa JS exception.**
  - wali: 5 menu Kesiswaan, tanpa Absensi Upacara/Literasi;
  - dropdown Presensi dan form Pelanggaran hanya `X_AKT_1`, siswa termuat;
  - GURU biasa dan BK tanpa menu Presensi;
  - KS tetap memiliki semua menu dan semua kelas.
- **Belum diuji:**
  - E25 (regresi presensi dari Jurnal): kodenya tidak diubah;
  - E26 (tombol proses insiden bila id ≠ nama kelas): data lokal memakai `class_homeroom` `X-AKT-1`, id `X_AKT_1`, nama `X AKT 1`, dan pencocokan `L$` di `wp` menormalisasi ketiganya menjadi sama;
  - pengujian di **staging** (spesifikasi §9) tetap wajib sebelum produksi.
