# Usulan Perbaikan SIMANTAP: Persetujuan Massal Jurnal Bulanan oleh Kepala Sekolah

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-24 |
| Lampiran | `backend-persetujuan-jurnal.diff` |
| Spesifikasi lengkap | `docs/specs/persetujuan-jurnal-bulanan.md` |

## 1. Kebutuhan

Kepala sekolah wajib memverifikasi jurnal mengajar guru, tetapi aplikasi hanya menyediakan
verifikasi **satu jurnal per klik**. Dengan puluhan guru dan beberapa jurnal per hari, jumlahnya
ratusan hingga ribuan per bulan. Akibatnya:

1. beban klik kepala sekolah tidak realistis;
2. jurnal tidak sempat terverifikasi sebelum rekap/cetak bulanan, sehingga laporan bulanan
   menampilkan banyak "Pending".

Kebutuhan sekolah: kepala sekolah bisa **menyetujui sekaligus semua jurnal yang sudah dikirim guru
pada satu bulan**, umumnya di akhir bulan berjalan, tanpa menghilangkan verifikasi satu per satu.

## 2. Kondisi saat ini (1.0.2)

- `verify_journal` hanya menerima satu `id`, tidak mengecek status draf, dan tidak mengecek
  keberadaan jurnal (id salah tetap `success`).
- `save_journal` menerima `isVerified`, `verifiedBy`, dan `verifiedAt` dari body:
  - guru dapat memverifikasi jurnalnya sendiri lewat API;
  - frontend selalu mengirim `isVerified: false` saat submit **maupun edit**, sehingga mengedit
    jurnal terverifikasi (termasuk oleh Admin) diam-diam menghapus status verifikasinya.
- `save_journal` (lewat `REPLACE INTO` berdasarkan `id`) dan `delete_journal` tidak mengecek
  kepemilikan: guru dapat mengubah atau menghapus jurnal guru lain lewat API.
- Tidak ada jejak siapa menyetujui apa selain `verifiedBy`/`verifiedAt` per jurnal.

## 3. Perbaikan yang diusulkan

### 3.1 Backend (lihat `backend-persetujuan-jurnal.diff`)

**Dua action baru**, keduanya untuk `KEPALA_SEKOLAH` dan `ADMIN`:

- `GET preview_journal_month_approval&month=YYYY-MM` (read-only): daftar id jurnal yang layak,
  rincian per guru, serta jumlah draf, jurnal bertanggal setelah hari ini, dan yang sudah
  terverifikasi.
- `POST approve_journals_month {month, ids}`: menyetujui id dari preview yang **masih** memenuhi
  kriteria, dalam satu transaksi dengan `SELECT … FOR UPDATE`, lalu mencatat satu baris log batch.

**Aturan kelayakan:** `isDraft = 0`, `isVerified = 0`, tanggal antara awal bulan dan *cutoff*.
*Cutoff* = hari ini untuk bulan berjalan, akhir bulan untuk bulan lampau. Bulan depan ditolak.

Klien mengirim daftar id dari preview, bukan hanya bulan. Dengan begitu yang disetujui persis yang
dilihat kepala sekolah: jurnal yang masuk setelah preview tetap pending, dan id yang statusnya
berubah dilaporkan sebagai `skipped`.

**Tabel baru** `journal_approval_batches` (id, month, cutoff_date, approved_by, approved_by_name,
approved_at, journal_count, journal_ids JSON). Di sekolah kami tabel ini dibuat dengan
`CREATE TABLE IF NOT EXISTS` di handler; kami usulkan menjadi migrasi resmi. DDL ada di diff
(`persetujuanJurnalPastikanTabel`).

**Pengetatan terkait:**

