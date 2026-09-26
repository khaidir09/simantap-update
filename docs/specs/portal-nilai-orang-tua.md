# Spesifikasi: Portal Nilai untuk Siswa dan Orang Tua

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — lihat `docs/patches/portal-nilai-orang-tua.md` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch lokal terakhir `assets/index-CGWL69P7-pantaunilai1.js` (commit `bb33d9c`) |
| Tanggal | 2026-09-26 |
| Jalur rilis | Patch lokal pada bundle ini **dan** usulan perubahan ke upstream BTIKP |
| Spesifikasi terkait | `docs/specs/pantau-nilai-wali-kelas.md` (tahap 1: sumber data dan aturan hitung nilai; §9 menunda akses siswa/ortu ke spesifikasi ini) |

## 1. Latar belakang dan kebutuhan bisnis

Tahap 1 memberi wali kelas dan guru BK tampilan nilai lintas mapel. Siswa dan orang tua belum punya jalur apa pun untuk memantau nilai, sehingga informasi tugas yang tertinggal baru sampai ke rumah saat panggilan orang tua.

Tujuan: siswa dan orang tua bisa membuka **halaman web publik** dari HP, memasukkan **NISN + PIN** dari sekolah, lalu melihat nilai, tugas/kuis yang tertinggal, dan presensi **hanya untuk siswa itu**.

### 1.1 Keputusan wawancara

| Topik | Keputusan |
|---|---|
| Verifikasi | **NISN + PIN** acak yang dibuat sistem, dibagikan lewat kartu akses cetak. Tanpa akun `users` |
| Penyimpanan PIN | **Hash** (bcrypt). PIN tidak bisa ditampilkan ulang; cetak ulang = reset PIN |
| Pengelola PIN | **Wali kelas** (kelas perwaliannya) dan **ADMIN** (semua kelas) |
| Data yang tampil | Nilai per mapel, daftar tugas/kuis tertinggal, nilai per pertemuan, ringkasan presensi |
| Catatan guru | **Tidak** ditampilkan |
| Waktu rilis nilai | Langsung (jurnal non-draf), sama dengan tampilan wali kelas |
| Anti tebak PIN | 5 gagal per NISN → terkunci 15 menit; 20 gagal per IP dalam 15 menit → IP diblok sementara |
| Lokasi portal | Halaman PHP server-rendered terpisah **`/nilai/`**, mobile-first, di luar SPA |
| UI kelola wali | Tombol **"🔑 Akses Orang Tua"** di tab "📝 Nilai" Dashboard Kelas Saya → panel status PIN + cetak kartu |
| UI kelola ADMIN | Menu sidebar ADMIN **"Portal Nilai Ortu"**: saklar portal + pemilih kelas + panel yang sama |
| Saklar global | `system_config.portal_nilai_aktif`, **default nonaktif** |
| Periode | Semester Ganjil & Genap tahun ajaran aktif (toggle, default semester aktif) |
| Lama sesi | **7 hari** (ingat perangkat), tombol Keluar |
| Pembatal sesi | PIN direset; portal dinonaktifkan |
| Isi kartu | Nama sekolah (kop, tanpa logo), nama siswa, kelas, NISN, PIN, alamat portal |
| Pengujian | Skrip uji API `.mjs`, uji unit PHP, `php -l`, dan verifikasi di browser (pola tahap 1) |

## 2. Perilaku saat ini (hasil analisis kode)

- **Tidak ada jalur publik** ke data nilai. Semua endpoint nilai memanggil `requireAuth()`; portal staf (`index.php` → `app.html`) mensyaratkan login `users`.
- Gerbang `index.php` (root): install → maintenance (`api/.updating`) → lisensi (`simantapLicenseStatus()` dari `api/license_guard.php`) → `app.html`. Halaman publik baru harus melewati gerbang yang sama.
- **Tidak ada pembatasan percobaan login** di mana pun (`login` di `api/index.php:66` tidak menghitung kegagalan).
- **Data identitas siswa** (DB lokal, 968 siswa aktif): `students.nisn` terisi 100% (umumnya 10 digit) dan **UNIQUE** (`idx_nisn`, `install/schema.sql:727`; NULL boleh lebih dari satu). `tanggal_lahir`, `nik`, dan `parent_phone` kosong semua. NISN bukan rahasia (tercetak di rapor/kartu pelajar dan bisa dicek di situs NISN), sehingga tidak boleh menjadi satu-satunya kunci.
- **Sumber data nilai** sudah tersedia: `pantauNilaiCollect($db, $classId, $academicYear, $semester)` di `api/pantau_nilai.php` mengembalikan rekap per siswa (`grades`, `missing`), daftar pertemuan per mapel (`meetings`), dan sel per siswa × pertemuan (`cells`). Fungsi ini mengumpulkan data **seluruh kelas**; portal harus menyaring ke satu siswa sebelum merender.
- **`get_system_config`** dan `save_system_config` mengelola `system_config`; key `portal_nilai_aktif` belum ada.
- **Frontend:**
  - Tab Nilai dirender komponen `__pnkPantauNilaiKelas` (disisipkan patch tahap 1). View internalnya diatur state `tampilan.jenis` ∈ `rekap`, `mapel`, `siswa`.
  - Sidebar difilter per role, dan grup tanpa item dibuang (`.filter(Y=>Y.items.length>0)`). ADMIN melihat grup "Manajemen Data" (`class-management`, `student-management`, `subject-management`, `teacher-management`, `violation-points`).
