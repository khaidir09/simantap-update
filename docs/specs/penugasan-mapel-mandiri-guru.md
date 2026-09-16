# Spesifikasi: Penugasan Mata Pelajaran Mandiri oleh Guru

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/penugasan-mapel-mandiri-guru.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch lokal wali kelas, rekap bulanan, pembina ekskul, dan perangkat belajar |
| Bundle frontend acuan | `assets/index-CGWL69P7-perangkat1.js` (SHA-256 `518c88dbd27e812779a661bdadfff87cdf3c858f6a94de0619d05dbf9d972537`), direferensikan `app.html` saat ini |
| Tanggal | 2026-09-16 |
| Jalur rilis | Patch lokal pada bundle ini **dan** paket usulan perubahan ke upstream BTIKP |

## 1. Latar belakang dan kebutuhan bisnis

Penugasan mata pelajaran (mapping guru ↔ mapel ↔ kelas, tabel `teacher_subjects`) menentukan pilihan mapel dan kelas yang tampil saat guru mengisi **Jurnal Mengajar** dan **Nilai/Ujian**. Saat ini penugasan hanya bisa diatur lewat modal edit user milik Admin.

Masalah yang dilaporkan:
1. **Admin kewalahan.** Admin harus menginput penugasan semua guru satu per satu, terutama di awal semester.
2. **Jurnal terhambat.** Selama penugasan belum diinput, guru tidak bisa memilih mapel dan kelas yang benar. Tanpa penugasan, form menampilkan semua mapel dan kelas, sehingga data jurnal rawan salah pilih.

Tujuan perubahan:
- **GURU** dapat menambah, mengubah (hapus lalu tambah), dan menghapus penugasan mapelnya sendiri dari halaman **Profil**. Perubahan **langsung berlaku** tanpa persetujuan.
- **ADMIN** tetap dapat mengelola penugasan semua guru dari modal edit user.
- Role lain tidak dapat mengubah penugasan siapa pun. Pembatasan **ditegakkan di backend**.
- Data yang disimpan tervalidasi di server: mapel ada di master, kelas ada, dan tidak ada duplikat.

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Backend (`api/index.php`)

Keempat action hanya ada di `api/index.php`. Tidak ada duplikat di `endpoints_v2.php`, `endpoints_v3.php`, atau `endpoints_tu.php`.

| Action | Baris | Auth saat ini | Catatan |
|---|---|---|---|
| `get_teacher_subjects` | ~1495 | **tanpa auth** | `?teacher_id=` opsional. Tanpa parameter, mengembalikan penugasan **semua** guru. LEFT JOIN `subjects` dengan `COLLATE utf8mb4_unicode_ci`. `class_ids` di-decode menjadi array. |
| `save_teacher_subjects` | ~1513 | `requireRole(['ADMIN','KEPALA_SEKOLAH'])` | `REPLACE INTO` satu baris. **Tidak dipakai bundle.** |
| `save_teacher_subjects_bulk` | ~1533 | `requireAuth()` | GURU sudah dibatasi ke `teacher_id === uid`. **TATA_USAHA, KEPALA_SEKOLAH, dan role lain dapat mengubah penugasan guru mana pun.** Cara kerja: `DELETE` semua baris guru, lalu `INSERT` ulang dalam transaksi. `subject_name`, `class_level`, dan `class_ids` diterima apa adanya dari body tanpa validasi. Item tanpa `subject_code` dilewati diam-diam. Setelah commit, `gtk_pegawai.mapel`/`kelas_ajar` disinkronkan **hanya bila hasilnya tidak kosong**, sehingga mengosongkan semua penugasan meninggalkan data basi di modul TU. Method HTTP tidak dicek. Pesan error mengandung detail exception DB. |
| `delete_teacher_subject` | ~1609 | `requireRole(['ADMIN','KEPALA_SEKOLAH'])` | Hapus per `id` via GET. **Tidak dipakai bundle.** |

Penulis dan pembaca lain `teacher_subjects` (tidak diubah oleh spesifikasi ini):
- `delete_user` (`api/index.php` ~294) dan `gtk_delete` (`api/endpoints_tu.php` ~371) menghapus baris guru;
- `gtk_update` (`api/endpoints_tu.php` ~311) membangun ulang `teacher_subjects` dari `gtk_pegawai.mapel`;
- `gtk_list` (`api/endpoints_tu.php` ~59) membaca `teacher_subjects` sebagai fallback.

Tabel `teacher_subjects` (`install/schema.sql` ~532): `id` (PK auto increment), `teacher_id` varchar(128), `subject_code` varchar(100), `subject_name` varchar(255), `class_level` varchar(50) NULL, `created_at`, `class_ids` text (JSON array id kelas). Tidak ada unique key.

Tabel `subjects` (`install/schema.sql` ~458): `id`, `name`, `is_active`, `sort_order`, `category`, `description`.

Tabel `classes` tidak ada di `schema.sql`. Tabel ini dibuat oleh `get_classes` (`api/endpoints_v3.php` ~554) dengan kolom `id` (PK), `name`, `level`, `homeroom_teacher`, `created_at`. Nilai `level` hasil seed adalah token pertama `class_id` (misalnya `X`, `XI`, `XII`).

### 2.2 Frontend (`assets/index-CGWL69P7-perangkat1.js`)

