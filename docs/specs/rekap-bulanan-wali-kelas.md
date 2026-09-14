# Spesifikasi: Cetak Rekap Bulanan Wali Kelas (PDF ber-Kop Sekolah)

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/rekap-bulanan-wali-kelas.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch wali kelas (`docs/patches/wali-kelas-kesiswaan.md`) |
| Tanggal | 2026-09-14 |
| Jalur rilis | Patch lokal pada bundle ini **dan** usulan perubahan ke upstream BTIKP (pola sama dengan patch wali kelas) |
| Spesifikasi terkait | `docs/specs/menu-kesiswaan-wali-kelas.md` (definisi wali kelas, kunci kelas, helper otorisasi) |

## 1. Latar belakang dan kebutuhan bisnis

Setiap akhir bulan, guru ASN yang menjadi wali kelas mengunggah bukti kinerja ke **Laporan Kinerja bulanan ASN** (e-Kinerja). Salah satu bukti tugas tambahan wali kelas adalah rekap kehadiran dan kedisiplinan siswa kelas perwaliannya.

Data ini sudah tercatat di SIMANTAP (presensi harian, siswa terlambat, izin keluar, pelanggaran, panggilan orang tua), tetapi saat ini tidak ada dokumen yang bisa dicetak. Wali kelas harus menyalinnya secara manual.

Tujuan: wali kelas bisa menghasilkan **dokumen PDF A4 ber-Kop Sekolah** berisi rekap bulanan kelas perwaliannya, lengkap dengan tanda tangan wali kelas dan "Mengetahui, Kepala Sekolah". Dokumen itu siap diunggah ke e-Kinerja.

Keputusan dari wawancara:

| Topik | Keputusan |
|---|---|
| Cara menghasilkan PDF | Backend PHP merender **halaman HTML siap cetak** (A4). User menekan tombol Cetak, lalu memilih "Simpan sebagai PDF" di dialog cetak browser. Tanpa library PDF baru. |
| Isi dokumen | (A) Ringkasan per siswa + (B) lampiran detail kejadian: terlambat, izin keluar, pelanggaran, panggilan orang tua |
| Tanda tangan | Kiri: "Mengetahui, Kepala Sekolah". Kanan: "Wali Kelas" |
| Lokasi UI | Tombol di halaman **Dashboard Kelas Saya** |
| Otorisasi | Wali kelas untuk kelas perwaliannya. KEPALA_SEKOLAH dan ADMIN untuk kelas mana pun lewat URL/API, tanpa UI baru |
| Periode | Bulan mana pun dalam tahun ajaran aktif, sampai bulan berjalan. Bulan berjalan diberi keterangan "Data s.d. tanggal …" |
| Insiden Alpha | Tidak dihitung ganda sebagai pelanggaran. Poinnya tetap masuk "Poin bulan ini" |
| Status insiden | Semua status dicantumkan beserta labelnya |
| Hari efektif | Diisi wali di halaman cetak. Default = jumlah tanggal yang punya presensi |
| Daftar siswa | Siswa aktif di kelas + siswa yang punya data di kelas itu pada bulan tersebut tetapi sudah pindah/keluar |
| Kertas | A4 portrait |
| Arsip | Tidak ada. Dokumen dibuat on-the-fly, tanpa perubahan skema |
| Tempat & tanggal TTD | Default tanggal = hari terakhir bulan (atau hari ini untuk bulan berjalan). Kota default dari config opsional `kop_kota`. Keduanya bisa diedit di halaman cetak |
| Bagian kosong | Tetap ditampilkan dengan baris "Nihil" |
| Panggilan orang tua | Masuk sebagai subbagian ringkas |

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Frontend (`assets/index-CGWL69P7-wali1.js`)

- **Dashboard Kelas Saya** adalah komponen `dot` (`function dot({user:e,onNavigate:t,activeSemester:n})`). Isinya:
  - toggle "Smt Ganjil/Smt Genap" dan tombol "Perbarui Data";
  - kartu statistik, lalu tab "📊 Rekap Bulanan", "👥 Per Siswa", dan "⚠️ Insiden";
  - data dari `get_homeroom_dashboard&class_id=<class_homeroom>&semester=<1|2>` dan `get_incidents&class=<class_homeroom>&semester=<1|2>`;
  - tab Rekap Bulanan hanya menampilkan total H/S/I/A per bulan tanpa rincian siswa per bulan, dan **tidak ada tombol cetak**.
- Semua fitur cetak lain di bundle memakai `window.print()` pada halaman SPA dengan kelas CSS `no-print`. Kop dibaca dari `get_system_config`. Tidak ada jsPDF, html2canvas, atau library PDF lain.
- Variabel `K$` di `dot` berisi URL dasar API (`api/index.php`).

### 2.2 Backend

- **Kop sekolah** tersimpan di `system_config` dan dikelola admin lewat `save_system_config`:
  - `kop_instansi`, `kop_dinas`, `kop_sekolah`, `kop_alamat`, `kop_telp`, `kop_email`, `kop_website`;
  - `kop_logo_kiri`, `kop_logo_kanan` (path relatif root deploy, mis. `uploads/logos/logo_kiri_<ts>.png`);
  - `kop_kepsek_nama`, `kop_kepsek_nip`, `kop_npsn`, `kop_nss`.
  - Belum ada key untuk kota.
- **Presensi harian:** tabel `attendance_records`, satu baris per siswa per tanggal (`id` unik), dengan status `HADIR/SAKIT/IZIN/ALFA`. Sumbernya `MANUAL` (Presensi Kelas) atau `JURNAL` (`save_journal`). `class_id` berisi `classes.id`. Kolom `academic_year` pada data lama bisa berisi `2025/2026` hardcoded (lihat spesifikasi wali kelas §2.3).
- **Insiden:** tabel `incidents` dengan `type` ∈ `VIOLATION`, `LATE` (terlambat), `OUT` (izin keluar), `PARENT_CALL`.
  - `className` berisi **nama** kelas (format bisa beragam), `details` berisi keterangan, dan `violation_code` merujuk ke `violation_types`.
  - Alpha dari presensi otomatis membuat insiden `id = inc_att_<siswa>_<tanggal>`, `type='VIOLATION'`, `violation_code='ALFA'`, `status='REPORTED'`.
  - Status yang dipakai bundle: `PENDING`, `REPORTED`, `WALI_KELAS_APPROVED`, `BP_PROCESS`, `PARENT_INVITED`, `RESOLVED`, `REJECTED`.
