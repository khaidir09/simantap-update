# Usulan Fitur SIMANTAP: Tanggal Belum Ada Presensi untuk Wali Kelas dan Pengaturan Hari Sekolah (5/6 Hari)

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-11-04 |
| Lampiran | `backend-tanggal-belum-presensi.diff` |
| Dokumentasi patch lokal | `docs/patches/tanggal-belum-presensi.md` |
| Patch bundle lokal | `docs/patches/apply-tanggal-belum-presensi-bundle.js`, `docs/patches/apply-hari-sekolah-bundle.js` |
| Prasyarat | Diff dibuat di atas usulan-usulan sekolah sebelumnya: **Kesiswaan Wali Kelas** (`resolveClassRef`, `normalizeClassKey`, `activeAcademicPeriod`), **Rekap Bulanan** (`api/rekap_bulanan.php`), **Jenjang Sekolah** dan **Mode Verifikasi Jurnal** (blok `get_config`/`get_system_config`/`save_system_config` yang menjadi konteks diff). Tanpa usulan itu, beberapa hunk perlu disesuaikan manual. Logikanya sendiri tidak bergantung pada fitur tersebut. |

## 1. Kebutuhan

1. Wali kelas perlu tahu **tanggal mana yang belum ada presensi** untuk kelasnya. Saat ini Dashboard Kelas hanya
   menampilkan jumlah Hadir/Sakit/Izin/Alfa per bulan. Hari yang terlewat (guru tidak mengisi jurnal, presensi
   manual lupa diisi) tidak terlihat, sehingga baru ketahuan saat rekap atau rapor.
2. Sekolah ada yang berjalan **5 hari (Senin–Jumat)** dan ada yang **6 hari (Senin–Sabtu)**. Tanpa pengaturan ini,
   Sabtu di sekolah 5 hari selalu muncul sebagai "belum presensi" dan informasinya menjadi tidak jelas.

## 2. Kondisi saat ini (1.0.2)

- `get_homeroom_dashboard` hanya mengembalikan rekap jumlah (per siswa, per bulan, hari ini). Tidak ada daftar
  tanggal kosong.
- Tidak ada pengaturan hari sekolah. Usulan Rekap Bulanan yang sudah kami kirim memakai Senin–Sabtu secara hardcode
  untuk catatan "Tanggal belum ada presensi".

## 3. Perbaikan yang diusulkan

### 3.1 Pengaturan hari sekolah

| Topik | Keputusan |
|---|---|
| Penyimpanan | Key baru `system_config.school_days_per_week`: `'5'` = Senin–Jumat, `'6'` = Senin–Sabtu. Tidak ada DDL |
| Default | Key belum ada atau tidak valid → **6**, sama dengan perilaku lama |
| Ubah | Hanya ADMIN, lewat `save_system_config` yang sudah ada. Nilai selain 5/6 → 400. `save_config` menolak key ini agar validasi tidak terlewati |
| Baca | `get_system_config` selalu mengirim nilai efektif (deskripsi "Bawaan" bila belum diatur); `get_config` menambah `school_days_per_week` |
| Dipakai oleh | Panel tanggal kosong di Dashboard Kelas dan catatan "Tanggal belum ada presensi" di Cetak Rekap Bulanan |

### 3.2 Aturan tanggal belum ada presensi

| Topik | Keputusan |
|---|---|
| Rentang | Semester yang dipilih di Dashboard Kelas: GANJIL 1 Juli – 31 Desember, GENAP 1 Januari – 30 Juni (dari tahun ajaran aktif), sampai hari ini |
| Titik mulai | Tanggal presensi **pertama di sekolah** pada semester itu, agar libur awal semester (Juli) tidak ikut dihitung. Belum ada presensi sama sekali → panel tidak tampil |
| Hari dicek | Hari sekolah sesuai §3.1. Minggu selalu dilewati |
| "Ada presensi" | Minimal satu baris `attendance_records` untuk kelas itu pada tanggal tersebut, baik dari jurnal maupun presensi manual wali kelas. Kelas dicocokkan lewat `class_id` atau `class_name` (kunci dinormalisasi) |
| Kemungkinan libur | Tanggal yang **tidak punya presensi di seluruh kelas** dipisahkan ke `kemungkinan_libur` (libur nasional, kegiatan sekolah) dan tidak dihitung sebagai kekurangan kelas |
| Hari ini | Ikut dicek dan ditandai "(hari ini)", karena jurnal hari ini bisa belum diisi |

### 3.3 Backend (lihat `backend-tanggal-belum-presensi.diff`)

**File baru `api/presensi_kosong.php`.** File ini hanya berisi helper dan tidak menambah action:

| Fungsi | Isi |
|---|---|
| `pkNormalisasiHari`, `pkHariSekolah`, `pkHariMasuk`, `pkLabelHari` | Pengaturan hari sekolah (§3.1) |
| `pkRentangSemester` | Rentang tanggal semester dari teks tahun ajaran `2026/2027` |
| `presensiTanggalKosong` | Aturan §3.2. Tiga query: tanggal presensi pertama di sekolah, tanggal ber-presensi di sekolah, dan tanggal ber-presensi kelas ini |

