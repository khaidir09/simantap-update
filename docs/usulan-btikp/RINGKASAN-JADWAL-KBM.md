# Usulan Fitur SIMANTAP: Jadwal KBM Mandiri oleh Guru dan Isi Otomatis Jam Jurnal

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-27 |
| Lampiran | `backend-jadwal-kbm.diff` |
| Terkait | Memakai helper `loadFreshUser()`/`resolveClassRef()` (`api/kesiswaan_access.php`) dan penugasan mapel dari usulan "Penugasan Mata Pelajaran Mandiri oleh Guru" (`RINGKASAN-PENUGASAN-MAPEL.md`); melengkapi usulan "Tampilan Halaman Jurnal Mengajar" (waktu jurnal dari jam pelajaran) |

## 1. Kebutuhan

Jadwal KBM guru disimpan per pegawai di `gtk_pegawai.jadwal_mengajar_json` dan saat ini hanya dapat diinput oleh Tata Usaha dari menu Kepegawaian (GTK). Di sekolah kami, baru 1 dari 61 pegawai yang memiliki jadwal.

Masalah:

1. **Tata Usaha kewalahan** menginput jadwal seluruh guru.
2. **Kolom "Jam Ke / Waktu KBM" berupa teks bebas** (contoh data nyata: `08 - 11 (13.15 - 16.30)`), sehingga tidak dapat dipakai sistem.
3. **Jam jurnal diketik ulang** oleh guru setiap mengisi Jurnal Mengajar, dan salah ketik terjadi (contoh data nyata: jam ke `4-7`, `10:10`–`01:55`).
4. **Informasi ruang belajar** pada jadwal tidak diperlukan.

**Usulan:** guru dapat mengelola jadwal KBM-nya sendiri dari halaman Profil (langsung berlaku), jam pelajaran disimpan dalam field terstruktur, dan form Jurnal Mengajar mengisi jam pelajaran (serta mapel) otomatis dari jadwal. Tata Usaha dan Admin tetap mengelola jadwal semua pegawai dengan format yang sama.

## 2. Kondisi saat ini (1.0.2)

**Backend:**
- `gtk_update_atribut` hanya memanggil `requireAuth()`. **Guru mana pun dapat mengubah jadwal, wali kelas (termasuk `users.class_homeroom`), dan status BK (`users.is_bk`) pegawai siapa pun.** Jadwal diterima tanpa validasi, method HTTP tidak dicek, dan sinkronisasi ke `users` menyusun SQL dengan `real_escape_string`.
- `gtk_create`/`gtk_update` menulis ulang seluruh `jadwal_mengajar_json` dari body. Saat TU menyimpan biodata, bundle mengirim jadwal dari state klien, sehingga jadwal di DB dapat tertimpa data basi.
- Format sesi `{id, hari, kelas, jamKe, ruang}`: `kelas` berupa **nama** kelas (dari teks `kelas_ajar`), bukan `classes.id` yang dipakai jurnal; `jamKe` teks bebas; `id` dibuat klien.

**Frontend:** form jadwal hanya di detail GTK (TU/Admin), dengan input teks bebas "Jam Ke / Waktu KBM" dan pilihan ruang. Kegagalan simpan hanya dicatat di console. Guru hanya melihat jadwal read-only di dashboard.

## 3. Perbaikan yang diusulkan

### 3.1 Model akses

| Aksi | GURU (milik sendiri) | GURU (pegawai lain) | TATA_USAHA | ADMIN | KEPALA_SEKOLAH | Role lain | Tanpa login |
|---|---|---|---|---|---|---|---|
| `jadwal_kbm_get` | Ya | 403 | Semua | Semua | Semua | 403 | 401 |
| `jadwal_kbm_save` | Ya | 403 | Semua | Semua | 403 | 403 | 401 |
| `gtk_update_atribut` | 403 | 403 | Ya | Ya | 403 | 403 | 401 |

Role dibaca ulang dari DB setiap request. Tanpa perubahan skema.

### 3.2 Format sesi baru (aditif)

```json
{ "id": "JM-…", "hari": "Senin", "classId": "XII_TKJ_2", "kelas": "XII TKJ 2",
  "subjectCode": "MTK", "mapel": "Matematika",
  "jamKeMulai": 1, "jamKeSelesai": 4, "jamMulai": "07:30", "jamSelesai": "10:45",
  "jamKe": "1 - 4 (07:30 - 10:45)" }
```

