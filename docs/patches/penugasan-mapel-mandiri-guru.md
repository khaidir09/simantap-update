# Patch lokal: Penugasan Mata Pelajaran Mandiri oleh Guru

- Spesifikasi: `docs/specs/penugasan-mapel-mandiri-guru.md`
- Versi bundle acuan: SIMANTAP **1.0.2** + patch wali kelas, rekap bulanan, pembina ekskul, dan perangkat belajar (`docs/patches/perangkat-belajar-per-guru.md`)
- Tanggal patch: 2026-09-16
- Status upstream: **belum** ada perbaikan resmi. Dokumen ini sekaligus menjadi lampiran usulan ke BTIKP (`docs/usulan-btikp/RINGKASAN-PENUGASAN-MAPEL.md`).

## 1. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/teacher_subject_access.php` | baru: helper otorisasi dan validasi penugasan mapel | `7a82604bc57a3f776c81ab40ab3415848425ef4e821c202f93d2d029e10fd15a` |
| `api/index.php` | ubah: `require_once` helper; `get_teacher_subjects`, `save_teacher_subjects`, `save_teacher_subjects_bulk`, `delete_teacher_subject` | `dfab04017db19ae6fdb7bf111b9972e6780e1fef83e4b22b93f8dcbe324ae8c8` |
| `assets/index-CGWL69P7-perangkat1.js` | **tidak diubah** (sumber patch) | `518c88dbd27e812779a661bdadfff87cdf3c858f6a94de0619d05dbf9d972537` |
| `assets/index-CGWL69P7-mapel1.js` | baru: salinan `-perangkat1.js` dengan patch F1–F8 | `acc716435b50c113d26bcd3c2693007e766bfd82fcf698f22bd5f8c0c899193a` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-mapel1.js` | `9946c8e4386dc30f46df2b19898dbdeed28c5c8d096430382b7af8f59c79e94e` |
| `docs/patches/apply-penugasan-mapel-bundle.js` | baru: skrip penerap patch bundle | – |

Hash file PHP hanya berlaku bila file itu tidak berubah karena patch lain. Jika berbeda, bandingkan isinya dengan `docs/usulan-btikp/backend-penugasan-mapel.diff`.

**Skema:** tidak ada tabel, kolom, index, atau migrasi baru.

## 2. Ringkasan perilaku backend

Detail lengkap ada di spesifikasi §3.3.

- Role dibaca ulang dari tabel `users` setiap request (`loadFreshUser` di `api/kesiswaan_access.php`).
- `get_teacher_subjects`: wajib login (401). Hanya GURU, ADMIN, KEPALA_SEKOLAH, dan TATA_USAHA (lainnya 403). GURU hanya boleh membaca `teacher_id` miliknya (403 bila berbeda); `teacher_id` kosong otomatis berarti dirinya sendiri.
- `save_teacher_subjects_bulk`: hanya GURU (uid sendiri) dan ADMIN, hanya `POST` (lainnya 405).
  - Validasi berjalan **sebelum** transaksi.
  - Item yang identik dengan baris tersimpan (data warisan) dilewati aturan baru, dicocokkan sebagai multiset dan mengabaikan urutan `class_ids`.
  - Item baru: `subject_code` harus ada di `subjects` (GURU: harus `is_active = 1`), `subject_name` diambil dari master, setiap `class_ids` harus ada di `classes` dan cocok levelnya. GURU wajib mengisi `class_level` dan minimal satu kelas.
  - Duplikat `subject_code` + `class_level` ditolak, kecuali bila seluruh anggotanya item warisan.
  - Simpan tetap hapus-lalu-insert dalam satu transaksi, dan sinkronisasi `gtk_pegawai.mapel`/`kelas_ajar` kini ikut **dikosongkan** saat daftar kosong.
  - Respons: `{success, count, items}` (`items` = daftar tersimpan, aditif).
  - Error DB tidak lagi dikirim ke klien (500 dengan pesan umum, detail masuk `error_log`).
- `save_teacher_subjects` dan `delete_teacher_subject` (tidak dipakai bundle): hanya ADMIN, dan menolak GET (405).

## 3. Ringkasan perilaku frontend (F1–F8)

| # | Lokasi | Perubahan |
|---|---|---|
| F1–F7 | Komponen Profil `uot` | Kartu baru **"Penugasan Mata Pelajaran"** (hanya `role === "GURU"`): memuat `get_teacher_subjects`, `get_subjects&active_only=1`, dan `get_classes`; form tambah (mapel, level wajib, kelas wajib ≥1); cek duplikat di klien; daftar dengan konfirmasi hapus inline dan badge "Perlu dilengkapi"; tombol **Simpan Penugasan** aktif hanya saat ada perubahan, dengan pesan sukses/gagal dari backend. |
| F8 | Modal edit user Admin | Alert kegagalan menampilkan pesan `error` dari backend, bukan hanya "Cek koneksi server". |

Identifier baru memakai prefiks `__tm`. Menu sidebar, form Jurnal Mengajar, dan halaman Nilai tidak diubah.

## 4. Cara menerapkan ulang

```
# 1. Backend
git apply docs/usulan-btikp/backend-penugasan-mapel.diff   # atau salin manual
php -l api/index.php
php -l api/teacher_subject_access.php

# 2. Bundle frontend (sumber harus -perangkat1.js dan app.html masih menunjuk ke sana)
node docs/patches/apply-penugasan-mapel-bundle.js
```

Skrip memverifikasi SHA-256 sumber, menolak jika prefiks `__tm` sudah ada, menulis `assets/index-CGWL69P7-mapel1.js`, lalu mengarahkan `app.html`. Menjalankannya dua kali akan gagal pada langkah `app.html` (sudah tidak menunjuk `-perangkat1.js`).

### Anchor yang dipakai

| # | Anchor (string eksak, 1 kemunculan) |
|---|---|
| F1–F7a | `function uot({user:e}){` |
| F1–F7b | `a.jsxs(H$,{title:"Ubah Password"` |
| F8 | `alert("Gagal menyimpan. Cek koneksi server.")` |

## 5. Checklist pasca auto-update

1. Cek `app.html` masih mereferensikan `index-CGWL69P7-mapel1.js`.
2. Cek `api/index.php` masih memuat `require_once __DIR__ . '/teacher_subject_access.php';` dan `save_teacher_subjects_bulk` masih memanggil `tsUser`.
3. Cek `api/teacher_subject_access.php` masih ada.
4. Bila ada yang tertimpa: terapkan ulang patch backend dari diff, jalankan skrip bundle terhadap bundle baru (anchor mungkin perlu dicari ulang), lalu ulangi uji E2, E4, E11, E14, E16, dan E17 pada spesifikasi §9.3.

## 6. Rollback

1. Kembalikan `app.html` ke `index-CGWL69P7-perangkat1.js`.
2. `git checkout <commit sebelum patch> -- api/index.php`.
3. `api/teacher_subject_access.php` dan `assets/index-CGWL69P7-mapel1.js` boleh dibiarkan (tidak direferensikan).
4. Tidak ada perubahan skema yang perlu dibatalkan. Data penugasan yang dibuat guru tetap valid untuk kode lama.

## 7. Catatan

- `gtk_update` di modul TU tetap membangun ulang `teacher_subjects` dari teks `gtk_pegawai.mapel`, sehingga penugasan kelas yang dibuat guru bisa tertimpa bila staf TU mengedit mapel GTK. Di luar scope patch ini, dan diusulkan ke BTIKP.
- File unggahan, menu, dan endpoint lain tidak tersentuh.