Perubahan pada file yang ada:

| File | Perubahan |
|---|---|
| `api/index.php` | `require_once` helper; `get_system_config` mengirim nilai efektif; `save_system_config` memvalidasi `school_days_per_week` dan mengisi deskripsinya |
| `api/endpoints_v2.php` | `get_config` menambah `school_days_per_week`; `save_config` menolak key itu |
| `api/endpoints_v3.php` | `get_homeroom_dashboard` menambah field `presensi_kosong` |
| `api/rekap_bulanan.php` | Catatan "Tanggal belum ada presensi" memakai hari sekolah dan labelnya ("Senin–Jumat"/"Senin–Sabtu") |

Bentuk field baru di `get_homeroom_dashboard`:

```json
"presensi_kosong": {
  "mulai": "2026-09-01", "sampai": "2026-11-04", "hari_ini": "2026-11-04",
  "hari_sekolah": 6, "hari_dicek": 56,
  "tanggal": ["2026-09-23", "2026-09-24"],
  "kemungkinan_libur": ["2026-09-05", "..."]
}
```

### 3.4 Frontend (patch bundle di sekolah kami)

- **Dashboard Kelas:** panel baru di bawah kartu Hadir/Sakit/Izin/Alfa, di atas tab.
  - Bila ada tanggal kosong: panel kuning "N Tanggal Belum Ada Presensi" dengan chip tanggal ("Rab, 23 Sep").
  - Bila tidak ada: panel hijau "Presensi Kelas Lengkap".
  - Panel menyebut rentang dan aturan yang dipakai ("Dicek Senin–Jumat (sekolah 5 hari), 1 Sep s.d. 4 Nov"), menjelaskan bahwa presensi terisi dari jurnal guru atau Presensi Kelas, dan memberi catatan kecil daftar tanggal kemungkinan libur.
- **Pengaturan → Konfigurasi (ADMIN):** field **Hari Sekolah** berupa pilihan "6 hari — Senin s.d. Sabtu" / "5 hari — Senin s.d. Jumat". Nilainya tersimpan lewat tombol simpan konfigurasi yang sama.

## 4. Kompatibilitas

- Tidak ada tabel, kolom, atau migrasi baru. Respons API hanya bertambah key.
- Sekolah yang tidak mengubah pengaturan tetap memakai Senin–Sabtu, sama seperti sebelumnya.
- `save_system_config` tanpa `school_days_per_week` berperilaku sama seperti sebelumnya.
- Tanpa sisi frontend, field `presensi_kosong` tidak berpengaruh apa pun.
- Biaya: tiga query ringan per pemanggilan dashboard (4–13 ms per kelas di data uji kami).

## 5. Hasil uji di sekolah (dev lokal, 2026-11-04)

- `php -l` untuk semua file yang berubah dan `node --check` untuk bundle hasil patch: lolos.
- `presensiTanggalKosong` untuk kelas XII_TKJ_2, 2026/2027 GANJIL:

  | Pengaturan | Hari dicek | Belum presensi | Kemungkinan libur | Sabtu ikut tercantum |
  |---|---|---|---|---|
  | belum diatur / `6` / nilai tidak valid | 56 | 3 | 36 | 8 |
  | `5` | 47 | 3 | 28 | 0 |

- Semester yang belum berjalan dan tahun ajaran yang tidak valid → `mulai = null` (panel tidak tampil).
- Normalisasi pengaturan: `"5"`→5, `" 6 "`→6, `"7"`/`"abc"`/`""`→tidak valid.
- HTTP:
  - `get_system_config` → `{"value":"6","description":"Bawaan"}`;
  - `save_system_config` tanpa login → 401;
  - `get_homeroom_dashboard` → `presensi_kosong` terisi.
- Tampilan di browser dan simpan pengaturan lewat UI sebagai ADMIN **belum** diuji pada saat dokumen ini dibuat.

## 6. Permintaan ke pengembang

1. Menyediakan daftar tanggal belum presensi di Dashboard Kelas dengan aturan §3.2 atau setara.
2. Menyediakan pengaturan hari sekolah 5/6 hari (§3.1) sebagai pengaturan resmi. Pengaturan ini juga relevan untuk
   fitur lain yang menghitung hari efektif, misalnya rekap bulanan dan persentase kehadiran.
3. Mempertimbangkan **kalender akademik / daftar hari libur** resmi, agar `kemungkinan_libur` tidak lagi
   bergantung pada tebakan "tidak ada presensi di seluruh kelas".

## 7. Catatan tambahan

- **`get_homeroom_dashboard` (1.0.2) tidak memanggil `requireAuth()`.** Rekap presensi per siswa (nama, pas foto)
  bisa dibaca tanpa login. Usulan ini tidak mengubah perilaku tersebut, dan field barunya hanya berisi tanggal.
  Kami menyarankan menambahkan `requireAuth()` beserta pembatasan ke kelas perwalian.
- **Keterbatasan heuristik libur:** bila seluruh sekolah lupa mengisi presensi di hari yang sama, tanggal itu
  dianggap kemungkinan libur, bukan kekurangan kelas.