- **Klien API.** `et.teacherSubjects = { get: e => yt("get_teacher_subjects&teacher_id="+e), save: (e,t) => yt("save_teacher_subjects_bulk","POST",{teacher_id:e, subjects:t}) }`.
- **Modal edit user (Admin).** Bagian "Penugasan Mata Pelajaran" berisi:
  - **Mata Pelajaran**: dropdown dari `get_subjects&active_only=1`;
  - **Level Kelas**: `— Semua Level —`/`X`/`XI`/`XII`;
  - **Kelas Spesifik yang Diampu**: chip kelas yang sesuai level, *opsional, kosong = semua*;
  - tombol "Tambahkan ke Daftar". Tombol ini menolak duplikat `subject_code` + `class_level` di klien dengan alert "Mapel dengan level kelas yang sama sudah ada…".

  Daftar item hanya punya tombol hapus, tanpa edit. Tombol simpan memanggil `et.users.save(...)` lalu `et.teacherSubjects.save(uid, daftar)`. Kalau gagal, pesannya generik: "Gagal menyimpan. Cek koneksi server."
- **Halaman Profil** (komponen `uot`, dirender saat tab `profile`): bisa dibuka semua role. Isinya edit nama/NIP/sekolah (`update_profile`), ganti password, dan foto. Belum ada bagian penugasan.
- **Pemakai data penugasan** (hanya untuk `role === "GURU"`, memanggil `get_teacher_subjects&teacher_id=<uid sendiri>`):
  - **form Jurnal Mengajar**: daftar kelas disaring dari gabungan `class_ids`. Kalau penugasan kosong, atau `class_ids` kosong, semua kelas tampil;
  - **halaman Nilai/Ujian**: daftar mapel disaring dari `subject_code` penugasan (fallback ke semua mapel), dan kelas disaring dari `class_ids` mapel terpilih.

## 3. Expected behavior

### 3.1 Definisi

- **Item penugasan**: satu objek `{subject_code, subject_name, class_level, class_ids[]}`, sama dengan satu baris `teacher_subjects`.
- **Kunci duplikat**: pasangan `subject_code` + `class_level` (dibandingkan setelah trim dan tanpa beda huruf besar/kecil). `class_level` kosong juga dihitung sebagai nilai.
- **Item lama tersimpan (legacy)**: item di body yang **identik** dengan salah satu baris `teacher_subjects` guru tersebut sebelum disimpan. Identik berarti `subject_code`, `subject_name`, dan `class_level` sama, dan himpunan `class_ids` sama (urutan diabaikan). Pencocokan bersifat multiset: satu baris tersimpan hanya bisa dipakai untuk satu item.
- **Item baru**: setiap item di body yang bukan item lama tersimpan.
- **Perlu dilengkapi**: item lama yang tidak lolos aturan item baru untuk aktor tersebut (misalnya tanpa kelas spesifik, mapel nonaktif atau hilang, atau kelas sudah dihapus).

### 3.2 Matriks akses

| Aksi | GURU (milik sendiri) | GURU (guru lain) | ADMIN | KEPALA_SEKOLAH | TATA_USAHA | Role lain | Tanpa login |
|---|---|---|---|---|---|---|---|
| Baca (`get_teacher_subjects`) | Ya | 403 | Semua | Semua | Semua | 403 | 401 |
| Simpan bulk (`save_teacher_subjects_bulk`) | Ya | 403 | Semua guru | 403 | 403 | 403 | 401 |
| `save_teacher_subjects` (tunggal, legacy) | 403 | 403 | Ya | 403 | 403 | 403 | 401 |
| `delete_teacher_subject` (legacy) | 403 | 403 | Ya | 403 | 403 | 403 | 401 |

"Role lain" berarti setiap nilai `users.role` selain `GURU`, `ADMIN`, `KEPALA_SEKOLAH`, dan `TATA_USAHA`. GURU dengan `is_bk=1` atau `class_homeroom` terisi diperlakukan sama seperti GURU biasa.

Target simpan bulk untuk ADMIN adalah uid mana pun. Server tidak memvalidasi bahwa `teacher_id` ber-role GURU, supaya perilaku modal Admin tetap sama.

### 3.3 Perilaku detail per endpoint

Semua pengecekan memakai session (`requireAuth`). Role lalu dibaca ulang dari DB lewat `loadFreshUser()` (`api/kesiswaan_access.php`), sehingga perubahan role atau penghapusan user langsung berlaku. User yang sudah dihapus mendapat 401. Aktor diambil dari session, bukan dari body. Semua validasi dilakukan **sebelum** transaksi atau query mutasi, karena mysqli PHP 8.2 melempar exception.

Helper otorisasi dan validasi disarankan ditaruh di file baru `api/teacher_subject_access.php`, mengikuti pola `api/lesson_access.php`, lalu di-`require_once` dari `api/index.php`. Helper yang dibutuhkan:
- `tsUser($db, array $roles)`: user segar + cek role (401/403);
- `tsRequireMethod(array $allowed)`: 405;
- `tsLoadRows($db, $teacherId)`: baris tersimpan;
- `tsNormalizeItem(array $raw)`: trim, alias `code`/`name`, dan `class_ids` menjadi array string unik;
- `tsValidateItems($db, $actor, $teacherId, array $items)`: mengembalikan daftar item final atau memanggil `errorResponse`;
- `tsSyncGtk($db, $teacherId, array $items)`.

#### `get_teacher_subjects`
- `tsUser($db, ['GURU','ADMIN','KEPALA_SEKOLAH','TATA_USAHA'])`.
- GURU:
  - `teacher_id` kosong → diperlakukan sebagai uid sendiri;
  - `teacher_id` berbeda dari uid → 403 `Anda hanya dapat melihat penugasan mata pelajaran milik sendiri`.
- Role lain yang diizinkan: perilaku sama seperti sekarang (dengan atau tanpa `teacher_id`).
- Bentuk respons tidak berubah (array baris + `subject_name_full`, `category`, dan `class_ids` sebagai array). Pertahankan `COLLATE utf8mb4_unicode_ci` pada JOIN.