- **Panggilan orang tua:** tabel `parent_calls` (`class_name`, `student_id`, `total_points`, `trigger_type` AUTO/MANUAL, `scheduled_date`, `status`, `created_at`).
- **Siswa:** tabel `students` (`class_id`, `class_name`, `nisn`, `status`). Nilai `status` bisa enum lama `AKTIF` atau VARCHAR `Aktif`/`Pindah`/…, karena `student_*` di TU mengubah kolom ini.
- **Otorisasi:** helper di `api/kesiswaan_access.php`.
  - `kesiswaanScope($db, $user, 'attendance_read')` menghasilkan ADMIN/KS → `ALL`, GURU wali non-BK → `CLASS`, peran lain (termasuk BK) → `DENY`. Matriks ini **sama persis** dengan kebutuhan fitur ini.
  - Helper lain yang dipakai ulang: `resolveClassRef`, `normalizeClassKey`, `classInScope`, `activeAcademicPeriod`.
- `requireAuth()` dan `errorResponse()` di `api/db.php` selalu membalas **JSON**. Tidak ada endpoint yang membalas HTML.
- Tidak ada action bernama `cetak_*`/`rekap_*` di keempat file endpoint, sehingga nama baru aman dari shadowing.

## 3. Expected behavior

### 3.1 Alur pengguna (wali kelas)

1. Wali membuka **Kelas Saya → Dashboard <kelas>**. Di sebelah tombol "Perbarui Data" ada tombol baru **"Cetak Rekap Bulanan"**.
2. Tombol membuka **tab baru** ke `api/index.php?action=cetak_rekap_bulanan&class_id=<class_homeroom>&month=<YYYY-MM default>&auto=1`. Bulan default:
   - tanggal 1–10: bulan lalu (wali biasanya mencetak di awal bulan berikutnya);
   - tanggal 11 ke atas: bulan berjalan;
   - karena ada `auto=1`, bulan default yang jatuh di luar tahun ajaran aktif digeser backend ke bulan valid terdekat (§3.3).
3. Tab baru menampilkan **halaman pratinjau**:
   - **Panel kontrol** (tidak ikut tercetak): pilihan bulan, isian hari efektif, kota, tanggal tanda tangan, tombol "Terapkan" (submit GET ke URL yang sama), tombol **"Cetak / Simpan PDF"** (`window.print()`), dan petunjuk singkat "Pilih tujuan *Simpan sebagai PDF*, ukuran kertas A4, matikan *Header dan footer*".
   - **Info pratinjau** (tidak ikut tercetak): daftar tanggal Senin–Sabtu sampai hari ini dalam bulan itu yang **belum ada presensi** untuk kelas ini, dengan catatan "Abaikan bila hari libur."
   - **Dokumen** A4 (§3.4), tampil seperti kertas.
4. Wali menekan "Cetak / Simpan PDF". Nama file default berasal dari `<title>`: `Rekap_Bulanan_<NamaKelas>_<YYYY-MM>`, dengan karakter di luar `[A-Za-z0-9_-]` diganti `_`.

KEPALA_SEKOLAH dan ADMIN bisa membuka URL yang sama dengan `class_id` kelas mana pun. Tidak ada tombol UI baru untuk mereka.

### 3.2 Matriks akses

| Peran | `cetak_rekap_bulanan` |
|---|---|
| Anonim / session habis | 401 (halaman HTML "Sesi Anda telah berakhir. Silakan login ulang.") |
| ADMIN | Semua kelas |
| KEPALA_SEKOLAH | Semua kelas |
| GURU non-BK dengan `class_homeroom` | Hanya kelas perwalian. Kelas lain → 403 |
| GURU BK, GURU tanpa `class_homeroom`, TATA_USAHA, peran lain | 403 |
| User sudah dihapus dari DB | 401 |

- Scope ditentukan oleh `kesiswaanScope($db, $user, 'attendance_read')`, yaitu **data user dibaca ulang dari DB** pada setiap request.
- Wali tanpa parameter `class_id` otomatis memakai kelas perwaliannya (`scope['classId']`).
- ADMIN/KS tanpa `class_id` → 400.

### 3.3 Kontrak endpoint `cetak_rekap_bulanan`

- **Metode:** `GET` (read-only). Metode lain → 405.
- **Respons:** `Content-Type: text/html; charset=utf-8`, dengan header `Cache-Control: no-store` dan `X-Content-Type-Options: nosniff`.
- **Error:** ditampilkan sebagai **halaman HTML sederhana** berbahasa Indonesia dengan HTTP status yang sesuai, bukan JSON, karena dibuka di tab baru. Karena itu handler **tidak** memakai `requireAuth()`/`errorResponse()`; ia memakai `getCurrentUser()` dan pengecekan sendiri. Pesan dan status sama dengan matriks §3.2. Pengecualian yang boleh: `loadFreshUser` tetap membalas 401 JSON bila user terhapus. Idealnya handler memeriksa keberadaan user dengan query sendiri lebih dulu agar balasannya tetap HTML.

**Parameter:**

| Parameter | Wajib | Validasi | Default |
|---|---|---|---|
| `class_id` | Wali: tidak. ADMIN/KS: ya | Id atau nama kelas dalam format apa pun. Diselesaikan dengan `resolveClassRef`. Wali: `classInScope` harus benar, jika tidak → 403 | Wali: kelas perwalian |
| `month` | Ya | Regex `^\d{4}-(0[1-9]\|1[0-2])$`. Harus berada di rentang tahun ajaran aktif **dan** ≤ bulan berjalan (zona `APP_TIMEZONE`); jika tidak → 400 dengan pesan rentang yang valid | – |
| `auto` | Tidak | Hanya nilai `1` yang berarti. Jika `auto=1`, `month` (yang formatnya valid) digeser ke bulan valid terdekat, bukan ditolak, dan panel kontrol menampilkan info. Dipakai tombol di Dashboard Kelas; form "Terapkan" tidak mengirimnya | – |
| `hari_efektif` | Tidak | Bilangan bulat 1 s.d. jumlah hari dalam bulan; jika tidak → 400 | Jumlah tanggal berbeda yang punya presensi untuk kelas ini di bulan itu. Jika 0, tampil "-" |
| `kota` | Tidak | Teks, di-trim, maks. 50 karakter | `system_config.kop_kota`, atau kosong |
| `tanggal` | Tidak | `YYYY-MM-DD` valid; jika tidak → 400 | Hari terakhir bulan. Jika bulan berjalan: hari ini |

