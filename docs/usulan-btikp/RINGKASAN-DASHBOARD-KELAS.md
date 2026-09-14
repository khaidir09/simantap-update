# Usulan Perbaikan SIMANTAP: Data Dashboard Kelas Wali Kelas Tidak Muncul

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-13 |
| Lampiran | `backend-dashboard-kelas.diff` |
| Terkait | Lanjutan dari usulan "Menu Kesiswaan untuk Guru Wali Kelas" (`RINGKASAN-PENGEMBANG.md`) |

## 1. Masalah

Di menu **Kelas Saya → Dashboard Kelas**, wali kelas melihat halaman seolah-olah belum ada data, padahal presensi kelas tersebut sudah diisi, baik lewat Jurnal Mengajar maupun Presensi Kelas. Semua bagian halaman kosong:

- kartu ringkasan (Total Hadir, Sakit, Izin, Alfa);
- tab **Rekap Bulanan** dan **Per Siswa**;
- total poin pelanggaran kelas.

Untuk sebagian wali kelas, **daftar siswanya pun kosong**.

Selain itu, sidebar dan isi halaman menampilkan **id kelas** (`XII_TKJ_2`), bukan nama kelas (`XII TKJ 2`).

## 2. Penyebab

Semua data di halaman diambil dari satu request:

```
api/index.php?action=get_homeroom_dashboard&class_id=<user.class_homeroom>&semester=<1|2>
```

Handler `get_homeroom_dashboard` di `api/endpoints_v3.php` punya tiga masalah.

### 2.1 Tahun ajaran di-hardcode `2025/2026` (penyebab utama)

```php
$academicYear = $_GET['year'] ?? '2025/2026';
```

Frontend tidak mengirim parameter `year`, sehingga query rekap selalu memfilter `academic_year = '2025/2026'`. Padahal presensi disimpan dengan tahun ajaran aktif dari `system_config`, baik oleh `save_journal` maupun `save_attendance_records`. Setelah sekolah berganti tahun ajaran, rekap dashboard **selalu kosong**.

Data di sekolah kami:

| `attendance_records` | Nilai |
|---|---|
| `academic_year` | seluruhnya `2026/2027` |
| `system_config.active_academic_year` | `2026/2027` |
| Filter yang dipakai dashboard | `2025/2026`, sehingga 0 baris |

`get_class_attendance_summary` memakai default yang sama.

### 2.2 `class_id` dicocokkan persis, padahal formatnya berbeda

Handler memakai `class_homeroom` mentah dari user untuk `students.class_id = ?` dan `attendance_records.class_id = ?`. Di data nyata ketiga nilai ini tidak sama:

| Sumber | Contoh |
|---|---|
| `users.class_homeroom` | `X-AKT-1` atau `XII_TKJ_2` (keduanya ada di data kami) |
| `classes.id`, `students.class_id`, `attendance_records.class_id` | `X_AKT_1` |
| `classes.name`, `incidents.className` | `X AKT 1` |

Di sekolah kami, **11 dari 35 wali kelas** menyimpan `class_homeroom` dengan tanda hubung. Bagi mereka, jumlah siswa di dashboard pun 0.

### 2.3 Total poin mencocokkan `className` dengan id kelas

```php
SELECT ... FROM incidents WHERE className = ?   -- diisi class_id, mis. "XI_TKJ_2"
```

Kolom `incidents.className` menyimpan **nama** kelas (`XI TKJ 2`), sehingga total poin selalu 0.

Masalah serupa yang lebih kecil ada di `get_incidents`: normalisasi SQL untuk filter kelas hanya membuang spasi, `-`, dan `.`, tetapi tidak `_`. Sementara itu, normalisasi di PHP untuk parameter juga membuang `_`.

### 2.4 Tampilan memakai id kelas

Label sidebar ("Dashboard `XII_TKJ_2`"), judul dan deskripsi halaman, judul tab, serta banner wali kelas di halaman insiden menampilkan `user.class_homeroom` apa adanya. Session tidak membawa nama kelas.

## 3. Perbaikan yang diusulkan

### 3.1 Backend (lihat `backend-dashboard-kelas.diff`)

Diff dibuat **di atas** `backend-kesiswaan-wali-kelas.diff`, karena memakai helper dari `api/kesiswaan_access.php`. Kami sudah memverifikasi bahwa kedua diff bisa diterapkan bersih secara berurutan ke bundle 1.0.2 asli (`git apply --ignore-whitespace`) dan lolos `php -l`. Tidak ada perubahan skema.

