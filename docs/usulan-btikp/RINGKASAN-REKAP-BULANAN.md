# Usulan Fitur SIMANTAP: Cetak Rekap Bulanan Wali Kelas (PDF ber-Kop Sekolah)

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-14 |
| Lampiran | `backend-rekap-bulanan.diff` |
| Terkait | Lanjutan dari usulan "Menu Kesiswaan untuk Guru Wali Kelas" (`RINGKASAN-PENGEMBANG.md`) dan "Data Dashboard Kelas Wali Kelas Tidak Muncul" (`RINGKASAN-DASHBOARD-KELAS.md`) |

## 1. Kebutuhan

Setiap akhir bulan, guru ASN yang menjadi wali kelas mengunggah bukti kinerja ke **Laporan Kinerja bulanan ASN** (e-Kinerja). Salah satu buktinya adalah rekap kehadiran dan kedisiplinan siswa kelas perwalian.

Datanya sudah ada di SIMANTAP: presensi harian, siswa terlambat, izin keluar, pelanggaran, dan panggilan orang tua. Namun belum ada dokumen yang bisa dicetak, sehingga wali kelas menyalinnya secara manual.

**Usulan:** wali kelas bisa mencetak **rekap bulanan kelasnya sebagai PDF A4 ber-Kop Sekolah**, lengkap dengan tanda tangan wali kelas dan "Mengetahui, Kepala Sekolah", langsung dari menu **Kelas Saya → Dashboard Kelas**.

## 2. Kondisi saat ini

- Dashboard Kelas hanya punya tab "Rekap Bulanan" berisi total H/S/I/A per bulan (per semester), tanpa rincian siswa per bulan dan **tanpa tombol cetak**.
- Semua fitur cetak lain di aplikasi memakai `window.print()`. Kop sudah tersedia di `system_config` (`kop_instansi`, `kop_dinas`, `kop_sekolah`, `kop_alamat`, `kop_telp`, `kop_email`, `kop_website`, `kop_logo_kiri`/`kanan`, `kop_kepsek_nama`/`nip`). Belum ada key untuk **kota**.
- Data sumber:
  - presensi di `attendance_records`;
  - terlambat, izin keluar, dan pelanggaran di `incidents` (`type` = `LATE`/`OUT`/`VIOLATION`);
  - panggilan orang tua di `parent_calls`.
- Dua sifat data yang harus ditangani:
  - Alpha pada presensi otomatis membuat insiden `VIOLATION` berkode `ALFA` (`id = inc_att_<siswa>_<tanggal>`), sehingga mudah terhitung dua kali.
  - Kolom `academic_year` pada data lama tidak konsisten (hardcoded `2025/2026`), dan `incidents.className` menyimpan **nama** kelas, sedangkan `attendance_records.class_id` menyimpan **id** kelas.

## 3. Fitur yang diusulkan

### 3.1 Alur

1. Di Dashboard Kelas ada tombol **"Cetak Rekap Bulanan"** di samping "Perbarui Data".
2. Tombol membuka tab baru ke `api/index.php?action=cetak_rekap_bulanan&class_id=<class_homeroom>&month=<YYYY-MM>&auto=1`.
   - Bulan default: tanggal 1–10 = bulan lalu, selain itu bulan berjalan.
   - `auto=1` membuat backend menggeser bulan default yang jatuh di luar tahun ajaran aktif (mis. Juni saat awal Juli) ke bulan valid terdekat.
3. Halaman menampilkan **panel kontrol** (tidak ikut tercetak) dan **dokumen A4**.
   - Panel berisi: pilihan bulan, hari efektif, kota, tanggal tanda tangan, tombol "Terapkan", dan tombol "Cetak / Simpan PDF".
   - Panel juga menampilkan petunjuk dialog cetak dan daftar tanggal Senin–Sabtu yang belum ada presensinya.
4. Pengguna memilih **Simpan sebagai PDF**. Nama file default dari `<title>`: `Rekap_Bulanan_<Kelas>_<YYYY-MM>`.

PDF dihasilkan lewat dialog cetak browser, **tanpa library PDF baru**. Pilihan ini konsisten dengan fitur cetak lain dan tidak menambah dependensi. Konsekuensinya, hasil sedikit bergantung pada browser, dan pengguna perlu mematikan opsi "Header dan footer".

### 3.2 Isi dokumen

1. **Kop sekolah** dari `system_config` (logo kiri/kanan, garis ganda).
2. **Identitas:** kelas, wali kelas, bulan, tahun ajaran/semester (diturunkan dari bulan, bukan `active_semester`), hari efektif, dan hari presensi tercatat. Bulan berjalan diberi keterangan "Data s.d. tanggal …".
3. **Bagian A, per siswa:** NISN, nama, H/S/I/A, % hadir, jumlah terlambat, izin keluar, pelanggaran, poin bulan ini, dan keterangan. Ditutup baris Jumlah.
4. **Bagian B, rincian:** B.1 Terlambat, B.2 Izin Keluar, B.3 Pelanggaran (dengan nama jenis dari `violation_types`), dan B.4 Panggilan Orang Tua. Subbagian kosong ditulis "Nihil".
5. **Tanda tangan:** "Mengetahui, Kepala Sekolah" (`kop_kepsek_*`) dan "Wali Kelas" (nama dan NIP dari `users`).
6. **Footer:** waktu cetak dan nama pencetak.

