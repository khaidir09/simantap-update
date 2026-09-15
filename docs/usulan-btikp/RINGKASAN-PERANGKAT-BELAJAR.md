# Usulan Perbaikan SIMANTAP: Perangkat Belajar per Guru dan Tampilan Modul Ajar/Administrasi

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-15 |
| Lampiran | `backend-perangkat-belajar.diff` |
| Terkait | Memakai helper `loadFreshUser()` dari usulan "Menu Kesiswaan untuk Guru Wali Kelas" (`api/kesiswaan_access.php`, `RINGKASAN-PENGEMBANG.md`) |

## 1. Kebutuhan

Menu **Perangkat Belajar** dipakai guru untuk mengumpulkan RPP, Silabus, Modul Ajar, dan dokumen Administrasi, lalu diverifikasi Kepala Sekolah. Di sekolah kami ditemukan dua masalah:

1. **Dokumen tidak terkunci per guru.** Dokumen seorang guru tampil di akun semua guru, dan guru lain bisa mengedit atau menghapusnya. Endpoint daftar bahkan bisa dibaca tanpa login.
2. **Modul Ajar dan Administrasi tidak tampil.** Upload tersimpan di tabel `lesson_rpps`, tetapi tidak muncul di daftar, dan penghitung Modul/Administrasi selalu 0. Kepala Sekolah juga tidak bisa memverifikasi dokumen hasil upload.

**Usulan:**
- **Guru** hanya melihat dan mengelola dokumen miliknya. Dokumen yang sudah diverifikasi terkunci.
- **Kepala Sekolah** melihat semua dokumen, bisa memfilter per guru, dan memverifikasi, tetapi tidak membuat atau mengunggah dokumen.
- **Admin** bisa melihat semua dokumen dan menghapus dokumen apa pun untuk koreksi data.
- Semua pembatasan ditegakkan di backend.

## 2. Kondisi saat ini (1.0.2)

**Backend (`api/index.php`):**
- `get_lesson_rpps` **tanpa auth** dan mengembalikan semua dokumen (termasuk NIP/NUPTK/NIK dari `gtk_pegawai`). Isi `payload` kiriman klien juga menimpa kolom DB saat merge.
- `save_lesson_rpp` memakai `INSERT … ON DUPLICATE KEY UPDATE` dengan `id` dari body:
  - siapa pun yang tahu `id` bisa menimpa dokumen orang lain, dan `authorId` ikut berganti;
  - `status` diterima dari body, sehingga guru bisa mengirim `VERIFIED`.
- `delete_lesson_rpp` **tanpa auth**, bisa lewat GET, dan tidak menghapus file.
- `tu_file_upload` `module=lesson` tidak memeriksa pemilik `record_id`.

**Frontend (komponen halaman Perangkat Belajar):**
- Saat dimuat, data disaring `type==="RPP" || !type`, sehingga `MODUL` dan `ADMINISTRASI` dibuang.
- Pemetaan daftar memaksa `type:"RPP"` dan `isInteractive:true`. Akibatnya dokumen hasil upload tampil sebagai "RPP interaktif" (tombol Edit, bukan Unduh), dan tombol hapus dokumen unggahan hanya menghapus dari state lokal.
- Modal upload selalu default semester **GENAP**.
- Modal detail dokumen unggahan tidak punya tombol Verifikasi, dan tombol "Unduh Berkas" tidak punya aksi.
- Tombol Buat/Upload/Edit/Hapus tampil untuk semua role, termasuk Kepala Sekolah, dan untuk dokumen yang sudah VERIFIED.

## 3. Perbaikan yang diusulkan

### 3.1 Model akses

Tanpa perubahan skema. Pemilik = `lesson_rpps.authorId`. Role dibaca ulang dari DB setiap request.

| Aksi | GURU pemilik | GURU lain | KEPALA_SEKOLAH | ADMIN | Role lain | Tanpa login |
|---|---|---|---|---|---|---|
| Lihat daftar | Miliknya saja | – | Semua | Semua | 403 | 401 |
| Buat | Ya | – | 403 | 403 | 403 | 401 |
| Edit / upload file | Ya, bila belum VERIFIED (409) | 403 | 403 | 403 | 403 | 401 |
| Hapus | Ya, bila belum VERIFIED (409) | 403 | 403 | Ya, apa pun statusnya | 403 | 401 |
| Verifikasi | 403 | 403 | Ya | Ya | 403 | 401 |