- Alamat aplikasi: lisensi memaksa host request sama dengan host `installed_url`, sehingga alamat portal di kartu bisa diturunkan dari request saat kartu dicetak.

## 3. Expected behavior

### 3.1 Alur orang tua/siswa

1. Membuka alamat pada kartu, mis. `https://simantap.sman1.sch.id/nilai/`.
2. Jika portal nonaktif: halaman "Layanan cek nilai belum dibuka. Silakan hubungi wali kelas." (HTTP 503).
3. Form **NISN** + **PIN** → tombol "Lihat Nilai".
   - Salah (NISN tidak ada, belum punya PIN, siswa tidak aktif, NISN ganda, atau PIN salah): pesan **generik** "NISN atau PIN salah." (HTTP 401). Tidak membedakan penyebab.
   - Terkunci: "Terlalu banyak percobaan. Coba lagi dalam N menit." (HTTP 429), baik karena NISN maupun IP.
4. Berhasil → cookie sesi 7 hari → **halaman nilai**:
   - Kepala: nama sekolah (`kop_sekolah`), nama siswa, NISN, kelas, "TA 2026/2027", toggle **Ganjil/Genap**, tombol **Keluar**.
   - **Ringkasan**: jumlah tugas/kuis belum ada nilai, jumlah mapel di bawah KKM, presensi semester (H/S/I/A + % hadir).
   - **Tugas/Kuis yang Belum Ada Nilainya**: tanggal, mapel, topik, jenis, presensi. Kosong → "Tidak ada. 👍"
   - **Nilai per Mata Pelajaran**: satu kartu per mapel berisi guru, rata-rata tugas (n), rata-rata kuis (n), UTS, UAS, nilai akhir, KKM, dan keterangan (Tuntas/Remedi/Belum lengkap/sementara/Belum ada penilaian). Setiap kartu punya `<details>` "Lihat per pertemuan" berisi tanggal, topik, nilai tugas, nilai kuis, presensi.
   - Catatan kaki rumus nilai akhir dan arti "sementara", serta "Data s.d. <tanggal jam>".
5. **Keluar** menghapus cookie dan kembali ke form.

### 3.2 Alur wali kelas

1. Dashboard Kelas Saya → tab "📝 Nilai" → tombol **"🔑 Akses Orang Tua"** di baris kontrol (sebelah "Perbarui Nilai").
2. Panel "Akses Orang Tua" (view baru `tampilan.jenis = 'akses'`, dengan tombol kembali):
   - Info status portal: "Portal nilai: **Aktif**/**Nonaktif** (diatur Admin)". Jika nonaktif, tambahkan "PIN tetap bisa dibuat, tetapi orang tua belum bisa masuk."
   - Alamat portal (teks, bisa disalin).
   - Tabel siswa aktif: checkbox, Nama, NISN, **Status PIN** (`Belum dibuat` / `Aktif sejak dd/mm/yyyy` / `Terkunci s.d. HH:MM` / `NISN kosong` / `NISN ganda`), Login terakhir, Jumlah login.
   - Tombol **"Buat PIN & Cetak Kartu (n siswa belum punya PIN)"** → mode `baru`, nonaktif bila n = 0.
   - Tombol **"Reset PIN Terpilih & Cetak (n)"** → konfirmasi "PIN lama siswa terpilih tidak berlaku lagi dan perangkat yang sudah masuk akan keluar. Lanjutkan?" → mode `reset`.
   - Kedua tombol membuka **tab baru** berisi kartu siap cetak (§4.6), lalu panel memuat ulang statusnya.
3. Siswa dengan NISN kosong atau ganda tidak bisa dipilih dan dilewati saat membuat PIN.

### 3.3 Alur ADMIN

1. Sidebar grup "Manajemen Data" → item baru **"Portal Nilai Ortu"** (`portal-nilai`, hanya `ADMIN`).
2. Halaman berisi kartu judul, **saklar** "Portal nilai orang tua: Aktif/Nonaktif" (tombol toggle; konfirmasi saat menonaktifkan: "Semua orang tua yang sedang masuk akan keluar."), dropdown kelas (`get_classes`), lalu panel yang sama dengan §3.2.

### 3.4 Matriks akses

| Peran | `/nilai/` | `portal_nilai_status` | `cetak_kartu_akses` | `portal_nilai_set_aktif` |
|---|---|---|---|---|
| Publik (NISN + PIN valid) | Data siswa itu saja | – | – | – |
| Anonim staf | – | 401 | 401 (HTML) | 401 |
| ADMIN | – | Semua kelas | Semua kelas | Ya |
| GURU wali kelas (non-BK maupun BK) | – | Kelas perwalian | Kelas perwalian | 403 |
| KEPALA_SEKOLAH, TATA_USAHA, GURU lain, GURU BK non-wali | – | 403 | 403 | 403 |

