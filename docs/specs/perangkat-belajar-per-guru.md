# Spesifikasi: Isolasi Data Perangkat Belajar per Guru dan Perbaikan Tampilan Modul Ajar/Administrasi

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/perangkat-belajar-per-guru.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch lokal wali kelas, rekap bulanan, dan pembina ekskul |
| Bundle frontend acuan | `assets/index-CGWL69P7-ekskul1.js` (SHA-256 `c5d02ea3b2a80424a8ec7aca3b2f013eeb7472f4944a88341968b1c4ca62e33e`), direferensikan `app.html` saat ini |
| Tanggal | 2026-09-15 |
| Jalur rilis | Patch lokal pada bundle ini **dan** paket usulan perubahan ke upstream BTIKP |

## 1. Latar belakang dan kebutuhan bisnis

Menu **Perangkat Belajar** (`lesson-kit`) adalah tempat guru mengumpulkan RPP, Silabus, Modul Ajar, dan dokumen Administrasi per semester. Kepala Sekolah memverifikasi dokumen tersebut. Ada dua masalah yang dilaporkan sekolah:

1. **Data tidak terkunci per guru.** Dokumen yang diunggah seorang guru tampil di akun semua guru. Guru lain juga bisa mengedit dan menghapusnya. Ini melanggar privasi administrasi guru dan membuat panel "Sisa Target Dokumen" tidak bermakna karena menghitung dokumen semua guru.
2. **Modul Ajar dan Administrasi tidak muncul.** Upload berhasil tersimpan di tabel `lesson_rpps`, tetapi dokumen tidak tampil di daftar dan penghitungnya tetap 0.

Tujuan perubahan:
- **GURU** hanya melihat dan mengelola dokumen miliknya sendiri. Dokumen yang sudah `VERIFIED` terkunci.
- **KEPALA_SEKOLAH** melihat semua dokumen, dapat memfilter per guru, dan memverifikasi. KS tidak membuat atau mengunggah dokumen.
- **ADMIN** dapat melihat semua dokumen dan menghapus dokumen apa pun (koreksi data) lewat API. ADMIN tidak mendapat menu baru.
- Semua tipe dokumen (RPP, SILABUS, MODUL, ADMINISTRASI) tampil sesuai tipenya.
- Pembatasan **ditegakkan di backend**, bukan hanya di UI.

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Backend (`api/index.php`)

Keempat action hanya ada di `api/index.php`. Tidak ada duplikat di `endpoints_v2.php`, `endpoints_v3.php`, atau `endpoints_tu.php`.

| Action | Baris | Auth saat ini | Masalah |
|---|---|---|---|
| `get_lesson_rpps` | ~1223 | **tanpa auth** | `SELECT` semua baris `lesson_rpps` + JOIN `gtk_pegawai` (NIP/NUPTK/NIK). Tidak ada filter pemilik. `array_merge($row, $payload)` membuat isi payload (yang dikirim klien) menimpa kolom DB. |
| `save_lesson_rpp` | ~1269 | `requireAuth()` | `INSERT … ON DUPLICATE KEY UPDATE` dengan `id` dari body. Siapa pun yang tahu `id` dapat menimpa dokumen orang lain, dan `authorId` ikut berganti ke pengirim. `status` diterima dari body (guru dapat mengirim `VERIFIED`). `fileUrl` tidak divalidasi. Tidak ada cek role. |
| `delete_lesson_rpp` | ~1304 | **tanpa auth** | Hapus berdasarkan `$_GET['id']` untuk siapa pun, termasuk tanpa login. Method HTTP tidak dicek. File fisik tidak ikut terhapus. |
| `verify_lesson_rpp` | ~1317 | `requireRole(['KEPALA_SEKOLAH','ADMIN'])` | Sudah benar. Memperbarui kolom `status`/`verified_*` dan field sejenis di payload. |

Upload file memakai `tu_file_upload` (`api/endpoints_tu.php` ~524) dengan `module=lesson` dan `record_id=<id dokumen>`:
- hanya `requireAuth()`;
- tidak ada cek bahwa `record_id` milik pengunggah;
- file disimpan di `uploads/tu/lesson/<safeRecordId>/dokumen_<time>.<ext>` tanpa pencatatan ke tabel file mana pun.

Kolom tabel `lesson_rpps` (`install/schema.sql` ~322): `id` (PK), `title`, `subject`, `classLevel`, `topic`, `objectives`, `activities`, `authorId`, `authorName`, `isCustom`, `createdAt`, `type` (default `'RPP'`), `payload` (JSON), `file_url`, `status` (default `'PENDING'`), `verified_by`, `verified_at`, `verified_by_name`. Tidak ada index pada `authorId`.

### 2.2 Frontend (`assets/index-CGWL69P7-ekskul1.js`)