**Rentang tahun ajaran aktif:** dari `activeAcademicPeriod()`. Untuk `YYYY/YYYY+1`, rentangnya **Juli YYYY s.d. Juni YYYY+1**. Bila formatnya tidak dikenali, validasi rentang dilewati dan hanya aturan "≤ bulan berjalan" yang berlaku.

**Semester** yang ditampilkan di dokumen diturunkan dari bulan (Juli–Desember = Ganjil, Januari–Juni = Genap), **bukan** dari `active_semester`, agar bulan lampau tercetak benar.

### 3.4 Isi dokumen (A4 portrait)

Semua teks keluaran di-escape dengan `htmlspecialchars`. Font sans-serif sistem, isi 9–10pt, margin `@page { size: A4 portrait; margin: 15mm 15mm 15mm 20mm; }`. Header tabel diulang di setiap halaman (`thead { display: table-header-group; }`), dan baris tabel tidak terpotong (`tr { page-break-inside: avoid; }`).

**1. Kop sekolah**
- Logo kiri dan logo kanan dari `kop_logo_kiri`/`kop_logo_kanan`, masing-masing hanya bila terisi. Path relatif diberi prefiks `../` karena halaman dilayani dari `api/`. URL absolut `http(s)://` dipakai apa adanya. Nilai lain (mis. `javascript:`) diabaikan.
- Teks tengah per baris: `kop_instansi`, `kop_dinas`, **`kop_sekolah`** (paling besar dan tebal), `kop_alamat`, lalu satu baris gabungan "Telp. … · Email … · Website …" yang hanya memuat bagian yang terisi.
- Garis ganda di bawah kop.
- Key kosong dilewati. Jika seluruh kop kosong, tampil peringatan di panel kontrol (tidak tercetak): "Kop sekolah belum diatur. Minta Admin mengisi Pengaturan Kop Surat."

**2. Judul dan identitas**
- Judul: **REKAPITULASI BULANAN KEHADIRAN DAN KEDISIPLINAN PESERTA DIDIK**.
- Tabel identitas:
  - Kelas: `classes.name`;
  - Wali Kelas;
  - Bulan: nama bulan Indonesia + tahun, mis. "September 2026";
  - Tahun Ajaran / Semester: mis. "2026/2027 / Ganjil";
  - Hari Efektif;
  - Hari Presensi Tercatat.
- Jika bulan berjalan, tambahkan baris *"Data s.d. tanggal 14 September 2026"*.

**3. Bagian A: Rekap Kehadiran dan Kedisiplinan per Siswa**

| Kolom | Isi |
|---|---|
| No | Urutan |
| NISN | `students.nisn`, atau "-" |
| Nama Siswa | Diurutkan A–Z |
| H, S, I, A | Jumlah `attendance_records` siswa di kelas ini dalam bulan itu per status (`ALFA` → kolom A) |
| % Hadir | `H ÷ (H+S+I+A) × 100`, 1 desimal. Jika penyebut 0, tampil "-" |
| Terlambat | Jumlah insiden `LATE` |
| Izin Keluar | Jumlah insiden `OUT` |
| Pelanggaran | Jumlah insiden `VIOLATION` **selain** Alpha otomatis (`violation_code = 'ALFA'` atau `id LIKE 'inc_att\_%'`) |
| Poin | Jumlah `points` semua insiden siswa bulan itu (termasuk Alpha dan terlambat) |
| Ket. | Kosong untuk siswa aktif di kelas. Selain itu salah satu dari: "Pindah ke <kelas>", status siswa (mis. "Keluar", "Lulus"), atau "Data siswa tidak ditemukan" |

- Hitungan insiden dan poin **mengecualikan status `REJECTED`**. Insiden yang ditolak tetap muncul di bagian B dengan label "Ditolak" (lihat §9, asumsi A1).
- Baris terakhir "Jumlah" berisi total H/S/I/A, total Terlambat/Izin Keluar/Pelanggaran/Poin, dan % Hadir kelas (`ΣH ÷ Σ(H+S+I+A)`).

**Aturan pemilihan siswa:**
1. Semua `students` yang kelasnya cocok dengan kelas ini (`normalizeClassKey(class_id)` atau `normalizeClassKey(class_name)` ∈ kunci kelas) dan `UPPER(status) = 'AKTIF'`.
2. Ditambah setiap `student_id` yang punya `attendance_records` (kelas cocok) atau `incidents` (`className` cocok) pada bulan itu tetapi tidak termasuk poin 1.
   - Nama dan NISN diambil dari `students` bila ada.
   - Bila tidak ada, dipakai `student_name`/`studentName` dan `student_nisn` dari record.
   - Insiden dengan `studentId` kosong dikelompokkan berdasarkan `studentName`.

**4. Bagian B: Rincian Kejadian**

Setiap subbagian selalu tampil. Jika tidak ada data, tabel berisi satu baris "Nihil". Semua diurutkan tanggal naik, lalu nama siswa.

| Subbagian | Sumber | Kolom |
|---|---|---|
| B.1 Siswa Terlambat | `incidents.type='LATE'` | No, Tanggal, Nama Siswa, Keterangan (`details`), Poin, Status |
| B.2 Izin Keluar | `incidents.type='OUT'` | No, Tanggal, Nama Siswa, Keterangan, Status |
| B.3 Pelanggaran | `incidents.type='VIOLATION'` tanpa Alpha otomatis | No, Tanggal, Nama Siswa, Jenis (`violation_types.violation_name`, fallback `violation_code`), Keterangan, Poin, Status |
| B.4 Panggilan Orang Tua | `parent_calls` | No, Tanggal Dibuat, Jadwal (`scheduled_date` atau "-"), Nama Siswa, Total Poin, Pemicu (Otomatis/Manual), Status |

- **Filter tanggal:** insiden memakai `incidents.date` di dalam bulan. Panggilan orang tua ikut bila `DATE(created_at)` **atau** `scheduled_date` jatuh di bulan itu.
- **Kecocokan kelas:**
  - insiden: `normalizeClassKey(className)` ∈ kunci kelas;
  - panggilan ortu: `normalizeClassKey(class_name)` ∈ kunci kelas, **atau** `class_name` kosong dan kelas siswa saat ini cocok (setara `parentCallInScope`).
- Insiden `PARENT_CALL` di tabel `incidents` tidak dimasukkan ke bagian B.4. B.4 hanya dari `parent_calls`.
- `details` panjang dipotong ke 200 karakter dengan "…", dan baris baru diubah menjadi spasi.

