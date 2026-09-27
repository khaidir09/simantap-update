# Usulan Fitur SIMANTAP: Isi Otomatis Sakit/Izin dari Jam Pelajaran Sebelumnya

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-27 |
| Lampiran | `backend-presensi-jam-sebelumnya.diff` |
| Dokumentasi patch lokal | `docs/patches/presensi-jam-sebelumnya.md` |
| Prasyarat | Usulan **Presensi Harian dari Jurnal** (`backend-presensi-harian.diff`) dan **Dispensasi Siswa** (`backend-dispensasi.diff`). Diff ini diterapkan **setelah** keduanya. |
| Terkait | Usulan **Jadwal KBM Mandiri Guru** (`RINGKASAN-JADWAL-KBM.md`): kolom "Jam Ke-" yang terisi otomatis dari jadwal membuat urutan jam jurnal lebih andal |

## 1. Kebutuhan

Contoh kasus: guru jam 1–3 menandai murid A **Izin** (misalnya pulang karena urusan keluarga). Guru jam 4–5
membuka form jurnal dan murid A kembali tampil **Hadir**. Guru tersebut harus tahu sendiri dan menandai ulang.

Kebutuhan sekolah:

1. Status Sakit/Izin yang sudah dicatat guru sebelumnya pada hari yang sama **terbawa** ke form jurnal guru berikutnya.
2. Guru tetap memegang keputusan: status yang terbawa bisa diubah bila murid ternyata masuk kelas.
3. Keputusan wali kelas di presensi harian (Sakit/Izin manual) juga terbaca oleh guru mapel.

## 2. Kondisi saat ini (1.0.2)

- Form jurnal baru mengisi **semua murid HADIR**. Form tidak membaca jurnal lain pada tanggal yang sama dan tidak
  membaca `attendance_records`.
- Dampaknya pada data: bila guru berikutnya lupa mengubah, murid tercatat HADIR di jam itu. Dengan usulan presensi
  harian, satu jam HADIR sudah cukup membuat status harian **HADIR**, sehingga murid yang izin seharian bisa
  tercatat hadir di rekap dan rapor.
- Sebaliknya, guru yang tidak tahu murid sudah izin cenderung menandai **Alfa**, padahal alasan ketidakhadirannya
  sudah diketahui sekolah.

## 3. Perbaikan yang diusulkan

### 3.1 Aturan

| Topik | Keputusan |
|---|---|
| Sumber utama | Jurnal non-draf **kelas yang sama** pada tanggal itu yang jam akhirnya **sebelum** jam awal jurnal yang sedang diisi |
| Jurnal yang dipakai | Hanya jurnal **terakhir** sebelum jam ini. Murid Izin di jam 1 lalu Hadir di jam 3 tidak diisi Izin untuk jam 5 |
| Status yang diteruskan | Hanya **SAKIT** dan **IZIN**. **ALFA tidak diteruskan**: murid bisa kembali ke kelas; bolos di tengah hari sudah ditangani insiden `TINGGAL_JP` |
| Beberapa jurnal pada jam akhir yang sama | Ada HADIR/DISPENSASI → tidak diisi; selain itu SAKIT, lalu IZIN |
| Sumber cadangan | Presensi harian `MANUAL` wali kelas berstatus SAKIT/IZIN, bila murid belum tercatat di jurnal sebelumnya |
| Dispensasi | Murid yang dispensasinya mencakup jam ini dilewati; form tetap mengisi D |
| Jam tidak terbaca | Jurnal dengan "Jam Ke-" tanpa angka tidak dipakai (tidak bisa diurutkan). Bila jam jurnal yang diisi masih kosong, hanya sumber MANUAL yang dipakai |
| Cakupan | Hanya isian awal form jurnal **baru**. Mode edit tidak berubah. Perhitungan status harian tidak berubah |

### 3.2 Backend (lihat `backend-presensi-jam-sebelumnya.diff`)

**File baru `api/presensi_jam_sebelumnya.php`**, satu action (`case` eksplisit di `api/index.php`):