- **Menu.** Item `{id:"lesson-kit",label:"Perangkat Belajar",…,roles:["GURU","KEPALA_SEKOLAH"]}`. ADMIN tidak punya menu ini.
- **Konsumen API.** Semua pemanggilan `et.lessonRpps.*` (6× `list`, 6× `save`, 1× `delete`, 1× `uploadFile`) dan `verify_lesson_rpp` ada di komponen `Jat` (halaman) dan `Qat` (modal detail). Tidak ada halaman lain yang membaca data ini.
- **Akar masalah Modul Ajar/Administrasi tidak tampil** (komponen `Jat`):
  1. Saat mount, respons disaring ke dua state: `y(re.filter(Qe=>Qe.type==="RPP"||!Qe.type))` dan `A(re.filter(Qe=>Qe.type==="SILABUS"))`. Baris `MODUL` dan `ADMINISTRASI` **dibuang**. Filter yang sama dipakai setelah simpan RPP, simpan Silabus, verifikasi, dan hapus.
  2. Hanya handler upload (`Ve`) yang filternya menyertakan `MODUL`/`ADMINISTRASI`. Namun pemetaan daftar (`jn`) **memaksa** `type:"RPP"` dan `isInteractive:!0` untuk semua isi state `b`. Akibatnya dokumen sempat tampil sebagai "RPP interaktif", lalu hilang setelah refresh.
  3. Penghitung (`Be`) menghitung dari `type` hasil pemetaan, jadi `modulCount` dan `adminCount` selalu 0.
  4. Karena `isInteractive` dipaksa `true`, dokumen hasil upload (termasuk RPP yang diunggah) menampilkan tombol **Edit RPP** alih-alih **Unduh**. Cabang non-interaktif memanggil `Zn(id,!1)`, yang hanya menghapus dari state lokal (`Yat=[]`) dan **tidak** memanggil server.
  5. Modal upload `Zat` memakai default semester `useState("GENAP")`, bukan semester yang sedang dipilih. Dokumen yang diunggah tanpa mengubah dropdown saat melihat Semester Ganjil masuk ke Genap dan terlihat seperti "tidak muncul".
- **Tidak ada filter pemilik** di frontend. Tombol "Buat RPP Interaktif", "Buat Silabus Interaktif", "Upload Dokumen Baru", Edit, dan Hapus tampil untuk semua role yang membuka menu, termasuk KS, dan untuk dokumen yang sudah `VERIFIED`.
- **Tombol verifikasi** di `Qat` sudah di-gate `role==="KEPALA_SEKOLAH"||role==="ADMIN"` dan `status!=="VERIFIED"`, tetapi **hanya untuk dokumen interaktif**. Untuk dokumen hasil upload, footer `Qat` hanya berisi "Batal" dan "Unduh Berkas" (tanpa `onClick`), sehingga KS tidak bisa memverifikasi Modul Ajar/Administrasi. Panel info juga menulis "Admin Jurusan / Guru" dan "Publik (Guru & Staf)" secara statis. *(Ditemukan saat implementasi.)*
- **Kolom tabel daftar:** Nama Dokumen, Tipe, Semester, Terakhir Diubah, Kapasitas, Keabsahan, Aksi. Tidak ada kolom guru.

## 3. Expected behavior

### 3.1 Definisi

- **Pemilik dokumen**: user dengan `users.uid = lesson_rpps.authorId`.
- **Terkunci**: `lesson_rpps.status = 'VERIFIED'`.
- **Pemantau**: `KEPALA_SEKOLAH` dan `ADMIN`.
- **Dokumen yatim**: `authorId` kosong atau tidak cocok dengan `users.uid` mana pun.

### 3.2 Matriks akses

| Aksi | GURU (pemilik) | GURU (bukan pemilik) | KEPALA_SEKOLAH | ADMIN | TATA_USAHA / role lain | Tanpa login |
|---|---|---|---|---|---|---|
| Lihat daftar (`get_lesson_rpps`) | Miliknya saja | — | Semua (termasuk yatim) | Semua (termasuk yatim) | 403 | 401 |
| Buat baru (`save_lesson_rpp` tanpa `id`) | Ya | — | 403 | 403 | 403 | 401 |
| Edit (`save_lesson_rpp` dengan `id`) | Ya, jika belum `VERIFIED` | 403 | 403 | 403 | 403 | 401 |
| Upload file (`tu_file_upload` `module=lesson`) | Ya, jika belum `VERIFIED` | 403 | 403 | 403 | 403 | 401 |
| Hapus (`delete_lesson_rpp`) | Ya, jika belum `VERIFIED` | 403 | 403 | Ya, apa pun statusnya | 403 | 401 |
| Verifikasi (`verify_lesson_rpp`) | 403 | 403 | Ya | Ya | 403 | 401 |

"Role lain" berarti setiap nilai `users.role` selain `GURU`, `KEPALA_SEKOLAH`, dan `ADMIN`. GURU dengan `is_bk=1` atau `class_homeroom` terisi diperlakukan sama seperti GURU biasa.

### 3.3 Perilaku detail per endpoint

Semua pengecekan memakai session (`requireAuth`), lalu role dibaca ulang dari DB lewat `loadFreshUser()` (`api/kesiswaan_access.php`), sehingga perubahan role atau penghapusan user langsung berlaku tanpa login ulang. Aktor selalu diambil dari `$user['uid']`/`$user['displayName']`, tidak pernah dari body. Karena mysqli pada PHP 8.2 melempar exception, penolakan memakai `errorResponse()` eksplisit sebelum query mutasi.