**Label status** (nilai di luar daftar ditampilkan mentah):

| Status | Label |
|---|---|
| `PENDING`, `REPORTED` | Dilaporkan |
| `WALI_KELAS_APPROVED` | Diproses Wali Kelas |
| `BP_PROCESS` | Diproses BK |
| `PARENT_INVITED` | Orang Tua Dipanggil |
| `RESOLVED` | Selesai |
| `REJECTED` | Ditolak |

Untuk `parent_calls.status`, label yang sama dipakai bila cocok; nilai lain ditampilkan mentah.

**5. Tanda tangan**

Dua kolom, dengan ruang tanda tangan ±20mm:

| Kiri | Kanan |
|---|---|
| (kosong) | `<Kota>, <tanggal Indonesia>` (bila kota kosong, hanya tanggal) |
| Mengetahui, | |
| Kepala Sekolah | Wali Kelas `<nama kelas>` |
| **`kop_kepsek_nama`**, atau garis titik bila kosong | **Nama wali kelas** |
| NIP. `kop_kepsek_nip` (baris dihilangkan bila kosong) | NIP. `users.nip` (baris dihilangkan bila kosong) |

- **Wali kelas** diambil dari DB: user `role='GURU'` yang `normalizeClassKey(class_homeroom)` sama dengan kunci id kelas.
  - Jika pencetaknya wali itu sendiri, pakai datanya.
  - Jika ADMIN/KS dan tidak ada wali, tampil garis titik.
  - Jika ada lebih dari satu kandidat, ambil yang pertama berdasarkan `displayName`.

**6. Footer**: teks kecil "Dicetak dari SIMANTAP pada `<tanggal jam>` WITA oleh `<displayName>`" di akhir dokumen, bukan di setiap halaman.

## 4. Role, authorization, dan data model

- **Tidak ada perubahan skema, tabel baru, atau DDL.**
- Tabel yang dibaca: `system_config`, `classes`, `users`, `students`, `attendance_records`, `incidents`, `violation_types`, dan `parent_calls`. Semuanya read-only.
- **Config opsional baru `kop_kota`:** tidak ada UI. Admin bisa mengisinya lewat SQL (`INSERT … ON DUPLICATE KEY UPDATE`). `save_system_config` hanya meng-upsert key yang dikirim, jadi halaman Pengaturan Kop yang ada tidak akan menghapusnya.
- **Filter bulan memakai rentang tanggal** (`date >= 'YYYY-MM-01' AND date < bulan berikutnya`), **bukan** kolom `academic_year`/`semester`, karena kolom itu tidak konsisten pada data lama.
- **Kecocokan kelas pada `attendance_records`:** `class_id = classes.id` **atau** kunci ter-normalisasi dari `class_id`/`class_name` ∈ kunci kelas. Kunci kelas = `{key(classes.id), key(classes.name)}` hasil `resolveClassRef`, ditambah `scope['classKeys']` untuk wali.
- **Konvensi query:** semua query baru memakai prepared statement. Normalisasi SQL memakai pola `REPLACE(REPLACE(REPLACE(REPLACE(LOWER(x),' ',''),'_',''),'-',''),'.','')` yang sudah ada, dengan kunci di-bind sebagai parameter. Jangan meniru `real_escape_string` dan interpolasi di `get_incidents`.
- **Actor** (footer, otorisasi) diambil dari session/DB, tidak pernah dari parameter.

## 5. File dan interface terkait

### 5.1 Backend

| File | Perubahan |
|---|---|
| `api/rekap_bulanan.php` (**baru**) | Diawali guard `if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }`. Berisi fungsi pengumpul data dan renderer HTML (daftar di bawah). Tidak berisi `switch`. |
| `api/index.php` | Tambah `require_once __DIR__ . '/rekap_bulanan.php';` setelah `kesiswaan_access.php`, dan `case 'cetak_rekap_bulanan': handleCetakRekapBulanan($db); break;` di `switch` utama, sebelum `default:`. |
| `api/kesiswaan_access.php` | Tidak diubah. Helpernya dipakai ulang. |

Fungsi di `api/rekap_bulanan.php` (nama boleh disesuaikan, perilaku wajib sama):
- `handleCetakRekapBulanan(mysqli $db): void`: cek metode, auth, scope, validasi parameter (§3.3), panggil pengumpul data, render, lalu `exit`.
- `rekapHtmlError(int $status, string $message): void`: halaman error HTML, lalu `exit`.
- `rekapAcademicMonthRange(string $activeYear): ?array`: `['YYYY-07', 'YYYY+1-06']` atau `null`.
- `rekapCollectData(mysqli $db, array $classRef, array $classKeys, string $month): array` mengembalikan:
  - `students`: baris ringkasan A;
  - `totals`;
  - `late`, `out`, `violations`, `parent_calls`: baris B.1–B.4;
  - `recorded_dates`: string `Y-m-d` yang punya presensi;
  - `homeroom`: `['name', 'nip']` atau `null`.
- `rekapLoadKop(mysqli $db): array`: key `kop_*`.
- `rekapRender(array $ctx): void`: output HTML lengkap (`<!doctype html>`, CSS cetak inline, panel kontrol `no-print`, dokumen). Tanpa aset eksternal selain logo.
- `rekapStatusLabel(?string $status): string`, `rekapTanggalIndo(string $ymd): string`, `rekapBulanIndo(string $ym): string`.

Pola yang wajib diikuti:
- prepared statement;
- pesan dan label berbahasa Indonesia;
- `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` untuk setiap nilai dinamis, termasuk atribut `value` di form panel kontrol;
- parameter form di-escape dan divalidasi ulang di server.

### 5.2 Frontend (patch bundle lokal)

- **Sumber:** `assets/index-CGWL69P7-wali1.js`, SHA-256 `8e153c7fd69ee8cf789e0f7c8be89f2b06d564f8b4e3b8b799e71eecb01f0d97`.
- **Hasil:** salinan baru `assets/index-CGWL69P7-wali2.js`. File `-wali1.js` dan bundle asli **tidak diubah**.
- **`app.html`** (SHA-256 saat ini `1b862eeefa7770b5fc71ba0e50fe681bd1a2bb89a305b62fd5df3b3e115b034c`) diarahkan ke `-wali2.js`.
- **Skrip penerap:** `docs/patches/apply-rekap-bulanan-bundle.js` (**baru**), dengan pola sama seperti `apply-wali-kelas-bundle.js`: verifikasi hash sumber, anchor tepat 1 kali, tulis salinan, ubah `app.html`, aman dijalankan ulang.

