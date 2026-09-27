# Usulan Fitur SIMANTAP: Dispensasi Siswa

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-27 |
| Lampiran | `backend-dispensasi.diff` |
| Dokumentasi patch lokal | `docs/patches/dispensasi-siswa.md` |
| Prasyarat | Usulan **Presensi Harian dari Jurnal** (`RINGKASAN-PRESENSI-HARIAN.md`, `backend-presensi-harian.diff`). Diff ini diterapkan **setelah** diff presensi harian. |
| Terkait | Usulan **Jadwal KBM Mandiri Guru** (`RINGKASAN-JADWAL-KBM.md`): kolom "Jam Ke-" jurnal yang terisi otomatis dari jadwal membuat pencocokan jam dispensasi lebih andal |

## 1. Kebutuhan

Murid sering meninggalkan kelas untuk kegiatan yang **ditugaskan atau diakui sekolah** (lomba, paskibra,
delegasi, tugas OSIS) dengan surat rekomendasi atau izin dispensasi. Contoh: murid A hadir jam 1, lalu sejak
jam 3 berangkat mengikuti lomba.

Secara administrasi murid tersebut **tidak absen**: ia menjalankan tugas sekolah. Kebutuhan sekolah:

1. Wali kelas, BK, atau TU mencatat dispensasi **sekali**, lengkap dengan surat, untuk satu atau banyak murid,
   satu hari atau beberapa hari, sehari penuh atau jam tertentu.
2. Guru mapel yang mengisi jurnal **langsung tahu** murid mana yang sedang dispensasi, tanpa harus bertanya.
3. Jam dispensasi **tidak menjadi alfa atau pelanggaran**, dan murid tetap terhitung hadir di presensi harian/rapor.
4. Dispensasi yang baru diinput **setelah** guru terlanjur menandai alfa tetap berlaku (surat sering datang belakangan).

## 2. Kondisi saat ini (1.0.2)

- Status presensi hanya `HADIR/SAKIT/IZIN/ALFA`: enum `attendance_records.status`, `normalizeAttendanceStatus`,
  dan tombol H/S/I/A di form jurnal. Tidak ada status dispensasi.
- Guru jam berikutnya terpaksa memilih IZIN atau ALFA. ALFA memicu insiden, poin pelanggaran, dan bisa sampai
  panggilan orang tua. Dengan usulan presensi harian, ALFA setelah hadir memicu `TINGGAL_JP`.
- Tidak ada tempat menyimpan surat dispensasi, dan tidak ada data yang menghubungkan surat dengan jam pelajaran.

## 3. Perbaikan yang diusulkan

### 3.1 Keputusan desain

| Topik | Keputusan |
|---|---|
| Penginput | ADMIN, KEPALA_SEKOLAH, TATA_USAHA, Guru BK (semua kelas); wali kelas (kelas perwaliannya). Langsung berlaku tanpa alur persetujuan |
| Cakupan waktu | Rentang tanggal (maks 31 hari); per hari **sehari penuh** atau **jam ke-X s.d. Y** |
| Satu input, banyak murid | Satu baris per murid, surat yang sama dipakai bersama |
| Form jurnal | Tombol **D** baru; murid yang dicakup otomatis D + keterangan kegiatan; **guru tetap bisa mengubah** (misalnya murid kembali ke kelas) |
| Dispensasi susulan | Jam yang terlanjur ALFA di jurnal dan dicakup dispensasi dihitung DISPENSASI; insiden ALFA/`TINGGAL_JP` dicabut. **Isi jurnal guru tidak diubah** |
| Presensi harian | Jam DISPENSASI dihitung hadir: status harian HADIR, `notes` mencatat kegiatannya. Enum `attendance_records.status` tidak berubah |
| Pencocokan jam | Angka di kolom "Jam Ke-" jurnal: `1-2`, `3`, `1, 3`, `3 s.d. 5`, `Jam ke 7 - 8`. Jurnal tanpa angka jam hanya dicakup dispensasi sehari penuh |

### 3.2 Backend (lihat `backend-dispensasi.diff`)