Catatan: berbeda dengan tahap 1, guru BK **tidak** mengelola PIN, dan wali kelas yang juga BK tetap mengelola kelas perwaliannya. Scope dihitung dengan helper baru `portalNilaiScope` (§4.4), user dibaca ulang dari DB.

## 4. Kontrak backend

### 4.1 File

- **`api/portal_nilai.php`** (baru): helper bersama (tabel, PIN, throttle, cookie) + handler staf. Guard `SIMANTAP_API` seperti file lain. Di-`require_once` di `api/index.php` setelah `pantau_nilai.php`.
- **`nilai/index.php`** (baru): halaman publik. Menjalankan gerbang install/maintenance/lisensi (sama dengan root `index.php`), lalu `define('SIMANTAP_API', true)` dan me-require `api/db.php`, `api/kesiswaan_access.php`, `api/rekap_bulanan.php`, `api/pantau_nilai.php`, `api/portal_nilai.php`.
- **`nilai/.htaccess` tidak dibuat** (tidak ada `.htaccess` di bundle; halaman ini memang publik).

### 4.2 Data model

Tabel dibuat saat pertama dibutuhkan oleh `portalNilaiEnsureTables($db)` (`CREATE TABLE IF NOT EXISTS`, pola sama dengan `journal_approval_batches`). Tidak ada perubahan pada tabel lama.

```sql
CREATE TABLE IF NOT EXISTS portal_nilai_akses (
  student_id        VARCHAR(128) NOT NULL PRIMARY KEY,
  pin_hash          VARCHAR(255) NOT NULL,
  dibuat_oleh       VARCHAR(128) DEFAULT NULL,
  dibuat_oleh_nama  VARCHAR(255) DEFAULT NULL,
  dibuat_pada       DATETIME     NOT NULL,
  login_terakhir    DATETIME     DEFAULT NULL,
  jumlah_login      INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_nilai_percobaan (
  id          BIGINT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip          VARCHAR(45) NOT NULL,
  nisn        VARCHAR(32) NOT NULL,
  berhasil    TINYINT(1)  NOT NULL,
  dibuat_pada DATETIME    NOT NULL,
  KEY idx_ip_waktu (ip, dibuat_pada),
  KEY idx_nisn_waktu (nisn, dibuat_pada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `system_config.portal_nilai_aktif`: `'1'` = aktif; tidak ada atau nilai lain = nonaktif. Ditulis dengan `INSERT … ON DUPLICATE KEY UPDATE`.
- Baris percobaan lebih dari 7 hari dihapus secara oportunistik (±1% request login).
- Status "terkunci" **dihitung** dari `portal_nilai_percobaan`, tidak disimpan.
- Kolom `berhasil`: `0` = gagal, `1` = berhasil, `2` = gagal yang kuncinya sudah dibuka (reset PIN atau login berhasil). Kunci per NISN menghitung `0` saja; batas per IP menghitung `0` dan `2`. Baris tidak pernah dihapus saat membuka kunci, agar login berhasil tidak menghapus jejak per IP.

### 4.3 PIN

- 8 karakter dari alfabet `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (31 simbol, tanpa `0/O/1/I/L`), dibangkitkan `random_int`. Ruang ≈ 8,5 × 10¹¹.
- Ditampilkan di kartu sebagai `XXXX-XXXX`. Input dinormalisasi: huruf besar, hapus spasi dan `-`.
- Disimpan `password_hash($pin, PASSWORD_DEFAULT)`; diverifikasi `password_verify`.
- **Mode `baru`**: hanya siswa terpilih/semua siswa kelas yang belum punya baris `portal_nilai_akses`. **Mode `reset`**: siswa terpilih wajib (`student_ids` tidak kosong); baris di-`REPLACE` (hash baru, `dibuat_pada` baru, `login_terakhir`/`jumlah_login` di-nol-kan), dan baris percobaan gagal untuk NISN siswa itu diubah ke `berhasil = 2` (membuka kunci per NISN, tetap dihitung untuk batas per IP).
- Siswa yang dilewati: bukan siswa aktif kelas itu, NISN kosong, atau NISN dipakai lebih dari satu siswa aktif.

### 4.4 Scope staf: `portalNilaiScope(mysqli $db, array $sessionUser): array`

Format sama dengan `pantauNilaiScope`. `loadFreshUser` → ADMIN → `ALL`; GURU dengan `class_homeroom` terisi (BK atau bukan) → `homeroomClassScope`; lainnya → `DENY`.

### 4.5 `portal_nilai_status` (GET, JSON)

Parameter `class_id` (wali: default kelas perwalian; ADMIN: wajib). Validasi kelas memakai `resolveClassRef` + `classInScope` seperti tahap 1. Metode selain GET → 405.