| Action | Metode | Akses | Hasil |
|---|---|---|---|
| `presensi_jam_sebelumnya` | GET `class_id` (id atau nama kelas), `date`, `jam` | semua user login | `{items: {student_id: {status: SAKIT\|IZIN, sumber: JURNAL\|MANUAL, keterangan}}}` |

- `keterangan` untuk sumber JURNAL berisi label jam dan mapel, misalnya `jam 1-3 (Matematika)`; untuk MANUAL
  `presensi harian wali kelas (Nama)`.
- Memakai fungsi yang sudah ada dari usulan sebelumnya: `presensiAngkaJam`, `presensiParseJurnal`,
  `presensiDispensasiTanggal`, `presensiDispensasiMencakup`, `normalizeClassKey`.
- Aturan pemilihan status ada di fungsi murni `presensiSaranDariJamTerakhir`.
- Metode HTTP diperiksa (405 bila bukan GET). Tidak ada perubahan skema.

### 3.3 Frontend (patch bundle di sekolah kami)

Form Jurnal Mengajar, langkah presensi:

- Hook memanggil `presensi_jam_sebelumnya` saat kelas, tanggal, jam, atau daftar murid berubah, lalu mengisi
  SAKIT/IZIN hanya untuk murid yang masih HADIR bawaan dan belum diubah guru.
- Isian otomatis yang tidak berlaku lagi (guru mengubah jam/tanggal) dikembalikan ke HADIR; perubahan guru tidak ditimpa.
- Keterangan di bawah nama murid (`Izin di jam 1-3 (Matematika)`), berwarna sesuai status.
- Pemberitahuan di atas daftar: "N siswa tercatat Sakit/Izin sebelumnya hari ini dan sudah diisi otomatis.
  Ubah bila siswa ternyata hadir di jam ini."

## 4. Kompatibilitas

- Hanya membaca data; tidak ada tabel, kolom, atau migrasi baru.
- Tidak mengubah isi jurnal yang sudah ada maupun cara status harian dihitung.
- Tanpa sisi frontend, action ini tidak berpengaruh apa pun.

## 5. Hasil uji di sekolah (dev lokal: PHP 8.3 Apache, MySQL 5.7)

- Uji API **20/20 lulus**: tanpa login 401, POST 405, tanggal tidak valid; Izin jam 1-3 terbawa ke jam 4 beserta
  keterangan; jam 1 dan jam 2 (tumpang tindih) tidak diisi; jam kosong; kelas lewat nama; kelas lain kosong;
  murid Hadir kembali di jam 4 → jam 5 tidak diisi; Sakit jam 5 terbawa ke jam 6; Alfa jam 6 tidak diteruskan;
  presensi MANUAL wali kelas terbaca di jam 1 tetapi kalah oleh jurnal sebelumnya; dispensasi yang mencakup jam
  didahulukan.
- Uji UI di Chrome (akun guru): jurnal jam 1-3 mencatat murid A Izin → form jurnal baru jam 4-5 menampilkan A = I
  dengan keterangan dan pemberitahuan, ringkasan Hadir 1 / Izin 1; jam diubah ke 2 → A kembali H.

## 6. Permintaan ke pengembang

1. Menyediakan isian otomatis ini di sumber resmi dengan aturan §3.1 atau setara.
2. Mempertimbangkan kolom jam terstruktur (angka jam mulai/selesai) di `journal_entries` agar urutan jam tidak
   bergantung pada teks bebas "Jam Ke-".
3. Mempertimbangkan tampilan ringkas presensi per jam untuk satu kelas per hari (matriks murid × jam) bagi wali
   kelas, memakai data yang sama.

## 7. Catatan tambahan

- **`presensi_jam_sebelumnya` terbuka untuk semua user login**, karena guru mapel mana pun perlu melihatnya saat
  mengisi jurnal (sama seperti `dispensasi_jurnal`). Data yang dikembalikan hanya id murid, status Sakit/Izin,
  dan label jam/mapel atau nama pencatat.
- **Jurnal yang diisi terlambat.** Guru jam 1 yang mengisi jurnal setelah guru jam 4 tidak mendapat isian dari
  jam 4, karena hanya jam sebelumnya yang dipakai. Ini disengaja: status di jam yang lebih akhir belum tentu
  berlaku untuk jam yang lebih awal.