Disarankan menaruh helper otorisasi di file baru `api/lesson_access.php`, mengikuti pola `api/ekskul_access.php`, lalu me-`require_once` file itu dari `api/index.php` dan `api/endpoints_tu.php`. Helper yang dibutuhkan:
- `lessonLoad($db, $id)`: baris dokumen atau `null`;
- `lessonCanView($user, $row)`;
- `lessonAssertMutable($user, $row, $aksi)`: 404/403/409 sesuai §3.3.

#### `get_lesson_rpps`
- `requireRole(['GURU','KEPALA_SEKOLAH','ADMIN'])`.
- GURU: `WHERE r.authorId = ?` (uid session). KS/ADMIN: tanpa filter.
- Pertahankan JOIN `gtk_pegawai … COLLATE utf8mb4_unicode_ci` dan urutan `ORDER BY r.createdAt DESC`.
- Setelah merge payload, field berikut **wajib** ditimpa dari kolom DB (kolom DB otoritatif):
  - `id`, `type`;
  - `authorId`, `authorName`, `teacherId` (= `authorId`), `teacherName` (= `authorName`);
  - `status`, `verifiedBy`, `verifiedByName`, `verifiedAt`;
  - `fileUrl` (dari `file_url` bila ada), `nip`, `nuptk`, `nik`.
- `type` kosong dinormalisasi menjadi `'RPP'`.
- Field aditif baru:
  - `isInteractive` (bool): `false` bila payload menyatakan `isInteractive === false` atau `file_url` terisi; selain itu `true`;
  - `canEdit` (bool): pemilik GURU dan belum `VERIFIED`;
  - `canDelete` (bool): (pemilik GURU dan belum `VERIFIED`) atau role ADMIN.

#### `save_lesson_rpp`
- `requireRole(['GURU'])`. Hanya method `POST`; method lain → 405.
- **Tanpa `id` di body**: buat baru dengan `uniqid('rpp_')`, `authorId`/`authorName` dari session, dan `status = 'PENDING'`.
- **Dengan `id`**:
  - dokumen tidak ditemukan → 404 `Dokumen tidak ditemukan`;
  - bukan pemilik → 403 `Anda bukan pemilik dokumen ini`;
  - `VERIFIED` → 409 `Dokumen sudah diverifikasi dan tidak dapat diubah`.
  - Jika lolos, jalankan `UPDATE` (bukan upsert) pada `title`, `subject`, `classLevel`, `topic`, `type`, `payload`, dan (bila dikirim) `file_url`. **Tidak mengubah** `authorId`, `authorName`, `status`, `verified_*`, atau `createdAt`.
- `status`, `verifiedBy`, `verifiedByName`, dan `verifiedAt` dari body **diabaikan**. Sebelum `json_encode`, field payload `status`, `teacherId`, `teacherName`, `authorId`, dan `authorName` ditimpa dengan nilai server (status DB atau `PENDING`, dan identitas session).
- `type` hanya boleh `RPP`, `SILABUS`, `MODUL`, atau `ADMINISTRASI`. Nilai lain → 400. Deteksi otomatis yang sudah ada (`capaian`/`rows` → `SILABUS`) tetap berlaku bila `type` tidak dikirim.
- `fileUrl`, bila dikirim, harus diawali `uploads/tu/lesson/<safeId>/` (dengan `safeId = preg_replace('/[^a-zA-Z0-9_\-]/','_',$id)`) dan tidak mengandung `..`. Pelanggaran → 400.
- Respons tetap `{success:true, id, newId}`.

#### `delete_lesson_rpp`
- `requireRole(['GURU','ADMIN'])`. Hanya method `DELETE` atau `POST`; `GET` → 405. `id` dibaca dari `$_GET['id']` (seperti sekarang).
- Tidak ditemukan → 404.
- GURU: harus pemilik (403) dan belum `VERIFIED` (409). ADMIN: selalu boleh.
- Setelah baris terhapus, hapus folder `uploads/tu/lesson/<safeId>/` beserta isinya. Path **dibangun dari id tersanitasi di server**, tidak pernah dari body atau `file_url`. Kegagalan menghapus file tidak menggagalkan respons, cukup `error_log`.

#### `verify_lesson_rpp`
Perilaku tidak berubah. Tambahan: id tidak ditemukan → 404 (saat ini tetap `success`), dan hanya method `POST` yang diterima (lainnya 405; frontend sudah memakai `POST`).

#### `tu_file_upload` (hanya bila `module === 'lesson'`)
- Wajib role GURU, `record_id` ada di `lesson_rpps`, aktor = `authorId`, dan status belum `VERIFIED`. Pelanggaran → 403/404/409, dengan pesan sama seperti `save_lesson_rpp`.
- Pengecekan dilakukan **sebelum** `move_uploaded_file`.
- Modul lain (`gtk`, `siswa`, `surat`, `legalisir`, `arsip`) tidak berubah.

### 3.4 Perilaku UI yang diharapkan (patch bundle)

Patch diterapkan lewat skrip `docs/patches/apply-perangkat-belajar-bundle.js`, dengan sumber `-ekskul1.js` dan hasil `-perangkat1.js`, mengikuti pola `apply-pembina-ekskul-bundle.js`: cek SHA-256 sumber, anchor string eksak, tulis salinan baru, lalu arahkan `app.html`.