```json
{
  "aktif": false,
  "portal_url": "https://host/path/nilai/",
  "class": {"id": "X_AKT_1", "name": "X AKT 1"},
  "is_admin": false,
  "students": [
    {"student_id": "s1", "student_name": "Ani", "nisn": "0012345678",
     "pin_status": "AKTIF", "pin_created_at": "2026-09-26 10:00:00",
     "locked_until": null, "last_login_at": "2026-09-27 19:10:00", "login_count": 3}
  ],
  "summary": {"total": 32, "belum": 5, "aktif": 26, "terkunci": 1, "tidak_bisa": 0}
}
```

`pin_status` ∈ `BELUM`, `AKTIF`, `TERKUNCI` (punya PIN dan sedang terkunci per NISN), `NISN_KOSONG`, `NISN_GANDA`. Siswa dari `getActiveClassStudents` + NISN. **Tidak pernah** mengembalikan `pin_hash`.

### 4.6 `cetak_kartu_akses` (POST form, HTML)

- Dikirim SPA lewat `<form method="post" target="_blank">` (bukan `window.open`), sehingga PIN **tidak pernah** muncul di URL, riwayat, atau log akses. Body `application/x-www-form-urlencoded`: `class_id`, `mode` (`baru`|`reset`), `student_ids[]`.
- Metode selain POST → 405 (HTML). Anonim → 401 (HTML). `DENY` / kelas di luar scope → 403 (HTML).
- **Cek asal permintaan**: header `Origin` (jika ada) atau `Referer` harus ber-host sama dengan `HTTP_HOST`; jika tidak → 403. Ditambah cookie sesi `SameSite=Lax`.
- Proses dalam transaksi. Jika tidak ada siswa yang diproses → halaman info "Tidak ada siswa yang perlu dibuatkan PIN." (200).
- **Halaman kartu** (`rekapSendHeaders` + `Cache-Control: no-store`, `Referrer-Policy: no-referrer`):
  - panel `no-print`: judul, jumlah kartu, peringatan "PIN hanya ditampilkan sekali. Cetak atau simpan sebagai PDF sekarang; setelah halaman ditutup PIN tidak dapat dilihat lagi.", tombol "Cetak / Simpan PDF";
  - A4 portrait, 2 kolom × 5 baris (10 kartu/halaman), garis potong putus-putus, `break-inside: avoid`;
  - isi kartu: `kop_sekolah` (fallback "SIMANTAP"), judul "Kartu Akses Nilai Siswa", Nama, Kelas, NISN, **PIN** (monospace besar, `XXXX-XXXX`), alamat portal, satu baris "Buka alamat di atas, lalu masukkan NISN dan PIN.";
  - urut nama siswa. Semua teks di-escape `rekapEsc`.
- **Alamat portal** = `scheme://HTTP_HOST` + direktori induk `api/` + `/nilai/` (dihitung dari `SCRIPT_NAME`). Scheme `https` bila HTTPS/`X-Forwarded-Proto: https`/port 443 (logika sama dengan `$isHttps` di `api/index.php`).

### 4.7 `portal_nilai_set_aktif` (POST, JSON)

ADMIN saja (`requireRole(['ADMIN'])`, lalu `loadFreshUser` untuk memastikan role terkini). Body `{ "aktif": true|false }` (wajib boolean, selain itu 400). Menulis `portal_nilai_aktif`. Balasan `{ "success": true, "aktif": true }`. Metode selain POST → 405.

### 4.8 Sesi orang tua (cookie bertanda tangan)

Tidak memakai sesi PHP (default `gc_maxlifetime` 24 menit tidak cocok untuk 7 hari, dan sesi staf tidak boleh tercampur).

- Nama cookie `simantap_nilai`, nilai `base64url(student_id) . '.' . exp . '.' . sig`.
- `sig = hash_hmac('sha256', student_id . '|' . exp, pin_hash)`, yaitu **hash PIN siswa itu menjadi kunci HMAC**. Reset PIN otomatis membatalkan semua cookie lama tanpa kolom versi. Verifikasi memakai `hash_equals`.
- `exp` = waktu login + 7 hari (tetap, bukan sliding).
- Atribut: `Path=<base>/nilai/`, `HttpOnly`, `SameSite=Lax`, `Secure` bila HTTPS, `Max-Age=604800`.
- Setiap request divalidasi: portal aktif → cookie terbaca → `exp` > sekarang → baris `portal_nilai_akses` ada → tanda tangan cocok → siswa masih ada. Gagal → hapus cookie, tampilkan form login.
- Siswa yang sudah tidak aktif (pindah/keluar) dengan cookie valid: tampil "Data nilai siswa ini tidak tersedia. Silakan hubungi sekolah." dan tombol Keluar. Login baru untuk siswa tidak aktif ditolak dengan pesan generik.

### 4.9 Login publik (`POST /nilai/` dengan `aksi=masuk`)