**Tabel baru `student_dispensations`** (dibuat `CREATE TABLE IF NOT EXISTS` oleh handler dispensasi di luar
transaksi; kami usulkan menjadi migrasi resmi, DDL di `dispensasiPastikanTabel`):
`id`, `student_id`, `student_name`, `class_id`, `class_name`, `tanggal_mulai`, `tanggal_selesai`,
`jam_ke_mulai`/`jam_ke_selesai` (NULL = sehari penuh), `kegiatan`, `nomor_surat`, `surat_path`,
`dicatat_oleh`, `dicatat_oleh_nama`, `created_at`, dengan indeks per siswa+tanggal dan per tanggal.

**File baru `api/dispensasi.php`**, enam action (`case` eksplisit di `api/index.php`):

| Action | Metode | Akses | Isi |
|---|---|---|---|
| `dispensasi_list` | GET `dari`, `sampai`, `class_id?` | cakupan dispensasi | Dispensasi yang beririsan dengan rentang + mode cakupan |
| `dispensasi_siswa` | GET `class_id` | cakupan dispensasi | Siswa aktif satu kelas |
| `dispensasi_simpan` | POST JSON | cakupan dispensasi | Validasi, satu baris per siswa, lalu **presensi harian dihitung ulang untuk setiap tanggal** dalam satu transaksi |
| `dispensasi_hapus` | POST JSON `{id}` | cakupan dispensasi | Hapus + hitung ulang; berkas surat dihapus bila tidak dipakai dispensasi lain |
| `dispensasi_unggah_surat` | POST multipart `file` | cakupan dispensasi | Simpan surat, balas `path` |
| `dispensasi_jurnal` | GET `class_id`, `date`, `jam` | semua user login | Murid yang dicakup pada jam jurnal itu: `{student_id: {kegiatan, cakupan}}` (data minimal untuk form jurnal) |

Cakupan dispensasi (`dispensasiScope`) membaca ulang user dari DB (`loadFreshUser`), sehingga perubahan role
atau wali kelas langsung berlaku. Semua action **memeriksa metode HTTP** (405 bila salah).

**Keamanan unggahan surat:**
- ekstensi ditentukan dari **isi berkas** (`finfo`: PDF/JPEG/PNG), bukan dari nama berkas klien;
- nama berkas acak `bin2hex(random_bytes(16))`, folder `uploads/tu/dispensasi/<yyyymm>/` dengan `index.html`;
- maks 5 MB; `surat_path` pada simpan hanya diterima bila cocok pola tersebut dan berkasnya ada;
- penghapusan berkas hanya untuk path yang cocok pola yang sama.

**Perubahan `api/presensi_harian.php`:**
- status per jam `DISPENSASI` (juga `D`) dengan parser sendiri `presensiParseJurnal`. `pantauNilaiAttendanceStatus`
  tidak diubah karena dipakai penghitung rekap H/S/I/A di modul nilai;
- `presensiAngkaJam` (angka jam dari teks "Jam Ke-"), `presensiDispensasiMencakup`, `presensiDispensasiTanggal`
  (membaca tabel dispensasi; tanpa DDL karena bisa berjalan di dalam transaksi `save_journal`);
- saat menghitung ulang, jam ALFA yang dicakup dispensasi dianggap DISPENSASI, dan DISPENSASI dihitung hadir.

### 3.3 Frontend (patch bundle di sekolah kami)

- Menu **"Dispensasi Siswa"**: grup Kesiswaan (wali kelas, Kepala Sekolah, Admin, TU) dan grup Bimbingan &
  Konseling (Guru BK).
- Halaman kelola: filter tanggal/kelas, daftar (tanggal, siswa, kelas, cakupan, kegiatan, nomor dan tautan surat,
  pencatat, tombol hapus), form tambah (pilih banyak siswa dengan pencarian, rentang tanggal, sehari penuh/jam ke-,
  kegiatan, nomor surat, unggah surat).
- Form Jurnal Mengajar langkah presensi: tombol **D**, keterangan "Dispensasi: kegiatan (cakupan)" di bawah nama
  murid, dan kotak ringkasan Dispensasi. Status D otomatis hanya pada jurnal **baru** dan hanya untuk murid yang
  masih HADIR bawaan; perubahan oleh guru tidak ditimpa.

## 4. Kompatibilitas