| # | Lokasi | Perubahan |
|---|---|---|
| F1 | `Jat`: semua 6 titik `lessonRpps.list()` | Satu fungsi pemisah: state `b` berisi semua tipe **selain** `SILABUS` (RPP, MODUL, ADMINISTRASI, dan tipe kosong), state `A` berisi `SILABUS`. |
| F2 | `Jat`: pemetaan `jn` | `type` diambil dari data (`Qe.type||"RPP"`), `isInteractive` dari field backend, `size` default `"Dokumen Unggahan"` untuk non-interaktif. `rawRpp` tetap diisi agar `Qat` dan tombol Unduh (`rawRpp.fileUrl`) berjalan. Deskripsi tetap `topic`. Tambah `teacherId`, `teacherName`, `canEdit`, `canDelete`. |
| F3 | `Jat`: tombol hapus cabang non-interaktif (`Zn(re.id,!1)`, di tampilan list dan grid) | Memanggil server (`Zn(re.id,!0)`) sehingga dokumen unggahan benar-benar terhapus. |
| F4 | `Jat`: tombol Edit dan Hapus (list dan grid) | Tampil hanya bila `canEdit`/`canDelete`. Dokumen `VERIFIED` milik guru menampilkan ikon/label "Terkunci". |
| F5 | `Jat`: tombol "Buat RPP Interaktif", "Buat Silabus Interaktif", "Upload Dokumen Baru" | Disembunyikan untuk `role !== "GURU"`. |
| F6 | `Jat`: toolbar daftar (untuk KS/ADMIN) | Tambah dropdown **Guru**: opsi "Semua guru" (default) + akun `role === "GURU"` dari `et.users.list()` (`get_users`), urut `displayName`. Filter diterapkan pada `W` sebelum pencarian dan penghitung. Dokumen yatim hanya muncul pada "Semua guru". |
| F7 | `Jat`: tabel daftar dan kartu grid (untuk KS/ADMIN) | Tambah kolom/label **Guru** (`teacherName`, fallback "—"). |
| F8 | `Jat`: panel "Sisa Target Dokumen Semester" | Untuk KS/ADMIN dengan filter "Semua guru", panel disembunyikan dan diganti teks "Pilih guru untuk melihat sisa target". Saat satu guru dipilih, panel tampil dengan hitungan guru tersebut. Untuk GURU, panel selalu tampil (datanya sudah miliknya saja). |
| F9 | `Zat` (modal upload) | Default semester = semester aktif di halaman (prop baru dari `Jat`), bukan `"GENAP"`. |
| F10 | `Jat`: handler `rs`, `pe`, `Ve`, `Zn`, `Sn` | Pesan `alert` menampilkan pesan error dari backend (403/409/404) bila tersedia, bukan hanya pesan generik "periksa koneksi". |
| F11 | Header deskripsi | Tidak berubah. |
| F12 | `Qat` (dokumen unggahan) | "Diupload Oleh" menampilkan `teacherName`. "Izin Akses" menjadi "Guru pemilik, Kepala Sekolah & Admin". |
| F13 | `Qat` (dokumen unggahan) | Tombol "Unduh Berkas" membuka file. Tombol "Verifikasi & Setujui" (KS/ADMIN, belum `VERIFIED`) atau badge "Terverifikasi" ditambahkan, sama seperti dokumen interaktif. |

Menu `lesson-kit` **tidak** diubah (tetap `GURU` dan `KEPALA_SEKOLAH`).

## 4. Role, authorization, dan data model

- **Role:** hanya `GURU`, `KEPALA_SEKOLAH`, dan `ADMIN` yang mendapat akses. Role dibaca ulang dari tabel `users` pada setiap request (`loadFreshUser`), sehingga perubahan role langsung berlaku. User yang sudah dihapus mendapat 401.
- **Kepemilikan:** tetap memakai kolom `lesson_rpps.authorId`. **Tidak ada migrasi data dan tidak ada kolom baru.**
- **Index:** `idx_lesson_author (authorId)` dibuat otomatis dan idempoten oleh `lessonEnsureIndex()` saat guru membuat dokumen baru. Kegagalan (misalnya user DB tanpa hak `ALTER`) hanya dicatat ke `error_log`. Implementasi tetap benar tanpa index ini.
- **Dokumen yatim:** tidak dicocokkan ulang dari `payload.teacherId`. Dokumen ini hanya terlihat oleh KS/ADMIN dan hanya dapat dihapus ADMIN.
- **Payload JSON:** tetap disimpan, tetapi field identitas/status di dalamnya bukan sumber kebenaran (§3.3).

## 5. File dan interface terkait

### 5.1 Backend (patch lokal)

| File | Perubahan |
|---|---|
| `api/lesson_access.php` (**baru**) | Guard `SIMANTAP_API`; helper `lessonUser` (user segar + cek role), `lessonRequireMethod`, `lessonLoad`, `lessonIsLocked`, `lessonIsOwner`, `lessonAssertMutable`, `lessonSafeId`, `lessonValidFileUrl`, `lessonDeleteFiles`, `lessonEnsureIndex`. Bergantung pada `loadFreshUser()` di `api/kesiswaan_access.php`. |
| `api/index.php` | `require_once __DIR__ . '/lesson_access.php';`, lalu ubah case `get_lesson_rpps`, `save_lesson_rpp`, `delete_lesson_rpp`, `verify_lesson_rpp` sesuai §3.3. |
| `api/endpoints_tu.php` | Di `tu_file_upload`, tambah blok `if ($module === 'lesson') { … }` sebelum proses file. |
| `api/db.php` | Tidak berubah. |

