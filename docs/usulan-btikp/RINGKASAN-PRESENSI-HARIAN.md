# Usulan Perbaikan SIMANTAP: Presensi Harian dari Jurnal, Insiden ALFA, dan Pelanggaran Meninggalkan Jam Pelajaran

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-27 |
| Lampiran | `backend-presensi-harian.diff` |
| Dokumentasi patch lokal | `docs/patches/presensi-harian-jurnal.md` |
| Terkait | Prasyarat usulan **Dispensasi Siswa** (`RINGKASAN-DISPENSASI.md`) |

## 1. Kebutuhan

Presensi harian yang dilihat wali kelas (menu Presensi Kelas, rekap bulanan, rapor) diisi otomatis dari
Jurnal Mengajar. Satu siswa bisa tercatat di banyak jurnal pada hari yang sama (satu jurnal per jam
pelajaran), sedangkan presensi harian hanya satu baris per siswa per tanggal.

Kebutuhan sekolah:

1. Presensi harian mencerminkan **seluruh jam pelajaran hari itu**, bukan hanya jurnal yang terakhir disimpan.
2. Murid yang **hadir di sekolah lalu meninggalkan jam pelajaran tanpa izin** tetap tercatat hadir hari itu,
   tetapi pelanggarannya tercatat otomatis, bukan dijadikan alfa sehari.
3. Keputusan wali kelas (presensi manual) dihormati secara konsisten, **termasuk poin pelanggarannya**.

## 2. Kondisi saat ini (1.0.2)

- `attendance_records` punya kunci unik `uniq_daily (student_id, date)`. `save_journal` melakukan upsert ke
  baris itu untuk **setiap** jurnal non-draf, dan statusnya ditimpa kecuali `source = 'MANUAL'`.
  Akibatnya, **jurnal yang terakhir disimpan menentukan status harian**, termasuk jurnal jam 1 yang diedit sore hari.

  | Jam | Status di jurnal | Status harian tersimpan |
  |---|---|---|
  | 1 | HADIR | HADIR |
  | 3 | ALFA (murid meninggalkan kelas) | **ALFA** + insiden + poin, bisa sampai panggilan orang tua |
  | 5 | IZIN | IZIN, insiden dan poin ALFA **dihapus lagi** |

- **Insiden ALFA tidak mengikuti prioritas MANUAL.** Upsert mempertahankan status MANUAL, tetapi blok insiden
  di `save_journal` tetap membuat/menghapus insiden menurut status jurnal:
  - wali kelas menyimpan ALFA (MANUAL), lalu guru jam berikutnya mengisi HADIR → status harian tetap ALFA,
    tetapi **insiden dan poinnya terhapus**;
  - wali kelas menyimpan HADIR (MANUAL), lalu jurnal mengisi ALFA → status harian tetap HADIR,
    tetapi **insiden dan poin ALFA dibuat**.
- Saat insiden ALFA dihapus, poin siswa dikurangi sebesar poin ALFA di `violation_types` **saat ini**, bukan
  poin yang dulu ditambahkan. Bila Admin mengubah poin ALFA, total poin siswa bergeser.
- `delete_journal` dan perubahan tanggal/siswa pada edit jurnal tidak memperbarui presensi harian maupun insiden.
- Tidak ada cara mencatat "hadir tetapi meninggalkan jam pelajaran". Pilihannya hanya ALFA sehari (terlalu berat)
  atau tidak tercatat sama sekali.

Di data sekolah kami (13 jurnal, 7–26 September 2026) kasus ini belum muncul karena belum ada kelas dengan
lebih dari satu jurnal per hari. Masalah ditemukan dari tinjauan kode dan dibuktikan dengan uji otomatis (§5).

## 3. Perbaikan yang diusulkan

### 3.1 Aturan status harian

Status harian **dihitung ulang dari semua jurnal non-draf** pada tanggal itu setiap kali jurnal disimpan,
diedit, atau dihapus:

| Kondisi | Status harian |
|---|---|
| Baris sudah `MANUAL` (disimpan wali kelas) | tidak diubah |
| Ada minimal satu jam HADIR | HADIR |
| Tidak ada HADIR, ada SAKIT | SAKIT |
| Tidak ada HADIR/SAKIT, ada IZIN | IZIN |
| Seluruh jam ALFA | ALFA |
| Tidak ada lagi jurnal non-draf yang mencatat siswa | baris `JURNAL` dihapus |

Bila status per jam tidak seragam, kolom `attendance_records.notes` (sudah ada di skema) diisi, misalnya
`Dari jurnal, tidak mengikuti jam 3 (Matematika): ALFA; jam 4 (Fisika): IZIN`.

### 3.2 Insiden otomatis selalu mengikuti status harian final

| Insiden | ID | Syarat | Poin |
|---|---|---|---|
| ALFA | `inc_att_<siswa>_<tanggal>` | status harian final ALFA (dari jurnal **atau MANUAL**) | poin `ALFA` di `violation_types` |
| **TINGGAL_JP** (baru) | `inc_tjp_<siswa>_<tanggal>` | status harian final HADIR (dari jurnal atau MANUAL) **dan** minimal satu jam ALFA di jurnal | poin `TINGGAL_JP` |

- Masing-masing paling banyak **satu per siswa per hari**. Rincian `TINGGAL_JP` mencantumkan semua jam yang
  ditinggalkan dan diperbarui bila jam berubah, tanpa menambah poin.
