# Patch lokal: Ekstrakurikuler untuk Admin, Guru Pembina, dan Wali Kelas

- Spesifikasi: `docs/specs/pembina-ekstrakurikuler.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch wali kelas (`docs/patches/wali-kelas-kesiswaan.md`) + patch rekap bulanan (`docs/patches/rekap-bulanan-wali-kelas.md`)
- Tanggal patch: 2026-09-14
- Status upstream: **belum** ada fitur resmi. Dokumen ini sekaligus menjadi lampiran usulan ke BTIKP (`docs/usulan-btikp/RINGKASAN-PEMBINA-EKSKUL.md`).

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/ekskul_access.php` | baru: helper otorisasi ekskul (level akses, filter wali, validasi pembina, hitungan menu) | `eac765fdaba527ae6c0dc825b5c27c39af9c481b63648800b9ef8ddde5750195` |
| `api/kesiswaan_access.php` | ubah: logika kelas perwalian dipindah ke fungsi baru `homeroomClassScope()` (perilaku `kesiswaanScope` tidak berubah) | `bf19cdbe684a5f2f20eafa63374328db86438de1bb15049fd3157bb3f3349bff` |
| `api/index.php` | ubah: `require_once` helper; `login`, `session`, `save_user`, `delete_user`, `get_extracurriculars`, `get_ekskul_journals`, `save_ekskul_journal`, `save_extracurricular`, `delete_extracurricular` | `011ebaf7c595ea276fa0e3bd11d4d5b6ba625268983568f97fc3b5419106db92` |
| `api/endpoints_v3.php` | ubah: `get/save/delete_extracurricular_member(s)`, `get/save/delete_extracurricular_activit(y/ies)`, `save_user_v2` | `4dbf68bc8baa8fedb7e3f618310f0aab2039441c44c73c1a94726fe66f08b7c2` |
| `api/endpoints_tu.php` | ubah: `gtk_delete` mengosongkan pembina | `3e4e0758af38fbc664044f938dbf562a1d9ae71fcbde5de3aea71e372fccd43b` |
| `assets/index-CGWL69P7-wali2.js` | **tidak diubah** (sumber patch) | `b4b4a68973c30eb13d1b7d6ea6d69141dbd5b3418caacce596cee7810f30c1f3` |
| `assets/index-CGWL69P7-ekskul1.js` | baru: salinan `-wali2.js` dengan patch F1–F13 | `c5d02ea3b2a80424a8ec7aca3b2f013eeb7472f4944a88341968b1c4ca62e33e` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-ekskul1.js` | `9be8d432e773971207ae873ea27c9f09ebdfad4285a1d4a59387517767cda38c` |
| `docs/patches/apply-pembina-ekskul-bundle.js` | baru: skrip penerap patch bundle | – |
| `docs/patches/uji-pembina-ekskul-api.mjs` | baru: skrip uji matriks akses API (dev/staging) | – |

Hash file PHP hanya berlaku bila file itu tidak berubah karena patch lain. Jika berbeda, bandingkan isinya dengan `docs/usulan-btikp/backend-pembina-ekskul.diff`, bukan dengan hash.

Tidak ada perubahan skema dan tidak ada migrasi data.

## 2. Ringkasan perilaku backend

Detail lengkap ada di spesifikasi §3.2–§3.3.

- Level akses per ekskul dihitung ulang dari DB pada setiap request:
  - `ADMIN`: kelola penuh;
  - `MONITOR` (KEPALA_SEKOLAH): baca saja;
  - `COACH` (GURU dengan `coachId = uid`): kelola, kecuali nama, pembina, dan hapus;
  - `HOMEROOM` (GURU wali yang siswanya anggota aktif): baca saja, data siswa perwaliannya;
  - `NONE`.
- Semua endpoint ekskul wajib login (401). TATA_USAHA mendapat 403. Mutasi menolak `GET` (405).
- `get_extracurriculars` menambah field `access`, `coachValid`, `coachNameLegacy`, dan `memberCount`. `coachName` diambil dari `users.displayName`, atau berisi "Belum ada pembina".
- `get_ekskul_journals` menambah `canManage`. Untuk wali, `attendanceRate` dihitung dari siswa perwalian (`null` bila tidak ada).
- `save_extracurricular` memakai `INSERT`/`UPDATE`, bukan `REPLACE`, sehingga `createdAt`/`students`/`activities` tidak ter-reset. `coachId` wajib akun GURU (400).
- `save_extracurricular_member` mengambil nama dan kelas dari tabel `students`.
- `save_extracurricular_activity` menolak `id` milik ekskul lain (403).
- `login`/`session` menambah `ekskul_coach_count` dan `ekskul_homeroom_count` untuk sidebar.
- `delete_user`, `gtk_delete`, dan `save_user`/`save_user_v2` dengan role bukan GURU mengosongkan `coachId`/`coachName`.

## 3. Patch frontend

Terapkan dari root deploy, **setelah** patch wali kelas dan rekap bulanan:

```
node docs/patches/apply-pembina-ekskul-bundle.js
```

Skrip ini:
1. memverifikasi hash `-wali2.js` dan berhenti jika berbeda;
2. memastikan prefiks identifier sisipan `__ek` belum dipakai;
3. memastikan setiap anchor muncul **tepat 1 kali**;
4. menulis `assets/index-CGWL69P7-ekskul1.js`;
5. mengarahkan `app.html` dari `-wali2.js` ke `-ekskul1.js`.

Skrip aman dijalankan ulang.

| # | Komponen | Anchor (ringkas) | Perubahan |
|---|---|---|---|
| F1 | menu | `{id:"extracurricular",…,roles:["KEPALA_SEKOLAH","GURU"]}` | Tambah `"ADMIN"` |
| F2 | sidebar GURU non-BK | `…\|\|Y.title==="Umum & Ekskul")return!1` | Item `extracurricular` lolos bila `ekskul_coach_count>0 \|\| ekskul_homeroom_count>0`. Item lain di grup tetap tersembunyi. |
| F3 | sidebar GURU BK | `if(M){if(Y.title==="Pusat Dashboard"…)return!1}` | Item `extracurricular` disembunyikan bila kedua hitungan 0 |
| F4 | `tot` | `Y=role==="KEPALA_SEKOLAH"\|\|…` | `Y` = ADMIN; tambah `__ekMan` = ekskul dengan `canManageActivities` |
| F5 | `tot` kartu daftar | `Y?a.jsxs("div",{className:"flex gap-2"…` | Edit bila `Y \|\| access.canEditInfo`; hapus hanya `Y` |
| F6 / F6b | `tot` Input Kegiatan & picker `rot` | kondisi role tombol + `a.jsx(rot,{ekskuls:i,` | Tampil bila `Y \|\| __ekMan.length>0`; picker memakai `__ekMan` |
| F7 / F7b | `tot` kartu jurnal | kondisi role aksi + `children:[X.attendanceRate,"%"]` | Aksi bila `X.canManage`; rate `null` tampil `--` |
| F8a–e | `not` form | `coachId:(t==null?void 0:t.coachId)\|\|""`, awal fungsi, input nama, input pembina, `console.error("Create ekskul error:",h)` | Pembina tidak valid dimulai kosong; muat `et.users.list()` (khusus ADMIN, filter GURU, urut nama); nama disabled untuk non-ADMIN; input teks pembina menjadi `select` (ADMIN) atau teks disabled (pembina) + petunjuk "Tercatat sebelumnya"; alert berisi pesan error backend |
| F9 | `not` submit | `coachId:o.coachId\|\|e.uid` | Tanpa fallback uid pembuat |
| F10a/b, F11 | `sot` | awal fungsi, tombol Tambah Anggota, tombol keluarkan anggota | Flag `__ekMem`/`__ekAct` dari `ekskul.access`; tombol anggota bila `__ekMem` |
| F12, F13 | `sot` | kondisi role aksi aktivitas, tombol Catat Aktivitas | Bila `__ekAct` |

Semua sisipan membaca `access` dengan optional check. Bila backend belum di-patch, tombol kelola tersembunyi untuk non-ADMIN (fail-closed).

Hal yang sudah diverifikasi:
- kelas Tailwind `text-amber-600` dan `cursor-not-allowed` ada di `assets/index-CC1PBzaO.css`;
- `et.users.list()` memanggil `get_users`, yang tersedia untuk ADMIN;
- SPA memanggil `session` saat dimuat dan memakai `user`-nya untuk sidebar, sehingga menu mengikuti penugasan setelah refresh.

Setelah deploy, minta pengguna melakukan *hard refresh*.

## 4. Langkah setelah deploy (admin sekolah)

1. Beri tahu Kepala Sekolah dan guru BK bahwa akses tulis ekskul kini hanya milik Admin dan pembina.
2. Jalankan kueri audit data lama, lalu assign ulang pembina lewat **Ekstrakurikuler → Daftar Ekskul → Edit**:
   ```sql
   SELECT e.id, e.name, e.coachId, e.coachName AS coach_tercatat, u.displayName AS akun, u.role
   FROM extracurriculars e LEFT JOIN users u ON u.uid = e.coachId
   ORDER BY u.role, e.name;
   ```
   Baris dengan `role = 'GURU'` langsung dianggap pembina sah. Tinjau apakah guru itu memang pembinanya, karena kode lama mengisi `coachId` dengan uid pembuat ekskul.
3. Guru yang baru di-assign cukup me-refresh halaman untuk melihat menu.

## 5. Re-apply setelah auto-update

1. Catat `api/version.php` dan file `assets/index-*.js` yang direferensikan `app.html`.
2. Jika versi atau bundle berubah, cek dulu apakah upstream sudah menyediakan fitur resmi (spesifikasi E1, E4, E8, E17, E28). Jika sudah, patch ini tidak diperlukan lagi.
3. Terapkan ulang patch wali kelas dan rekap bulanan lebih dulu.
4. **Backend:**
   - salin `api/ekskul_access.php` bila hilang;
   - pastikan `homeroomClassScope()` ada di `api/kesiswaan_access.php`;
   - terapkan hunk dari `docs/usulan-btikp/backend-pembina-ekskul.diff` ke `api/index.php`, `api/endpoints_v3.php`, dan `api/endpoints_tu.php`;
   - pastikan setiap `case` ekskul hanya ada 1 di keempat file endpoint.
5. **Frontend:** bila `-wali2.js` punya hash berbeda, verifikasi manual bahwa semua anchor di skrip masih muncul tepat 1 kali dan variabel minify (`Y`, `i`, `X`, `e`, `t`, `o`, `l`, `ne`, `s`, `M`) masih bermakna sama. Setelah itu perbarui `SRC_SHA256` dan jalankan skrip.
6. Jalankan `php -l` untuk kelima file PHP dan `node --check assets/index-CGWL69P7-ekskul1.js`.
7. Jalankan `docs/patches/uji-pembina-ekskul-api.mjs` di staging (§6), lalu uji UI: spesifikasi E1, E7, E8, E16, E17, E24, E26, E30.

## 6. Menjalankan skrip uji API (dev/staging saja)

Skrip **mengubah data**:
- membuat dua ekskul uji lalu menghapusnya;
- mengubah pembina, jadwal, ruang, dan deskripsi ekskul `EA`;
- menambah dan menghapus anggota serta aktivitas uji;
- menghapus akun `uji_hapus`;
- mengubah role `uji_rangkap` bolak-balik (membuat baris `gtk_pegawai` `uji_rangkap`).

Langkah:
1. Backup database.
2. Buat akun uji dengan email `<uid>@uji.test` dan password yang sama:
   - `uji_admin` (ADMIN), `uji_ks` (KEPALA_SEKOLAH), `uji_tu` (TATA_USAHA);
   - `uji_pembina`, `uji_guru`, `uji_hapus` (GURU);
   - `uji_wali` (GURU, `class_homeroom` = kelas semua anggota `EA`);
   - `uji_rangkap` (GURU, `class_homeroom` = kelas `STUDENT_B`);
   - `uji_bk` (GURU, `is_bk=1`).
3. Pilih `EA` (ekskul yang semua anggota aktifnya siswa kelas `uji_wali`), `STUDENT_A` (anggota `EA`), dan `STUDENT_B` (siswa kelas `uji_rangkap` yang bukan anggota `EA`).
4. Jalankan:
   ```
   BASE=https://<host>/api/index.php EA=<id> STUDENT_A=<id> STUDENT_B=<id> UJI_PASSWORD=<pw> node docs/patches/uji-pembina-ekskul-api.mjs
   ```
5. Setelah selesai:
   - hapus akun `uji_%` dan `gtk_pegawai` `uji_rangkap`;
   - kembalikan pembina, jadwal, ruang, dan deskripsi `EA`.

## 7. Rollback

1. Di `app.html`, kembalikan `./assets/index-CGWL69P7-ekskul1.js` menjadi `./assets/index-CGWL69P7-wali2.js`.
2. Pulihkan `api/index.php`, `api/endpoints_v3.php`, `api/endpoints_tu.php`, dan `api/kesiswaan_access.php` dari salinan sebelum patch (atau `git checkout 3912dc8 -- <file>`).
3. Hapus `api/ekskul_access.php` (**setelah** langkah 2).

Tidak ada perubahan skema. `coachId` yang sudah di-assign tetap tersimpan.

## 8. Hasil verifikasi (lingkungan dev lokal, 2026-09-14)

**Lingkungan:**
- Laragon, Apache + PHP 8.2 (`SIMANTAP_DEV_MODE`), MySQL 5.7.33 dengan `STRICT_TRANS_TABLES` dan `ONLY_FULL_GROUP_BY`;
- data sekolah lokal: 60 GURU, 1 ekskul ("Web Design", 7 anggota kelas `XII_TKJ_1`, 1 jurnal);
- akun `uji_*` dibuat via SQL. `uji_wali` memakai `class_homeroom` `XII-TKJ-1` untuk menguji normalisasi terhadap id `XII_TKJ_1`.

Semua data uji dibersihkan setelah pengujian, dan ekskul "Web Design" dikembalikan ke nilai awal (`createdAt` tidak berubah).

**Pemeriksaan statis:**
- `php -l` lolos untuk kelima file PHP;
- `node --check` lolos untuk `-ekskul1.js`;
- hash `-wali2.js` tidak berubah;
- 20 anchor unik;
- setiap `case` ekskul hanya ada 1.

**Backend (`uji-pembina-ekskul-api.mjs`): 64/64 lulus.** Cakupannya:
- spesifikasi E2–E4, E6–E14, E16–E20, E22–E29, E31, E32;
- pencabutan pembina langsung berlaku di API;
- siswa tidak ada → 400;
- nama/kelas anggota dari tabel `students`;
- `id` aktivitas lintas ekskul → 403.

Regresi patch wali kelas (via HTTP):
- `get_attendance_records` kelas sendiri 200, kelas lain 403;
- `get_incidents` 200;
- `get_homeroom_dashboard` 200.

**UI (Chrome, akun uji), tanpa error console:**
- **ADMIN:** menu Ekstrakurikuler tampil. Form edit berisi dropdown 65 akun GURU (opsi pertama "— Belum ada pembina —") dengan pembina saat ini terpilih.
- **Pembina:** sidebar menampilkan Ekstrakurikuler tanpa Literasi, Keagamaan, dan Kebersihan.
  - Tab Jurnal: tombol Input Kegiatan dan aksi kartu jurnal tampil.
  - Daftar Ekskul: tombol Edit tampil, tanpa Hapus maupun Tambah Ekskul.
  - Form edit: Nama dan Pembina disabled; Jadwal, Ruang, dan Deskripsi aktif.
  - Detail: Tambah Anggota, 7 tombol keluarkan, Catat Aktivitas, dan aksi aktivitas tampil.
- **Wali kelas:** menu Kesiswaan dan Dashboard Kelas tetap ada, dan Ekstrakurikuler tampil. Tidak ada Input Kegiatan, aksi jurnal, Edit, Tambah Anggota, tombol keluarkan, Catat Aktivitas, maupun aksi aktivitas. Anggota dan presensi tampil.
- **Guru BK non-pembina:** Literasi, Keagamaan, dan Kebersihan tampil, Ekstrakurikuler tidak.
- **KEPALA_SEKOLAH:** data terlihat tanpa satu pun tombol kelola.

**Belum diuji:**
- alur simpan lewat UI (klik Simpan di form, tambah anggota lewat modal, catat aktivitas lewat modal). Endpoint-nya sudah teruji via API, dan dialog `confirm`/`alert` sengaja tidak dipicu di browser otomatis;
- `gtk_delete` (hook identik dengan `delete_user`, tetapi tidak dijalankan agar tidak menghapus data GTK);
- ekskul dengan `coachNameLegacy` (petunjuk "Tercatat sebelumnya" di form);
- lebar layar ponsel dan browser selain Chrome;
- pengujian di **staging** (spesifikasi §9) tetap wajib sebelum produksi.