`kelas`, `mapel`, dan `jamKe` dibentuk server. `jamKe` tetap ada agar tampilan lama tetap terbaca. Sesi lama tidak dimigrasi: saat dibaca, server mem-parse `jamKe` (pola `08 - 11 (13.15 - 16.30)`, `3 s.d. 5`, dll.) dan me-resolve nama kelas; sesi yang gagal diparse ditandai "Perlu dilengkapi" dan tidak dipakai isi otomatis.

### 3.3 Backend

- File baru `api/jadwal_kbm.php` dengan dua action:
  - `jadwal_kbm_get`: data tersimpan apa adanya + nilai efektif, status kelengkapan, dan `versi` (sha1 isi kolom).
  - `jadwal_kbm_save` (POST): simpan daftar penuh dengan `versi` (konflik → 409, dicek ulang dengan `SELECT … FOR UPDATE`); sesi tersimpan yang tidak berubah disimpan apa adanya; sesi baru divalidasi (hari Senin–Jumat, kelas ada, jam ke 1–11, jam mulai < selesai, mapel dari master; untuk guru kelas/mapel dibatasi penugasan mapel); bentrok jam ke dengan sesi sendiri ditolak; bentrok kelas dengan guru lain menjadi peringatan.
- `gtk_update_atribut`: hanya TU/Admin, hanya POST, prepared statement. Field jadwal tetap diterima demi kompatibilitas bundle lama.
- `gtk_create`/`gtk_update`: tidak lagi menimpa jadwal saat update.

### 3.4 Frontend

- **Profil guru:** kartu "Jadwal Mengajar (KBM)" (daftar per hari, tambah/hapus, pesan backend, peringatan bentrok antarguru).
- **Detail GTK (TU/Admin):** grid Senin–Jumat dipertahankan, form memakai field terstruktur dan endpoint yang sama; ruang dihapus.
- **Dashboard:** widget jadwal tanpa ruang.
- **Jurnal Mengajar (jurnal baru):** bila hari dari tanggal dan kelas cocok dengan satu sesi, Jam Ke-, Jam Mulai, Jam Selesai, dan mapel (bila guru belum memilih sendiri) terisi otomatis. Bila lebih dari satu sesi cocok, atau jam sudah diubah manual, tampil chip pilihan sesi. Tidak berlaku saat mengedit jurnal lama. Format `Jam Ke-` (`1-4`) tetap kompatibel dengan perhitungan presensi harian dan dispensasi.

Di sekolah kami, frontend diterapkan sebagai patch pada bundle hasil build (`docs/patches/jadwal-kbm-mandiri-guru.md`, 9 anchor).

## 4. Kompatibilitas dan catatan migrasi

- Tidak ada tabel, kolom, index, atau migrasi baru.
- Data format baru tetap terbaca oleh bundle lama (key `hari`, `kelas`, `jamKe` dipertahankan). Data lama tetap terbaca oleh endpoint baru.
- **Perubahan yang terlihat pengguna:**
  - guru dapat mengatur jadwalnya sendiri, langsung berlaku;
  - field ruang hilang dari jadwal;
  - guru dan Kepala Sekolah tidak lagi dapat memanggil `gtk_update_atribut` (keduanya tidak punya UI untuk itu);
  - simpan biodata GTK tidak lagi mengubah jadwal.
- Bundle lama milik TU masih dapat menulis jadwal lewat `gtk_update_atribut` tanpa cek versi; jalur ini dapat dihapus setelah rilis resmi memakai endpoint baru.

## 5. Hasil uji di sekolah (dev lokal)

- Unit test helper (parser jam lama, irisan jam ke, nilai efektif): 39/39 lulus.
- Uji API (matriks akses, validasi, 409, sesi lama, peringatan antarguru, `gtk_update` tidak menimpa jadwal, jalur legacy): 47/47 lulus.
- Uji browser (Chrome headless) untuk Profil guru, form jurnal, dashboard, dan detail GTK: sesuai, tanpa error console baru.
- Staging tetap diperlukan sebelum produksi.

## 6. Permintaan ke pengembang

1. Menyediakan pengelolaan jadwal KBM mandiri untuk guru pada rilis resmi, dengan otorisasi di backend.
2. Mengganti input teks bebas "Jam Ke / Waktu KBM" dengan field terstruktur, dan memakai jadwal untuk mengisi otomatis jam pelajaran di Jurnal Mengajar.
3. Menutup celah `gtk_update_atribut` (saat ini dapat dipakai guru mana pun untuk mengubah wali kelas/BK pegawai lain).
4. Menghentikan `gtk_update` menimpa jadwal dari state klien.
5. Pertimbangan lanjutan: master jam pelajaran/bel sekolah (jam ke-n = HH:MM) agar guru cukup memilih jam ke, dan menutup `gtk_list` yang saat ini dapat dibaca tanpa login.