| # | Action | Perubahan |
|---|---|---|
| S1 | `save_journal` | Status verifikasi tidak diambil dari body. Jurnal baru selalu belum terverifikasi; edit mempertahankan status lama. |
| S2 | `save_journal` | Selain Admin/Kepala Sekolah tidak bisa mengedit jurnal terverifikasi (403). |
| S3 | `delete_journal` | Selain Admin/Kepala Sekolah tidak bisa menghapus jurnal terverifikasi (403). |
| S4 | `verify_journal` | 404 untuk id tidak ada, 400 untuk draf, idempoten untuk jurnal terverifikasi. |
| S5/S6 | `save_journal`, `delete_journal` | Selain Admin/Kepala Sekolah hanya bisa mengubah/menghapus jurnal milik sendiri (403). |

Semua pengecekan dilakukan **sebelum** transaksi `save_journal`, sehingga permintaan yang ditolak
tidak ikut menulis presensi, insiden ALFA, poin pelanggaran, atau panggilan orang tua.

### 3.2 Frontend (halaman Jurnal Mengajar)

- Tombol **"Setujui Jurnal Bulanan"** di samping "Laporan Bulanan", hanya untuk Kepala Sekolah/Admin.
- Modal konfirmasi: pemilih bulan dan tahun (default mengikuti filter halaman, bulan depan tidak
  bisa dipilih), jumlah yang akan disetujui beserta periode, jumlah yang dikecualikan, tabel per
  guru, dan peringatan bahwa filter guru tidak berlaku serta persetujuan tidak dapat dibatalkan.
- Setelah berhasil, status jurnal di daftar langsung berubah tanpa reload.
- Header halaman dibuat `flex-wrap` agar tombol ketiga tidak memotong tab bulan.

## 4. Kompatibilitas

- Klien lama tetap berfungsi: action baru bersifat aditif dan payload `verify_journal` tidak berubah.
- **Perubahan perilaku yang disengaja:**
  - edit jurnal terverifikasi oleh Admin/Kepala Sekolah tidak lagi me-reset verifikasi;
  - klien non-UI yang mengedit/menghapus jurnal terverifikasi atau milik guru lain sebagai GURU
    kini mendapat 403 (UI sudah menyembunyikan tombol tersebut);
  - `verify_journal` pada draf mendapat 400.
- Tidak ada perubahan kolom pada `journal_entries`.

## 5. Hasil uji di sekolah (dev lokal)

- Uji API otomatis **40/40 lulus**: preview, otorisasi, validasi bulan, approve dengan id preview
  (jurnal baru tetap pending, id asing dilewati), idempotensi, approve paralel Kepala Sekolah +
  Admin (setiap jurnal tepat sekali), kunci jurnal terverifikasi, dan kepemilikan jurnal.
- Uji UI di Chrome: tombol hanya untuk Kepala Sekolah; angka modal cocok dengan rekap halaman;
  persetujuan bulan uji berhasil dan rekap berubah tanpa reload; tanpa error console.
- Belum diuji: Firefox, layar ponsel, dan volume ribuan jurnal dalam satu batch.

## 6. Permintaan ke pengembang

1. Menyediakan persetujuan massal bulanan di sumber resmi, dengan kontrak seperti §3.1 atau setara.
2. Menjadikan `journal_approval_batches` migrasi resmi.
3. Menerapkan pengetatan S1–S6. Poin S1 memperbaiki bug nyata: saat ini setiap edit jurnal
   terverifikasi menghapus status verifikasinya.
4. Mempertimbangkan halaman riwayat batch dan fitur pembatalan batch. Kolom `journal_ids` sudah
   menyimpan data yang dibutuhkan.

## 7. Catatan tambahan

- **Filter tahun di daftar jurnal.** Daftar jurnal hanya mencocokkan singkatan bulan, bukan tahun,
  sehingga tab "Jan" menampilkan jurnal Januari dari semua tahun. Modal persetujuan memakai bulan
  dan tahun sendiri sehingga tidak terdampak, tetapi rekap di halaman bisa menyesatkan.
- **`teacherId` tertimpa saat Admin/Kepala Sekolah mengedit.** `save_journal` selalu menulis
  `teacherId`/`teacherName` dari sesi, sehingga jurnal guru yang diedit Admin berpindah pemilik.
- **Angka "belum diverifikasi" di dashboard** (`get_dashboard_analytics`) masih ikut menghitung draf.