#### `save_teacher_subjects_bulk`
1. `tsUser($db, ['GURU','ADMIN'])`. Hanya `POST`; method lain → 405.
2. `teacher_id` wajib (400 `teacher_id wajib diisi`). GURU dengan `teacher_id !== uid` → 403 `Anda hanya dapat mengubah penugasan mata pelajaran milik sendiri`.
3. `subjects` harus berupa array (boleh kosong). Selain array → 400.
4. Normalisasi setiap item. Item dengan `subject_code` kosong → 400 `Mata pelajaran wajib dipilih` (bukan dilewati diam-diam lagi).
5. Pisahkan item lama tersimpan dan item baru (§3.1). **Item lama tersimpan diterima tanpa validasi aturan baru** dan disimpan persis seperti sebelumnya.
6. Validasi setiap **item baru**:

   | Aturan | GURU | ADMIN | Error |
   |---|---|---|---|
   | `subject_code` ada di `subjects` | ✔ dan `is_active = 1` | ✔ (aktif maupun nonaktif) | 400 `Mata pelajaran <kode> tidak ditemukan atau tidak aktif` |
   | `subject_name` | Diambil dari `subjects.name`; nilai dari body diabaikan | Sama | — |
   | `class_level` | **Wajib** tidak kosong | Opsional (kosong = semua level) | 400 `Level kelas wajib dipilih` |
   | `class_ids` | **Wajib** minimal 1 | Opsional (kosong = semua kelas) | 400 `Pilih minimal satu kelas yang diampu` |
   | Setiap `class_ids` ada di `classes.id` | ✔ | ✔ | 400 `Kelas <id> tidak ditemukan` |
   | Bila `class_level` terisi, `classes.level` setiap kelas sama dengan `class_level` (trim, tanpa beda huruf besar/kecil) | ✔ | ✔ | 400 `Kelas <id> bukan level <level>` |

   Bila tabel `classes` belum ada dan ada item baru dengan `class_ids`, kembalikan 400 `Data kelas belum tersedia`.
7. **Duplikat**: bila dua item mana pun di daftar final memiliki kunci duplikat yang sama → 400 `Mapel <nama> level <level> tercantum lebih dari sekali`. **Pengecualian:** duplikat yang seluruh anggotanya item lama tersimpan (data lama yang memang sudah duplikat) tetap diterima.
8. Dalam **satu transaksi**:
   - `DELETE` semua baris `teacher_id`;
   - `INSERT` daftar final;
   - sinkronkan `gtk_pegawai` (`UPDATE gtk_pegawai SET mapel=?, kelas_ajar=? WHERE id=?`) dengan `mapel` = gabungan unik `subject_name` dan `kelas_ajar` = gabungan unik `class_ids` (dengan `_` diganti spasi, sama seperti sekarang). **Sinkronisasi juga dijalankan bila daftar kosong** (kedua kolom menjadi string kosong). Bila baris `gtk_pegawai` tidak ada, `UPDATE` tidak berdampak dan bukan error.
9. Kalau terjadi exception: rollback, `error_log` detailnya, lalu kembalikan 500 `Gagal menyimpan penugasan mata pelajaran` **tanpa** detail DB.
10. Respons: `{success: true, count: <jumlah baris tersimpan>, items: [<baris final seperti format get_teacher_subjects>]}`. `count` tetap ada demi kompatibilitas.

#### `save_teacher_subjects` dan `delete_teacher_subject` (legacy, tidak dipakai bundle)
- Role dipersempit menjadi `ADMIN` saja (sebelumnya ADMIN dan KS).
- `save_teacher_subjects` hanya menerima `POST`, dan `delete_teacher_subject` hanya menerima `POST` atau `DELETE` (GET → 405).
- Tidak ada validasi baru (tidak dipakai UI). Sinkronisasi GTK tidak ditambahkan.

### 3.4 Perilaku UI yang diharapkan (patch bundle)

Patch diterapkan lewat skrip `docs/patches/apply-penugasan-mapel-bundle.js`, dengan sumber `-perangkat1.js` dan hasil `-mapel1.js`. Skrip mengikuti pola `apply-perangkat-belajar-bundle.js`: cek SHA-256 sumber, pakai anchor string eksak, gagal bila prefiks identifier baru `__tm` sudah dipakai, tulis salinan baru, lalu arahkan `app.html`.