Interface API (kontrak untuk frontend):

```
GET    api/index.php?action=get_lesson_rpps
       → 200 [ { id, type, title, subject, classSession?, semester, topic, status,
                 authorId, authorName, teacherId, teacherName, fileUrl?, isInteractive,
                 canEdit, canDelete, verifiedBy, verifiedByName, verifiedAt, nip, nuptk, nik, …payload } ]
       → 401 / 403
POST   api/index.php?action=save_lesson_rpp      body: { id?, type?, title, subject, …, fileUrl? }
       → 200 { success, id, newId } | 400 | 401 | 403 | 404 | 405 | 409
DELETE api/index.php?action=delete_lesson_rpp&id=<id>
       → 200 { success } | 401 | 403 | 404 | 405 | 409
POST   api/index.php?action=verify_lesson_rpp    body: { id }
       → 200 { success, id } | 401 | 403 | 404
POST   api/index.php?action=tu_file_upload       multipart: file, module=lesson, record_id=<id>
       → 200 { …, path } | 401 | 403 | 404 | 409
```

Format error tetap mengikuti `errorResponse()` di `api/db.php`.

### 5.2 Frontend (patch bundle lokal)

- `docs/patches/apply-perangkat-belajar-bundle.js` (**baru**): anchor F1–F10, sumber `assets/index-CGWL69P7-ekskul1.js` (hash di header dokumen ini), prefiks identifier baru `__pb` (skrip gagal bila prefiks sudah dipakai).
- `assets/index-CGWL69P7-perangkat1.js` (**baru**, hasil skrip).
- `app.html`: `src` diarahkan ke `-perangkat1.js`.
- Komponen yang disentuh: `Jat` (halaman), `Zat` (modal upload), dan `Qat` (modal detail, hanya cabang dokumen unggahan: F12, F13).

### 5.3 Dokumen

- `docs/specs/perangkat-belajar-per-guru.md` (dokumen ini).
- `docs/patches/perangkat-belajar-per-guru.md` (**baru**): daftar file yang diubah, hash sebelum dan sesudah, anchor final, langkah re-apply, checklist pasca auto-update (§9.4), dan rollback (§9.5).
- `docs/usulan-btikp/RINGKASAN-PERANGKAT-BELAJAR.md`, `docs/usulan-btikp/backend-perangkat-belajar.diff`, `docs/usulan-btikp/usulan-perangkat-belajar.zip` (**baru**): paket usulan upstream, dengan format mengikuti usulan sebelumnya.

## 6. Di luar scope

- **Proteksi akses file unggahan.** File di `uploads/tu/lesson/…` tetap dapat diunduh siapa pun yang tahu URL-nya (tidak ada `.htaccess` atau endpoint download berotorisasi). Dicatat sebagai risiko §10.
- Validasi tipe/ekstensi file dan validasi segmen `module` pada `tu_file_upload` secara umum.
- Menu Perangkat Belajar untuk ADMIN atau role lain.
- KS membuat atau mengunggah dokumen miliknya sendiri.
- Membatalkan verifikasi, menolak dokumen (status `REJECTED`), catatan revisi, atau notifikasi ke guru.
- Mengubah target dokumen per semester (`r2`: RPP 8, Silabus 2, Modul 4, Administrasi 3) atau menambah bar verifikasi Modul/Administrasi di panel "Status Verifikasi".
- Migrasi atau pencocokan ulang `authorId` dokumen yatim.
- Rekap/laporan kepatuhan dokumen lintas guru (ekspor, cetak).
- Celah keamanan lain yang dicatat di `CLAUDE.md` (`register_guru`, `session_regenerate_id`, CSRF di endpoint lain, `tu_file_delete`, dan sebagainya).
- Rilis resmi upstream. Spesifikasi ini hanya menyiapkan paket usulan.

## 7. Compatibility

- **Konsumen API.** Hanya `Jat`/`Qat` yang memakai endpoint ini (§2.2), sehingga penambahan auth tidak memengaruhi halaman lain. SPA memakai cookie session same-origin.
- **Bundle lama tanpa patch** (misalnya setelah auto-update menimpa `app.html` saja): backend tetap membatasi data. Guru hanya menerima dokumennya sendiri, tetapi bug tampilan Modul/Administrasi kembali muncul, dan tombol Edit/Hapus yang tidak diizinkan akan menampilkan alert gagal.
- **Method HTTP.** Frontend sudah memanggil `delete_lesson_rpp` dengan `DELETE` dan `save`/`verify` dengan `POST`, jadi pembatasan method tidak memutus UI.
- **Field respons baru** (`teacherId`, `teacherName`, `isInteractive`, `canEdit`, `canDelete`) bersifat aditif. Field payload yang sekarang ditimpa nilai DB sebelumnya memang seharusnya sama, kecuali data yang pernah dimanipulasi.
- **Perubahan perilaku yang terlihat pengguna:**
  - GURU tidak lagi melihat dokumen guru lain, dan panel target kini hanya menghitung miliknya;
  - KS tidak lagi melihat tombol Buat/Upload/Edit/Hapus;
  - dokumen `VERIFIED` tidak bisa diubah atau dihapus guru.

  Beri tahu sekolah sebelum rilis.
