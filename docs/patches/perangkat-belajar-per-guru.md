# Patch lokal: Perangkat Belajar per Guru dan Perbaikan Tampilan Modul Ajar/Administrasi

- Spesifikasi: `docs/specs/perangkat-belajar-per-guru.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch wali kelas, rekap bulanan, dan pembina ekskul (`docs/patches/pembina-ekstrakurikuler.md`)
- Tanggal patch: 2026-09-15
- Status upstream: **belum** ada perbaikan resmi. Dokumen ini sekaligus menjadi lampiran usulan ke BTIKP (`docs/usulan-btikp/RINGKASAN-PERANGKAT-BELAJAR.md`).

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/lesson_access.php` | baru: helper otorisasi Perangkat Belajar (pemilik, kunci VERIFIED, method, validasi `fileUrl`, hapus folder unggahan, index `authorId`) | `fcce00766e1e04a2106ad47941617f7b9f2801063ce6d523b4d8e926780e5612` |
| `api/index.php` | ubah: `require_once` helper; `get_lesson_rpps`, `save_lesson_rpp`, `delete_lesson_rpp`, `verify_lesson_rpp` | `032ec1de0370eb26f3d089d173b014abe31d52e24565e88a55118de585fecde6` |
| `api/endpoints_tu.php` | ubah: `tu_file_upload` dengan `module=lesson` wajib GURU pemilik dan dokumen belum VERIFIED | `cfd8fcad37487d25dbd32583bc88904cfa3274abafb3dae30209d659f2958cd0` |
| `assets/index-CGWL69P7-ekskul1.js` | **tidak diubah** (sumber patch) | `c5d02ea3b2a80424a8ec7aca3b2f013eeb7472f4944a88341968b1c4ca62e33e` |
| `assets/index-CGWL69P7-perangkat1.js` | baru: salinan `-ekskul1.js` dengan patch F1–F13 | `518c88dbd27e812779a661bdadfff87cdf3c858f6a94de0619d05dbf9d972537` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-perangkat1.js` | `03fb7bdbc6a3b6c4c95f921d01799458c580c543582a7130cb0591c9d94d5e45` |
| `docs/patches/apply-perangkat-belajar-bundle.js` | baru: skrip penerap patch bundle | – |

Hash file PHP hanya berlaku bila file itu tidak berubah karena patch lain. Jika berbeda, bandingkan isinya dengan `docs/usulan-btikp/backend-perangkat-belajar.diff`.

**Skema:** tidak ada kolom baru dan tidak ada migrasi data. Index `idx_lesson_author (authorId)` pada `lesson_rpps` dibuat otomatis (idempoten) saat guru pertama kali membuat dokumen setelah patch.

## 2. Ringkasan perilaku backend

Detail lengkap ada di spesifikasi §3.2–§3.3.

- Role dibaca ulang dari tabel `users` pada setiap request (`loadFreshUser`).
- `get_lesson_rpps`:
  - wajib login (401); hanya GURU, KEPALA_SEKOLAH, dan ADMIN (lainnya 403);
  - GURU hanya menerima dokumen dengan `authorId` = uid-nya;
  - kolom DB menimpa isi payload untuk `id`, `type`, `authorId`/`authorName`, `teacherId`/`teacherName`, `status`, `verified*`, dan `fileUrl`;
  - field baru: `isInteractive`, `canEdit`, `canDelete`.
- `save_lesson_rpp`:
  - hanya GURU dan hanya `POST`;
  - tanpa `id` → dokumen baru milik aktor dengan status `PENDING`;
  - dengan `id` → harus ada (404), milik aktor (403), dan belum VERIFIED (409). Proses berupa `UPDATE` yang tidak mengubah pemilik, status, atau `createdAt`;
  - `status`/`verified*` dari body diabaikan; `type` harus RPP/SILABUS/MODUL/ADMINISTRASI (400);
  - `fileUrl` wajib berada di `uploads/tu/lesson/<id>/` (400).
- `delete_lesson_rpp`: `DELETE`/`POST` saja (GET → 405). GURU hanya dokumen miliknya yang belum VERIFIED. ADMIN boleh menghapus dokumen apa pun. Folder `uploads/tu/lesson/<id>/` ikut dihapus.
- `verify_lesson_rpp`: KEPALA_SEKOLAH/ADMIN, hanya `POST`, id tidak ada → 404.
- `tu_file_upload` `module=lesson`: GURU pemilik, dokumen ada, dan belum VERIFIED. Pengecekan dilakukan sebelum file ditulis.

## 3. Patch frontend

Terapkan dari root deploy, **setelah** patch pembina ekskul:

```
node docs/patches/apply-perangkat-belajar-bundle.js
```

Skrip ini:
1. memverifikasi hash `-ekskul1.js` dan berhenti jika berbeda;
2. memastikan prefiks identifier sisipan `__pb` belum dipakai;
3. memastikan setiap anchor muncul sesuai jumlah yang diharapkan (1, kecuali F1b = 2);
4. menulis `assets/index-CGWL69P7-perangkat1.js`;
5. mengarahkan `app.html` dari `-ekskul1.js` ke `-perangkat1.js`.

Skrip aman dijalankan ulang.

| # | Komponen | Perubahan |
|---|---|---|
| F1a–e | `Jat` (6 titik muat ulang daftar) | Filter `type==="RPP"\|\|!type` diganti `type!=="SILABUS"`, sehingga MODUL dan ADMINISTRASI tidak lagi dibuang |
| F2a/b | `Jat` pemetaan `jn` | `type` dan `isInteractive` mengikuti data; bawa `teacherId`, `teacherName`, `canEdit`, `canDelete`, `fileUrl` |
| F3a/b | `Jat` hapus dokumen unggahan (list & grid) | `Zn(id,!1)` (hanya state lokal) diganti `Zn(id,!0)` (server) |
| F4a–h | `Jat` aksi (list & grid) | Edit bila `canEdit`, Hapus bila `canDelete`; label "Terkunci" untuk dokumen VERIFIED milik guru |
| F5a–c | `Jat` toolbar atas | Buat RPP, Buat Silabus, dan Upload hanya untuk GURU |
| F6a–c | `Jat` | State role dan daftar akun GURU (`get_users`, khusus KS/ADMIN); dropdown "Semua guru"/per guru; filter diterapkan pada `W` (daftar + penghitung) |
| F7a–d | `Jat` tabel & grid | Kolom/label Guru untuk KS/ADMIN; `colSpan` 8 |
| F8 | `Jat` panel Sisa Target | KS/ADMIN dengan "Semua guru": diganti teks "Pilih guru untuk melihat sisa target dokumen semester." |
| F9a/b | `Zat` modal upload | Default semester = semester yang sedang dipilih (sebelumnya selalu GENAP) |
| F10a–g | `Jat` handler simpan/verifikasi/hapus/upload | `alert` menampilkan pesan error backend |
| F12a/b | `Qat` dokumen unggahan | "Diupload Oleh" = `teacherName`; "Izin Akses" = "Guru pemilik, Kepala Sekolah & Admin" |
| F13 | `Qat` dokumen unggahan | Tombol "Unduh Berkas" membuka file; tombol "Verifikasi & Setujui" / badge "Terverifikasi" untuk KS/ADMIN |

Hal yang sudah diverifikasi:
- semua kelas Tailwind yang disisipkan ada di `assets/index-CC1PBzaO.css`. Awalnya `max-w-[14rem]` dan `-mt-3` tidak ada, lalu diganti `max-w-xs` dan `-mt-1`;
- `et.users.list()` memanggil `get_users`, yang tersedia untuk KEPALA_SEKOLAH dan ADMIN;
- `delete` di bundle memakai method `DELETE`, sedangkan `save`, `verify`, dan upload memakai `POST`.

Setelah deploy, minta pengguna melakukan *hard refresh*.

## 4. Langkah setelah deploy (admin sekolah)

1. Beri tahu guru dan Kepala Sekolah:
   - guru kini hanya melihat dokumennya sendiri;
   - dokumen yang sudah diverifikasi terkunci (koreksi lewat Admin);
   - KS tidak lagi membuat atau mengunggah dokumen.
2. Audit dokumen yatim (tanpa pemilik yang valid). Dokumen ini hanya terlihat oleh KS/ADMIN:
   ```sql
   SELECT r.id, r.type, r.title, r.authorId, r.authorName, r.status
   FROM lesson_rpps r LEFT JOIN users u ON u.uid = r.authorId COLLATE utf8mb4_unicode_ci
   WHERE u.uid IS NULL;
   ```
3. Bila user DB aplikasi tidak punya hak `ALTER`, buat index secara manual:
   `ALTER TABLE lesson_rpps ADD INDEX idx_lesson_author (authorId);`

## 5. Re-apply setelah auto-update

1. Catat `api/version.php` dan file `assets/index-*.js` yang direferensikan `app.html`.
2. Jika versi atau bundle berubah, cek apakah upstream sudah memperbaiki isolasi data dan tampilan MODUL/ADMINISTRASI (spesifikasi E1, E2, E6, E10). Jika sudah, patch ini tidak diperlukan lagi.
3. Terapkan ulang patch wali kelas, rekap bulanan, dan pembina ekskul lebih dulu.
4. **Backend:**
   - salin `api/lesson_access.php` bila hilang;
   - pastikan `loadFreshUser()` masih ada di `api/kesiswaan_access.php`;
   - terapkan hunk dari `docs/usulan-btikp/backend-perangkat-belajar.diff` ke `api/index.php` dan `api/endpoints_tu.php`;
   - pastikan keempat `case` `*_lesson_rpp*` hanya ada 1 di keempat file endpoint.
5. **Frontend:** bila `-ekskul1.js` punya hash berbeda, verifikasi manual bahwa semua anchor masih muncul sesuai jumlah dan variabel minify (`e`, `b`, `N`, `W`, `I`, `re`, `Zn`, `i`, `s`) masih bermakna sama. Setelah itu perbarui `SRC_SHA256` dan jalankan skrip.
6. Jalankan `php -l api/index.php api/endpoints_tu.php api/lesson_access.php` dan `node --check assets/index-CGWL69P7-perangkat1.js`.
7. Ulangi uji manual spesifikasi E1, E2, E6, E7, E10, E12, E13, dan E14.

## 6. Rollback

1. Di `app.html`, kembalikan `./assets/index-CGWL69P7-perangkat1.js` menjadi `./assets/index-CGWL69P7-ekskul1.js`.
2. Pulihkan `api/index.php` dan `api/endpoints_tu.php` dari salinan sebelum patch (atau `git checkout 43828a9 -- api/index.php api/endpoints_tu.php`).
3. Hapus `api/lesson_access.php` (**setelah** langkah 2).

Index `idx_lesson_author` aman dibiarkan. Data dokumen tidak berubah.

## 7. Hasil verifikasi (lingkungan dev lokal, 2026-09-15)

**Lingkungan:**
- Laragon, Apache + PHP 8.2 (`SIMANTAP_DEV_MODE`), MySQL 5.7.33;
- data lokal: 60 GURU, 6 dokumen `lesson_rpps` (3 RPP, 3 MODUL, semua milik satu guru, file fisik tidak ada di lokal);
- 5 akun uji sementara `uji_lesson_{a,b,ks,admin,tu}` dibuat via SQL, lalu dihapus setelah pengujian beserta seluruh dokumen uji dan foldernya.

**Pemeriksaan statis:**
- `php -l` lolos untuk ketiga file PHP;
- `node --check` lolos untuk `-perangkat1.js`;
- hash `-ekskul1.js` tidak berubah;
- 38 anchor cocok;
- skrip dijalankan ulang tanpa error.

**Backend (smoke test API sementara, tidak disimpan di repo): 31/31 lulus.** Cakupan:
- E1/E2: alur upload MODUL (simpan → upload → simpan `fileUrl`); tipe MODUL, `PENDING` walau body mengirim `VERIFIED`, dan `teacherId` palsu diabaikan;
- E6–E8: guru B tidak melihat dokumen A; hapus, edit, dan upload lintas pemilik → 403;
- id tidak ada → 404; `fileUrl` di luar folder → 400; tipe tidak valid → 400; GET delete → 405;
- E10 tanpa login → 401; E15 TATA_USAHA → 403;
- E12–E14: KS melihat semua tanpa `canEdit`/`canDelete`; KS buat/hapus → 403; guru verifikasi → 403; KS verifikasi → 200; setelah VERIFIED, edit/hapus/upload guru → 409;
- E16: ADMIN edit → 403, hapus VERIFIED → 200, file tidak bisa diakses lagi (404);
- E17: guru menghapus dokumen miliknya.

**UI:** belum diuji di browser dalam sesi ini. Login ke aplikasi memerlukan pengisian password, sehingga uji UI dilakukan manual oleh sekolah sesuai spesifikasi §9.3 (E1–E5, E12–E14, E17–E19). Potongan kode hasil patch sudah ditinjau terhadap anchor dan dicek sintaksnya.

**Belum diuji:**
- seluruh skenario UI (§9.3), termasuk tampilan filter guru, panel target, label Terkunci, dan tombol Verifikasi pada modal dokumen unggahan;
- regresi menu wali kelas, rekap bulanan, dan ekstrakurikuler (E18);
- lebar layar ponsel;
- pengujian di **staging** tetap wajib sebelum produksi.