### 3.3 Aturan perhitungan

| Aturan | Keputusan |
|---|---|
| Rentang data | Tanggal dalam bulan (`date >= 'YYYY-MM-01' AND date < bulan berikutnya`), **bukan** kolom `academic_year`/`semester` yang tidak konsisten pada data lama |
| Kecocokan kelas | Kunci ternormalisasi (lowercase, tanpa spasi/`_`/`-`/`.`) terhadap id **dan** nama kelas, sehingga presensi (id) dan insiden (nama) sama-sama masuk |
| Alpha | Dihitung di kolom A. **Tidak** dihitung sebagai pelanggaran dan tidak didaftar di B.3, tetapi poinnya tetap masuk kolom Poin |
| Insiden `REJECTED` | Tidak dihitung di ringkasan dan poin, tetapi tetap tampil di rincian dengan label "Ditolak" (sama dengan total poin di `get_homeroom_dashboard`) |
| % Hadir | `H ÷ (H+S+I+A)` per siswa. Baris Jumlah memakai total kelas |
| Hari efektif | Diisi wali. Default = jumlah tanggal yang punya presensi. Sistem belum punya kalender libur |
| Daftar siswa | Siswa aktif di kelas, ditambah siswa yang punya data di kelas ini pada bulan itu tetapi sudah pindah/keluar (keterangan "Pindah ke <kelas>", status siswa, atau "Data siswa tidak ditemukan") |
| Periode | Bulan dalam tahun ajaran aktif (Juli–Juni) dan tidak melewati bulan berjalan. Selain itu 400 |
| Tempat dan tanggal TTD | Kota default dari config opsional `kop_kota`. Tanggal default = hari terakhir bulan, atau hari ini untuk bulan berjalan. Keduanya bisa diubah di panel |

### 3.4 Matriks akses

Keterangan: ✅ semua kelas · 🏫 hanya kelas perwalian · ⛔ 403 · tanpa login 401. Semua error dibalas sebagai **halaman HTML**, bukan JSON, karena endpoint dibuka di tab baru.

| Action | ADMIN | KEPALA_SEKOLAH | GURU wali | GURU BK | GURU lain | TATA_USAHA |
|---|---|---|---|---|---|---|
| Tombol "Cetak Rekap Bulanan" | – | – | ✅ (Dashboard Kelas) | – | – | – |
| `cetak_rekap_bulanan` | ✅ | ✅ | 🏫 | ⛔ | ⛔ | ⛔ |

- Scope memakai `kesiswaanScope($db, $user, 'attendance_read')` dari usulan sebelumnya, sehingga status wali kelas **dibaca ulang dari DB** pada setiap request.
- KS dan Admin bisa membuka URL untuk kelas mana pun, tetapi belum ada UI pemilih kelas untuk mereka.
- Endpoint hanya menerima `GET`; metode lain dibalas 405. Semua nilai dinamis di-escape dengan `htmlspecialchars`, dan respons memakai header `Cache-Control: no-store`.

## 4. Perubahan backend (lihat `backend-rekap-bulanan.diff`)

Diff dibuat **di atas** `backend-kesiswaan-wali-kelas.diff` dan `backend-dashboard-kelas.diff`, karena memakai helper dari `api/kesiswaan_access.php` (`kesiswaanScope`, `classInScope`, `resolveClassRef`, `normalizeClassKey`, `activeAcademicPeriod`). Kami sudah memverifikasi bahwa ketiga diff bisa diterapkan bersih secara berurutan ke bundle 1.0.2 asli (`git apply --ignore-whitespace`) dan lolos `php -l`. **Tidak ada perubahan skema.**

| File | Perubahan |
|---|---|
| `api/rekap_bulanan.php` (baru) | Diawali guard `SIMANTAP_API`. Isi utamanya: `handleCetakRekapBulanan()` (metode, auth, scope, validasi parameter), `rekapCollectData()` (semua query memakai prepared statement), `rekapRender()` (HTML A4 dengan CSS `@page`, header tabel berulang, panel `no-print`), dan `rekapHtmlError()` (halaman error HTML) |
| `api/index.php` | `require_once __DIR__ . '/rekap_bulanan.php';` dan `case 'cetak_rekap_bulanan'` di `switch` utama sebelum `default:`. Tidak ada action lain yang berubah, dan nama action baru tidak bentrok dengan keempat file endpoint |

**Parameter `cetak_rekap_bulanan`:**

| Parameter | Keterangan |
|---|---|
| `class_id` | Id atau nama kelas, format apa pun. Wajib untuk ADMIN/KS. Untuk wali, default kelas perwalian |
| `month` | Wajib, `YYYY-MM` |
| `auto` | `1` = geser bulan ke rentang valid, bukan ditolak |
| `hari_efektif` | Opsional, 1 s.d. jumlah hari dalam bulan |
| `kota` | Opsional, maks. 50 karakter |
| `tanggal` | Opsional, `YYYY-MM-DD` |