- **Dokumen yang pernah "dibajak"** lewat upsert (authorId sudah berganti) tidak dipulihkan. Dokumen itu tetap milik `authorId` saat ini.
- **Auto-updater.** Update dapat menimpa `api/index.php`, `api/endpoints_tu.php`, dan `app.html`. File baru (`lesson_access.php`, `-perangkat1.js`) tetap ada tetapi tidak direferensikan. Setelah tertimpa, perilaku kembali seperti sekarang tanpa kerusakan data.
- **Patch lokal sebelumnya.** Spesifikasi ini dibangun di atas `-ekskul1.js`. Menu wali kelas, rekap bulanan, dan pembina ekskul harus tetap berfungsi (regresi E14).
- **PHP 8.2 / collation:** pertahankan `COLLATE utf8mb4_unicode_ci` pada JOIN `gtk_pegawai`.

## 8. Edge cases

| Kasus | Perilaku yang diharapkan |
|---|---|
| Upload: `save_lesson_rpp` pass pertama berhasil, `tu_file_upload` gagal | Baris tanpa `file_url` tetap ada milik guru (perilaku sekarang). Tombol Unduh menampilkan "Berkas tidak ditemukan", dan guru dapat menghapusnya. Tidak ada rollback otomatis. |
| Guru mengirim `save_lesson_rpp` dengan `id` dokumen guru lain | 403, data tidak berubah, `authorId` tetap. |
| Guru mengirim `id` yang tidak ada | 404, tidak ada baris baru. |
| Guru mengirim `status: "VERIFIED"` saat membuat dokumen | Tersimpan sebagai `PENDING`, termasuk di payload. |
| Guru mengedit dokumen yang baru saja diverifikasi KS (tab lama masih terbuka) | 409 dengan pesan terkunci. Setelah reload, tombol Edit hilang. |
| Guru mengirim `fileUrl` ke path di luar `uploads/tu/lesson/<id>/` | 400. |
| Guru upload file ke `record_id` milik guru lain atau yang `VERIFIED` | 403/409, tidak ada file tertulis. |
| `delete_lesson_rpp` via `GET` (tautan/CSRF) | 405, data tetap. |
| ADMIN menghapus dokumen `VERIFIED` | Berhasil, folder file ikut terhapus. |
| Folder file sudah tidak ada saat hapus | Hapus baris tetap sukses. |
| `id` berisi karakter aneh (`../`, spasi) | Folder dihitung dari id tersanitasi. Tidak ada path di luar `uploads/tu/lesson/`. |
| Dokumen dengan `type` NULL/kosong (data lama) | Diperlakukan sebagai `RPP`. |
| Dokumen yatim | Tidak tampil untuk guru mana pun. Tampil untuk KS/ADMIN pada "Semua guru" dengan kolom Guru = `authorName` atau "—". |
| Guru baru tanpa dokumen | Daftar kosong, panel target menampilkan sisa penuh. Muncul di dropdown KS dengan 0 dokumen. |
| User GURU dihapus, dokumennya tetap ada | Menjadi yatim (§4). |
| Role guru diubah menjadi TATA_USAHA saat masih login | Request berikutnya langsung 403 (role dibaca ulang dari DB). |
| Guru BK / wali kelas | Sama seperti GURU. |
| Upload saat melihat Semester Ganjil | Default dropdown semester modal = Ganjil (F9), dan dokumen langsung tampil. |
| Dua tipe unggahan RPP (interaktif vs file) | Interaktif menampilkan Edit/Cetak. File menampilkan Unduh. Keduanya dihitung sebagai RPP. |

## 9. Testing dan verifikasi end-to-end

Pengujian utama adalah **uji manual di browser** (sesuai keputusan). Tidak ada skrip uji otomatis dalam scope. Satu-satunya pemeriksaan statis yang tersedia di repo ini (`php -l`) tetap dijalankan sebagai prasyarat.

### 9.1 Persiapan staging

1. Salin database produksi ke staging (atau pakai data dev), dengan `api/config.local.php` berisi `SIMANTAP_DEV_MODE` dan `api/install.lock` tersedia.
2. Siapkan akun:
   - **GURU A** dan **GURU B** (salah satunya wali kelas atau BK);
   - **KEPALA_SEKOLAH**;
   - **ADMIN**;
   - **TATA_USAHA**.
3. Siapkan data awal:
   - catat jumlah baris `lesson_rpps` per `authorId`: `SELECT authorId, type, COUNT(*) FROM lesson_rpps GROUP BY 1,2`;
   - bila tidak ada, buat satu baris yatim secara manual (`authorId = NULL`).
4. Siapkan file uji: `modul.pdf`, `admin.docx`, `rpp.pdf` (masing-masing < 2 MB).

### 9.2 Pemeriksaan statis