| # | Anchor (string persis, sudah diverifikasi muncul **1 kali** di `-wali1.js`) | Perubahan |
|---|---|---|
| R1 | `" Perbarui Data"]})` (di komponen `dot`, menutup tombol "Perbarui Data") | Setelah anchor, sisipkan sibling `,a.jsxs("button",{onClick:()=>{…},className:…,children:[<ikon printer>," Cetak Rekap Bulanan"]})`. |

Detail R1:
- `onClick` menghitung bulan default (§3.1 langkah 2), lalu `window.open(`${K$}?action=cetak_rekap_bulanan&class_id=${encodeURIComponent(r)}&month=${m}&auto=1`,"_blank")`. Di sini `r` = `class_homeroom` dan `K$` = URL API di `dot`.
- **Pembungkus (diimplementasikan):** tombol "Perbarui Data" adalah anak langsung `div.flex.items-center.justify-between`. R1 membungkus kedua tombol dalam `a.jsxs("div",{className:"flex flex-wrap items-center gap-2",children:[…]})`. Anchor mencakup tombol "Perbarui Data" utuh, dari `a.jsxs("button",{onClick:L,className:"flex items-center gap-2 px-4 py-2 bg-white border …` sampai `" Perbarui Data"]})`, dan terverifikasi muncul tepat 1 kali.
- **Ikon:** komponen ikon printer yang sama dengan tombol "Cetak Rekap (PDF)" yang sudah ada, yaitu `Ss`. Sudah diverifikasi bahwa `Ss` dideklarasikan di top-level bundle dan tidak dideklarasikan ulang di `dot`.
- **Gaya:** mengikuti tombol "Perbarui Data", dengan warna emerald (`bg-emerald-600 text-white hover:bg-emerald-700`).
- **Tombol tidak dirender bila** `r` kosong. Komponen `dot` sudah menampilkan pesan lain dalam kondisi itu.

### 5.3 Dokumen

- `docs/specs/rekap-bulanan-wali-kelas.md` (dokumen ini).
- `docs/patches/rekap-bulanan-wali-kelas.md` (**baru**, dibuat saat implementasi), berisi:
  - daftar file dan hash setelah patch;
  - anchor R1;
  - langkah re-apply setelah auto-update, termasuk urutan terhadap patch wali kelas (§7);
  - rollback;
  - hasil verifikasi.
- `docs/patches/wali-kelas-kesiswaan.md`: tambahkan catatan bahwa `app.html` kini mereferensikan `-wali2.js` dan rollback penuh harus membatalkan patch rekap lebih dulu.

## 6. Di luar scope

- File `.pdf` asli dari server atau klien (FPDF/dompdf/jsPDF), dan unduhan otomatis.
- Integrasi atau unggah otomatis ke e-Kinerja/SKP.
- Arsip, riwayat, log cetak, nomor dokumen, dan tanda tangan digital atau QR verifikasi.
- UI untuk KEPALA_SEKOLAH/ADMIN (pemilih kelas) dan cetak massal semua kelas.
- Field "Kota" di halaman Pengaturan Kop Surat. `kop_kota` diisi via SQL.
- Kalender akademik atau hari libur; hari efektif diisi manual.
- Rekap per semester atau tahunan, presensi per mata pelajaran dari jurnal, absensi upacara, ekskul, dan nilai.
- Perubahan tab "Rekap Bulanan" atau data `get_homeroom_dashboard` yang sudah ada.
- Perbaikan celah yang ditemukan saat analisis: `get_homeroom_dashboard`, `get_class_attendance_summary`, dan `get_homeroom_info` tidak memeriksa auth (PII). Dicatat untuk tiket terpisah (§10).
- Pembatasan transisi status insiden dan celah keamanan lain di `CLAUDE.md`.
- Kombinasi GURU BK + wali kelas (sama dengan spesifikasi wali kelas).

## 7. Compatibility

- **Patch wali kelas:** fitur ini **bergantung** pada patch wali kelas, yaitu `kesiswaan_access.php`, `class_homeroom_name` di session, dan bundle `-wali1.js`. Patch rekap diterapkan **setelah** patch wali kelas. Jika auto-update menimpa keduanya, terapkan ulang wali kelas lebih dulu.
- **Konsumen API lain:** action baru, tidak mengubah action lain, dan tidak ada shadowing (grep `cetak_rekap_bulanan` di `api/*.php` harus menghasilkan tepat 1 `case`).
- **Router:** `case` baru ada di `api/index.php`, sehingga melewati gerbang install, maintenance, dan lisensi seperti action lain. Tidak ada entry point PHP baru yang bisa diakses langsung: `rekap_bulanan.php` punya guard `SIMANTAP_API`.
- **Session:** tab baru same-origin memakai cookie session yang sama (`SameSite=Lax`, navigasi top-level GET).
- **Data lama:**
  - presensi dengan `academic_year` salah tetap terhitung karena filter memakai tanggal;
  - presensi dengan `status=''` (bug lama) tidak masuk H/S/I/A, tetapi tanggalnya tetap terhitung sebagai "tercatat";
  - `students.status` campuran huruf ditangani dengan `UPPER()`.
- **Browser:** diuji di Chrome/Edge versi terbaru (target utama guru) dan Firefox. Safari/iOS tidak menjadi target, tetapi dokumen tetap harus terbaca.
- **PHP 8.2 + mysqli exception:** handler dibungkus `try/catch (Throwable)`. Jika gagal, tampil halaman HTML 500 "Gagal menyusun rekap. Coba lagi atau hubungi admin." tanpa stack trace; detail dicatat via `error_log`.
- **Auto-updater:**
  - update dapat menimpa `api/index.php` dan `app.html`;
  - `rekap_bulanan.php` dan `-wali2.js` mungkin tetap ada, tetapi tidak lagi direferensikan;
  - dampaknya tombol hilang dan action → fallback chain → respons "action tidak dikenal";
  - tidak ada kerusakan data.

## 8. Edge cases

