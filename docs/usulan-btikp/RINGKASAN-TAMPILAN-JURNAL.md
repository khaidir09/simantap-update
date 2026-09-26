# Usulan Perbaikan SIMANTAP: Tampilan Halaman Jurnal Mengajar

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-26 |
| Lampiran | Tidak ada. Perubahan hanya di frontend dan tidak ada diff backend. |
| Terkait | Melanjutkan usulan "Hasil Cetak Rekap Jurnal Mengajar Guru" (`RINGKASAN-CETAK-REKAP-JURNAL.md`), yang sudah menghapus badge kategori dari cetak rekap. |

## 1. Kebutuhan

Lima hal pada halaman **Jurnal Mengajar** mengganggu keterbacaan atau menampilkan informasi yang keliru, baik di layar maupun di hasil cetak:

1. **Badge kategori di setiap kartu jurnal.** Hampir semua jurnal berisi nilai yang sama, jadi badge ini tidak membantu membedakan satu jurnal dengan yang lain. Badge ini hanya menambah keramaian.
2. **Daftar "Siswa Tidak Hadir" di modal Detail Jurnal Mengajar sulit dibaca.** Setiap item berbentuk `NISN - Nama` dan dipotong dengan elipsis. Akibatnya, nama siswa sering tidak terbaca utuh, padahal yang dibutuhkan guru dan kepala sekolah cukup nama dan keterangannya.
3. **Hasil cetak/PDF jurnal yang memiliki penilaian.** Bagian **IV. Hasil Evaluasi / Penilaian Masukan** menempel langsung di bawah tabel bagian **III. Laporan Kehadiran Siswa**, tanpa jarak.
4. **Waktu jurnal salah informasi.** Kartu dan tabel jurnal menampilkan jam saat guru mengisi jurnal (misalnya `23:28`), bukan jam pelajaran yang diajarkan. Badge "Pukul" di modal detail juga jatuh ke jam input ini.
5. **NISN pada "Hasil Penilaian Langsung".** Kartu penilaian di modal detail menampilkan `NISN - Nama`, tidak seragam dengan daftar siswa tidak hadir.

## 2. Kondisi saat ini (1.0.2)

**Badge kategori.** Kartu jurnal selalu merender `kategori || "Jurnal Utama"`, dan tampilan tabel merender `kategori` di bawah nama guru. `get_journals` memberi default `"Rencana Pembelajaran"` bila kolom kosong, sehingga badge hampir selalu berisi teks yang sama.

**Siswa tidak hadir.** Label dibentuk sebagai `${nisn} - ${name}` dan ditaruh di `<span class="truncate">`. Daftar ini berada di grid tiga kolom (`lg:grid-cols-3`), sehingga setiap kolom sempit dan nama terpotong pada panjang yang wajar sekalipun.

**Jarak bagian III dan IV.** Kedua bagian dibungkus `grid ... gap-6`. Mesin cetak bersama (clone + `window.print()`) memaksa `[class*="grid"] { display: block !important }` agar tabel panjang bisa mengalir antar halaman. Aturan itu juga dijelaskan di usulan cetak rekap. Karena gridnya berubah menjadi `display: block`, `gap-6` tidak berlaku lagi dan bagian IV menempel. Masalah yang sama pernah terjadi pada butir-butir bagian I dan sudah kami tangani dengan cara serupa.

**Waktu jurnal.** Field `time` dipetakan `get_journals` dari kolom `period`. `save_journal` mengisi `period` dari `time`/`waktu` pada body, dan frontend mengirim jam saat formulir diisi. Jam pertemuan yang sebenarnya ada di `jam_ke`, `jam_mulai`, dan `jam_selesai`, dan ketiganya sudah dikirim sebagai `jam`, `jamMulai`, dan `jamSelesai`. Contoh data nyata di sekolah kami: `period = 23:28`, `jam_ke = 7-11`, `jam_mulai = 12:30`, `jam_selesai = 16:30`. Pada data lokal kami, ketiga kolom jam pelajaran terisi di semua jurnal.

**Penilaian langsung.** Label nama dibentuk `${nisn} - ${student_name}`, sama seperti daftar siswa tidak hadir.

## 3. Perbaikan yang diusulkan (frontend)

1. **Kartu jurnal:** badge kategori dihapus. Baris badge hanya dirender bila jurnal memiliki penilaian (badge **Penilaian** tetap ada), sehingga tidak tersisa baris kosong pada kartu tanpa penilaian.
2. **Tabel jurnal:** badge kategori di bawah nama guru juga dihapus supaya kedua tampilan konsisten. Data `kategori` tetap ada di database dan ekspor CSV, jadi tidak ada data yang hilang.
3. **Modal detail, "Siswa Tidak Hadir":**
   - label cukup berisi nama siswa, tanpa NISN;
   - class `truncate` dihapus sehingga nama panjang turun ke baris berikutnya;
   - nama dan badge keterangan (SAKIT/IZIN/ALFA) diberi jarak `gap-3` agar tidak berimpitan saat nama membungkus.