| # | Lokasi | Perubahan |
|---|---|---|
| F1 | Halaman Profil (`uot`) | Tambah kartu **"Penugasan Mata Pelajaran"** di bawah kartu profil, hanya bila `user.role === "GURU"`. Teks bantu: "Penugasan ini menentukan mapel dan kelas yang muncul di Jurnal Mengajar dan Nilai. Perubahan langsung berlaku." |
| F2 | Kartu F1: pemuatan | Saat mount, panggil paralel `get_subjects&active_only=1`, `get_classes`, dan `et.teacherSubjects.get(user.uid)`. Tampilkan status memuat. Bila gagal, tampilkan pesan error dan tombol "Coba lagi". |
| F3 | Kartu F1: form tambah | Dropdown **Mata Pelajaran** (mapel aktif, urut seperti master), dropdown **Level Kelas** (`X`/`XI`/`XII`, **tanpa** "Semua Level"; opsi diambil dari nilai `level` unik di `get_classes`, dengan fallback `X`/`XI`/`XII`), lalu chip **Kelas yang Diampu** (kelas sesuai level, wajib ≥1). Tombol "Tambahkan ke Daftar" nonaktif sampai ketiganya terisi. Mengganti level mengosongkan pilihan kelas. |
| F4 | Kartu F1: duplikat di klien | Bila `subject_code` + `class_level` sudah ada di daftar, tampilkan pesan inline "Mapel dengan level kelas yang sama sudah ada. Hapus yang lama atau gabungkan kelasnya." dan jangan tambahkan. |
| F5 | Kartu F1: daftar | Tiap item menampilkan kode, nama, badge level, chip kelas, dan tombol hapus (dengan konfirmasi inline, tanpa `window.confirm`). Item yang **perlu dilengkapi** (tanpa level, tanpa kelas, mapel tidak ada di daftar aktif, atau kelas tidak ada di `get_classes`) diberi badge kuning **"Perlu dilengkapi"** beserta alasannya. Mengubah item dilakukan dengan hapus lalu tambah. Kalau daftar kosong: "Belum ada mapel ditugaskan". |
| F6 | Kartu F1: simpan | Tombol **"Simpan Penugasan"** aktif hanya bila daftar berubah dari data server (dirty). Tombol memanggil `et.teacherSubjects.save(user.uid, daftar)`. Berhasil: pesan "Penugasan mata pelajaran tersimpan", lalu daftar diganti dengan `items` dari respons (atau dimuat ulang). Gagal: tampilkan `error` dari backend. Selama menyimpan, tombol nonaktif. |
| F7 | Kartu F1: navigasi | Bila ada perubahan belum disimpan, tampilkan teks peringatan "Ada perubahan yang belum disimpan" di dekat tombol. Tidak ada blokir navigasi. |
| F8 | Modal edit user (Admin) | Pada kegagalan `et.teacherSubjects.save`, tampilkan pesan `error` dari backend (misalnya duplikat atau kelas tidak ditemukan), bukan hanya "Cek koneksi server". Form Admin lainnya tidak berubah (level dan kelas tetap opsional). |

Menu sidebar **tidak** diubah. Form Jurnal Mengajar dan halaman Nilai **tidak** diubah; keduanya membaca data baru saat dibuka berikutnya.

## 4. Role, authorization, dan data model

- **Role:** mutasi hanya untuk `GURU` (milik sendiri) dan `ADMIN`. Baca untuk `GURU` (milik sendiri), `ADMIN`, `KEPALA_SEKOLAH`, dan `TATA_USAHA`. Role dibaca ulang dari `users` pada setiap request.
- **Persetujuan:** tidak ada. Perubahan langsung berlaku.
- **Skema:** **tidak ada kolom, tabel, index, atau migrasi baru.** Tidak ada audit siapa yang mengubah penugasan.
- **Konflik lintas guru:** dua guru boleh punya mapel dan kelas yang sama (team teaching, guru pengganti), tanpa validasi atau peringatan.
- **Jurnal dan nilai lama:** tidak disentuh. Menghapus penugasan hanya memengaruhi pilihan pada form berikutnya. Tidak ada blokir walaupun penugasan sudah dipakai di jurnal.
- **Sinkronisasi `gtk_pegawai`:** `mapel` dan `kelas_ajar` selalu mencerminkan isi `teacher_subjects` setelah simpan bulk (oleh GURU maupun ADMIN), termasuk saat kosong.

## 5. File dan interface terkait

### 5.1 Backend (patch lokal)

| File | Perubahan |
|---|---|
| `api/teacher_subject_access.php` (**baru**) | Guard `SIMANTAP_API`; helper `tsUser`, `tsRequireMethod`, `tsLoadRows`, `tsNormalizeItem`, `tsValidateItems`, `tsSyncGtk` (§3.3). Bergantung pada `loadFreshUser()` di `api/kesiswaan_access.php`. |
| `api/index.php` | `require_once __DIR__ . '/teacher_subject_access.php';`, lalu ubah case `get_teacher_subjects`, `save_teacher_subjects`, `save_teacher_subjects_bulk`, `delete_teacher_subject` sesuai §3.3. |
| `api/endpoints_v3.php` | Tidak berubah (`get_classes` hanya dibaca untuk referensi). |
| `api/endpoints_tu.php`, `api/db.php` | Tidak berubah. |

Interface API (kontrak untuk frontend):

```
GET    api/index.php?action=get_teacher_subjects[&teacher_id=<uid>]
       → 200 [ { id, teacher_id, subject_code, subject_name, class_level, class_ids: string[],
                 created_at, subject_name_full, category } ]
       → 401 | 403
POST   api/index.php?action=save_teacher_subjects_bulk
       body: { teacher_id, subjects: [ { subject_code, subject_name?, class_level, class_ids: string[] } ] }
       → 200 { success: true, count, items: [ …format get_teacher_subjects… ] }
       → 400 (validasi) | 401 | 403 | 405 | 500
POST   api/index.php?action=save_teacher_subjects        (ADMIN)  → 200 | 401 | 403 | 405
DELETE api/index.php?action=delete_teacher_subject&id=<id> (ADMIN) → 200 | 401 | 403 | 405
```

Format error tetap `{"error": "<pesan>"}` dari `errorResponse()` di `api/db.php`.

### 5.2 Frontend (patch bundle lokal)

- `docs/patches/apply-penugasan-mapel-bundle.js` (**baru**): anchor F1–F8, sumber `assets/index-CGWL69P7-perangkat1.js` (hash di header dokumen ini), prefiks identifier `__tm`.
- `assets/index-CGWL69P7-mapel1.js` (**baru**, hasil skrip).
- `app.html`: `src` diarahkan ke `-mapel1.js`.
- Komponen yang disentuh: `uot` (halaman Profil) dan modal edit user Admin (hanya handler simpan, F8). Klien `et.teacherSubjects` tidak berubah.