| Kasus | Perilaku yang diharapkan |
|---|---|
| Bulan tanpa presensi dan tanpa insiden | Dokumen tetap tercetak. Bagian A berisi siswa aktif dengan angka 0 dan "-" pada % Hadir. Bagian B.1–B.4 "Nihil". Hari Presensi Tercatat 0, Hari Efektif "-" bila tidak diisi |
| Kelas tanpa siswa aktif dan tanpa data | Bagian A berisi satu baris "Nihil" |
| `month` bulan depan | 400: "Bulan belum berjalan." |
| `month` di luar tahun ajaran aktif | 400: "Bulan harus dalam tahun ajaran <tahun> (Juli <YYYY> – Juni <YYYY+1>)." |
| Tombol ditekan tanggal 1–10 bulan Juli (awal tahun ajaran) | Default bulan lalu (Juni) di luar rentang. Karena `auto=1`, backend memakai bulan pertama yang valid (Juli) dan panel kontrol menampilkan info. `month=2026-06` tanpa `auto` tetap 400 |
| `month` tidak valid atau kosong | 400. Halaman error menampilkan tautan ke bulan berjalan |
| `hari_efektif` 0, negatif, huruf, atau > jumlah hari bulan | 400 |
| `hari_efektif` < hari presensi tercatat | Tetap dicetak. Panel kontrol memberi peringatan (tidak tercetak) |
| `tanggal` di luar bulan rekap | Diizinkan (mis. dicetak tanggal 2 bulan berikutnya) |
| Presensi di akhir pekan | Dihitung normal. Hanya daftar "belum diisi" yang mengabaikan Minggu |
| Siswa pindah kelas di tengah bulan | Muncul di rekap kelas lama (data sebelum pindah, Ket. "Pindah ke <kelas baru>") dan kelas baru (data setelah pindah) |
| Siswa dihapus dari `students` | Muncul dengan nama dari record dan Ket. "Data siswa tidak ditemukan" |
| Nama siswa sama, `studentId` insiden kosong | Dikelompokkan berdasarkan nama. Risiko salah gabung dicatat (§10) |
| Alpha → dikoreksi Hadir | Insiden `inc_att_*` sudah dihapus oleh logika presensi, sehingga poin dan hitungan ikut hilang |
| Insiden `REJECTED` | Tidak dihitung di A dan poin, tetap tampil di B dengan label "Ditolak" |
| Insiden dengan `className` tidak cocok format apa pun | Tidak masuk rekap (sama dengan filter `get_incidents`) |
| Id kelas ≠ nama kelas (mis. `X_AKT_1` vs `X AKT 1`) | Kecocokan memakai kunci id **dan** nama, sehingga data dari kedua format masuk |
| `class_homeroom` tidak cocok dengan baris `classes` | Kunci = `key(class_homeroom)` saja, dan nama kelas tampil sebagai nilai `class_homeroom`. Tidak boleh 500 |
| Logo kop tidak ada di disk | Gambar gagal dimuat. Atribut `alt=""` dan `onerror` menyembunyikan gambar agar tidak tampil ikon rusak |
| Nilai kop atau `details` berisi HTML/skrip | Ter-escape dan tampil sebagai teks |
| Wali dicabut penugasannya saat tab rekap masih terbuka | Klik "Terapkan" berikutnya → 403 |
| Session habis | 401 HTML dengan tautan ke halaman login (`../`) |
| Data besar (±40 siswa, ratusan insiden) | Dokumen multi-halaman dengan header tabel berulang. Waktu respons < 2 detik di data sekolah |
| Pengguna mencetak dengan "Header dan footer" browser aktif | URL dan tanggal browser ikut tercetak. Panel petunjuk meminta dimatikan; ini risiko yang diterima (§10) |

## 9. Asumsi yang perlu dikonfirmasi

- **A1:** Insiden `REJECTED` tidak dihitung di ringkasan, tetapi tetap ditampilkan di rincian. Dasarnya konsistensi dengan `get_homeroom_dashboard` yang mengecualikan `REJECTED` dari total poin.
- **A2:** Tahun ajaran berjalan Juli–Juni.
- **A3:** Daftar "belum diisi" memakai Senin–Sabtu. Sekolah 5 hari kerja cukup mengabaikan Sabtu.

## 10. Risiko dan trade-off

| Risiko / trade-off | Dampak | Mitigasi |
|---|---|---|
| PDF dari print browser, bukan file PDF asli | Tampilan sedikit berbeda antarbrowser; user bisa salah memilih printer, kertas, atau lupa mematikan header/footer | Petunjuk di panel kontrol, CSS `@page` A4, uji di Chrome/Edge/Firefox. Library PDF bisa diusulkan ke upstream bila perlu |
| Endpoint membalas HTML, bukan JSON (pola baru di router) | Menyimpang dari konvensi `jsonResponse` | Diisolasi di satu file dan satu `case`, error HTML sendiri, header `no-store` |
| Patch pada JS minify (R1) | Salah sisip bisa merusak Dashboard Kelas | Anchor unik + hash, salinan file terpisah, `node --check`, uji UI E1–E3 |
| Ketergantungan pada patch wali kelas | Update yang menimpa satu patch mematahkan yang lain | Urutan re-apply di dokumen patch; checklist pasca-update |
| Hari efektif diisi manual | Bisa salah isi; tidak divalidasi terhadap kalender | Default dari data tercatat, dan peringatan bila < hari tercatat |
| Pengelompokan insiden tanpa `studentId` berdasarkan nama | Dua siswa bernama sama bisa tergabung | Jarang terjadi (form UI mengisi `studentId`); dicatat |
| Kecocokan kelas via normalisasi `LOWER/REPLACE` di SQL | Tidak memakai indeks; lebih lambat pada data besar | Selalu dikombinasikan dengan filter rentang tanggal; volume per bulan kecil |
| Dokumen memuat PII siswa (nama, NISN, pelanggaran) dan diunggah ke sistem eksternal | Kebocoran bila tersebar | Hanya wali/KS/Admin yang bisa membuat; `no-store`. Kebijakan unggah e-Kinerja menjadi tanggung jawab sekolah |
| Celah auth di `get_homeroom_dashboard`/`get_class_attendance_summary`/`get_homeroom_info` (ditemukan saat analisis) | PII presensi terbaca tanpa login | Di luar scope; buat tiket terpisah dan masukkan ke usulan upstream |
| Tanpa arsip | Rekap bulan lalu bisa berubah bila data dikoreksi setelah dicetak | Footer mencantumkan waktu cetak; wali menyimpan PDF-nya sendiri |

## 11. Acceptance criteria