```
php -l api/index.php
php -l api/endpoints_tu.php
php -l api/lesson_access.php
node docs/patches/apply-perangkat-belajar-bundle.js   # harus sukses; jalankan ulang → gagal karena hash/prefiks
```

### 9.3 Skenario end-to-end (browser, DevTools → Network terbuka)

| # | Aktor | Langkah | Hasil yang diharapkan |
|---|---|---|---|
| E1 | GURU A | Buka Perangkat Belajar (Smt Ganjil), lalu Upload Dokumen Baru dengan tipe **Modul Ajar**, `modul.pdf` | Dropdown semester modal default "Ganjil". Setelah simpan, dokumen tampil dengan tipe `MODUL`, tombol **Unduh**, kartu "Modul Ajar" = 1, "Target Modul Ajar" terkumpul 1/4. |
| E2 | GURU A | Refresh halaman (F5) | Dokumen E1 tetap tampil dengan tipe `MODUL`. |
| E3 | GURU A | Upload tipe **Administrasi** `admin.docx`, lalu upload tipe **RPP** `rpp.pdf` | Keduanya tampil sesuai tipe. Kartu Administrasi = 1. RPP unggahan menampilkan Unduh (bukan Edit). |
| E4 | GURU A | Klik Unduh pada E1 | File terbuka di tab baru. |
| E5 | GURU A | Buat RPP Interaktif dan Silabus Interaktif, lalu simpan | Tampil dengan Edit/Hapus. Cetak berfungsi. |
| E6 | GURU B | Buka Perangkat Belajar | Tidak ada dokumen GURU A maupun dokumen yatim. Penghitung hanya milik GURU B. |
| E7 | GURU B | Di DevTools console, jalankan `fetch('api/index.php?action=delete_lesson_rpp&id=<id E1>',{method:'DELETE'})` | 403. Dokumen masih ada (cek sebagai GURU A). |
| E8 | GURU B | `fetch('api/index.php?action=save_lesson_rpp',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:'<id E5>',title:'x'})})` | 403. Judul dan `authorId` di DB tidak berubah. |
| E9 | GURU A | Kirim `save_lesson_rpp` baru dengan `status:'VERIFIED'` via console | Tersimpan dengan `status = PENDING` (cek kolom dan payload di DB). |
| E10 | Tanpa login (jendela incognito) | Buka `api/index.php?action=get_lesson_rpps` dan `…delete_lesson_rpp&id=<id>` | 401. Tidak ada data yang bocor atau terhapus. |
| E11 | GURU A | Buka `api/index.php?action=delete_lesson_rpp&id=<id E3>` langsung di address bar (GET) | 405, dokumen tetap. |
| E12 | KEPALA_SEKOLAH | Buka Perangkat Belajar | Tombol Buat/Upload tidak ada. Kolom **Guru** tampil. Dropdown Guru berisi semua akun GURU (termasuk yang belum upload). Dokumen A dan B serta yatim tampil pada "Semua guru". Panel target diganti teks "Pilih guru…". Tombol Edit/Hapus tidak tampil. |
| E13 | KEPALA_SEKOLAH | Pilih GURU A, lalu buka detail E1 (dokumen unggahan), klik Unduh Berkas, lalu klik Verifikasi & Setujui | Hanya dokumen A tampil. Panel target tampil untuk A. Status E1 menjadi `VERIFIED`. |
| E14 | GURU A | Refresh | E1 `VERIFIED`, Hapus tidak tampil, label "Terkunci". Hapus via console → 409. Upload file ke `record_id` E1 via console (`FormData` `module=lesson`) → 409. |
| E15 | TATA_USAHA | Panggil `get_lesson_rpps` via console | 403. |
| E16 | ADMIN | `fetch('api/index.php?action=delete_lesson_rpp&id=<id E1>',{method:'DELETE'})` | 200. Baris hilang, folder `uploads/tu/lesson/<id E1>/` terhapus. |
| E17 | GURU A | Hapus dokumen unggahan E3 (Administrasi) lewat UI, lalu refresh | Dokumen hilang permanen (bukan hanya di layar). Folder uploadnya terhapus. |
| E18 | Semua role | Regresi: menu Kesiswaan wali kelas, cetak rekap bulanan, halaman Ekstrakurikuler (pembina/wali/admin) | Berfungsi seperti sebelum patch. |
| E19 | GURU A | Refresh DevTools → Console | Tidak ada error JS baru dari halaman Perangkat Belajar. |

### 9.4 Checklist pasca auto-update (masuk ke `docs/patches/perangkat-belajar-per-guru.md`)

1. Cek apakah `app.html` masih mereferensikan `-perangkat1.js`.
2. Cek apakah `api/index.php` masih me-`require` `lesson_access.php` dan apakah `get_lesson_rpps` masih memanggil `requireRole`.
3. Cek blok `module === 'lesson'` di `tu_file_upload`.
4. Bila ada yang tertimpa, terapkan ulang patch backend dari diff, jalankan skrip bundle terhadap bundle baru (anchor mungkin perlu dicari ulang), lalu ulangi E1, E2, E6, E7, E10, dan E12.

### 9.5 Rollback