### 5.3 Dokumen

- `docs/specs/penugasan-mapel-mandiri-guru.md` (dokumen ini).
- `docs/patches/penugasan-mapel-mandiri-guru.md` (**baru**): file yang diubah, hash sebelum dan sesudah, anchor final, langkah re-apply, checklist pasca auto-update (§9.4), dan rollback (§9.5).
- `docs/usulan-btikp/RINGKASAN-PENUGASAN-MAPEL.md`, `docs/usulan-btikp/backend-penugasan-mapel.diff`, `docs/usulan-btikp/usulan-penugasan-mapel.zip` (**baru**): paket usulan upstream, formatnya mengikuti usulan sebelumnya.

## 6. Di luar scope

- Alur persetujuan atau verifikasi penugasan oleh Admin/KS, status `PENDING`, dan notifikasi ke Admin.
- Audit trail (siapa/kapan mengubah penugasan) dan kolom baru di `teacher_subjects`.
- Validasi atau peringatan konflik mapel+kelas antar guru.
- Blokir atau peringatan penghapusan penugasan yang sudah dipakai di jurnal/nilai.
- Banner atau tautan "Atur penugasan" di form Jurnal Mengajar dan halaman Nilai, serta perubahan fallback "tanpa penugasan = semua mapel/kelas" di kedua halaman itu.
- Edit inline item penugasan (ubah dilakukan dengan hapus lalu tambah).
- Batas jumlah penugasan per guru.
- Penugasan per semester atau tahun ajaran (`teacher_subjects` tidak punya kolom periode).
- Menu atau halaman penugasan untuk KEPALA_SEKOLAH atau TATA_USAHA, termasuk memberi KS hak ubah.
- Perubahan pada `gtk_update`/`gtk_create`/`gtk_delete`/`delete_user` dan sinkronisasi arah `gtk_pegawai → teacher_subjects`. `gtk_update` tetap dapat menimpa penugasan yang dibuat guru (dicatat sebagai risiko §10).
- Unique key database pada `teacher_subjects`.
- Uji otomatis (skrip API atau unit test).
- Celah keamanan lain yang dicatat di `CLAUDE.md`, termasuk `gtk_list` yang tetap membaca `teacher_subjects` tanpa login.
- Rilis resmi upstream. Spesifikasi ini hanya menyiapkan paket usulan.

## 7. Compatibility

- **Konsumen `get_teacher_subjects`:**
  - form Jurnal dan halaman Nilai hanya memanggilnya untuk GURU dengan uid sendiri, sehingga tetap berfungsi;
  - modal Admin memanggilnya dengan uid guru lain sebagai ADMIN, dan juga tetap berfungsi;
  - pemanggil tanpa login (tidak ada di bundle) kini mendapat 401.
- **Konsumen `save_teacher_subjects_bulk`:** hanya modal Admin (dan kartu Profil baru). Modal Admin sudah memakai `POST`. Respons hanya ditambah `items`.
- **Perubahan perilaku yang terlihat pengguna:**
  - Admin kini bisa mendapat penolakan 400 untuk duplikat atau kelas yang tidak ada. Sebelumnya duplikat hanya dicegah di klien, dan kelas tidak dicek sama sekali;
  - KS dan TU tidak lagi dapat mengubah penugasan lewat API (tidak ada UI untuk mereka sebelumnya);
  - mengosongkan penugasan kini ikut mengosongkan `mapel`/`kelas_ajar` di data GTK modul TU.

  Beri tahu sekolah sebelum rilis.
- **Data lama:** penugasan lama yang tidak memenuhi aturan baru tetap tersimpan selama tidak diubah (§3.3 langkah 5), sehingga simpan oleh guru maupun Admin tidak gagal karena data warisan. Guru melihat badge "Perlu dilengkapi".
- **Bundle lama tanpa patch** (misalnya setelah auto-update menimpa `app.html` saja): backend tetap menegakkan otorisasi dan validasi. Guru tidak punya UI untuk mengelola penugasan, jadi perilaku kembali seperti sekarang.
- **Auto-updater:** update dapat menimpa `api/index.php` dan `app.html`. File baru (`teacher_subject_access.php`, `-mapel1.js`) tetap ada tetapi tidak direferensikan. Setelah tertimpa, perilaku kembali seperti sekarang tanpa kerusakan data.
- **Patch lokal sebelumnya:** spesifikasi ini dibangun di atas `-perangkat1.js`. Wali kelas, rekap bulanan, pembina ekskul, dan perangkat belajar harus tetap berfungsi (regresi E17).
- **PHP 8.2 / collation:** `subjects` memakai `utf8mb4_general_ci`, sedangkan `teacher_subjects` memakai `utf8mb4_unicode_ci`. Pertahankan `COLLATE utf8mb4_unicode_ci` pada JOIN dan pada query validasi yang membandingkan kedua tabel. Query validasi ke `subjects`/`classes` sebaiknya memakai parameter bind per id, bukan JOIN lintas tabel.

## 8. Edge cases