- **AC-1** Wali kelas (GURU non-BK dengan `class_homeroom`) melihat tombol **"Cetak Rekap Bulanan"** di Dashboard Kelas Saya. Tombol membuka tab baru ke `cetak_rekap_bulanan` untuk kelasnya dengan bulan default sesuai §3.1.
- **AC-2** Halaman menampilkan dokumen A4 dengan kop dari `system_config`, judul, identitas, Bagian A, Bagian B.1–B.4, tanda tangan dua kolom, dan footer. Panel kontrol serta info "belum diisi" **tidak ikut tercetak**.
- **AC-3** "Cetak / Simpan PDF" menghasilkan PDF A4 portrait yang rapi (tabel tidak terpotong di tengah baris, header tabel berulang) di Chrome dan Edge. Nama file default `Rekap_Bulanan_<Kelas>_<YYYY-MM>`.
- **AC-4** Angka H/S/I/A, % Hadir, Terlambat, Izin Keluar, Pelanggaran, dan Poin per siswa serta total kelas sama dengan hasil query SQL referensi (§12.3) untuk bulan uji.
- **AC-5** Insiden Alpha otomatis tidak dihitung di kolom Pelanggaran maupun B.3, tetapi poinnya masuk kolom Poin. Insiden `REJECTED` tidak dihitung, tetapi tampil di rincian sebagai "Ditolak".
- **AC-6** Subbagian tanpa data menampilkan "Nihil". Siswa pindah/keluar yang punya data bulan itu tercantum dengan keterangan.
- **AC-7** Hari efektif, kota, dan tanggal dapat diubah dan tercermin di dokumen. Default sesuai §3.3. Bulan berjalan menampilkan "Data s.d. tanggal …".
- **AC-8** Otorisasi sesuai §3.2: anonim 401, GURU non-wali/BK/TU 403, wali ke kelas lain 403, KS dan ADMIN bisa semua kelas. Perubahan `class_homeroom` berlaku tanpa login ulang. Semua error tampil sebagai halaman HTML.
- **AC-9** Validasi parameter §3.3 menolak bulan depan, bulan di luar tahun ajaran aktif, format bulan/tanggal salah, dan hari efektif di luar rentang dengan 400.
- **AC-10** Semua nilai dinamis ter-escape: kop atau `details` berisi `<script>` tampil sebagai teks.
- **AC-11** Tidak ada perubahan skema. `-wali1.js` dan bundle asli tidak dimodifikasi (hash sama). Semua file PHP yang diubah atau baru lolos `php -l`, dan `-wali2.js` lolos `node --check`.
- **AC-12** Tidak ada regresi: Dashboard Kelas Saya (tab, semester, Perbarui Data), menu Kesiswaan wali, dan halaman lain berfungsi seperti sebelumnya.
- **AC-13** `docs/patches/rekap-bulanan-wali-kelas.md` tersedia berisi hash, anchor R1, urutan re-apply terhadap patch wali kelas, rollback, dan hasil verifikasi.
- **AC-14** Skenario E1–E24 (§12.4) lulus di dev lokal dan staging.

## 12. Testing dan verifikasi end-to-end

Tidak ada test otomatis di bundle ini. Verifikasi dilakukan dengan `php -l`, `node --check`, query SQL referensi, dan pengujian manual/headless di **dev lokal (Laragon)** lalu **staging dengan data sekolah**.

### 12.1 Persiapan

1. Backup database (`mysqldump`) dan salin folder deploy.
2. Pastikan patch wali kelas sudah terpasang (`app.html` → `-wali1.js`, hash sesuai §5.2).
3. Lisensi valid untuk host, atau `SIMANTAP_DEV_MODE` khusus dev/staging.
4. **Akun uji** (sama dengan spesifikasi wali kelas §9.1): `admin`, `ks`, `tu`, `waliA` (kelas A), `waliB` (kelas B), `guru` (non-wali), `bk`. Minimal satu kelas sebaiknya punya id ≠ nama.
5. **Konfigurasi:** `system_config.active_academic_year` terisi (mis. `2026/2027`). Semua `kop_*` terisi, termasuk kedua logo dan `kop_kepsek_nama`/`nip`. Isi `kop_kota` (mis. `Banjarbaru`). `waliA` punya `nip`.
6. **Data uji** di bulan **Agustus 2026** untuk kelas A, semua id berprefiks `test_rekap_` agar mudah dibersihkan:
   - presensi 3 tanggal untuk semua siswa aktif, termasuk minimal 1 Sakit, 1 Izin, 1 Alpha (via UI Presensi Kelas, sehingga insiden `inc_att_*` terbentuk);
   - 2 insiden `LATE`, 1 `OUT`, 2 `VIOLATION` (salah satunya `REJECTED`);
   - 1 insiden dengan `details` berisi `<script>alert(1)</script>`;
   - 1 panggilan orang tua manual;
   - 1 siswa kelas A yang punya data lalu dipindah ke kelas B (`student_update`).
7. Catat hasil **query referensi** (§12.3) sebelum membuka dokumen.
8. **Pembersihan setelah uji:** hapus record `test_rekap_*` dan `inc_att_*` tanggal uji, kembalikan `total_violation_points` dan kelas siswa yang dipindah.

### 12.2 Pemeriksaan statis

- `php -l api/rekap_bulanan.php` dan `php -l api/index.php`.
- `grep -n "case 'cetak_rekap_bulanan'" api/*.php` menghasilkan tepat 1 baris.
- `grep` bahwa `rekap_bulanan.php` tidak memakai `->query(` dengan string interpolasi variabel request.
- `sha256sum` untuk `assets/index-CGWL69P7.js` dan `-wali1.js` sama dengan hash yang tercatat. `node --check assets/index-CGWL69P7-wali2.js` lolos.
- Anchor R1 muncul 1 kali di `-wali1.js`. String `cetak_rekap_bulanan` muncul 1 kali di `-wali2.js`. `app.html` mereferensikan `-wali2.js`.

### 12.3 Query referensi

Ganti `:ids`, `:keys`, dan tanggal sesuai kelas dan bulan uji.

```sql
-- H/S/I/A per siswa
SELECT student_id, student_name,
  SUM(status='HADIR') h, SUM(status='SAKIT') s, SUM(status='IZIN') i, SUM(status='ALFA') a
FROM attendance_records
WHERE date >= '2026-08-01' AND date < '2026-09-01' AND class_id IN (:ids)
GROUP BY student_id, student_name ORDER BY student_name;

-- Insiden per siswa (kunci kelas ternormalisasi)
SELECT studentId, studentName,
  SUM(type='LATE') terlambat, SUM(type='OUT') izin_keluar,
  SUM(type='VIOLATION' AND COALESCE(violation_code,'')<>'ALFA' AND id NOT LIKE 'inc\_att\_%') pelanggaran,
  SUM(points) poin
FROM incidents
WHERE date >= '2026-08-01' AND date < '2026-09-01' AND status <> 'REJECTED'
  AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(className),' ',''),'_',''),'-',''),'.','') IN (:keys)
GROUP BY studentId, studentName;

-- Hari presensi tercatat
SELECT COUNT(DISTINCT date) FROM attendance_records
WHERE date >= '2026-08-01' AND date < '2026-09-01' AND class_id IN (:ids);
```