1. Portal nonaktif → 503.
2. `ip = REMOTE_ADDR` (**tidak** memakai `X-Forwarded-For`, karena mudah dipalsukan). NISN dinormalisasi (trim, hanya digit; kosong/lebih dari 20 karakter → pesan generik tanpa query).
3. Throttle: gagal dari IP ini dalam 15 menit ≥ 20 → 429. Gagal untuk NISN ini dalam 15 menit ≥ 5 → 429, dengan menit tersisa dihitung dari percobaan gagal ke-5 terakhir.
4. Cari siswa aktif dengan NISN itu. Tepat 1 siswa + baris `portal_nilai_akses` → `password_verify`. Kasus lain tetap menjalankan `password_verify` terhadap hash dummy (agar waktu respons setara).
5. Catat percobaan (`berhasil` 0/1). Berhasil → update `login_terakhir`, `jumlah_login + 1`, set cookie, redirect 303 ke `./` (Post/Redirect/Get). Gagal → 401 + form dengan pesan generik; NISN diisi ulang, PIN tidak.
6. `aksi=keluar` (POST) → hapus cookie → 303 ke `./`.

### 4.10 Halaman portal (`GET /nilai/`)

- Parameter `semester` (`1`/`2`/`GANJIL`/`GENAP`, default aktif; lainnya diabaikan → default).
- Data: `pantauNilaiCollect($db, students.class_id, TA aktif, semester)`, lalu **hanya** baris siswa yang login, dengan `cells[mapel][student_id]`. Presensi semester dengan query yang sama seperti `cetak_rekap_nilai_siswa` (filter tanggal).
- **Dibuang sebelum render**: `notes` di `missing` dan `cells`, `pas_foto`, serta seluruh data siswa lain. HTML tidak boleh memuat nama siswa lain.
- Header keamanan semua respons `/nilai/`: `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'`, `X-Robots-Tag: noindex, nofollow`. Halaman tanpa JavaScript (toggle semester = tautan, pertemuan = `<details>`).
- Tampilan mobile-first (lebar maks. 720px, font sistem, kontras cukup), CSS inline.

## 5. Kontrak frontend (patch bundle)

Skrip baru `docs/patches/apply-portal-nilai-bundle.js`: sumber `index-CGWL69P7-pantaunilai1.js` (hash diverifikasi), hasil `index-CGWL69P7-portalnilai1.js`, perbarui `app.html`. Aturan sama dengan tahap 1: anchor tepat sekali, peta class Tailwind divalidasi terhadap CSS, nama baru dicek belum ada.

1. **Komponen `__ponPanelAksesOrtu({ classId, showClassPicker, isAdmin })`**:
   - memuat `portal_nilai_status`; menampilkan status portal, alamat portal, ringkasan, dan tabel §3.2;
   - tombol aksi membangun `<form>` tersembunyi (POST, `target="_blank"`, action `${K$}?action=cetak_kartu_akses`) lalu `submit()`, kemudian memuat ulang status setelah ±2 detik dan saat jendela kembali fokus;
   - ADMIN (`isAdmin`): saklar portal (`portal_nilai_set_aktif`) + dropdown kelas (`get_classes`);
   - error JSON ditampilkan di kartu, bukan `alert`.
2. **Di `__pnkPantauNilaiKelas`** (mode wali, `!showClassPicker`): tombol "🔑 Akses Orang Tua" di baris kontrol → `setTampilan({jenis:'akses'})`, dan cabang render `akses` → tombol kembali + `__ponPanelAksesOrtu({classId: kelas})`.
3. **Menu ADMIN**: item `{id:"portal-nilai",label:"Portal Nilai Ortu",icon:…,roles:["ADMIN"]}` di akhir grup "Manajemen Data".
4. **Router**: `portal-nilai` → halaman dengan `zi` + `__ponPanelAksesOrtu({showClassPicker:true,isAdmin:true})`; tambahkan `"portal-nilai"` ke daftar halaman dikenal.

## 6. File dan interface terkait

| File | Perubahan |
|---|---|
| `api/portal_nilai.php` (**baru**) | `portalNilaiEnsureTables`, `portalNilaiAktif`, `portalNilaiUrl`, `portalNilaiBuatPin`, `portalNilaiNormalisasiPin`, `portalNilaiScope`, throttle (`portalNilaiSisaKunci`, `portalNilaiCatatPercobaan`, `portalNilaiBukaKunci`), cookie (`portalNilaiBuatCookie`, `portalNilaiBacaCookie`), `handlePortalNilaiStatus`, `handleCetakKartuAkses`, `handlePortalNilaiSetAktif` |
| `api/index.php` | `require_once` + tiga `case`: `portal_nilai_status`, `cetak_kartu_akses`, `portal_nilai_set_aktif` |
| `nilai/index.php` (**baru**) | Halaman publik: gerbang, login/keluar, halaman nilai |
| `api/pantau_nilai.php` | Dipakai ulang tanpa perubahan: `pantauNilaiCollect`, `pantauNilaiStudents`, `pantauNilaiSemester`, `pantauNilaiAttendanceStatus`, `pantauNilaiAngka` |
| `api/rekap_bulanan.php`, `api/kesiswaan_access.php`, `api/db.php`, `api/license_guard.php` | Dipakai ulang tanpa perubahan |
| `docs/patches/apply-portal-nilai-bundle.js` (**baru**), `assets/index-CGWL69P7-portalnilai1.js` (**hasil**), `app.html` | Patch frontend §5 |
| `docs/patches/portal-nilai-orang-tua.md` (**baru**) | Catatan patch, rollback, langkah setelah update portal |
| `docs/patches/uji-portal-nilai-api.mjs`, `uji-portal-nilai-unit.php`, `uji-portal-nilai-seed.sql`, `uji-portal-nilai-cleanup.sql` (**baru**) | Pengujian §10 |