4. **Cetak/PDF:** pembungkus bagian III dan IV diberi class `smt-jurnal-sec34` dengan aturan `.smt-jurnal-sec34 > * + * { margin-top: 24px }`. Aturan ini ditaruh di `<style>` di dalam area cetak, jadi ikut tersalin oleh mesin cetak. Margin tetap berlaku walau grid dipaksa menjadi block. Di layar, `margin-top` ini menambah jarak antar-baris grid. Pada modal detail, area cetak disembunyikan (`hidden print:block`), jadi tidak ada perubahan yang terlihat di layar.
5. **Waktu jurnal memakai jam pelajaran.** Sebuah helper membentuk teks `Jam ke-7-11 (12:30 - 16:30)` dari `jam`, `jamMulai`, dan `jamSelesai`, dengan detik dipangkas. Bila hanya salah satu yang ada, bagian itu saja yang tampil. Helper ini dipakai di:
   - kartu dan tabel jurnal (bila kosong tampil `Waktu tidak diatur`);
   - kotak **Waktu** pada modal detail dan cetakan (bila kosong tampil `-`, tidak lagi jatuh ke jam input);
   - badge **Pukul** pada modal detail, yang kini hanya muncul bila jam mulai dan selesai terisi.
6. **Hasil Penilaian Langsung:** nama siswa tanpa NISN, seragam dengan daftar siswa tidak hadir.

## 4. Kompatibilitas

- Tidak ada perubahan backend, skema, atau payload API.
- Perubahan yang terlihat pengguna:
  - badge kategori tidak lagi tampil;
  - NISN tidak lagi muncul pada daftar siswa tidak hadir dan hasil penilaian langsung di modal detail;
  - cetakan jurnal berpenilaian mendapat jarak antar-bagian;
  - waktu pada kartu, tabel, modal, dan cetakan berubah arti dari jam input menjadi jam pelajaran.
- **Perlu diperiksa sebelum rilis:** jurnal lama yang `jam_ke`, `jam_mulai`, dan `jam_selesai`-nya kosong akan menampilkan `Waktu tidak diatur` atau `-`. Proporsinya bisa dicek dengan
  `SELECT COUNT(*) FROM journal_entries WHERE (jam_ke IS NULL OR jam_ke = '') AND (jam_mulai IS NULL OR jam_mulai = '');`
  Kami sengaja tidak jatuh ke `period`, karena jam input bukan jam pertemuan dan akan menyesatkan pada dokumen bertanda tangan.

## 5. Hasil uji di sekolah (dev lokal)

- Di sekolah kami, perubahan ini sementara dipasang sebagai patch string pada bundle hasil build (`docs/patches/apply-tampilan-jurnal-bundle.js`). Kedua belas anchor ditemukan tepat satu kali, dan class Tailwind yang dipakai (`min-w-0`, `gap-3`) sudah divalidasi ada di CSS bundle.
- Bundle hasil patch diparse penuh sebagai ES module lewat `import()` di Node tanpa kesalahan sintaks.
- **Belum diuji:** render di browser dan hasil dialog Print/Save as PDF. Keduanya perlu dicek manual sebelum rilis.

## 6. Permintaan ke pengembang

1. Menghapus badge kategori dari kartu dan tabel jurnal di sumber React, atau mempertimbangkan menghapus field `kategori` sepenuhnya jika memang tidak dipakai lagi.
2. Menampilkan nama siswa secara utuh, tanpa NISN, pada daftar siswa tidak hadir dan hasil penilaian langsung di modal Detail Jurnal Mengajar.
3. Memberi jarak antar-bagian pada cetak detail jurnal yang tetap berlaku ketika mesin cetak memaksa grid menjadi block. Secara umum, sebaiknya jarak antar-bagian cetak memakai margin, bukan `gap` atau `space-*` di dalam grid, karena aturan `display: block !important` membatalkan `gap`.
4. Memakai satu helper bersama untuk menampilkan waktu jurnal dari jam pelajaran di semua tempat, dan berhenti menampilkan `period` sebagai jam pertemuan. Perlu diputuskan juga apakah `period` masih dibutuhkan. Bila hanya berfungsi sebagai jam input, `createdAt` sudah mencatat hal yang sama.

## 7. Catatan tambahan

- **Ekspor CSV rekap bulanan** masih mengisi kolom "Waktu" dari `time` (jam input). Kami belum mengubahnya karena menyangkut format berkas yang mungkin sudah diolah pihak lain, tetapi idealnya ikut memakai jam pelajaran.