1. Kembalikan `app.html` ke `-ekskul1.js`.
2. Kembalikan `api/index.php` dan `api/endpoints_tu.php` dari git (`git checkout <commit sebelum patch> -- <file>`).
3. `api/lesson_access.php` dan `-perangkat1.js` boleh dibiarkan (tidak direferensikan).
4. Tidak ada perubahan skema yang wajib dibatalkan. Index `idx_lesson_author`, bila dibuat, aman dibiarkan.

## 10. Risiko dan trade-off

| Risiko / trade-off | Dampak | Mitigasi |
|---|---|---|
| File unggahan tetap publik via URL langsung | Dokumen guru dapat diunduh orang yang tahu/menebak path (`dokumen_<timestamp>.<ext>`) | Di luar scope. Direkomendasikan tindak lanjut: `.htaccess` deny + endpoint download berotorisasi. Nama folder memakai id `uniqid` sehingga sulit ditebak, tetapi ini bukan kontrol keamanan. |
| Ekstensi file dari klien tetap dipakai | Upload `.php` ke `uploads/` berpotensi dieksekusi | Di luar scope (celah umum `tu_file_upload`). Dicatat untuk usulan BTIKP. |
| Satu query tambahan (`users`) per request Perangkat Belajar | Beban kecil | Diterima demi perubahan role yang langsung berlaku. |
| Dokumen terverifikasi terkunci untuk guru | Guru yang salah unggah setelah verifikasi harus meminta ADMIN menghapus | Sesuai keputusan bisnis. ADMIN tetap bisa hapus via API. Belum ada UI ADMIN (trade-off cakupan kecil). |
| ADMIN tidak punya menu | Penghapusan oleh ADMIN hanya lewat API/console | Diterima. Menu ADMIN bisa menjadi fitur terpisah. |
| Filter guru KS memakai `get_users` (semua user) | Payload lebih besar pada sekolah dengan banyak akun | Hanya dipanggil sekali saat halaman dimuat oleh KS/ADMIN. Filter `role==='GURU'` di klien. |
| Patch bundle berbasis anchor string | Auto-update dengan bundle baru mematahkan skrip | Cek SHA-256 sumber. Skrip gagal eksplisit. Checklist §9.4. |
| Auto-updater menimpa backend | Proteksi hilang diam-diam | Checklist §9.4 dan paket usulan upstream agar perbaikan masuk rilis resmi. |
| Kegagalan upload setelah baris dibuat | Baris tanpa file | Perilaku lama dipertahankan agar perubahan kecil. Guru dapat menghapusnya. |
| Tidak ada uji otomatis | Regresi hanya tertangkap uji manual | Skenario E1–E19 wajib dijalankan penuh sebelum rilis ke produksi. |

## 11. Acceptance criteria

1. **AC1:** GURU yang login hanya menerima dokumen dengan `authorId` = uid-nya dari `get_lesson_rpps`, dan halaman Perangkat Belajar hanya menampilkan dokumen tersebut (E6).
2. **AC2:** KEPALA_SEKOLAH dan ADMIN menerima semua dokumen, termasuk yatim. KS melihat kolom Guru dan dapat memfilter per akun GURU. Panel target hanya tampil saat satu guru dipilih (E12, E13).
3. **AC3:** Dokumen bertipe `MODUL` dan `ADMINISTRASI` hasil upload tampil dengan tipe yang benar, tetap tampil setelah refresh, dan terhitung di kartu serta panel target masing-masing (E1–E3).
4. **AC4:** Dokumen hasil upload (semua tipe) menampilkan tombol Unduh yang membuka file, dan hapus lewat UI menghapus baris di server beserta foldernya (E4, E17).
5. **AC5:** `get_lesson_rpps` tanpa login → 401. Role selain GURU/KS/ADMIN → 403 (E10, E15).
6. **AC6:** GURU tidak dapat membuat, mengubah, mengunggah file ke, atau menghapus dokumen milik guru lain. Server menolak dengan 403/404 dan data tidak berubah (E7, E8).
7. **AC7:** `status`/`verified_*` tidak dapat diubah lewat `save_lesson_rpp`. Dokumen baru selalu `PENDING` (E9).
8. **AC8:** Dokumen `VERIFIED` tidak dapat diedit, diunggah ulang, atau dihapus oleh GURU (409), dan UI menyembunyikan aksi tersebut (E14).
9. **AC9:** ADMIN dapat menghapus dokumen apa pun, termasuk `VERIFIED`. KS tidak dapat membuat, mengedit, atau menghapus dokumen (E12, E16).
10. **AC10:** `delete_lesson_rpp` via GET → 405 (E11).
11. **AC11:** Modal upload default ke semester yang sedang dipilih (E1).
12. **AC12:** `php -l` lolos untuk semua file PHP yang diubah atau ditambah. Skrip patch bundle sukses pada sumber yang benar dan gagal eksplisit pada sumber yang salah (§9.2).
13. **AC13:** Tidak ada regresi pada menu wali kelas, rekap bulanan, dan ekstrakurikuler (E18), serta tidak ada error console baru (E19).
14. **AC14:** Dokumen patch (`docs/patches/perangkat-belajar-per-guru.md`) dan paket usulan BTIKP tersedia sesuai §5.3.