| File | Perubahan |
|---|---|
| `api/kesiswaan_access.php` | Helper baru `resolveClassRef($db, $idAtauNama)`. Helper mencocokkan referensi kelas dalam format apa pun (kunci ternormalisasi: lowercase, tanpa spasi/`_`/`-`/`.`) ke baris `classes`, lalu mengembalikan `['id', 'name']`. Jika tidak ditemukan, input dikembalikan apa adanya. |
| `api/endpoints_v3.php` | `get_homeroom_dashboard` dan `get_class_attendance_summary`: `class_id` di-resolve ke `classes.id`. Jika `year`/`semester` tidak dikirim, nilainya diambil dari `system_config` (`activeAcademicPeriod()`). Total poin dicocokkan lewat kunci id **atau** nama kelas. |
| `api/index.php` | `login` dan `session` menambahkan field `class_homeroom_name` (nama kelas perwalian) ke objek user. `class_homeroom` **tidak diubah** dan tetap menjadi kunci untuk API. `get_incidents`: normalisasi SQL filter kelas juga membuang `_`. |

Karena frontend memanggil `session` setiap kali aplikasi dibuka, nama kelas langsung tersedia tanpa login ulang.

### 3.2 Frontend (level source)

Di sekolah kami perubahan ini sementara dipasang sebagai patch pada file JS hasil build. Mohon diterapkan di source React:

1. **Tampilkan nama kelas, bukan id.** Gunakan `user.class_homeroom_name || user.class_homeroom` untuk teks tampilan di:
   - label sidebar "Kelas Saya": `Dashboard ${...}`;
   - komponen Dashboard Kelas: judul, deskripsi, judul tab "Rekap Presensi Bulanan / Rekap Individual Siswa / Insiden Siswa — Kelas …", dan field `class` pada data per siswa;
   - banner di halaman insiden: "Anda adalah Wali Kelas …" dan "Wali kelas Anda: …".

   Request API (`class_id=`, `class=`) **tetap** memakai `user.class_homeroom`.
2. **Kirim tahun ajaran aktif** pada `get_homeroom_dashboard`, misalnya `&year=${activeAcademicYear}`, agar tidak bergantung pada default backend. Terapkan juga pada request lain yang masih memakai `2025/2026`.
3. **Pertimbangkan penyeragaman format `class_homeroom`.** Simpan `classes.id` saat wali kelas dipilih di form Kelola Guru/GTK, dan migrasikan data lama dengan pola `X-AKT-1` ke `X_AKT_1`. Dengan begitu, pencocokan ternormalisasi hanya menjadi jaring pengaman.

## 4. Hasil uji di sekolah

Lingkungan: PHP 8.2, MySQL 5.7.33 dengan `STRICT_TRANS_TABLES` dan `ONLY_FULL_GROUP_BY`, data sekolah (35 kelas, 968 siswa, tahun ajaran aktif 2026/2027).

Pemanggilan `get_homeroom_dashboard&semester=1`:

| `class_id` yang dikirim | Sebelum | Sesudah |
|---|---|---|
| `XII_TKJ_2` | 29 siswa, rekap bulanan dan per siswa kosong, kartu ringkasan 0 | 29 siswa; hari ini 28 Hadir dan 1 Sakit; September 113 Hadir dan 3 Sakit |
| `XI-TKJ-2` | 0 siswa, rekap kosong, poin 0 | 29 siswa; September 54 Hadir, 1 Sakit, 1 Izin, 2 Alfa; poin 10 |
| `X-AKT-1` | 0 siswa | 32 siswa (memang belum ada presensi) |

Pemanggilan `session`:

| `class_homeroom` | `class_homeroom_name` |
|---|---|
| `XII_TKJ_2` | `XII TKJ 2` |
| `X-AKT-1` | `X AKT 1` |
| kosong | kosong |

Kolom "Sebelum" diturunkan dari query lama terhadap data yang sama.

Pengujian di browser oleh wali kelas: data Dashboard Kelas sudah muncul setelah perbaikan backend.

## 5. Catatan tambahan

- Total poin pelanggaran di dashboard menjumlahkan **semua** insiden kelas (selain `REJECTED`) tanpa filter tahun ajaran atau semester. Sebaiknya diputuskan apakah angka ini perlu mengikuti semester yang dipilih.
- Filter `students.status = 'AKTIF'` tetap berjalan pada data kami, karena kolom `status` sudah menjadi VARCHAR berisi `Aktif` dengan collation case-insensitive (`utf8mb4_unicode_ci`). Pada collation biner, siswa tidak akan terhitung.
- Temuan terkait hak akses endpoint dashboard wali kelas kami sampaikan secara terpisah bersama temuan keamanan lain.