| Kasus | Perilaku yang diharapkan |
|---|---|
| Guru belum punya penugasan, lalu menyimpan daftar kosong | 200, `count: 0`. `gtk_pegawai.mapel`/`kelas_ajar` menjadi kosong. |
| Guru menghapus semua penugasan | Sama seperti di atas. Form Jurnal kembali menampilkan semua kelas (fallback lama). |
| Guru mengirim `teacher_id` guru lain via console | 403, data tidak berubah. |
| KS/TU mengirim `save_teacher_subjects_bulk` | 403. |
| Guru menambah item tanpa kelas via console | 400 `Pilih minimal satu kelas yang diampu`, seluruh simpan dibatalkan dan data tidak berubah. |
| Admin menambah item tanpa level dan kelas | Diterima (kosong = semua). |
| Guru mengirim `subject_name` palsu untuk mapel valid | Tersimpan dengan nama dari master. |
| Mapel nonaktif pada item **baru** oleh guru | 400. Oleh Admin: diterima. |
| Item lama dengan mapel yang kini nonaktif atau terhapus dari master | Tetap tersimpan bila tidak diubah. Badge "Perlu dilengkapi". Guru boleh menghapusnya. |
| Item lama tanpa `class_ids` buatan Admin | Tetap tersimpan saat guru menyimpan perubahan lain. Bila guru ingin melengkapinya: hapus, lalu tambah dengan kelas (item baru harus lolos validasi). |
| Kelas dihapus dari tabel `classes` setelah penugasan dibuat | Item lama tetap tersimpan. Badge "Perlu dilengkapi" (kelas tidak ditemukan). |
| `class_ids` berisi kelas level lain (misalnya level `X` dengan kelas `XI IPA 1`) | 400 `Kelas XI IPA 1 bukan level X`. |
| `class_ids` duplikat dalam satu item | Dinormalisasi menjadi unik, bukan error. |
| Dua item baru mapel + level sama | 400 duplikat. UI mencegahnya lebih dulu (F4). |
| Data lama sudah berisi duplikat identik | Diterima selama keduanya item lama tersimpan. Menambah item baru dengan kunci yang sama → 400. |
| Dua guru mengampu mapel dan kelas yang sama | Diterima tanpa peringatan. |
| Guru dan Admin menyimpan penugasan guru yang sama hampir bersamaan | *Last write wins* (hapus lalu insert dalam transaksi). Tidak ada deteksi konflik. |
| Tab Profil lama terbuka setelah Admin mengubah penugasan | Simpan dari tab lama menimpa perubahan Admin. Item yang dihapus Admin tapi masih ada di daftar tab lama diperlakukan sebagai item baru dan harus lolos validasi. Diterima sebagai trade-off (§10). |
| Tabel `classes` belum ada | Item baru dengan kelas → 400 `Data kelas belum tersedia`. Dalam praktik tabel dibuat saat UI memuat `get_classes` (F2). |
| Role guru diubah menjadi TATA_USAHA saat masih login | Request simpan berikutnya → 403. Baca tetap diizinkan (TU boleh baca). |
| User guru dihapus saat masih login | 401. |
| Guru tanpa baris `gtk_pegawai` | Simpan sukses. Sinkronisasi GTK tidak berdampak. |
| `save_teacher_subjects_bulk` via GET | 405. |
| Admin modal: `save_user` sukses tetapi penugasan ditolak 400 | Data user sudah tersimpan, penugasan tidak. Alert menampilkan pesan backend (F8). Perilaku dua langkah ini tidak diubah. |
| Guru BK / wali kelas | Sama seperti GURU. |
| Staf TU menjalankan `gtk_update` dengan mengubah `mapel` guru | `teacher_subjects` guru dibangun ulang dari teks `mapel` (perilaku lama, di luar scope). |

## 9. Testing dan verifikasi end-to-end

Pengujian utama adalah **uji manual di browser** (sesuai keputusan). Tidak ada skrip uji otomatis dalam scope. Pemeriksaan statis `php -l` tetap dijalankan sebagai prasyarat.

### 9.1 Persiapan staging

1. Salin database produksi ke staging (atau pakai data dev), dengan `api/config.local.php` berisi `SIMANTAP_DEV_MODE` dan `api/install.lock` tersedia.
2. Siapkan akun:
   - **GURU A** (wali kelas) dan **GURU B** (tanpa penugasan);
   - **ADMIN**;
   - **KEPALA_SEKOLAH**;
   - **TATA_USAHA**.
3. Siapkan data awal:
   - pastikan `classes` terisi minimal dua kelas level `X` dan satu level `XI` (buka menu apa pun yang memanggil `get_classes`);
   - pastikan ada satu mapel aktif `M1`, satu mapel aktif `M2`, dan satu mapel nonaktif `M0` di `subjects`;
   - buat **data warisan** untuk GURU A lewat SQL: satu baris `M0` tanpa `class_level` dan dengan `class_ids = '[]'`;
   - catat kondisi awal: `SELECT * FROM teacher_subjects WHERE teacher_id IN ('<uid A>','<uid B>')` dan `SELECT id, mapel, kelas_ajar FROM gtk_pegawai WHERE id IN (…)`.

### 9.2 Pemeriksaan statis

```
php -l api/index.php
php -l api/teacher_subject_access.php
node docs/patches/apply-penugasan-mapel-bundle.js   # harus sukses; jalankan ulang → gagal karena hash/prefiks
```

### 9.3 Skenario end-to-end (browser, DevTools → Network terbuka)