Nama action baru sudah dicek belum dipakai di keempat file endpoint.

## 7. Edge cases

| Kasus | Perilaku |
|---|---|
| NISN dua siswa aktif sama | Dicegah oleh `UNIQUE idx_nisn`. Status `NISN_GANDA` tetap diperiksa sebagai pengaman (mis. skema diubah), tidak bisa dibuat PIN, login ditolak generik |
| NISN kosong | `NISN_KOSONG`, dilewati |
| Siswa pindah kelas | PIN melekat ke siswa, tetap berlaku; portal menampilkan kelas baru; wali kelas baru melihat statusnya |
| Siswa pindah sekolah/keluar | Login baru ditolak generik; sesi lama melihat "data tidak tersedia" |
| Siswa dihapus | Baris `portal_nilai_akses` yatim; login/sesi gagal karena siswa tidak ditemukan |
| Reset saat orang tua sedang membuka portal | Request berikutnya kembali ke form login |
| Portal dinonaktifkan | Semua request `/nilai/` → 503 "belum dibuka"; cookie tidak dihapus tetapi tidak dipakai (tetap tidak berlaku bila PIN direset di antaranya) |
| Dua anak dalam satu keluarga | Login bergantian (satu cookie per browser); di luar scope untuk multi-anak |
| Buat PIN kelas, lalu tab kartu ditutup sebelum dicetak | PIN tidak bisa dilihat lagi → wali memakai Reset untuk siswa itu |
| Banyak orang tua di belakang satu IP (CGNAT/wifi sekolah) | Batas 20 **gagal**/15 menit per IP; login sukses tidak dihitung. Risiko pemblokiran sementara dicatat §11 |
| NISN berformat dengan spasi/titik | Dinormalisasi ke digit saja |
| Jurnal nilai tanpa `student_id` siswa | Aturan tertinggal tahap 1 berlaku apa adanya |
| Semester tanpa data | Ringkasan 0, mapel "Belum ada penilaian", pesan "Belum ada penilaian pada semester ini." |
| Kop kosong | Kartu dan portal memakai "SIMANTAP" |
| Request ke `/nilai/` saat `.updating` | 503 maintenance, sama dengan root |

## 8. Compatibility

- Tabel baru dibuat saat runtime dan tidak mengubah tabel lama. Rollback kode meninggalkan tabel (tidak berbahaya).
- Endpoint dan tampilan tahap 1 tidak berubah perilakunya, kecuali tombol tambahan di tab Nilai.
- Folder `nilai/` baru di root deploy. Auto-updater tidak menyentuhnya kecuali upstream membuat folder bernama sama. `api/index.php`, `app.html`, dan bundle bisa tertimpa → terapkan ulang seperti tahap 1.
- Patch bundle berantai: sumber `pantaunilai1`.
- PHP 8+, ekstensi standar (`hash`, `random_int`, `password_hash`). Tidak memakai `sodium`.

## 9. Di luar scope

- Akun login penuh untuk siswa/ortu (role `SISWA`), atau login dengan tanggal lahir/NIK.
- Reset PIN mandiri oleh orang tua, pengiriman PIN lewat WA/SMS/email, dan kode QR di kartu.
- Satu login untuk beberapa anak.
- Notifikasi otomatis nilai baru atau tugas tertinggal.
- Catatan guru, foto siswa, data insiden/pelanggaran, dan panggilan orang tua di portal.
- Pengelolaan PIN oleh KEPALA_SEKOLAH, TATA_USAHA, atau guru BK.
- Menaruh saklar di halaman Pengaturan sistem yang sudah ada.
- Publikasi nilai terjadwal/manual per kelas; nilai hanya dari jurnal terverifikasi.
- Pembatasan percobaan login staf (`login`) — tetap dicatat sebagai temuan keamanan.
- Tahun ajaran lampau.

## 10. Pengujian dan verifikasi

### 10.1 Uji unit `docs/patches/uji-portal-nilai-unit.php` (tanpa DB)

- PIN: panjang 8, hanya alfabet yang diizinkan, 1.000 PIN tanpa duplikat (sanity), normalisasi `abcd-efgh ` → `ABCDEFGH`.
- Cookie: buat → baca dengan hash yang sama sukses; hash berbeda (reset) gagal; `exp` lewat gagal; tanda tangan diubah 1 karakter gagal; format rusak gagal.
- URL portal dari variasi `SCRIPT_NAME`/HTTPS.