- Satu tabel baru, dibuat saat runtime; rollback kode meninggalkan tabel yang tidak berbahaya.
- Enum `attendance_records.status` tidak berubah. `DISPENSASI` hanya muncul di JSON `journal_entries.studentAttendance`.
- Pembaca JSON jurnal lain belum mengenal `DISPENSASI`: detail jurnal dan cetak laporan jurnal tidak
  menghitungnya secara khusus, pantau nilai per pertemuan menampilkan "-", dan `attendanceRate` jurnal tetap
  dihitung frontend dari jumlah HADIR.
- Tanpa ekstensi PHP tambahan selain `fileinfo` (bawaan PHP).

## 5. Hasil uji di sekolah (dev lokal: PHP 8.3 Apache, MySQL 5.7)

- Uji unit **44/44 lulus** (termasuk 14 uji presensi harian): status D, catatan kegiatan, angka jam dari berbagai
  format "Jam Ke-", pencocokan cakupan, validasi simpan (tanggal tidak ada, rentang > 31 hari, jam terbalik,
  path surat asing), label cakupan, rentang tanggal.
- Uji integrasi DB **39/39 lulus** (di-rollback): dispensasi susulan jam 3–8 mencabut `TINGGAL_JP`; jam di luar
  cakupan tetap `TINGGAL_JP`; semua jam dispensasi → HADIR tanpa insiden; dispensasi dihapus → ALFA kembali;
  dispensasi sehari penuh mencakup jurnal tanpa angka jam; status D dari guru dihitung hadir.
- Uji API **33/33 lulus**: matriks akses (guru biasa 403, wali hanya kelasnya, TU/BK semua kelas), GET ke aksi
  POST 405, validasi, unggah PDF bernama `.php` disimpan sebagai `.pdf`, **isi PHP berlabel PNG ditolak**,
  dispensasi susulan lewat HTTP, pencarian untuk form jurnal, jurnal berstatus D, hapus + berkas surat yatim ikut
  terhapus, banyak siswa + rentang tanggal. Regresi uji API presensi harian tetap **12/12**.
- Uji UI di Chrome (akun wali kelas): menu, halaman kelola, simpan dispensasi, D otomatis + keterangan di form
  jurnal, ubah manual D↔H, dan kotak ringkasan berjalan. Pengiriman jurnal lewat tombol di browser tidak sempat
  diuji (tab tertahan dialog cetak), tetapi penyimpanan jurnal berstatus D sudah lulus uji API.

## 6. Permintaan ke pengembang

1. Menyediakan pencatatan dispensasi di sumber resmi dengan desain §3.1–3.2 atau setara, dan menjadikan
   `student_dispensations` migrasi resmi.
2. Menetapkan cara dispensasi dilaporkan: kami mengusulkan dihitung **hadir** di rekap dan rapor (kategori
   S/I/A tidak berubah), dengan jumlah dispensasi sebagai informasi tambahan di rekap wali kelas.
3. Menampilkan status D di detail jurnal, cetak laporan jurnal, dan pantau nilai per pertemuan.
4. Menambahkan fitur ubah dispensasi (saat ini hanya hapus lalu input ulang).
5. Meninjau keamanan unggahan secara umum. Unggahan dispensasi sudah memvalidasi isi berkas, tetapi bundle
   belum punya `.htaccess` yang mencegah eksekusi PHP di `uploads/`, dan unggahan lain (`tu_file_upload`) masih
   memakai ekstensi dari klien.

## 7. Catatan tambahan

- **Kualitas kolom "Jam Ke-".** Pencocokan jam memakai teks bebas "Jam Ke-" di jurnal. Salah ketik jam membuat
  dispensasi jam tertentu tidak cocok (dispensasi sehari penuh tidak terpengaruh). Usulan Jadwal KBM Mandiri
  Guru mengisi kolom ini otomatis dari jadwal sehingga masalah ini berkurang.
- **Dispensasi tanpa jurnal.** Bila hari itu tidak ada jurnal sama sekali untuk murid tersebut, dispensasi tidak
  membuat baris presensi harian (presensi harian tetap berasal dari jurnal atau input wali kelas).
- **`dispensasi_jurnal` terbuka untuk semua user login**, karena guru mapel mana pun perlu melihatnya saat mengisi
  jurnal. Data yang dikembalikan hanya id siswa, nama kegiatan, dan cakupan jam.