### 3.2 Backend

- Helper baru `api/lesson_access.php`: cek role dengan user segar, method, pemilik, kunci VERIFIED, validasi `fileUrl`, hapus folder unggahan, dan index `authorId`.
- `get_lesson_rpps`:
  - filter `authorId` untuk GURU;
  - kolom DB otoritatif atas payload;
  - field aditif `teacherId`, `teacherName`, `isInteractive`, `canEdit`, `canDelete`.
- `save_lesson_rpp`:
  - hanya GURU dan `POST`;
  - dokumen baru selalu `PENDING`;
  - edit memakai `UPDATE` tanpa mengubah pemilik, status, atau `createdAt`;
  - `type` divalidasi; `fileUrl` wajib di `uploads/tu/lesson/<id>/`.
- `delete_lesson_rpp`: `DELETE`/`POST` saja, aturan di atas, dan folder unggahan ikut dihapus.
- `verify_lesson_rpp`: `POST` saja, id tidak ada → 404.
- `tu_file_upload` `module=lesson`: cek pemilik dan status sebelum file ditulis.

### 3.3 Frontend

Untuk sumber React:
- Daftar dokumen memuat semua tipe selain SILABUS ke daftar RPP/Modul/Administrasi. `type` dan `isInteractive` dibaca dari data.
- Tombol Edit/Hapus mengikuti `canEdit`/`canDelete`, dengan label "Terkunci" untuk dokumen VERIFIED milik guru. Hapus dokumen unggahan memanggil server.
- Tombol Buat RPP, Buat Silabus, dan Upload hanya untuk GURU.
- Untuk Kepala Sekolah/Admin:
  - dropdown filter guru (akun GURU dari `get_users`) dan kolom Guru;
  - panel "Sisa Target Dokumen" hanya tampil saat satu guru dipilih.
- Modal upload default ke semester yang sedang dipilih.
- Modal detail dokumen unggahan: tombol Unduh berfungsi; tombol Verifikasi/badge Terverifikasi untuk KS/Admin; nama pengunggah dari `teacherName`.
- `alert` menampilkan pesan error dari backend.

Di sekolah kami, perubahan ini diterapkan sebagai patch pada bundle hasil build (`docs/patches/perangkat-belajar-per-guru.md`, 38 anchor).

## 4. Kompatibilitas dan catatan migrasi

- Tidak ada kolom baru dan tidak ada migrasi data. Field respons baru bersifat aditif. Index `idx_lesson_author` dibuat otomatis bila hak DB mengizinkan.
- **Perubahan yang terlihat pengguna:**
  - guru tidak lagi melihat dokumen guru lain;
  - Kepala Sekolah tidak lagi punya tombol Buat/Upload/Edit/Hapus;
  - dokumen VERIFIED tidak bisa diubah guru.
- Dokumen tanpa pemilik valid hanya terlihat oleh KS/Admin. Kueri audit tersedia di dokumen patch §4.
- Dokumen yang pemiliknya pernah berganti karena upsert lama tidak dipulihkan.

## 5. Hasil uji di sekolah (dev lokal)

- **API:** 31/31 skenario lulus. Cakupannya matriks akses per role, 401/403/404/405/409, status tidak bisa dipalsukan, validasi `fileUrl`/`type`, dan penghapusan folder unggahan.
- **UI:** diuji manual oleh sekolah sesuai spesifikasi §9.3. Rincian ada di `docs/patches/perangkat-belajar-per-guru.md` §7. Staging tetap diperlukan sebelum produksi.

## 6. Permintaan ke pengembang

1. Menerapkan model akses §3.1 di sumber resmi (backend dan React), atau desain setara yang tetap ditegakkan di backend.
2. Memperbaiki filter tipe dan pemetaan daftar di halaman Perangkat Belajar agar Modul Ajar dan Administrasi tampil, termasuk tombol Verifikasi pada dokumen unggahan.
3. Menutup `get_lesson_rpps` dan `delete_lesson_rpp` yang saat ini tanpa auth.
4. Di luar usulan ini, tetapi terkait: file di `uploads/` dapat diunduh publik via URL dan ekstensi file dari klien tetap dipakai. Kami menyarankan endpoint unduh berotorisasi dan whitelist ekstensi pada `tu_file_upload`.