**Config opsional `kop_kota`** (belum ada UI):

```sql
INSERT INTO system_config (config_key, config_value, description)
VALUES ('kop_kota', 'Amuntai', 'Kota pada tanda tangan dokumen')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
```

## 5. Perubahan frontend yang diusulkan (level source)

Di sekolah kami perubahan ini sementara dipasang sebagai patch pada file JS hasil build. Mohon diterapkan di source React:

1. **Tombol di komponen Dashboard Kelas.** Tambahkan tombol di samping "Perbarui Data":
   ```jsx
   <div className="flex flex-wrap items-center gap-2">
     <button onClick={refresh} /* tombol Perbarui Data yang sudah ada */ />
     {user?.class_homeroom && (
       <button
         onClick={() => {
           const now = new Date();
           const d = now.getDate() <= 10 ? new Date(now.getFullYear(), now.getMonth() - 1, 1) : now;
           const ym = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
           window.open(
             `${API_URL}?action=cetak_rekap_bulanan&class_id=${encodeURIComponent(user.class_homeroom)}&month=${ym}&auto=1`,
             "_blank"
           );
         }}
         className="flex items-center gap-2 px-4 py-2 bg-emerald-600 border border-emerald-600 rounded-xl text-xs font-black text-white hover:bg-emerald-700 transition-all"
       >
         <Printer size={13} /> Cetak Rekap Bulanan
       </button>
     )}
   </div>
   ```
2. **Field "Kota" di Pengaturan Kop Surat** yang menyimpan `kop_kota`, agar admin tidak perlu mengisinya lewat SQL.
3. **Opsional:** UI pemilih kelas untuk Kepala Sekolah (endpoint sudah mengizinkan), dan cetak massal semua kelas.

## 6. Hasil uji di sekolah

**Lingkungan:** PHP 8.2, MySQL 5.7.33 dengan `STRICT_TRANS_TABLES` dan `ONLY_FULL_GROUP_BY`, data sekolah (35 kelas, 968 siswa, tahun ajaran aktif 2026/2027), dan Chrome headless untuk UI dan PDF.

**Data uji** (bulan Agustus 2026, kelas dengan id `X_AKT_1`, nama `X AKT 1`, dan `class_homeroom` wali `X-AKT-1`), dibuat lewat API asli:
- presensi 3 hari berisi 1 Sakit, 1 Izin, 1 Alpha;
- insiden: 2 terlambat, 1 izin keluar, 3 pelanggaran (1 ditolak, 1 berisi `<script>`);
- 1 panggilan orang tua;
- ditambah 1 insiden tanpa `studentId`, 1 insiden kelas lain, dan 1 siswa yang dipindah kelas.

Semua data uji dibersihkan setelah pengujian.

- **Backend: 76/76 skenario lulus.** Mencakup:
  - angka per siswa dan total kelas cocok dengan query SQL referensi;
  - Alpha tidak dihitung ganda, dan insiden ditolak dikecualikan tetapi tetap tampil;
  - keterangan siswa pindah dan data siswa terhapus;
  - XSS ter-escape;
  - validasi 400 untuk semua parameter, dan `auto=1` menggeser bulan;
  - otorisasi: anonim 401, GURU/BK/TU 403, wali ke kelas lain 403, KS/Admin semua kelas, perubahan `class_homeroom` langsung berlaku, user terhapus 401, POST 405;
  - regresi `get_incidents`, `get_homeroom_dashboard`, dan action tak dikenal.
- **UI (Chrome headless): 20/20 lulus, tanpa JS exception.**
  - tombol tampil di Dashboard Kelas wali, dan tidak tampil untuk GURU biasa maupun KS;
  - URL tab baru benar, dan tab dashboard lain tetap berfungsi;
  - panel kontrol tersembunyi saat print;
  - PDF A4 dua halaman diperiksa visual (kop, tabel, rincian, tanda tangan);
  - lebar layar 400px tanpa scroll horizontal.
- **Belum diuji:** Firefox, dan tampilan logo kop (file logo tidak ada di salinan lokal kami).

## 7. Catatan tambahan

- **Perlu keputusan pengembang:** apakah insiden `REJECTED` memang dikecualikan dari rekap, apakah tahun ajaran selalu Juli–Juni, dan apakah sekolah 5 hari kerja perlu pengaturan agar Sabtu tidak masuk daftar "belum diisi".
- **Kalender akademik:** jika SIMANTAP nanti punya kalender hari libur, hari efektif bisa dihitung otomatis.
- **PDF asli dari server** (tanpa dialog cetak) bisa dipertimbangkan bila hasil print browser kurang seragam antar-perangkat. Konsekuensinya ada dependensi library PDF.
- **Keamanan:** temuan terkait hak akses endpoint dashboard wali kelas (`get_homeroom_dashboard`, `get_class_attendance_summary`, `get_homeroom_info`) kami sampaikan secara terpisah bersama temuan keamanan lain.