- Insiden yang syaratnya tidak lagi terpenuhi dihapus, dan poin dikembalikan sebesar **`incidents.points` insiden itu**.
- Ambang panggilan orang tua (`violation_threshold_parentcall`) berlaku sama untuk kedua insiden.
- Wali kelas yang menyimpan status MANUAL SAKIT/IZIN (misalnya murid pulang dengan izin) otomatis mencabut `TINGGAL_JP`.
- Jenis pelanggaran `TINGGAL_JP` "Meninggalkan Jam Pelajaran Tanpa Izin" (10 poin, RINGAN) dibuat otomatis
  saat pertama dibutuhkan. Poinnya dapat diubah dari menu Kelola Poin Pelanggaran.

### 3.3 Backend (lihat `backend-presensi-harian.diff`)

**File baru `api/presensi_harian.php`:**

| Fungsi | Isi |
|---|---|
| `presensiTurunkanHarian` | Fungsi murni: daftar status per jam → status harian + catatan |
| `presensiJamAlfa` | Fungsi murni: label jam yang ALFA (untuk rincian `TINGGAL_JP`) |
| `presensiPoinTinggalJp` | Poin `TINGGAL_JP`; membuat jenis pelanggaran bila belum ada |
| `presensiSinkronInsiden` / `presensiSinkronInsidenAlfa` | Samakan satu insiden otomatis harian dengan syaratnya |
| `presensiSinkronHarian` | Hitung ulang presensi harian + insiden untuk sejumlah siswa pada satu tanggal (di dalam transaksi pemanggil) |

**Perubahan:**
- `save_journal`: blok upsert presensi + insiden ALFA diganti `presensiSinkronHarian` untuk tanggal baru dan
  tanggal lama (bila jurnal diedit), untuk gabungan siswa lama dan baru.
- `delete_journal`: dibungkus transaksi dan menghitung ulang presensi harian siswa di jurnal yang dihapus.
- `save_attendance_records` (MANUAL): memakai helper insiden yang sama (status panggilan orang tua tetap
  `PARENT_INVITED`), lalu `presensiSinkronHarian` untuk menyamakan `TINGGAL_JP`.

Semua query baru memakai prepared statement. Blok lama menyusun SQL insiden dengan interpolasi string
(sebagian tanpa escape, misalnya `WHERE id = '$incId'` dengan `student_id` dari body).

## 4. Kompatibilitas

- **Tanpa perubahan skema.** Satu baris `violation_types` (`TINGGAL_JP`) ditambahkan saat runtime.
- **Tanpa perubahan frontend.** Format request/response `save_journal`, `delete_journal`, dan
  `save_attendance_records` tidak berubah.
- Rekap bulanan (`rekapIsAlphaIncident`) hanya menganggap `inc_att_*`/kode `ALFA` sebagai alfa, sehingga
  `TINGGAL_JP` tampil sebagai pelanggaran biasa dengan nama dari `violation_types`.
- **Data lama tidak dihitung ulang otomatis.** Presensi dan insiden yang sudah salah baru terkoreksi saat jurnal
  tanggal itu disimpan/dihapus lagi.
- `recorded_by`/`recorder_name` baris `JURNAL` berisi user yang terakhir memicu perhitungan ulang.

## 5. Hasil uji di sekolah (dev lokal: PHP 8.3 Apache, MySQL 5.7)

- Uji unit **14/14 lulus**: prioritas status, urutan jam tidak berpengaruh, catatan jam, normalisasi `ALPHA`,
  status asing diabaikan, label jam ALFA.
- Uji integrasi DB **31/31 lulus** (transaksi di-rollback): hadir jam 1 + alfa jam 3 → HADIR + `TINGGAL_JP`;
  satu insiden per hari dengan rincian diperbarui; hapus jurnal → ALFA + insiden ALFA menggantikan `TINGGAL_JP`;
  IZIN mencabut semua insiden; **MANUAL ALFA tidak dihapus oleh jurnal HADIR**; **MANUAL HADIR tidak memicu
  insiden ALFA**; MANUAL IZIN mencabut `TINGGAL_JP`; jurnal draf tidak dihitung; format presensi array.
- Uji API **12/12 lulus** lewat HTTP (`save_journal`, `delete_journal`, `save_attendance_records`, `get_attendance_records`, `get_incidents`).

## 6. Permintaan ke pengembang

1. Mengadopsi aturan status harian §3.1 dan insiden yang mengikuti status final §3.2 di sumber resmi. Bug
   insiden vs MANUAL (§2) berdampak langsung ke poin pelanggaran dan panggilan orang tua.
2. Menjadikan jenis pelanggaran `TINGGAL_JP` data bawaan resmi (migrasi), termasuk keputusan poin default.
3. Menyediakan alat hitung ulang presensi harian untuk rentang tanggal (misalnya di menu Admin), agar data yang
   sudah terlanjur salah dapat dikoreksi. Fungsi `presensiSinkronHarian` sudah mengembalikan daftar perubahan
   sehingga bisa dipakai untuk mode pratinjau.
4. Jangka panjang: mempertimbangkan tabel presensi per jam pelajaran, supaya rekap per mapel tidak perlu
   membaca JSON `journal_entries.studentAttendance`.

## 7. Catatan tambahan

- **Kesalahan kecil guru langsung berpoin.** Murid yang sedang ke toilet lalu ditandai Alfa langsung mendapat
  `TINGGAL_JP` sampai guru mengoreksi jurnal. Sekolah perlu menyosialisasikan hal ini ke guru.
- Murid yang mengikuti kegiatan resmi dengan surat dispensasi ditangani usulan **Dispensasi Siswa**
  (`RINGKASAN-DISPENSASI.md`), sehingga jam tersebut tidak menjadi `TINGGAL_JP`.