### 12.4 Skenario end-to-end

Jalankan di browser dengan cache dikosongkan setelah deploy.

| # | Akun | Langkah | Hasil yang diharapkan |
|---|---|---|---|
| E1 | waliA | Buka Dashboard Kelas Saya | Tombol "Cetak Rekap Bulanan" tampil di samping "Perbarui Data". Toggle semester, tab, dan Perbarui Data tetap berfungsi. Tidak ada error di Console |
| E2 | waliA | Klik tombol (tanggal sistem 14 September 2026) | Tab baru membuka `…action=cetak_rekap_bulanan&class_id=<A>&month=2026-09`, lalu dokumen tampil dengan keterangan "Data s.d. tanggal 14 September 2026" |
| E3 | guru, bk, ks | Buka halaman masing-masing | Tidak ada tombol baru di tempat mana pun; menu sama seperti sebelum patch |
| E4 | waliA | Ganti bulan ke 2026-08, Terapkan | Kop lengkap dengan kedua logo; judul, identitas (kelas, wali, "Agustus 2026", "2026/2027 / Ganjil"), dan Hari Presensi Tercatat = 3 |
| E5 | waliA | Bandingkan Bagian A dengan query §12.3 | Semua angka per siswa dan baris Jumlah identik. % Hadir sesuai rumus |
| E6 | waliA | Periksa siswa Alpha | Kolom A = 1, kolom Pelanggaran tidak bertambah karena Alpha, dan Poin mencakup poin ALFA. B.3 tidak memuat insiden `inc_att_*` |
| E7 | waliA | Periksa insiden `REJECTED` | Tidak dihitung di A; tampil di B.3 dengan status "Ditolak" |
| E8 | waliA | Periksa B.1, B.2, B.4 | Isi sesuai data uji, urut tanggal; label status sesuai §3.4 |
| E9 | waliA | Periksa insiden berisi `<script>` | Tampil sebagai teks; tidak ada alert |
| E10 | waliA | Periksa siswa yang dipindah ke kelas B | Tercantum dengan Ket. "Pindah ke <kelas B>" |
| E11 | waliA | Buka bulan uji tanpa data (mis. 2026-07) | Bagian A berisi siswa aktif dengan angka 0, B.1–B.4 "Nihil", tanpa error |
| E12 | waliA | Isi hari efektif 20, kota "Martapura", tanggal 2026-09-01, Terapkan | Identitas "Hari Efektif: 20"; tanda tangan "Martapura, 1 September 2026" |
| E13 | waliA | Isi hari efektif 2 (lebih kecil dari 3 tercatat) | Dokumen tetap tampil; panel memberi peringatan |
| E14 | waliA | "Cetak / Simpan PDF" di Chrome dan Edge, A4, header/footer mati | PDF A4 portrait: panel kontrol tidak tercetak, header tabel berulang bila > 1 halaman, tanda tangan tidak terpotong, nama file `Rekap_Bulanan_<Kelas>_2026-08`. Buka PDF dan periksa visual |
| E15 | waliA | Cetak ulang E14 di Firefox | Dokumen terbaca dan layout wajar (perbedaan kecil diterima) |
| E16 | waliA | URL `class_id=<B>` | 403 HTML "Anda hanya dapat mengakses data kelas perwalian Anda." |
| E17 | waliA | URL tanpa `class_id` | Memakai kelas A |
| E18 | waliA | `month=2026-10`, `month=2026-06`, `month=2026-13`, `hari_efektif=0`, `hari_efektif=abc`, `tanggal=2026-02-30` | Semua 400 HTML dengan pesan sesuai |
| E19 | guru, bk, tu | Buka URL untuk kelas A | 403 HTML |
| E20 | (logout) | `curl -i "<host>/api/index.php?action=cetak_rekap_bulanan&class_id=<A>&month=2026-08"` | 401, `Content-Type: text/html` |
| E21 | ks, admin | Buka URL untuk kelas A dan B | Dokumen tampil; tanda tangan kanan memuat wali kelas masing-masing. ADMIN/KS tanpa `class_id` → 400 |
| E22 | admin | Pindahkan waliA ke kelas B. Tanpa logout, waliA menekan "Terapkan" di tab rekap kelas A | 403. URL kelas B diizinkan |
| E23 | – | `curl -i -X POST …action=cetak_rekap_bulanan…` dengan cookie session wali | 405 |
| E24 | waliB | Ulangi E4–E5 di kelas yang id ≠ nama (bila tersedia) | Presensi (via `class_id`) dan insiden (via nama) sama-sama masuk rekap |

### 12.5 Checklist pasca auto-update (masuk ke `docs/patches/rekap-bulanan-wali-kelas.md`)

1. Periksa `api/version.php` dan bundle yang direferensikan `app.html`.
2. Terapkan ulang patch wali kelas lebih dulu (lihat dokumen patch wali kelas §3).
3. Backend: salin `api/rekap_bulanan.php` bila hilang, lalu tambahkan kembali `require_once` dan `case` di `api/index.php`. Grep bahwa tidak ada `case 'cetak_rekap_bulanan'` duplikat.
4. Frontend: verifikasi anchor R1 masih muncul tepat 1 kali di bundle hasil patch wali kelas yang baru. Perbarui hash sumber di skrip hanya setelah verifikasi manual, lalu jalankan skrip.
5. Jalankan `php -l`, `node --check`, dan subset regresi E1, E4, E5, E14, E16, E19, E20.

### 12.6 Rollback

1. Kembalikan referensi di `app.html` ke `index-CGWL69P7-wali1.js`.
2. Hapus `case 'cetak_rekap_bulanan'` dan `require_once __DIR__ . '/rekap_bulanan.php';` dari `api/index.php`.
3. Hapus `api/rekap_bulanan.php` (**setelah** langkah 2) dan, bila perlu, `assets/index-CGWL69P7-wali2.js`.
4. Tidak ada perubahan skema atau data yang perlu dipulihkan. Key `kop_kota` boleh dibiarkan.
