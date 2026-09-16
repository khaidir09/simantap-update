# Usulan Fitur SIMANTAP: Penugasan Mata Pelajaran Mandiri oleh Guru

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-16 |
| Lampiran | `backend-penugasan-mapel.diff` |
| Terkait | Memakai helper `loadFreshUser()` dari usulan "Menu Kesiswaan untuk Guru Wali Kelas" (`api/kesiswaan_access.php`, `RINGKASAN-PENGEMBANG.md`) |

## 1. Kebutuhan

Penugasan mata pelajaran (`teacher_subjects`) menentukan mapel dan kelas yang muncul di form **Jurnal Mengajar** dan halaman **Nilai/Ujian**. Saat ini penugasan hanya dapat diatur lewat modal edit user di menu Manajemen User, yang hanya dimiliki Admin.

Dua masalah di sekolah kami:

1. **Admin kewalahan.** Seluruh penugasan guru harus diinput satu per satu oleh Admin, terutama di awal semester dan saat ada perubahan jadwal.
2. **Pengisian jurnal terhambat.** Selama penugasan belum diinput, guru tidak memperoleh daftar mapel dan kelas yang benar. Tanpa penugasan, form menampilkan semua mapel dan semua kelas, sehingga data jurnal rawan salah pilih.

**Usulan:** guru dapat mengelola penugasan mata pelajarannya **sendiri** dari halaman Profil, berlaku langsung tanpa persetujuan, sementara Admin tetap dapat mengelola penugasan semua guru. Semua pembatasan ditegakkan di backend.

## 2. Kondisi saat ini (1.0.2)

**Backend (`api/index.php`):**
- `get_teacher_subjects` **tanpa auth**. Tanpa parameter, endpoint ini mengembalikan penugasan seluruh guru kepada siapa pun.
- `save_teacher_subjects_bulk` hanya memanggil `requireAuth()`. GURU memang sudah dibatasi ke uid sendiri, tetapi **TATA_USAHA dan KEPALA_SEKOLAH dapat mengubah penugasan guru mana pun**. Method HTTP tidak dicek.
- Isi body tidak divalidasi: `subject_code` bebas (tidak harus ada di `subjects`), `subject_name` diterima apa adanya, dan `class_ids` tidak dicocokkan dengan tabel `classes`. Item tanpa `subject_code` dilewati diam-diam.
- Duplikat mapel + level hanya dicegah di UI, tidak di server.
- Sinkronisasi ke `gtk_pegawai.mapel`/`kelas_ajar` hanya dijalankan bila hasilnya tidak kosong, sehingga **mengosongkan penugasan meninggalkan data basi** di modul Tata Usaha.
- Pesan kegagalan menyertakan detail exception MySQL ke klien.
- `save_teacher_subjects` dan `delete_teacher_subject` terbuka untuk ADMIN dan KEPALA_SEKOLAH, dan `delete_teacher_subject` dapat dipanggil lewat GET (rawan CSRF). Keduanya tidak dipakai bundle.

**Frontend:** bagian "Penugasan Mata Pelajaran" hanya ada di modal edit user milik Admin. Halaman Profil guru belum memuat penugasan.

## 3. Perbaikan yang diusulkan

### 3.1 Model akses

Tanpa perubahan skema. Kepemilikan tetap memakai `teacher_subjects.teacher_id`. Role dibaca ulang dari DB setiap request.

| Aksi | GURU (milik sendiri) | GURU (guru lain) | ADMIN | KEPALA_SEKOLAH | TATA_USAHA | Role lain | Tanpa login |
|---|---|---|---|---|---|---|---|
| Baca `get_teacher_subjects` | Ya | 403 | Semua | Semua | Semua | 403 | 401 |
| Simpan `save_teacher_subjects_bulk` | Ya | 403 | Semua guru | 403 | 403 | 403 | 401 |
| `save_teacher_subjects` / `delete_teacher_subject` | 403 | 403 | Ya | 403 | 403 | 403 | 401 |

### 3.2 Backend