### 10.2 Skrip uji API `docs/patches/uji-portal-nilai-api.mjs`

Prasyarat: seed akun `uji_admin`, `uji_ks`, `uji_tu`, `uji_guru`, `uji_wali` (wali `UJI`), `uji_wali2` (wali `UJI2`), `uji_bk`, kelas `UJI`/`UJI2`, siswa aktif `s_uji_1..3` dan `s_uji_6` di `UJI`, `s_uji_4` pindah (diberi PIN lewat seed), `s_uji_5` tanpa NISN, `s_uji2_1` di `UJI2`. NISN ganda tidak bisa di-seed karena `UNIQUE idx_nisn`. Data nilai dibuat lewat API seperti tahap 1, termasuk catatan guru berisi penanda unik.

| ID | Kasus | Harapan |
|---|---|---|
| P1 | Anonim → `portal_nilai_status` / `cetak_kartu_akses` / `portal_nilai_set_aktif` | 401 (JSON/HTML/JSON) |
| P2 | `uji_ks`, `uji_tu`, `uji_bk` → status `UJI` | 403 |
| P3 | `uji_wali` → status `UJI` / tanpa class_id / `UJI2` | 200 / 200 kelas `UJI` / 403 |
| P4 | `uji_wali` → `portal_nilai_set_aktif` | 403; `uji_admin` → 200 |
| P5 | Status awal | `s_uji_5` `NISN_KOSONG`, lainnya `BELUM`, `s_uji_4` tidak tampil; tidak ada `pin_hash` di JSON |
| P6 | `cetak_kartu_akses` GET | 405 |
| P7 | `cetak_kartu_akses` POST dengan `Origin: https://evil.example` | 403, tidak ada PIN dibuat |
| P8 | Wali mode `baru` kelas `UJI` | 200 HTML; tepat 4 kartu (s1–s3, s6), tanpa s5; PIN format `XXXX-XXXX`; status berubah `AKTIF` |
| P9 | Mode `baru` lagi | "Tidak ada siswa yang perlu dibuatkan PIN" |
| P10 | Wali `UJI` cetak untuk `s_uji2_1` (`student_ids[]`) | Dilewati atau 403 (kelas di luar scope) |
| P11 | Portal nonaktif → `GET /nilai/` dan login benar | 503 |
| P12 | Portal aktif → login PIN salah | 401 pesan generik, sama dengan NISN tidak dikenal |
| P13 | 5× salah untuk NISN s1 → login benar | 429 terkunci; status wali `TERKUNCI` |
| P14 | Reset s1 → login dengan PIN baru | 303 + cookie (kunci terbuka) |
| P15 | Halaman nilai s1 | Memuat nama s1, bagian ringkasan/tertinggal/mapel/pertemuan; **tidak** memuat nama s2/s3 dan penanda catatan guru |
| P16 | `?semester=2` | 200, label semester Genap |
| P17 | Login s2, reset PIN s2, request dengan cookie lama | Kembali ke form login |
| P18 | Nonaktifkan portal, request dengan cookie valid | 503 |
| P19 | Cookie dirusak | Form login, cookie dihapus |
| P20 | NISN `s_uji2_1` (belum punya PIN) dan `s_uji_4` (pindah, diberi PIN via seed) | 401 generik |
| P21 | 20 gagal dari satu IP (NISN acak) | Percobaan ke-21 → 429 |
| P22 | Header keamanan `/nilai/` | CSP, `X-Frame-Options: DENY`, `no-store`, `noindex` ada |
| P23 | `aksi=keluar` | Cookie dihapus, 303 |
| P24 | Regresi tahap 1: `uji-pantau-nilai-api.mjs` | Tetap lulus |

Skrip mengaktifkan portal di awal dan **mengembalikan nilai `portal_nilai_aktif` semula** di akhir. Cleanup SQL menghapus data uji, baris `portal_nilai_*` siswa uji, dan percobaan dari IP uji.

### 10.3 Lint

```
php -l api/portal_nilai.php
php -l api/index.php
php -l nilai/index.php
node --check docs/patches/apply-portal-nilai-bundle.js
node --check assets/index-CGWL69P7-portalnilai1.js
```

### 10.4 Verifikasi end-to-end di browser

1. `uji_admin`: menu "Portal Nilai Ortu" ada; saklar menampilkan Nonaktif; aktifkan.
2. `uji_wali`: Kelas Saya → tab Nilai → "🔑 Akses Orang Tua"; status sesuai P5; "Buat PIN & Cetak Kartu" membuka tab kartu A4 (10 kartu/halaman, garis potong, PIN terbaca); tutup tab → status `AKTIF`.
3. Buka `/nilai/` di jendela penyamaran dengan viewport HP (±390px): masuk dengan NISN + PIN dari kartu; periksa semua bagian, toggle semester, `<details>` pertemuan, Keluar.
4. Wali reset PIN siswa itu → refresh jendela penyamaran → kembali ke form.
5. Admin nonaktifkan portal → `/nilai/` menampilkan "belum dibuka".
6. `uji_ks`, `uji_bk`: tidak ada menu/tombol baru; halaman lain normal; tanpa error console.