| # | Aktor | Langkah | Hasil yang diharapkan |
|---|---|---|---|
| E1 | GURU B | Buka Profil | Kartu "Penugasan Mata Pelajaran" tampil dengan teks "Belum ada mapel ditugaskan". Tombol Simpan nonaktif. |
| E2 | GURU B | Pilih `M1`, level `X`, lalu klik "Tambahkan ke Daftar" tanpa memilih kelas | Tombol tambah nonaktif. Setelah memilih 2 kelas `X`, item masuk daftar dan tombol Simpan aktif dengan peringatan "belum disimpan". |
| E3 | GURU B | Tambah lagi `M1` level `X` | Pesan duplikat inline, item tidak bertambah. |
| E4 | GURU B | Tambah `M2` level `XI` (1 kelas), lalu klik Simpan | Pesan sukses. DB berisi 2 baris dengan `subject_name` dari master. `gtk_pegawai` B: `mapel = "M1, M2"` (nama), `kelas_ajar` berisi 3 kelas. |
| E5 | GURU B | Buka Jurnal Mengajar | Pilihan kelas hanya 3 kelas dari E4. |
| E6 | GURU B | Buka halaman Nilai/Ujian | Pilihan mapel hanya `M1` dan `M2`. Kelas mengikuti mapel terpilih. |
| E7 | GURU B | Di Profil, hapus item `M2`, tambah `M2` level `XI` dengan kelas lain, lalu Simpan (skenario "ubah") | Tersimpan. Jurnal (dibuka ulang) menampilkan kelas baru. Jurnal lama B untuk kelas sebelumnya tetap ada di riwayat. |
| E8 | GURU B | Hapus semua item, lalu Simpan | `teacher_subjects` B kosong. `gtk_pegawai.mapel` dan `kelas_ajar` B menjadi kosong. |
| E9 | GURU A | Buka Profil | Item warisan `M0` tampil dengan badge "Perlu dilengkapi" (mapel tidak aktif, level/kelas kosong). |
| E10 | GURU A | Tambah `M1` level `X` + 1 kelas, **tanpa** menghapus item `M0`, lalu Simpan | Sukses. DB berisi baris `M0` persis seperti semula (tanpa level/kelas) dan baris `M1`. |
| E11 | GURU A | Console: `fetch('api/index.php?action=save_teacher_subjects_bulk',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({teacher_id:'<uid A>',subjects:[{subject_code:'M2',class_level:'X',class_ids:[]}]})})` | 400 `Pilih minimal satu kelas yang diampu`. DB A tidak berubah (baris `M0` dan `M1` masih ada). |
| E12 | GURU A | Console: sama dengan E11, tetapi `subject_code:'M0'`, `class_level:'X'`, `class_ids:['<kelas X>']` | 400 mapel tidak aktif. |
| E13 | GURU A | Console: `class_level:'X'` dengan `class_ids:['<kelas XI>']`, lalu dengan `class_ids:['TIDAK_ADA']` | 400 "bukan level X", lalu 400 "tidak ditemukan". |
| E14 | GURU A | Console: `teacher_id:'<uid B>'` dengan item valid | 403. DB B tidak berubah. |
| E15 | GURU A | Buka `api/index.php?action=get_teacher_subjects&teacher_id=<uid B>` di address bar, lalu tanpa `teacher_id`, lalu buka `…save_teacher_subjects_bulk` via GET | 403, lalu hanya data A, lalu 405. |
| E16 | Tanpa login (incognito) / KS / TU | Incognito: buka `get_teacher_subjects`. KS dan TU: panggil `save_teacher_subjects_bulk` untuk uid A via console, lalu `get_teacher_subjects` | Incognito → 401. KS/TU simpan → 403, sedangkan baca → 200 berisi data. |
| E17 | ADMIN | Buka modal edit user GURU A | Daftar menampilkan `M0` dan `M1` dari E10. Tambah `M2` "Semua Level" tanpa kelas, lalu simpan → sukses. Tambah lagi kelas yang tidak ada via console → 400, dan alert modal menampilkan pesan backend (F8). |
| E18 | ADMIN | Console: `delete_teacher_subject&id=<id>` via GET, lalu via `DELETE` | 405, lalu 200. Sebagai KS via `DELETE` → 403. |
| E19 | ADMIN / KS | Buka Profil masing-masing | Kartu penugasan **tidak** tampil. |
| E20 | GURU B | Ubah role B menjadi TATA_USAHA lewat Admin, lalu (tanpa login ulang) klik Simpan di Profil B | 403 dengan pesan backend. |
| E21 | Semua role | Regresi: Kesiswaan wali kelas, cetak rekap bulanan, Ekstrakurikuler, Perangkat Belajar, form Jurnal (simpan satu jurnal), dan halaman Nilai | Berfungsi seperti sebelum patch. |
| E22 | GURU A | DevTools → Console setelah membuka Profil, Jurnal, dan Nilai | Tidak ada error JS baru. |

### 9.4 Checklist pasca auto-update (masuk ke `docs/patches/penugasan-mapel-mandiri-guru.md`)

1. Cek apakah `app.html` masih mereferensikan `-mapel1.js`.
2. Cek apakah `api/index.php` masih me-`require` `teacher_subject_access.php`, dan apakah `save_teacher_subjects_bulk` masih memanggil `tsUser`.
3. Bila ada yang tertimpa, terapkan ulang patch backend dari diff, jalankan skrip bundle terhadap bundle baru (anchor mungkin perlu dicari ulang), lalu ulangi E2, E4, E11, E14, E16, dan E17.

### 9.5 Rollback

1. Kembalikan `app.html` ke `-perangkat1.js`.
2. Kembalikan `api/index.php` dari git (`git checkout <commit sebelum patch> -- api/index.php`).
3. `api/teacher_subject_access.php` dan `-mapel1.js` boleh dibiarkan (tidak direferensikan).
4. Tidak ada perubahan skema yang perlu dibatalkan. Data penugasan yang dibuat guru tetap valid untuk kode lama.

## 10. Risiko dan trade-off