- Helper baru `api/teacher_subject_access.php`: cek role dengan user segar, cek method, normalisasi item, validasi master, deteksi duplikat, dan sinkronisasi GTK.
- `get_teacher_subjects`: wajib login. GURU hanya boleh membaca penugasan miliknya (`teacher_id` kosong berarti dirinya sendiri).
- `save_teacher_subjects_bulk`:
  - hanya GURU (uid sendiri) dan ADMIN, hanya `POST`;
  - validasi dijalankan sebelum transaksi, sehingga penolakan tidak meninggalkan data setengah jadi;
  - `subject_code` harus ada di `subjects` (untuk GURU harus aktif), dan `subject_name` selalu diambil dari master;
  - setiap `class_ids` harus ada di `classes` dan cocok dengan `class_level`;
  - GURU wajib mengisi level dan minimal satu kelas. Untuk ADMIN keduanya tetap opsional (kosong = semua);
  - duplikat `subject_code` + `class_level` ditolak (400);
  - **kompatibilitas data lama:** item yang identik dengan baris tersimpan dilewati aturan baru, sehingga penugasan warisan yang belum lengkap tidak memblokir penyimpanan;
  - sinkronisasi `gtk_pegawai` selalu mencerminkan isi penugasan, termasuk saat kosong;
  - respons menambah `items` (daftar tersimpan) agar UI tidak perlu memuat ulang;
  - kegagalan DB dikembalikan sebagai 500 dengan pesan umum, detailnya ke `error_log`.
- `save_teacher_subjects` dan `delete_teacher_subject`: dipersempit ke ADMIN dan menolak GET.

### 3.3 Frontend

- Halaman **Profil** guru mendapat kartu "Penugasan Mata Pelajaran" (hanya `role === "GURU"`): form tambah (mapel aktif, level, kelas), daftar penugasan dengan tombol hapus berkonfirmasi, tombol **Simpan Penugasan** yang aktif hanya saat ada perubahan, dan pesan error dari backend.
- Penugasan warisan yang belum lengkap diberi badge **"Perlu dilengkapi"** beserta alasannya.
- Modal edit user Admin: `alert` kegagalan menampilkan pesan error dari backend.

Di sekolah kami, perubahan ini diterapkan sebagai patch pada bundle hasil build (`docs/patches/penugasan-mapel-mandiri-guru.md`, 3 anchor).

## 4. Kompatibilitas dan catatan migrasi

- Tidak ada tabel, kolom, index, atau migrasi baru. Field respons baru (`items`) bersifat aditif.
- **Perubahan yang terlihat pengguna:**
  - guru dapat mengatur penugasannya sendiri, dan perubahannya langsung berlaku;
  - Admin kini dapat ditolak bila mengirim duplikat atau kelas yang tidak ada;
  - Kepala Sekolah dan Tata Usaha tidak lagi dapat mengubah penugasan lewat API (keduanya memang tidak punya UI untuk itu);
  - mengosongkan penugasan ikut mengosongkan `mapel`/`kelas_ajar` pada data GTK.
- Penugasan lama yang belum lengkap tetap tersimpan selama tidak diubah, sehingga tidak ada data yang hilang saat patch diterapkan.
- Perlu diperiksa sebelum rilis: nilai `classes.level` harus konsisten dengan level pada nama kelas (`SELECT DISTINCT level FROM classes`), karena validasi kelas mencocokkan keduanya.

## 5. Hasil uji di sekolah (dev lokal)

- **Logika validasi:** 10/10 pemeriksaan lulus untuk normalisasi item, pencocokan data warisan (multiset, urutan kelas diabaikan), deteksi item baru, dan pengecualian duplikat warisan.
- **Statis:** `php -l` lolos untuk `api/index.php` dan `api/teacher_subject_access.php`; bundle hasil patch lolos pemeriksaan sintaks.
- **UI:** diuji manual oleh sekolah sesuai spesifikasi `docs/specs/penugasan-mapel-mandiri-guru.md` §9.3. Staging tetap diperlukan sebelum produksi.

## 6. Permintaan ke pengembang

1. Menerapkan model akses §3.1 di sumber resmi (backend dan React), atau desain setara yang tetap ditegakkan di backend.
2. Menutup `get_teacher_subjects` yang saat ini tanpa auth, dan membatasi `save_teacher_subjects_bulk` agar hanya guru pemilik dan Admin yang dapat menyimpan.
3. Menambahkan pengelolaan penugasan mapel mandiri di halaman Profil guru pada rilis resmi.
4. Di luar usulan ini, tetapi terkait: `gtk_update` pada modul Tata Usaha membangun ulang `teacher_subjects` dari teks `gtk_pegawai.mapel`, sehingga penugasan berbasis kelas yang dibuat guru dapat tertimpa. Kami menyarankan sinkronisasi dua arah itu ditinjau ulang.
