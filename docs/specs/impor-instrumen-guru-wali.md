# Spesifikasi: Impor Instrumen Digital Guru Wali (Google Form)

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/instrumen-guru-wali.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`), bundle aktif `assets/index-CGWL69P7-guruwali1.js` |
| Tanggal | 2026-11-14 |
| Jalur rilis | Patch lokal pada bundle ini **dan** usulan perubahan ke upstream BTIKP |
| Rangkaian fitur | **Bimbingan Guru Wali**, tahap 2 dari 3: (1) penugasan murid dampingan (`docs/specs/penugasan-guru-wali.md`); (2) impor hasil instrumen digital (Google Form) yang diisi murid ← *spesifikasi ini*; (3) jurnal pendampingan |
| Instrumen acuan | Google Form **"Formulir Awal Murid Guru Wali 2026/2027"** (SMK Negeri 1 Amuntai, 7 halaman). Contoh respons: `docs/asesmen-murid-dampingan.xlsx` (sheet "Respons Form Awal", 66 kolom, 3 respons; nama, NISN, dan jawaban A64 diganti data buatan) |

## 1. Latar belakang dan kebutuhan bisnis

Profil Murid 360° (`docs/specs/profil-murid-360.md`) menyimpan asesmen non-kognitif dan rencana karir per murid per periode (TA + semester) di `student_asesmen`. Saat ini data itu hanya bisa masuk lewat:

- input manual oleh wali kelas/BK/KS/ADMIN, atau
- impor **template Excel SIMANTAP** yang berisi **hasil yang sudah diskor** (skor R/I/A/S/E/C 0–100, gaya belajar, dst.). Penskoran dilakukan di luar SIMANTAP.

Sekolah kini memakai instrumen digital: setiap guru wali membagikan Google Form "Formulir Awal Murid Guru Wali" ke murid dampingannya. Murid mengisinya sendiri sekali di awal tahun ajaran. Guru wali lalu mengunduh sheet respons dan saat ini harus menskor RIASEC/gaya belajar secara manual sebelum data bisa masuk SIMANTAP — atau datanya tidak pernah masuk.

Fitur ini membuat guru wali bisa **mengunggah sheet respons apa adanya**. SIMANTAP mengenali kolom dari kode butir (`[A00]`…`[A64]`), menskor RIASEC minat (42 pernyataan), kompetensi RIASEC (8 dari 24 kegiatan), dan gaya belajar V/A/K (5 soal), lalu menulis hasilnya ke asesmen periode aktif murid dampingan, sambil menyimpan jawaban mentah untuk audit.

### 1.1 Keputusan wawancara

| Topik | Keputusan |
|---|---|
| Pengimpor | **Hanya guru wali** (role `GURU`) untuk **murid dampingan aktifnya**. ADMIN, KS, BK, dan wali kelas **tidak** memakai fitur ini (mereka tetap punya impor template dan input manual) |
| Cakupan file | **Satu form per guru wali.** Tidak memilih kelas. Baris NISN yang bukan dampingan aktif pengimpor saat impor → `LEWATI` |
| Validitas guru wali | **Guru wali aktif saat impor** (`guruWaliDampinganIds`). Cap waktu tidak dicek terhadap tanggal mulai penugasan |
| Periode | **Dari "Cap waktu"** respons. Hanya respons yang tanggalnya jatuh dalam **TA + semester aktif** yang ditulis; lainnya → `LEWATI` |
| Konflik dengan asesmen yang ada | **Timpa yang terisi saja**: field yang dihasilkan instrumen menimpa nilai lama; field yang tidak dihasilkan (kosong/tidak terbaca) tidak menghapus nilai lama |
| Respons ganda dalam periode | **Pakai yang terbaru** (Cap waktu terakhir). Yang lebih lama → `LEWATI` "Diganti respons lebih baru (baris N)" |
| Persetujuan (A00) tidak dicentang / NISN bukan dampingan | `LEWATI` dengan alasan, tidak menggagalkan baris lain |
| Kunci RIASEC minat | Kuis Holland 42 butir standar (§4.4). Skor tipe = jumlah "Suka" ÷ 7 × 100. Kode = 3 tipe teratas via `profilMuridKodeRiasec` |
| Kunci gaya belajar | A30–A34, opsi ke-1 = V, ke-2 = A, ke-3 = K (teks persis, §4.5). Skor = jumlah ÷ 5 × 100 |
| A45 (8 dari 24 kegiatan) | **Skor kompetensi RIASEC terpisah** (6 tipe × 4 kegiatan, skor = jumlah dipilih ÷ 4 × 100). Skor/kode RIASEC utama tetap dari minat |
| Butir tanpa kolom | **Kolom baru** di `student_asesmen` untuk butir yang ditampilkan + **jawaban mentah** lengkap di tabel respons baru |
| Jarak & transportasi (A20/A21) | Disimpan di respons. **Mengisi `student_biodata` hanya jika field Dapodik kosong.** Jarak = titik tengah rentang |
| Letak UI | Menu **Murid Dampingan**: tombol "Impor Hasil Formulir (Google Form)" + status "sudah/belum mengisi" per murid |
| Visibilitas | Semua yang punya akses Profil Murid 360° murid itu (wali kelas, BK, KS, ADMIN, guru wali) melihat butir baru dan panel lipat "Jawaban murid" |
| Edit manual oleh guru wali | **Tetap tidak boleh.** Guru wali hanya menulis lewat impor instrumen (keputusan bawaan, tidak diubah pengguna) |
| Rilis & uji | Pola fitur sebelumnya: seed/cleanup SQL, uji API `.mjs`, uji unit PHP, `php -l` + `node --check`, E2E manual memakai file contoh |

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Asesmen Profil Murid (`api/profil_murid.php`)

- `student_asesmen` (dibuat oleh `profilMuridPastikanTabel`): unik `(student_id, academic_year, semester)`; field `PM_ASESMEN_FIELD` = `riasec_r..c`, `riasec_kode`, `gaya_belajar`, `skor_visual/auditori/kinestetik`, `kesulitan_belajar`, `mapel_termudah`/`mapel_tersulit` (JSON array, maks. 3), `cita_cita`, `rencana_lulus` (enum `KULIAH/KERJA/WIRAUSAHA/KULIAH_KERJA/BELUM_TAHU/LAINNYA`), `jurusan_tujuan`, `institusi_tujuan`, `catatan_karir`; `sumber` enum `IMPOR/MANUAL`; `logs` JSON (maks. 50).
- `profilMuridUpsertAsesmen` **menimpa seluruh** `PM_ASESMEN_FIELD` (nilai null ikut ditulis). Kolom di luar `PM_ASESMEN_FIELD` tidak disentuh.
- `profil_murid_asesmen_impor`: satu kelas, template SIMANTAP, `PM_MAKS_ASESMEN_KOLOM = 30`, `PM_MAKS_ASESMEN_BARIS = 101`, mode `pratinjau|simpan`, log di `student_import_log` (`jenis` enum `DAPODIK/ASESMEN`).
- Hak tulis `profilMuridBisaEditAsesmen`: scope `ALL` atau wali kelas murid aktifnya. Mode `DAMPINGAN` (guru wali) = baca saja (`PM_MSG_BACA_SAJA`).
- Fungsi murni yang bisa dipakai ulang: `profilMuridSel`, `profilMuridNormalNomor($v,'nisn')` (pad 8–9 digit ke 10, tolak notasi E+), `profilMuridKodeRiasec`, `profilMuridDaftarMapel`, `profilMuridPeriodeDariTanggal`.
- `student_biodata` (dari impor Dapodik) punya `alat_transportasi varchar(50)` dan `jarak_rumah_km decimal(6,2)`. Baris hanya ada untuk murid yang sudah diimpor Dapodik (`diimpor_oleh` NOT NULL).

### 2.2 Guru wali (`api/guru_wali.php`)

- `guruWaliDampinganIds($db, $uid)`: id murid dampingan aktif (murid aktif, penugasan `AKTIF`).
- `guru_wali_dampingan_saya` (GET): daftar dampingan guru + kehadiran, `biodata` ada/tidak, `asesmen` ada/tidak untuk periode aktif. Batas `GW_MAKS_MURID = 200`.
- Frontend Murid Dampingan dan Profil Murid membaca Excel di browser dengan SheetJS bundel (`C_`, `Jl.sheet_to_json`), lalu mengirim `rows` (array of array) sebagai JSON.

### 2.3 Instrumen (Google Form)

- Header kolom sheet respons berformat `[Axx] Teks pertanyaan`. Grid A40/A41 menjadi satu kolom per pernyataan: `[A40] Pernyataan minat 1–21 [01. Aku suka mengulik peralatan]`.
- Kolom pertama `Cap waktu` (xlsx: serial Excel, mis. `46339.7685`; CSV: teks tanggal sesuai lokal sheet).
- Checkbox (A35, A45) dikirim sebagai satu sel dipisah `", "`. Opsi "Lainnya" muncul sebagai teks bebas murid di sel yang sama.
- A01 divalidasi form `^[0-9]{10}$`, tetapi nol di depan bisa hilang bila CSV dibuka di Excel.

## 3. Expected behavior

### 3.1 Alur guru wali

1. Guru wali membuka **Murid Dampingan**. Tabel menampilkan kolom baru **"Formulir"**: `Sudah (dd/mm/yyyy)` bila ada respons tersimpan untuk periode aktif, `Belum` bila tidak. Ringkasan di atas: "X dari Y murid sudah mengisi formulir semester ini".
2. Klik **"Impor Hasil Formulir (Google Form)"** → modal berisi petunjuk singkat (Google Sheets respons → File → Download → Microsoft Excel (.xlsx); CSV juga diterima) dan pilihan file `.xlsx`/`.csv`.
3. Browser membaca sheet pertama dengan SheetJS (`header: 1, raw: true, defval: ''`), mengubah tiap sel menjadi string, dan mengirim `instrumen_gw_impor` mode `pratinjau`.
4. Pratinjau menampilkan:
   - ringkasan: baru, timpa, lewati, galat, total;
   - tabel per baris: No. baris, status (badge), NISN, nama di form, nama di SIMANTAP (hanya untuk dampingan), Cap waktu (terbaca), kode RIASEC minat, gaya belajar, kompetensi teratas, pesan/peringatan;
   - peringatan tingkat file (kode butir tidak dikenal, kode butir hilang, format tanggal diasumsikan d/m/Y).
5. Tombol **Simpan** aktif bila ada ≥ 1 baris `BARU`/`TIMPA`. Simpan mengirim ulang `rows` yang sama dengan mode `simpan`; server memvalidasi ulang (tidak mempercayai hasil pratinjau).
6. Setelah berhasil: toast "N hasil formulir disimpan", tabel Murid Dampingan dimuat ulang.

### 3.2 Profil Murid 360° (semua yang berhak membaca)

Di tab/seksi Asesmen periode yang dipilih:

- **Minat RIASEC** (skor + kode, sudah ada) dan **Kompetensi RIASEC** (baru, 6 batang 0–100 + kode 3 huruf) berdampingan, dengan label sumber "Formulir murid, dd/mm/yyyy" bila berasal dari instrumen.
- Gaya belajar + skor V/A/K (sudah ada).
- Kesulitan belajar (sudah ada) + **Cerita kesulitan** (baru).
- Mapel termudah/tersulit (sudah ada) + **Mapel pilihan** dan **Mapel pendukung karir** (baru).
- **Bakat/keterampilan dimiliki**, **Keterampilan ingin ditingkatkan**, **Kegiatan yang dikuasai** (8 chip) (baru).
- Rencana setelah lulus, cita-cita (profesi diminati), institusi tujuan (sudah ada) + **Alasan memilih rencana** (baru).
- Tab Identitas: bila `student_biodata` kosong untuk jarak/transportasi tetapi ada di respons, tampil "menurut murid: 1–3 km, Sepeda motor sendiri".
- Panel lipat **"Jawaban murid (Formulir Awal)"**: Cap waktu, diimpor oleh (nama guru wali) + waktu impor, lalu setiap butir dengan teks pertanyaan dan jawaban (A40/A41 sebagai daftar 42 pernyataan dengan tanda Suka/Tidak suka).

`cetak_profil_murid` menambahkan baris Kompetensi RIASEC, bakat, keterampilan ingin ditingkatkan, dan alasan rencana. Jawaban mentah **tidak** dicetak.

### 3.3 Yang tidak berubah

- Wali kelas/BK/KS/ADMIN tetap bisa input manual dan impor template; keduanya tidak menyentuh kolom baru (§8).
- Guru wali tetap tidak bisa menyimpan/menghapus asesmen manual (`profil_murid_asesmen_simpan`/`_hapus` tetap 403 untuk mode `DAMPINGAN`).

## 4. Kontrak backend

File baru **`api/instrumen_guru_wali.php`** (prefix fungsi `instrumenGw`, konstanta `IGW_`), di-`require` di `api/index.php` seperti modul lain, dengan `case` eksplisit.

### 4.1 Tabel dan kolom (dibuat/diubah saat pertama dipakai, `instrumenGwPastikanSkema`)

Dipanggil setelah `profilMuridPastikanTabel`. Setiap `ALTER` dijaga pengecekan `information_schema` (idempoten, aman dipanggil berulang), dan hanya dijalankan sekali per request (static flag).

**a. Kolom baru `student_asesmen`** (semua `DEFAULT NULL`):

| Kolom | Tipe | Isi |
|---|---|---|
| `kompetensi_r` … `kompetensi_c` | `decimal(5,2)` | Skor kompetensi RIASEC 0–100 dari A45 |
| `kompetensi_kode` | `varchar(3)` | 3 tipe kompetensi teratas (`profilMuridKodeRiasec`) |
| `kegiatan_dikuasai` | `text` | JSON array teks kegiatan A45 (yang dikenal saja) |
| `kesulitan_cerita` | `text` | A36, maks. 1.000 karakter |
| `mapel_pilihan` | `text` | JSON array dari A39 (`profilMuridDaftarMapel`, maks. 3) |
| `mapel_pendukung` | `text` | JSON array dari A63 (maks. 3) |
| `bakat` | `text` | A50, maks. 1.000 |
| `keterampilan_target` | `varchar(255)` | A51 |
| `alasan_rencana` | `text` | A64, maks. 1.000 |
| `instrumen_respons_id` | `varchar(64)` | Rujukan ke respons yang terakhir menulis baris ini |

`student_asesmen.sumber` diubah menjadi `enum('IMPOR','MANUAL','INSTRUMEN')` (MODIFY hanya menambah nilai; data lama tetap valid).

**b. Tabel baru `student_instrumen_respons`** — satu respons tersimpan per murid per periode:

```sql
CREATE TABLE IF NOT EXISTS `student_instrumen_respons` (
  `id` varchar(64) NOT NULL,                       -- uniqid('igr_')
  `student_id` varchar(128) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('GANJIL','GENAP') NOT NULL,
  `instrumen` varchar(50) NOT NULL,                -- 'GW_FORMULIR_AWAL'
  `cap_waktu` datetime NOT NULL,
  `nisn_form` varchar(20) NOT NULL,
  `nama_form` varchar(255) DEFAULT NULL,
  `jarak_kategori` varchar(50) DEFAULT NULL,       -- teks opsi A20 apa adanya
  `transportasi` varchar(100) DEFAULT NULL,        -- A21 (termasuk teks "Lainnya")
  `jawaban_json` mediumtext NOT NULL,              -- lihat format di bawah
  `guru_uid` varchar(128) NOT NULL,                -- pengimpor (guru wali saat impor)
  `guru_nama` varchar(255) NOT NULL,
  `impor_id` varchar(64) NOT NULL,
  `diimpor_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_instrumen_periode` (`student_id`, `instrumen`, `academic_year`, `semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`jawaban_json`: `{"jawaban": {"A00": "...", "A01": "...", ..., "A40_01": "Suka", ..., "A41_42": "Tidak suka", ...}, "judul": {"A30": "Saat mempelajari …", "A40_01": "Aku suka mengulik peralatan", ...}}`. `judul` diambil dari header file (agar tetap terbaca bila form berubah). Setiap nilai dipotong `PM_MAKS_SEL` (500) karakter. Kolom yang tidak berkode diabaikan.

**c. `student_import_log.jenis`** diubah menjadi `enum('DAPODIK','ASESMEN','INSTRUMEN')`. Satu baris log per impor `simpan` (`class_id` = null, `academic_year`/`semester` = periode aktif).

### 4.2 Pengenalan kolom (`instrumenGwPetaKolom(array $header): array`, fungsi murni)

- Header dinormalisasi (`profilMuridSel`, spasi dirapikan). Kode butir diambil dengan `/^\[(A\d{2})\]/`.
- Grid: `/^\[(A4[01])\].*\[(\d{1,2})\.\s*(.*)\]\s*$/` → kunci `A40_NN`/`A41_NN` (NN = nomor pernyataan 01–42, zero-pad). Nomor di luar 1–42, atau grid yang nomornya tidak sesuai bloknya (A40: 1–21, A41: 22–42) → peringatan file, kolom diabaikan.
- `Cap waktu`/`Timestamp` (tanpa kode, huruf bebas) → kunci `_cap_waktu`.
- **Wajib**: `_cap_waktu`, `A00`, `A01`. Bila salah satu hilang → 400 "Format file tidak dikenali sebagai respons Formulir Awal Murid Guru Wali (kolom Cap waktu, [A00], dan [A01] wajib ada)."
- Kode dikenal: `A00 A01 A02 A20 A21 A30–A34 A35 A36 A37 A38 A39 A40_01–A41_42 A45 A50 A51 A60–A64`. Kode lain → peringatan file "Kolom [Axx] tidak dikenal, diabaikan". Kode ganda → 400.
- Kode dikenal yang hilang → peringatan file per bagian yang terdampak, mis. "Kolom gaya belajar [A32] tidak ada: skor V/A/K dihitung dari soal yang ada". Bila **semua** 42 kolom minat hilang, RIASEC minat tidak diskor (field tidak ditimpa).

### 4.3 Validasi dan status per baris

Diproses berurutan; baris yang seluruh selnya kosong dilewati tanpa dicatat.

| Langkah | Kondisi | Status / pesan |
|---|---|---|
| 1. Cap waktu | Tidak bisa dibaca (§4.6) | `GALAT` "Cap waktu tidak terbaca" |
| 2. Persetujuan | `A00` ≠ `Saya mengerti dan bersedia mengisi formulir ini dengan jujur` (dibandingkan setelah trim, huruf bebas) | `LEWATI` "Murid tidak menyetujui pernyataan" |
| 3. NISN | `profilMuridNormalNomor($A01,'nisn')` galat/kosong | `GALAT` pesan normalisasi |
| 4. Dampingan | NISN bukan murid dampingan aktif pengimpor | `LEWATI` "Bukan murid dampingan aktif Anda". **Nama murid dari database tidak ditampilkan** |
| 5. Periode | Tanggal Cap waktu di luar `getSemesterDateRange(TA aktif, semester aktif)` | `LEWATI` "Diisi di luar periode aktif (TA …, semester …)" |
| 6. Respons ganda (dalam file) | Murid sama muncul > 1 kali di periode aktif | Baris dengan Cap waktu terbaru diproses (seri → baris terbawah). Lainnya `LEWATI` "Diganti respons lebih baru (baris N)" |
| 7. Respons tersimpan lebih baru | `student_instrumen_respons.cap_waktu` periode ini > Cap waktu baris | `LEWATI` "Sudah ada respons yang lebih baru (dd/mm/yyyy hh:mm)" |
| 8. Penskoran | §4.4–4.5. Tidak ada satu pun field asesmen yang dihasilkan | `LEWATI` "Tidak ada jawaban yang bisa diolah" |
| 9. Hasil | Belum ada baris `student_asesmen` periode ini | `BARU` |
| | Sudah ada | `TIMPA` (pesan: daftar field yang akan berubah, mis. "Mengganti RIASEC, gaya belajar, rencana") |

Peringatan per baris (tidak menggagalkan): nama form berbeda dari nama SIMANTAP (dibandingkan huruf kecil tanpa spasi/tanda baca berlebih), NISN tidak 10 digit, opsi tidak dikenal, jumlah kegiatan A45 ≠ 8, mapel > 3 (dipotong), teks dipotong karena terlalu panjang, Cap waktu diasumsikan d/m/Y.

Batas: `rows` maks. **501** baris (500 data + header), maks. **120** kolom → 400 seperti `profilMuridRows`.

### 4.4 Penskoran RIASEC (`instrumenGwSkorRiasec(array $jawaban): array`, fungsi murni)

**Minat (A40/A41).** Kunci (nomor pernyataan):

| Tipe | Pernyataan |
|---|---|
| R | 1, 7, 14, 22, 30, 32, 37 |
| I | 2, 11, 18, 21, 26, 33, 39 |
| A | 3, 8, 17, 23, 27, 31, 41 |
| S | 4, 12, 13, 20, 28, 34, 40 |
| E | 5, 10, 16, 19, 29, 36, 42 |
| C | 6, 9, 15, 24, 25, 35, 38 |

- `Suka` = 1, `Tidak suka` = 0 (huruf bebas, trim). Nilai lain/kosong = tidak terjawab (+ peringatan bila tidak kosong).
- Skor tipe = jumlah Suka ÷ jumlah butir terjawab tipe itu × 100, dibulatkan 2 desimal.
- Bila ada tipe tanpa butir terjawab → RIASEC minat tidak diskor (semua `riasec_*` null = tidak ditimpa) + peringatan.
- `riasec_kode` = `profilMuridKodeRiasec` (seri dipecah urutan R, I, A, S, E, C).

**Kompetensi (A45).** Teks kegiatan dibandingkan persis (trim, huruf bebas) dengan daftar berikut:

| Tipe | Kegiatan |
|---|---|
| R | Memperbaiki barang, Mengutak-atik barang, Membongkar-pasang barang, Merakit benda |
| I | Mencari solusi, Menganalisis masalah, Menghitung, Berpikir logis |
| A | Menari, Menulis cerita, Bermusik, Menggambar |
| S | Memberi saran, Memahami orang lain, Mengajar, Membantu |
| E | Mengelola acara, Memimpin kelompok, Menjual benda, Membujuk orang |
| C | Membereskan barang, Mengurutkan, Mengikuti aturan, Menjalankan rutin |

- Sel dipisah koma (`/,\s*/`). Karena tidak ada opsi yang mengandung koma, pemisahan aman.
- Skor tipe = jumlah dipilih ÷ 4 × 100. `kompetensi_kode` = `profilMuridKodeRiasec`. `kegiatan_dikuasai` = kegiatan yang dikenal, urutan sesuai form.
- Opsi tidak dikenal → peringatan, diabaikan. Jumlah dikenal ≠ 8 → peringatan "Murid memilih N kegiatan (seharusnya 8)", tetap diskor. Sel kosong → kompetensi tidak diskor.

### 4.5 Gaya belajar dan pemetaan butir lain (`instrumenGwPetakan(array $jawaban): array`, fungsi murni)

**Gaya belajar** — kunci opsi persis (trim, huruf bebas; tanda `…`/`–` dinormalisasi):

| Butir | V | A | K |
|---|---|---|---|
| A30 | Melihat gambar, diagram, atau video tutorial | Mendengarkan penjelasan guru atau teman | Langsung mencoba sendiri sambil praktik |
| A31 | Membayangkan tulisan atau gambarnya | Mengucapkannya berulang-ulang | Menuliskannya berkali-kali |
| A32 | Menampilkan slide, gambar, atau tulisan di papan | Menjelaskan secara lisan dan mengajak diskusi | Memberi praktik atau kegiatan langsung |
| A33 | Menonton atau membaca | Mendengarkan musik/podcast atau mengobrol | Berolahraga atau membuat sesuatu |
| A34 | Membuat catatan berwarna atau peta pikiran | Membaca keras-keras atau belajar sambil berdiskusi | Mengerjakan latihan soal atau belajar sambil bergerak |

- Skor V/A/K = jumlah pilihan ÷ jumlah soal terjawab dan dikenal × 100 (2 desimal). Opsi tidak dikenal → peringatan, tidak dihitung. Tidak ada soal terjawab → tidak diskor.
- `gaya_belajar` = skor tertinggi; seri dipecah urutan V, A, K (sama dengan aturan `profilMuridValidasiAsesmen`).

**Pemetaan butir ke kolom** (teks: `profilMuridSel` + trim; terlalu panjang → dipotong + peringatan, **bukan** galat, karena ditulis murid):

| Butir | Kolom | Aturan |
|---|---|---|
| A20 | `student_instrumen_respons.jarak_kategori`; biodata (§4.7) | Opsi: `Kurang dari 1 km`=0,5 · `1–3 km`=2 · `3–5 km`=4 · `5–10 km`=7,5 · `Lebih dari 10 km`=10 (tanda `-`/`–` disamakan) |
| A21 | `transportasi`; biodata (§4.7) | Opsi: Jalan kaki, Sepeda, Sepeda motor sendiri, Diantar keluarga, Angkutan umum / ojek, atau teks "Lainnya" |
| A35 | `kesulitan_belajar` | Teks sel apa adanya (sudah dipisah koma), maks. 1.000. Bila satu-satunya pilihan "Tidak ada kesulitan berarti" tetap disimpan |
| A36 | `kesulitan_cerita` | maks. 1.000 |
| A37 / A38 | `mapel_termudah` / `mapel_tersulit` | `profilMuridDaftarMapel`; > 3 → ambil 3 pertama + peringatan |
| A39 | `mapel_pilihan` | idem |
| A50 | `bakat` | maks. 1.000 |
| A51 | `keterampilan_target` | maks. 255 |
| A60 | `rencana_lulus` | `Bekerja`→KERJA, `Melanjutkan kuliah`→KULIAH, `Wirausaha`→WIRAUSAHA, `Belum tahu`→BELUM_TAHU. Opsi lain → peringatan, tidak ditulis |
| A61 | `cita_cita` | maks. 150 |
| A62 | `institusi_tujuan` | maks. 150 (kampus+prodi **atau** perusahaan/bidang usaha, teks utuh). `jurusan_tujuan` tidak disentuh |
| A63 | `mapel_pendukung` | `profilMuridDaftarMapel`, maks. 3 |
| A64 | `alasan_rencana` | maks. 1.000 |
| A40/A41 | `riasec_*`, `riasec_kode` | §4.4 |
| A45 | `kompetensi_*`, `kompetensi_kode`, `kegiatan_dikuasai` | §4.4 |
| A30–A34 | `skor_visual/auditori/kinestetik`, `gaya_belajar` | di atas |
| A00, A01, A02 | hanya `jawaban_json` (+ `nisn_form`, `nama_form`) | — |

`catatan_karir` dan `jurusan_tujuan` (milik wali kelas/BK) **tidak pernah** ditulis instrumen.

Sel kosong pada butir apa pun = field tidak dihasilkan → **tidak menimpa** nilai lama (§4.8).

### 4.6 Cap waktu (`instrumenGwCapWaktu(string $sel): ?array`, fungsi murni)

Hasil `['waktu' => 'Y-m-d H:i:s', 'asumsi_dmy' => bool]` atau null.

- Angka (`/^\d+(\.\d+)?$/`, 20000–80000): serial Excel sistem 1900 → tanggal-jam (hari 25569 = 1970-01-01, pecahan × 86400 detik, dibulatkan ke detik). Dibaca sebagai waktu lokal apa adanya (tanpa konversi zona).
- `YYYY-MM-DD[ HH:MM[:SS]]`.
- `D/M/YYYY[ H:MM[:SS]]` (lokal Indonesia, ekspor CSV Google Sheets): bila bagian pertama > 12 → pasti d/m; bila bagian kedua > 12 → m/d; bila keduanya ≤ 12 → **d/m** + `asumsi_dmy = true` (peringatan baris + peringatan file "Tanggal dibaca sebagai hari/bulan/tahun; gunakan unduhan .xlsx bila ragu").
- Tanggal tidak valid (`checkdate`) atau di masa depan > 1 hari → null.

### 4.7 Biodata (A20/A21)

Saat `simpan`, untuk murid yang **sudah punya** baris `student_biodata`:

- `UPDATE student_biodata SET jarak_rumah_km = ? WHERE student_id = ? AND jarak_rumah_km IS NULL` (nilai titik tengah, hanya bila A20 dikenal).
- `UPDATE ... SET alat_transportasi = ? WHERE ... AND (alat_transportasi IS NULL OR alat_transportasi = '')` (dipotong 50 karakter).

Murid **tanpa** baris biodata tidak dibuatkan baris (baris biodata menandakan impor Dapodik). Nilai tetap tersedia di respons dan ditampilkan sebagai "menurut murid". Impor Dapodik berikutnya menimpa seperti biasa.

### 4.8 Penulisan (`simpan`)

Dalam **satu transaksi** untuk semua baris `BARU`/`TIMPA`:

1. `SELECT … FROM student_asesmen WHERE student_id=? AND academic_year=? AND semester=? FOR UPDATE`.
2. Hitung field hasil instrumen (hanya yang non-null). Bila baris belum ada → `INSERT` dengan field itu, sisanya null, `sumber = 'INSTRUMEN'`, `class_id/class_name` = kelas murid saat ini, `dibuat_oleh = diubah_oleh` = guru wali dari session. Bila ada → `UPDATE` **hanya kolom yang dihasilkan** + `sumber = 'INSTRUMEN'`, `class_id/class_name`, `diubah_oleh(_nama)`, `instrumen_respons_id`.
3. Tambah log `{aksi: 'INSTRUMEN', oleh, oleh_nama, waktu, sumber: 'INSTRUMEN', impor_id, field: [daftar kolom yang ditulis]}` (maks. 50 entri, `PM_MAKS_LOG`).
4. Upsert `student_instrumen_respons` (unik per murid+instrumen+periode); `id` baru bila insert, `id` lama dipertahankan bila update. Cek ulang langkah 7 §4.3 dengan `FOR UPDATE`.
5. Biodata §4.7.
6. Satu baris `student_import_log` `jenis = 'INSTRUMEN'`.

Status pratinjau dihitung ulang di mode `simpan` dari data terbaru (penugasan bisa berubah di antara pratinjau dan simpan). Gagal di tengah → rollback, 500 dengan pesan umum (`error_log` detail, tanpa stack trace ke klien).

### 4.9 Scope dan otorisasi

- `instrumen_gw_impor`: `POST` saja (405 selain itu); `requireAuth()` + `loadFreshUser`; role harus `GURU` → selain itu 403 "Impor formulir guru wali hanya untuk guru wali." Guru tanpa dampingan aktif → 403 "Anda belum memiliki murid dampingan aktif."
- Murid yang boleh ditulis = `guruWaliDampinganIds($db, $uid)` saat request (bukan dari body).
- Aktor (`guru_uid`, `guru_nama`, `oleh`) selalu dari session/database, tidak dari body.
- Respons pratinjau untuk baris non-dampingan hanya memuat NISN dan nama **dari form**; tidak ada data murid lain dari database.
- Pembacaan respons ikut `profilMuridAmbilMurid` (scope Profil Murid). Untuk mode `DAMPINGAN`, NISN di `jawaban_json` ditampilkan apa adanya (NISN sudah tampil di profil; NIK/KK tidak ada di instrumen).

### 4.10 Action

| Action | Metode | Peran | Fungsi |
|---|---|---|---|
| `instrumen_gw_impor` | POST | GURU (guru wali) | Body `{mode: 'pratinjau'|'simpan', rows: string[][], nama_file?: string}`. Respons: `{mode, academic_year, semester, ringkasan: {baru, timpa, lewati, galat, total}, peringatan_file: string[], baris: [{baris, status, nisn, nama_form, nama, student_id?, cap_waktu, riasec_kode?, kompetensi_kode?, gaya_belajar?, rencana_lulus?, field?: string[], pesan: string[], peringatan: string[]}], impor_id?}` |

Tidak ada action terpisah untuk membaca respons; data ditambahkan ke action yang sudah ada (§4.11).

### 4.11 Perubahan pada file yang ada

| File | Perubahan |
|---|---|
| `api/index.php` | `require_once __DIR__ . '/instrumen_guru_wali.php';` + `case 'instrumen_gw_impor': handleInstrumenGwImpor($db); break;` (cek dulu nama action belum dipakai di keempat file endpoint) |
| `api/profil_murid.php` | `profilMuridBarisAsesmen`: decode `kegiatan_dikuasai`, `mapel_pilihan`, `mapel_pendukung` (JSON → array), cast `kompetensi_*` ke float; `profilMuridKumpulkan`: tambah `instrumen` = daftar respons murid (`[{id, academic_year, semester, cap_waktu, guru_nama, diimpor_pada, jarak_kategori, transportasi, jawaban, judul}]`, terbaru dulu) bila tabel ada; `cetak_profil_murid`: baris tambahan §3.2. `profilMuridPastikanTabel` memanggil `instrumenGwPastikanSkema` bila fungsi ada |
| `api/guru_wali.php` | `guru_wali_dampingan_saya`: tambah per murid `instrumen_cap_waktu` (null bila belum) untuk periode aktif, dan `ringkasan.instrumen_terisi` |
| `assets/` + `app.html` | Bundle baru `assets/index-CGWL69P7-instrumengw1.js` dari `guruwali1` lewat `docs/patches/apply-instrumen-guru-wali-bundle.js` |

`PM_ASESMEN_FIELD`, template asesmen, `profil_murid_asesmen_impor`, dan `profil_murid_asesmen_simpan` **tidak diubah**.

## 5. Kontrak frontend (patch bundle)

Script `docs/patches/apply-instrumen-guru-wali-bundle.js` (pola `apply-guru-wali-bundle.js`): baca `assets/index-CGWL69P7-guruwali1.js`, terapkan string-replacement dengan pengecekan jangkar (gagal keras bila jangkar tidak ditemukan atau ditemukan > 1), tulis `assets/index-CGWL69P7-instrumengw1.js`, arahkan `app.html`. Hanya memakai kelas Tailwind yang sudah ada di `assets/index-CC1PBzaO.css`.

1. **Murid Dampingan**: kolom "Formulir", ringkasan "X dari Y", tombol impor, modal impor (pilih file → pratinjau → simpan) memakai SheetJS bundel (`C_` + `Jl.sheet_to_json(ws, {header: 1, raw: true, defval: ''})`, setiap sel `String(v)`); sheet pertama; file > 2 MB ditolak di browser.
2. **Profil Murid 360°**: field baru di seksi asesmen, Kompetensi RIASEC di samping Minat, "menurut murid" di Identitas, panel lipat "Jawaban murid" (dirender sebagai teks, tanpa HTML).
3. Teks UI berbahasa Indonesia.

## 6. File dan interface terkait

| Jenis | Path / nama |
|---|---|
| Spesifikasi terkait | `docs/specs/profil-murid-360.md`, `docs/specs/penugasan-guru-wali.md` |
| Backend baru | `api/instrumen_guru_wali.php` |
| Backend diubah | `api/index.php`, `api/profil_murid.php`, `api/guru_wali.php` |
| Helper dipakai | `requireAuth`, `loadFreshUser`, `errorResponse`/`jsonResponse`, `getRequestBody`, `getActiveAcademicPeriod`, `getSemesterDateRange` (`api/db.php`); `guruWaliDampinganIds`; `profilMuridSel`, `profilMuridNormalNomor`, `profilMuridKodeRiasec`, `profilMuridDaftarMapel`, `profilMuridAmbilMurid`, `profilMuridPastikanTabel`, `rekapFetchAll` |
| Tabel | `student_asesmen` (kolom baru), `student_instrumen_respons` (baru), `student_import_log` (enum), `student_biodata` (isi bersyarat), `guru_wali_penugasan` & `students` (baca) |
| Frontend | `app.html`, `assets/index-CGWL69P7-instrumengw1.js`, `docs/patches/apply-instrumen-guru-wali-bundle.js` |
| Dokumen patch | `docs/patches/instrumen-guru-wali.md` (catatan patch, dibuat saat implementasi) |
| Uji | `docs/patches/uji-instrumen-guru-wali-seed.sql`, `-cleanup.sql`, `uji-instrumen-guru-wali-api.mjs`, `uji-instrumen-guru-wali-unit.php`, `uji-instrumen-guru-wali-kasus.json` (baris sintetis) |
| Data contoh | `docs/asesmen-murid-dampingan.xlsx` |
| CLAUDE.md | Tambah baris tabel domain untuk `api/instrumen_guru_wali.php` |

## 7. Edge cases

| Kasus | Perilaku |
|---|---|
| NISN kehilangan nol di depan (CSV dibuka di Excel: `114063177`) | `profilMuridNormalNomor` mem-pad 8–9 digit ke 10 + peringatan |
| NISN dalam notasi ilmiah (`1.14E+09`) | `GALAT` dari `profilMuridNormalNomor` |
| NISN milik murid dampingan guru lain / murid nonaktif / tidak ada | `LEWATI` "Bukan murid dampingan aktif Anda" (tidak membedakan alasan, tidak membocorkan nama) |
| Murid dampingan baru dipindah ke guru lain antara pratinjau dan simpan | Simpan menghitung ulang → baris jadi `LEWATI` |
| Murid naik kelas setelah mengisi | `class_id/class_name` = kelas saat impor (sama dengan perilaku impor template) |
| Murid mengisi dua kali | Terbaru dipakai (§4.3 langkah 6) |
| Impor ulang file yang sama | Idempoten: Cap waktu sama dengan yang tersimpan → `TIMPA` dengan nilai identik |
| File lama diimpor setelah file baru | Respons lebih lama dari yang tersimpan → `LEWATI` (langkah 7) |
| Diisi Juni (Genap), diimpor saat Ganjil aktif | `LEWATI` di luar periode aktif |
| `active_semester` belum diganti ADMIN saat semester baru mulai | Semua baris `LEWATI` di luar periode; pesan menyebut periode aktif agar guru menghubungi ADMIN |
| Form diubah: teks pertanyaan diganti tetapi kode tetap | Tetap terbaca (cocok kode) |
| Form diubah: opsi gaya belajar/kegiatan/rencana diganti | Opsi tidak dikenal → peringatan; bagian itu bisa tidak diskor. Kunci harus diperbarui di kode (konstanta) |
| Kolom grid pernyataan hilang sebagian | Skor dihitung dari butir terjawab per tipe; tipe tanpa butir → RIASEC tidak diskor |
| A45 > 8 atau < 8 pilihan (form lama tanpa validasi) | Peringatan, tetap diskor |
| Opsi "Lainnya" A35/A21 berisi koma | A35 disimpan sebagai teks utuh; A21 utuh (dipotong 100/50) |
| Emoji / karakter non-Latin (murid bisa mengetik emoji di jawaban teks) | utf8mb4; ditampilkan sebagai teks |
| Teks berisi `<script>` | Disimpan apa adanya, di-escape di React dan `htmlspecialchars` di cetak |
| Asesmen periode ini sudah diisi wali kelas manual | Field instrumen menimpa; `catatan_karir`, `jurusan_tujuan`, dan field yang tidak dihasilkan tetap |
| Wali kelas lalu mengimpor template untuk periode yang sama | Template menimpa seluruh `PM_ASESMEN_FIELD` (perilaku lama), kolom baru tetap. Pratinjau template tidak berubah di scope ini (§11) |
| `profil_murid_asesmen_hapus` | Menghapus baris asesmen (termasuk kolom baru); respons mentah tetap, status "Formulir: Sudah" tetap tampil |
| Guru wali sekaligus wali kelas | Impor instrumen tetap dibatasi dampingan; hak wali kelas tidak berubah |
| Sheet berisi > 500 respons | 400 "File terlalu besar" |
| File bukan respons form (template SIMANTAP dsb.) | 400 format tidak dikenali (§4.2) |
| Dua guru wali mengimpor murid yang sama bersamaan | Tidak mungkin sah (satu guru wali aktif per murid); `FOR UPDATE` menjaga baris |

## 8. Compatibility

- **Skema**: hanya `ADD COLUMN` dan perluasan `enum` (aditif), dijaga `information_schema`. Instalasi yang belum pernah membuka Profil Murid tetap berjalan; skema dibuat saat pertama dipakai. `install/schema.sql` tidak disentuh (DIGENERATE).
- **Kode lama**: `PM_ASESMEN_FIELD` tidak berubah, jadi impor template, simpan manual, ekspor template, dan `profilMuridValidasiAsesmen` berperilaku sama. Kolom baru ikut terbaca lewat `SELECT *` di `profilMuridAsesmen`; bundle lama mengabaikannya.
- **Guru wali tahap 1**: aturan baca-saja mode `DAMPINGAN` untuk simpan/hapus manual tetap.
- **Updater portal** bisa menimpa `api/profil_murid.php`, `api/guru_wali.php`, `api/index.php`, `app.html`. Patch harus bisa diterapkan ulang; dicatat di `docs/patches/instrumen-guru-wali.md`.
- **Versi form**: konstanta `IGW_INSTRUMEN = 'GW_FORMULIR_AWAL'` dan kunci opsi berada di satu tempat; form tahun berikutnya dengan kode sama tetap terbaca.
- Rollback: kolom/tabel baru tidak mengganggu kode lama bila file baru dihapus.

## 9. Di luar scope

- Menarik respons langsung dari Google Forms/Sheets API, sinkronisasi otomatis, atau tautan form per guru yang dibuat SIMANTAP.
- Membuat atau mengedit instrumen dari SIMANTAP; kunci penskoran yang bisa diubah lewat UI.
- Impor instrumen oleh ADMIN/KS/BK/wali kelas, atau untuk murid non-dampingan.
- Edit manual asesmen oleh guru wali, dan form edit manual untuk kolom baru (wali kelas/BK belum bisa mengedit kolom baru secara manual).
- Menambah kolom baru ke template asesmen SIMANTAP.
- Radar kompetensi 8 dimensi, target akademik, jurnal pendampingan (tahap 3).
- Menulis ke periode selain periode aktif, atau riwayat lebih dari satu respons per periode.
- Membuat baris `student_biodata` dari jawaban murid.
- Laporan agregat/statistik RIASEC per kelas atau sekolah.
- Perbaikan celah keamanan lama di CLAUDE.md (dicatat, tidak diperbaiki di sini).

## 10. Pengujian dan verifikasi

### 10.1 Seed `docs/patches/uji-instrumen-guru-wali-seed.sql` / `-cleanup.sql`

Prefiks id `uji_igw_`. Isi: 2 guru (`uji_igw_gw1` dengan 4 dampingan aktif, `uji_igw_gw2` dengan 1), 1 akun KS, 1 wali kelas, 6 murid (termasuk 3 NISN dari file contoh: `0099200001`, `0099200002`, `0099200003`; 1 murid nonaktif; 1 murid dampingan `gw2`), `student_biodata` untuk 1 murid dengan `jarak_rumah_km` NULL dan `alat_transportasi` terisi, asesmen manual periode aktif untuk 1 murid (dengan `catatan_karir` dan `jurusan_tujuan`). Seed menyetel periode aktif ke TA/semester yang mencakup 2026-11-13. Cleanup menghapus semua baris berprefiks dan mengembalikan `system_config`.

### 10.2 Uji API `docs/patches/uji-instrumen-guru-wali-api.mjs`

Node ≥ 18, login per peran, membaca `docs/asesmen-murid-dampingan.xlsx` lewat kasus JSON yang sudah dikonversi (`uji-instrumen-guru-wali-kasus.json`: header 66 kolom + 3 respons asli + baris sintetis). Kasus minimal:

1. Peran: KS, ADMIN, wali kelas non-guru-wali, TATA_USAHA → 403; GURU tanpa dampingan → 403; GET → 405.
2. File tanpa `[A01]` → 400; > 500 baris → 400; > 120 kolom → 400.
3. Pratinjau file contoh oleh `gw1` → 3 `BARU`/`TIMPA` sesuai seed; tidak ada tulisan ke database (hitung baris sebelum/sesudah).
4. Nilai skor respons 1 (A. Rizki): RIASEC, kompetensi, V/A/K, kode, `rencana_lulus = KERJA`, sesuai nilai yang dihitung manual di file kasus.
5. Baris sintetis: A00 kosong → `LEWATI`; NISN murid `gw2` → `LEWATI` tanpa nama DB; murid nonaktif → `LEWATI`; Cap waktu Juni 2026 → `LEWATI`; Cap waktu tidak valid → `GALAT`; NISN 9 digit → diproses + peringatan; dua respons murid sama → terbaru dipakai; A45 7 pilihan → peringatan; opsi gaya belajar asing → peringatan.
6. Simpan → `student_asesmen` terisi; murid dengan asesmen manual: `catatan_karir`/`jurusan_tujuan` tetap, field instrumen berubah, `sumber = INSTRUMEN`, log `INSTRUMEN`; `student_instrumen_respons` 1 baris per murid; `student_import_log` 1 baris `INSTRUMEN`.
7. Biodata: `jarak_rumah_km` terisi titik tengah, `alat_transportasi` tidak berubah; murid tanpa biodata tidak dibuatkan baris.
8. Impor ulang → idempoten; impor file dengan Cap waktu lebih lama → `LEWATI`.
9. `profil_murid_get` (KS dan `gw1`) memuat kolom baru dan `instrumen[0].jawaban`; `guru_wali_dampingan_saya` memuat `instrumen_cap_waktu`.
10. `gw1` memanggil `profil_murid_asesmen_simpan` → tetap 403.
11. Impor template oleh wali kelas setelah instrumen → kolom baru tetap ada.

### 10.3 Uji unit `docs/patches/uji-instrumen-guru-wali-unit.php`

CLI tanpa database, untuk fungsi murni: `instrumenGwPetaKolom` (header contoh, kode ganda, grid salah blok), `instrumenGwSkorRiasec` (semua Suka, semua Tidak suka, seri, butir hilang, kunci 42 butir lengkap: setiap nomor 1–42 tepat satu tipe dan setiap tipe 7 butir; kompetensi 24 kegiatan tepat 4 per tipe), `instrumenGwPetakan` (VAK seri, opsi asing, A60 semua opsi, A20 semua rentang dengan `-` dan `–`, pemotongan teks, mapel > 3), `instrumenGwCapWaktu` (serial `46339.768541458332` → `2026-11-13 18:26:42`, `13/11/2026 18:26:42`, `11/13/2026`, `05/11/2026` + asumsi, tanggal invalid, masa depan).

### 10.4 Lint

```
php -l api/instrumen_guru_wali.php
php -l api/profil_murid.php
php -l api/guru_wali.php
php -l api/index.php
php -l docs/patches/uji-instrumen-guru-wali-unit.php
php docs/patches/uji-instrumen-guru-wali-unit.php
node --check docs/patches/apply-instrumen-guru-wali-bundle.js
node --check assets/index-CGWL69P7-instrumengw1.js
node --check docs/patches/uji-instrumen-guru-wali-api.mjs
```

### 10.5 Verifikasi end-to-end (manual, di browser)

Prasyarat: dev mode (`api/config.local.php` dengan `SIMANTAP_DEV_MODE`, `api/install.lock`), seed dijalankan, bundle `instrumengw1` aktif (hard refresh).

1. Login sebagai `gw1` → Murid Dampingan: kolom "Formulir" = Belum untuk semua, ringkasan "0 dari 4".
2. Impor → pilih `docs/asesmen-murid-dampingan.xlsx` → pratinjau menampilkan 3 baris, Cap waktu 13/11/2026, kode RIASEC/kompetensi/gaya belajar terisi, tidak ada peringatan file.
3. Simpan → toast, kolom Formulir = "Sudah (13/11/2026)" untuk 3 murid, ringkasan "3 dari 4".
4. Buka Profil Murid salah satu murid → Minat & Kompetensi RIASEC, gaya belajar, bakat, keterampilan target, kegiatan (8 chip), mapel pilihan/pendukung, alasan, "Jawaban murid" lengkap 42 pernyataan; tombol edit asesmen tetap tidak tersedia.
5. Unduh sheet respons sebagai CSV, impor → hasil sama (status `TIMPA`, nilai identik).
6. Login wali kelas murid itu → profil memuat field baru; edit manual asesmen → field baru dan "Jawaban murid" tetap.
7. Login KS → Profil Murid sama terbaca; menu impor formulir tidak muncul untuk KS.
8. Cetak profil → baris kompetensi, bakat, keterampilan, alasan tercetak; jawaban mentah tidak.
9. Tab Identitas murid tanpa biodata → "menurut murid: …".
10. Jalankan cleanup SQL.

## 11. Risiko, trade-off, dan temuan

| Risiko / trade-off | Penanganan |
|---|---|
| Kunci RIASEC/VAK/kompetensi tertanam di kode; perubahan form oleh sekolah memutus penskoran tanpa disadari | Pencocokan per kode butir + peringatan opsi/kolom tidak dikenal di pratinjau; kunci dikumpulkan dalam konstanta + uji unit memeriksa kelengkapan kunci |
| Instrumen dipakai lintas sekolah dengan teks opsi berbeda | Di luar scope; spesifikasi mencatat instrumen acuan SMKN 1 Amuntai. Upstream perlu memutuskan instrumen baku |
| Skor minat biner (Suka/Tidak suka) kasar; banyak seri pada kode RIASEC | Seri dipecah urutan RIASEC (konsisten dengan fitur lama); skor mentah 6 tipe tetap tampil agar guru menafsirkan sendiri |
| Gaya belajar 5 soal: seri 2-2-1 sering terjadi; pemecahan V,A,K berpihak ke V | Konsisten dengan aturan lama; skor V/A/K tetap ditampilkan. Dicatat sebagai keterbatasan instrumen |
| Periode dari Cap waktu vs `active_semester` yang diatur manual | Memakai rentang `getSemesterDateRange` dari periode aktif; jika ADMIN terlambat mengganti semester, baris dilewati dengan pesan jelas |
| Form "diisi sekali di awal tahun ajaran", tetapi asesmen per semester | Semester Genap tidak otomatis terisi; Profil Murid menampilkan asesmen terbaru seperti biasa. Menyalin ke semester berikutnya di luar scope |
| Ambiguitas tanggal CSV (d/m vs m/d) | Asumsi d/m + peringatan; UI menyarankan unduhan .xlsx (serial tak ambigu) |
| "Timpa yang terisi saja" membuat satu baris asesmen berisi campuran sumber | `logs` mencatat field yang ditulis per aksi; `sumber` = penulis terakhir; `instrumen_respons_id` menunjuk respons mentah |
| Template wali kelas menimpa hasil instrumen tanpa peringatan | Diterima di scope ini (perilaku template lama); usulan lanjutan: pratinjau template menandai "akan menimpa hasil formulir murid" |
| Data pribadi (kesulitan belajar, alasan, kondisi ekonomi "tidak punya kuota") terbaca semua pemegang akses profil | Sesuai pernyataan A00 (guru wali, wali kelas, BK); KS/ADMIN juga melihat — perlu dicek apakah teks persetujuan form perlu menyebut KS |
| Mengisi biodata dari jawaban murid mencampur sumber Dapodik | Hanya bila kosong + hanya untuk baris Dapodik yang sudah ada; Dapodik berikutnya menang |
| Temuan: `student_import_log.class_id` di impor instrumen null (impor tidak per kelas) | Kolom sudah nullable |
| Temuan keamanan lama (CLAUDE.md): `requireAuth` hanya membaca session | Fitur ini memakai `loadFreshUser` + `guruWaliDampinganIds` dari database, sehingga perubahan peran/penugasan langsung berlaku |

## 12. Acceptance criteria

1. Guru wali dengan dampingan aktif dapat mengunggah `.xlsx` atau `.csv` sheet respons "Formulir Awal Murid Guru Wali", melihat pratinjau per baris, dan menyimpannya; peran lain menerima 403 dan tidak melihat tombol impor.
2. Kolom dikenali dari kode `[Axx]` (dan `[NN.` untuk grid), tidak bergantung pada urutan maupun teks pertanyaan; file tanpa Cap waktu/A00/A01 ditolak 400.
3. Skor RIASEC minat, kompetensi RIASEC, V/A/K, kode, dan gaya belajar untuk 3 respons di `docs/asesmen-murid-dampingan.xlsx` sama persis dengan nilai yang dihitung manual dari kunci di §4.4–4.5.
4. Hanya respons murid dampingan aktif pengimpor, dengan persetujuan A00, dan Cap waktu dalam periode aktif yang ditulis; lainnya `LEWATI`/`GALAT` dengan alasan, tanpa membocorkan data murid non-dampingan.
5. Respons ganda: hanya yang terbaru ditulis; respons yang lebih lama dari respons tersimpan dilewati.
6. Field yang tidak dihasilkan instrumen (termasuk `catatan_karir`, `jurusan_tujuan`) tidak berubah; field yang dihasilkan menimpa nilai lama; log mencatat aksi `INSTRUMEN` beserta daftar field.
7. Jawaban mentah lengkap tersimpan satu per murid per periode dan tampil di panel "Jawaban murid" Profil Murid untuk semua pemegang akses profil.
8. `jarak_rumah_km`/`alat_transportasi` hanya diisi bila baris biodata ada dan field kosong.
9. Kolom "Formulir" dan ringkasan "X dari Y" tampil di Murid Dampingan; field baru tampil di Profil Murid dan cetakannya.
10. Impor template, simpan/hapus manual, dan baca-saja guru wali berperilaku sama seperti sebelum fitur ini (uji 10.2 nomor 10–11 lulus).
11. Semua perintah §10.4 lulus, uji API §10.2 dan unit §10.3 lulus, checklist E2E §10.5 selesai.

## 13. Hasil implementasi (2026-11-14)

- Semua acceptance criteria §12 terpenuhi. Rincian file, hash, dan hasil uji: `docs/patches/instrumen-guru-wali.md`.
- Uji unit 88 lulus; uji API 66 lulus; regresi Profil Murid dan Guru Wali (unit + API) lulus; E2E di Chrome headless lulus tanpa error konsol.
- Penyimpangan kecil dari draf: `jawaban_json` memakai bentuk `{jawaban, judul}` (§4.1b); `instrumen_gw_impor` mengembalikan juga `disimpan` (jumlah respons yang ditulis) dan `kelas` per baris (hanya untuk murid dampingan); sel dibatasi 1.000 karakter (bukan 500 seperti impor template) agar jawaban paragraf murid tidak terpotong sebelum divalidasi.