## 11. Risiko, trade-off, dan temuan

| Risiko / trade-off | Mitigasi / keputusan |
|---|---|
| **Data pribadi anak di internet publik** | Dua faktor (NISN + PIN acak 8 karakter), throttle, cookie HttpOnly, tanpa catatan guru/foto, `noindex`, saklar global default nonaktif |
| Kartu PIN hilang/tersebar | Reset per siswa membatalkan PIN dan semua sesi lama; wali melihat login terakhir dan jumlah login |
| Sesi 7 hari di HP bersama | Keputusan eksplisit. Mitigasi: tombol Keluar yang jelas, reset PIN mencabut semua sesi |
| PIN hanya tampil sekali (hash) | Keputusan eksplisit. Peringatan di halaman kartu; reset bila terlewat |
| Batas per IP dapat memblokir orang tua di belakang NAT yang sama | Hanya percobaan **gagal** yang dihitung, dan blok berlaku 15 menit. Angka bisa dinaikkan bila terjadi di lapangan |
| `REMOTE_ADDR` di belakang reverse proxy = IP proxy | Batas per IP menjadi batas global per proxy. Dicatat untuk BTIKP; tidak memakai `X-Forwarded-For` karena bisa dipalsukan |
| Pembuatan PIN satu kelas memanggil bcrypt ±36× | ±2–4 detik per kelas, dapat diterima; tidak ada generate "semua kelas" sekaligus |
| Hash PIN sebagai kunci HMAC cookie | Bocor DB = bisa memalsukan cookie; tetapi bocor DB sudah berarti bocor seluruh data nilai. Menghindari rahasia global yang harus disimpan di file publik `api/` |
| Tabel dibuat saat runtime | Konsisten dengan pola `journal_approval_batches`; tidak ada migration CLI |
| Nilai salah input langsung terlihat ortu | Keputusan eksplisit (rilis langsung). Guru perlu diberi tahu bahwa nilai jurnal kini terlihat orang tua |
| Patch bundle dan auto-updater | Sama dengan tahap 1 |

**Temuan (tidak diubah di scope ini):** login staf (`login`) tidak membatasi percobaan dan tidak memanggil `session_regenerate_id` (sudah tercatat di CLAUDE.md). Setelah portal publik ada, host yang sama lebih banyak dikunjungi publik, sehingga kelemahan itu lebih relevan.

## 12. Acceptance criteria

- **AC-1:** Orang tua/siswa dapat membuka `/nilai/` tanpa akun staf, masuk dengan NISN + PIN yang valid, dan melihat nilai per mapel, daftar tertinggal, nilai per pertemuan, serta presensi semester **hanya untuk siswa itu**, untuk semester Ganjil/Genap TA aktif.
- **AC-2:** Angka nilai di portal identik dengan tampilan wali kelas tahap 1 untuk siswa, mapel, dan semester yang sama; catatan guru dan data siswa lain tidak pernah dirender.
- **AC-3:** Login gagal memberi pesan generik yang sama untuk semua penyebab; 5 gagal per NISN atau 20 gagal per IP dalam 15 menit menghasilkan 429 selama 15 menit.
- **AC-4:** Sesi bertahan 7 hari lewat cookie HttpOnly bertanda tangan; reset PIN, portal nonaktif, atau cookie rusak membuat request berikutnya kembali ke form login (atau 503 saat nonaktif).
- **AC-5:** Wali kelas dapat melihat status PIN kelas perwaliannya, membuat PIN untuk siswa yang belum punya, dan mereset PIN siswa terpilih; ADMIN dapat melakukan hal yang sama untuk semua kelas dan mengubah saklar portal. Peran lain 403.
- **AC-6:** PIN disimpan sebagai hash, hanya tampil sekali di halaman kartu yang dikirim lewat POST, tidak pernah muncul di URL maupun respons JSON. `cetak_kartu_akses` menolak GET (405) dan permintaan lintas asal (403).
- **AC-7:** Kartu akses A4 memuat nama sekolah, nama siswa, kelas, NISN, PIN `XXXX-XXXX`, dan alamat portal yang benar untuk host terpasang; 10 kartu per halaman.
- **AC-8:** Portal default nonaktif; `/nilai/` mematuhi gerbang install/maintenance/lisensi dan mengirim header keamanan §4.10.
- **AC-9:** Tidak ada perubahan pada tabel lama; tabel baru dibuat otomatis; endpoint tahap 1 tetap lulus uji regresi.
- **AC-10:** `php -l` bersih, uji unit dan `uji-portal-nilai-api.mjs` (P1–P24) lulus, dan verifikasi browser §10.4 selesai tanpa error console.