| Risiko / trade-off | Dampak | Mitigasi |
|---|---|---|
| Tanpa persetujuan dan tanpa audit | Guru dapat menugaskan dirinya ke mapel/kelas yang tidak ia ampu. Admin tidak tahu siapa yang mengubah. | Sesuai keputusan bisnis. Admin/KS tetap bisa melihat penugasan (baca), dan Admin bisa mengoreksi. Audit/approval dapat menjadi fitur lanjutan. |
| Tidak ada validasi konflik antar guru | Dua guru dapat mengklaim kelas yang sama | Sesuai keputusan (team teaching/guru pengganti). |
| Simpan hapus-lalu-insert tanpa deteksi konflik | Tab lama dapat menimpa perubahan Admin (*last write wins*) | Diterima. Frekuensi pengeditan rendah. UI memuat ulang data setelah simpan. |
| Pengecualian validasi untuk item lama tersimpan | Data warisan tidak valid dapat bertahan lama | Badge "Perlu dilengkapi" di Profil. Alternatifnya (menolak simpan) memblokir guru karena data buatan Admin. |
| Validasi baru juga berlaku untuk Admin (kelas ada, level cocok, duplikat) | Admin dapat gagal menyimpan bila `classes.level` tidak konsisten dengan nama kelas (misalnya kelas dibuat manual dengan level `10`) | Perbandingan level memakai trim dan tanpa beda huruf besar/kecil. Kelas spesifik tetap opsional untuk Admin. Pesan error menyebut kelas yang bermasalah. Periksa `SELECT DISTINCT level FROM classes` di staging sebelum rilis. |
| Opsi level di Profil diambil dari `classes.level` | Level tidak standar ikut tampil | Fallback `X`/`XI`/`XII` bila kosong. Validasi server tetap mencocokkan dengan `classes.level`. |
| `gtk_update` (modul TU) tetap membangun ulang `teacher_subjects` dari teks `mapel` | Penugasan kelas yang dibuat guru dapat tertimpa bila TU mengedit mapel GTK | Di luar scope. Dicatat di dokumen patch dan paket usulan BTIKP. |
| Pengosongan `gtk_pegawai.mapel/kelas_ajar` saat penugasan kosong | Data GTK di modul TU ikut kosong | Sesuai keputusan (hindari data basi). Disampaikan ke sekolah. |
| KS kehilangan hak ubah lewat API legacy | Tidak ada dampak UI (endpoint tidak dipakai bundle) | Diterima. |
| Satu query tambahan (`users`) per request | Beban kecil | Diterima demi perubahan role yang langsung berlaku. |
| Patch bundle berbasis anchor string | Auto-update dengan bundle baru mematahkan skrip | Cek SHA-256 sumber, skrip gagal eksplisit, dan checklist §9.4. |
| Auto-updater menimpa backend | Otorisasi hilang diam-diam | Checklist §9.4 dan paket usulan upstream. |
| Tidak ada uji otomatis | Regresi hanya tertangkap uji manual | Skenario E1–E22 wajib dijalankan penuh sebelum rilis ke produksi. |

## 11. Acceptance criteria

1. **AC1:** GURU melihat kartu "Penugasan Mata Pelajaran" di halaman Profil, dan dapat menambah, menghapus, dan mengubah (hapus lalu tambah) penugasannya sendiri. Perubahan tersimpan dan langsung tampil di form Jurnal dan Nilai saat dibuka berikutnya (E1–E7).
2. **AC2:** ADMIN dan KS tidak melihat kartu penugasan di Profil (E19). Modal Admin tetap dapat mengelola penugasan guru mana pun, termasuk "Semua Level" tanpa kelas (E17).
3. **AC3:** `save_teacher_subjects_bulk` hanya menerima GURU (untuk uid sendiri) dan ADMIN. Guru lain, KS, TU, dan role lain → 403, tanpa login → 401, method selain POST → 405, dan data tidak berubah (E14–E16, E20).
4. **AC4:** `get_teacher_subjects` tanpa login → 401. GURU hanya dapat membaca miliknya sendiri. ADMIN, KS, dan TU dapat membaca semua (E15, E16).
5. **AC5:** Item baru dari GURU wajib memiliki mapel aktif, level, dan minimal satu kelas yang ada dan sesuai level. Pelanggaran → 400 dengan pesan spesifik, dan seluruh simpan dibatalkan (E11–E13).
6. **AC6:** Item baru dari ADMIN wajib memiliki mapel yang ada di master dan kelas (bila diisi) yang ada serta sesuai level. Level dan kelas tetap opsional (E17).
7. **AC7:** Duplikat mapel + level pada item baru ditolak di server (400) dan dicegah di UI (E3).
8. **AC8:** `subject_name` selalu diambil dari master untuk item baru.
9. **AC9:** Item lama yang identik dengan data tersimpan tetap diterima walau tidak memenuhi aturan baru, dan ditandai "Perlu dilengkapi" di Profil (E9, E10).
10. **AC10:** Setelah simpan bulk, `gtk_pegawai.mapel` dan `kelas_ajar` sesuai isi `teacher_subjects`, termasuk menjadi kosong saat semua penugasan dihapus (E4, E8).
11. **AC11:** `save_teacher_subjects` dan `delete_teacher_subject` hanya untuk ADMIN, dan menolak GET (E18).
12. **AC12:** Error server tidak membocorkan pesan exception DB, dan UI Profil maupun modal Admin menampilkan pesan `error` dari backend (E17, E20).
13. **AC13:** `php -l` lolos untuk semua file PHP yang diubah atau ditambah. Skrip patch bundle sukses pada sumber yang benar dan gagal eksplisit pada sumber yang salah (§9.2).
14. **AC14:** Tidak ada regresi pada wali kelas, rekap bulanan, ekstrakurikuler, perangkat belajar, jurnal, dan nilai (E21), serta tidak ada error console baru (E22).
15. **AC15:** Dokumen patch (`docs/patches/penugasan-mapel-mandiri-guru.md`) dan paket usulan BTIKP tersedia sesuai §5.3.
