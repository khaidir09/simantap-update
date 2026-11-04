# Spesifikasi: Mode Verifikasi Jurnal — Otomatis SAH & Tetap Bisa Diedit

| | |
|---|---|
| Status | Diimplementasikan (patch lokal): `docs/patches/verifikasi-jurnal-otomatis.md` |
| Versi bundle acuan | `assets/index-CGWL69P7-jenjang1.js` (rujukan aktif di `app.html`) |
| Tanggal | 2026-11-04 |
| Jalur rilis | Patch lokal pada bundle ini (backend PHP + patch bundle string-replacement) |
| Peran terdampak | `GURU` (status & hak edit jurnal), `KEPALA_SEKOLAH` (verifikasi), `ADMIN` (mengatur mode) |

## 1. Latar belakang dan kebutuhan bisnis

Saat ini setiap jurnal mengajar yang dikirim guru berstatus **Pending** dan harus diverifikasi
Kepala Sekolah (satu per satu atau lewat Persetujuan Bulanan). Setelah diverifikasi, guru
**tidak bisa lagi mengedit atau menghapus** jurnalnya.

Kebutuhan sekarang:

1. Jurnal yang dikirim guru **langsung SAH** (`isVerified = 1`) tanpa menunggu Kepala Sekolah.
2. Jurnal SAH **tetap bisa diedit (dan dihapus)** oleh guru pemiliknya, tanpa batas waktu.
3. Perilaku lama tetap tersedia: ADMIN dapat mengembalikan sekolah ke mode **"Perlu verifikasi"**
   lewat saklar di Pengaturan Sistem.
4. Di tampilan, pengesah jurnal SAH otomatis tetap tertulis **atas nama Kepala Sekolah**;
   penanda `SISTEM` hanya tersimpan di database.

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Backend

- `save_journal` (`api/index.php` ±baris 477):
  - selain `ADMIN`/`KEPALA_SEKOLAH`: 403 bila mengubah jurnal guru lain, 403
    `"Jurnal sudah diverifikasi dan tidak dapat diubah"` bila `isVerified = 1`;
  - status verifikasi tidak diambil dari body: jurnal baru `isVerified = 0`, edit
    mempertahankan `isVerified/verifiedBy/verifiedAt` lama; bila sudah terverifikasi, `isDraft`
    dipaksa 0;
  - `isDraft` default 1 bila tidak dikirim; bundle mengirim `isDraft: false` saat Kirim;
  - memakai `REPLACE INTO` lalu `presensiSinkronHarian()` (presensi harian hanya dari jurnal non-draf);
  - `teacherId/teacherName` selalu diisi dari sesi (lihat §10, risiko R4).
- `delete_journal` (±baris 609): selain `ADMIN`/`KEPALA_SEKOLAH`, 403 bila bukan pemilik atau
  bila `isVerified = 1`.
- `verify_journal` (±baris 2054): `KEPALA_SEKOLAH`/`ADMIN`; tolak draf; idempoten.
- Persetujuan massal bulanan: `preview_journal_month_approval` / `approve_journals_month`
  (`api/persetujuan_jurnal.php`), mencatat batch di `journal_approval_batches`.
- `get_journals` (±baris 420): **tidak** mengembalikan `verifiedByName`; frontend selalu
  menampilkan fallback teks "Kepala Sekolah".
- `get_dashboard_analytics` (`api/endpoints_v3.php` ±baris 732/766): `jurnal_belum_verifikasi`
  = `COUNT(*) WHERE isVerified = 0` (draf ikut terhitung).
- `get_system_config` / `get_config` tanpa auth; `save_system_config` khusus ADMIN, upsert key
  apa pun; `save_config` (v2) khusus ADMIN, `UPDATE` satu key tanpa validasi.
- Nama kepala sekolah tersimpan di `system_config.kop_kepsek_nama` (diisi di Pengaturan → Kop Surat).

### 2.2 Data (`journal_entries`)

`isDraft tinyint(1) DEFAULT 1`, `isVerified tinyint(1) DEFAULT 0`, `verifiedBy varchar(128)`,
`verifiedAt timestamp NULL`. Tidak ada kolom riwayat edit.

### 2.3 Frontend (`assets/index-CGWL69P7-jenjang1.js`)

- Tombol **Edit** (daftar, kartu, modal detail) hanya tampil bila `!isVerified && teacherId === user.uid`.
- Tombol **Hapus**: `(!isVerified || role==="ADMIN") && (pemilik || ADMIN || …)`.
- Tombol **Verifikasi** (daftar, kartu) dan **"Verifikasi Sekarang"** (modal detail) untuk
  Kepsek/ADMIN bila `!isVerified`.
- Panel **Persetujuan Bulanan** (dipasang `docs/patches/apply-persetujuan-jurnal-bundle.js`).
- Badge `Verified`/`Pending`, `Terverifikasi`/`Pending`; rekap cetak `SAH`/`PENDING`;
  CSV `TERVERIFIKASI`/`PENDING` + kolom "Diverifikasi Oleh" dari `verifiedByName || "-"`.
- Modal detail: "Diverifikasi Oleh" = `verifiedByName || "Kepala Sekolah"`.
- Pusat Analisis Kepsek: kalimat `"<n> jurnal menunggu verifikasi."` dari `jurnal_belum_verifikasi`.
- Helper global `__jjMuat()` (patch jenjang) sudah memanggil `get_config` sekali saat start.

## 3. Expected behavior

### 3.1 Definisi

| Istilah | Arti |
|---|---|
| Key mode | `system_config.journal_verification_mode`, nilai `OTOMATIS` atau `MANUAL` |
| Mode efektif | nilai key bila valid; **key tidak ada / tidak valid → `OTOMATIS`** |
| Penanda migrasi | `system_config.journal_verification_migrated` = waktu (`Y-m-d H:i:s`) migrasi awal selesai |
| SAH otomatis | `isVerified = 1`, `verifiedBy = 'SISTEM'`, `verifiedAt` = waktu jurnal pertama kali dikirim (non-draf) |
| Jurnal Pending | `isDraft = 0 AND isVerified = 0` |

### 3.2 Mode OTOMATIS (default)

1. **Simpan jurnal** (`save_journal`):
   - `isDraft = 1` dan jurnal belum SAH → tetap `isVerified = 0` (draf tidak SAH).
   - `isDraft = 0` dan jurnal belum SAH (baru atau draf yang dikirim) → SAH otomatis
     (`verifiedBy = 'SISTEM'`, `verifiedAt = date('Y-m-d H:i:s')`).
   - Jurnal sudah SAH (oleh siapa pun) → `isVerified/verifiedBy/verifiedAt` **dipertahankan**
     (tidak di-reset, `verifiedAt` tidak berubah), `isDraft` tetap dipaksa 0 (jurnal SAH
     tidak bisa kembali menjadi draf).
2. **Hak edit/hapus**: pemilik jurnal (`teacherId = uid sesi`) boleh mengedit dan menghapus
   jurnalnya **meskipun SAH**, tanpa batas waktu. Cek kepemilikan tetap berlaku (403 untuk
   jurnal guru lain). `ADMIN`/`KEPALA_SEKOLAH` seperti sekarang.
3. Presensi harian, insiden ALFA, poin pelanggaran, dan panggilan orang tua tetap dihitung ulang
   seperti sekarang pada setiap simpan/hapus (tidak ada perubahan di `presensi_harian.php`).
4. `verify_journal` dan `approve_journals_month` **tetap berfungsi** (aman, idempoten) tetapi
   tidak ditampilkan di UI.
5. Tidak ada jejak audit edit (keputusan bisnis; lihat R1).

### 3.3 Mode MANUAL

Persis perilaku saat ini (§2): jurnal baru Pending, diverifikasi Kepsek/ADMIN, jurnal yang
`isVerified = 1` terkunci untuk guru (edit & hapus 403), tombol Verifikasi dan Persetujuan
Bulanan tampil.

Jurnal yang sudah SAH otomatis sebelum mode diubah ke MANUAL **tetap SAH dan terkunci**
(riwayat tidak diubah).

### 3.4 Migrasi jurnal Pending → SAH

Satu fungsi `vjSahkanPending($db)`:

```sql
UPDATE journal_entries
   SET isVerified = 1, verifiedBy = 'SISTEM',
       verifiedAt = COALESCE(createdAt, NOW())   -- lihat catatan
 WHERE isDraft = 0 AND isVerified = 0
```

Catatan: `journal_entries.createdAt` (`DEFAULT current_timestamp()`) ikut ter-reset setiap
`REPLACE INTO`, jadi nilainya = waktu simpan terakhir jurnal. Itu pendekatan yang cukup untuk
"waktu kirim" jurnal lama. Fungsi mengembalikan jumlah baris terdampak.

Dua pemicu:

1. **Migrasi awal, lazy, sekali jalan.** `vjPastikanMigrasi($db)` dipanggil di awal
   `save_journal`, `delete_journal`, `get_journals`, `get_dashboard_analytics`,
   `get_system_config`, `get_config`. Bila penanda migrasi belum ada **dan** mode efektif
   `OTOMATIS`:
   - `SELECT GET_LOCK('simantap_vj_migrasi', 5)`; bila gagal memperoleh lock → lewati (request
     lain sedang memigrasi).
   - Cek ulang penanda di dalam lock; dalam satu transaksi: `vjSahkanPending()`, upsert key mode
     = `OTOMATIS` (bila belum ada), upsert penanda = waktu sekarang; commit; `RELEASE_LOCK`.
   - Gagal → rollback, request tetap dilanjutkan (dicoba lagi pada request berikutnya).
   - Bila mode efektif `MANUAL` (key sudah diisi `MANUAL`) → hanya tulis penanda, tidak memigrasi.
2. **ADMIN mengubah mode `MANUAL` → `OTOMATIS`** lewat `save_system_config`:
   - Bila ada jurnal Pending dan body tidak membawa `konfirmasi_verifikasi: true` → HTTP 409:
     ```json
     {"error": "Ada 37 jurnal yang belum diverifikasi. Konfirmasi untuk mengesahkan semuanya.",
      "peringatan_verifikasi": {"pending": 37, "dari": "MANUAL", "ke": "OTOMATIS"}}
     ```
   - Dengan konfirmasi (atau jumlah Pending = 0): simpan key dan jalankan `vjSahkanPending()`
     dalam **transaksi yang sama** dengan upsert config.

Mengubah `OTOMATIS` → `MANUAL` tidak memerlukan konfirmasi dan tidak mengubah data jurnal.

### 3.5 Nama pengesah (`verifiedByName`) di `get_journals`

Ditambahkan ke setiap baris (aditif):

| `verifiedBy` | `verifiedByName` |
|---|---|
| `'SISTEM'` | nama kepala sekolah (lihat rantai di bawah) |
| uid user yang masih ada | `users.displayName` (LEFT JOIN `users v ON v.uid = j.verifiedBy`) |
| uid user yang sudah dihapus | nama kepala sekolah |
| `NULL` / kosong | `''` |

Rantai nama kepala sekolah (`vjNamaKepsek($db)`, dihitung sekali per request):
`system_config.kop_kepsek_nama` (trim, tidak kosong) → `displayName` user `role = 'KEPALA_SEKOLAH'`
pertama (`ORDER BY displayName LIMIT 1`) → `'Kepala Sekolah'`.
Nama bersifat dinamis: bila kepala sekolah berganti, jurnal SAH otomatis lama ikut menampilkan
nama baru (keputusan bisnis; lihat R3).

`verifiedBy` mentah (`'SISTEM'`) tetap dikirim di JSON (frontend tidak menampilkannya).

### 3.6 Backend lain

- `get_config`: tambah `journal_verification_mode` (mode efektif). `get_system_config`: bila key
  belum ada, kirim `{value: "OTOMATIS", description: "Bawaan"}`.
- `save_system_config`: validasi key `journal_verification_mode` ∈ {`OTOMATIS`,`MANUAL`}
  (huruf besar setelah trim), selain itu 400 `"Mode verifikasi jurnal tidak valid."`; hapus
  `konfirmasi_verifikasi` dan `journal_verification_migrated` dari `$updates` (penanda tidak
  boleh diubah dari body); isi `description` = `'Mode verifikasi jurnal (OTOMATIS/MANUAL)'`.
- `save_config` (v2): tolak key `journal_verification_mode` dan `journal_verification_migrated`
  (400 `"Gunakan Pengaturan Sistem untuk mengubah mode verifikasi jurnal."`) agar migrasi
  dan konfirmasi tidak terlewati.
- `get_dashboard_analytics`: tambah `mode_verifikasi_jurnal`; dalam mode `OTOMATIS`
  `jurnal_belum_verifikasi = 0`. Dalam mode `MANUAL` tetap seperti sekarang.

### 3.7 Frontend (patch bundle)

Script baru `docs/patches/apply-verifikasi-otomatis-bundle.js`; sumber
`index-CGWL69P7-jenjang1.js` (cek SHA-256), keluaran `index-CGWL69P7-verifotomatis1.js`;
`app.html` diarahkan ke file baru. Setiap penggantian string harus cocok tepat 1 kali (atau
sejumlah yang disebut); bila tidak, script gagal. Hanya kelas Tailwind yang sudah ada di
`assets/index-CC1PBzaO.css`.

- **V0 Helper global**: `__jjMuat()` ikut menyimpan `journal_verification_mode` dari respons
  `get_config` yang sama; `__vjOtomatis()` → `true` bila mode `OTOMATIS` **atau gagal dimuat**
  (selaras default backend). Tidak ada request tambahan.
- **V1 Tombol Edit** (daftar, kartu, modal detail): syarat `!isVerified` diganti
  `(!isVerified || __vjOtomatis())`, syarat pemilik tetap.
- **V2 Tombol Hapus** (daftar, kartu): `(!isVerified || role==="ADMIN")` diganti
  `(!isVerified || role==="ADMIN" || __vjOtomatis())`, syarat pemilik tetap.
- **V3 Tombol Verifikasi / "Verifikasi Sekarang"**: tambah syarat `!__vjOtomatis()`.
- **V4 Panel Persetujuan Bulanan** (tombol pembuka dan panel): tambah syarat `!__vjOtomatis()`.
- **V5 Pusat Analisis**: kalimat "jurnal menunggu verifikasi" hanya bila
  `mode_verifikasi_jurnal !== "OTOMATIS"` (backend juga mengirim 0).
- **V6 Pengaturan Sistem** (ADMIN, di dekat field "Jenjang Sekolah"): select
  "Verifikasi Jurnal" dengan opsi `OTOMATIS` ("Otomatis SAH — guru tetap bisa mengedit") dan
  `MANUAL` ("Perlu verifikasi Kepala Sekolah"). Saat simpan menerima 409
  `peringatan_verifikasi` → `confirm("Ada N jurnal belum diverifikasi. Sahkan semuanya?")`;
  OK → kirim ulang dengan `konfirmasi_verifikasi: true`; Batal → mode tidak berubah. Setelah
  sukses, nilai helper `__vjOtomatis()` diperbarui (atau halaman di-reload).
- **V7** Label badge (`Verified`/`Pending`, `SAH`/`PENDING`) **tidak diubah**. "Diverifikasi Oleh"
  di modal dan CSV otomatis benar karena `verifiedByName` kini dikirim backend (§3.5).
- Pesan sukses submit jurnal tidak perlu diubah.

## 4. Role, authorization, dan data model

| Aksi | GURU (pemilik) | GURU (bukan pemilik) | KEPALA_SEKOLAH | ADMIN | TATA_USAHA |
|---|---|---|---|---|---|
| Edit/hapus jurnal SAH — OTOMATIS | ✓ | 403 | ✓ (seperti sekarang) | ✓ | sama dengan GURU |
| Edit/hapus jurnal SAH — MANUAL | 403 | 403 | ✓ | ✓ | sama dengan GURU |
| Verifikasi satu / massal | – | – | ✓ (UI hanya di MANUAL) | ✓ (UI hanya di MANUAL) | – |
| Membaca mode (`get_config`) | ✓ | ✓ | ✓ | ✓ | ✓ (tanpa auth, seperti sekarang) |
| Mengubah mode (`save_system_config`) | – | – | 403 | ✓ | 403 |

Data model:

- **Tidak ada perubahan skema** `journal_entries`.
- `system_config` mendapat dua baris baru (dibuat lazy): `journal_verification_mode`,
  `journal_verification_migrated`.
- `verifiedBy = 'SISTEM'` adalah nilai sentinel baru; tidak ada user dengan uid `SISTEM`
  (uid dibuat `uniqid()`).

## 5. File dan interface terkait

### 5.1 Backend

| File | Perubahan |
|---|---|
| `api/verifikasi_jurnal.php` | **baru**, helper saja (tanpa action, diawali guard `SIMANTAP_API`): `VJ_SISTEM`, `vjMode()`, `vjOtomatis()`, `vjPastikanMigrasi()`, `vjHitungPending()`, `vjSahkanPending()`, `vjNamaKepsek()` |
| `api/index.php` | `require_once` helper; `save_journal`, `delete_journal`, `get_journals`, `get_system_config`, `save_system_config` |
| `api/endpoints_v2.php` | `get_config` (tambah key + panggil migrasi lazy), `save_config` (tolak 2 key) |
| `api/endpoints_v3.php` | `get_dashboard_analytics` |
| `api/persetujuan_jurnal.php` | tidak diubah |
| `api/presensi_harian.php` | tidak diubah |

Interface:

- `POST api/index.php?action=save_journal` — respons tidak berubah (`{success, id}`).
- `GET|POST ...?action=delete_journal&id=` — respons tidak berubah.
- `GET ...?action=get_journals` — tambah `verifiedByName` per baris.
- `GET ...?action=get_config` — tambah `journal_verification_mode`.
- `GET ...?action=get_system_config` — tambah/isi `journal_verification_mode`.
- `POST ...?action=save_system_config` body `{..., journal_verification_mode?, konfirmasi_verifikasi?}`
  → 200 / 400 / 403 / 409 (`peringatan_verifikasi`).
- `GET ...?action=get_dashboard_analytics` — tambah `mode_verifikasi_jurnal`.

### 5.2 Frontend

| File | Perubahan |
|---|---|
| `docs/patches/apply-verifikasi-otomatis-bundle.js` | **baru**, patch V0–V6 |
| `assets/index-CGWL69P7-verifotomatis1.js` | **dihasilkan** script di atas |
| `app.html` | rujukan bundle → `verifotomatis1` |

### 5.3 Dokumen dan uji

| File | Isi |
|---|---|
| `docs/patches/verifikasi-jurnal-otomatis.md` | catatan patch (cara menerapkan, rollback) |
| `docs/patches/uji-verifikasi-otomatis-api.mjs` | uji API otomatis (§9.3) |
| `CLAUDE.md` | baris tabel `api/verifikasi_jurnal.php`; perbarui kalimat "Journals are the exception…" di Security state |

## 6. Di luar scope

- Jejak audit/riwayat edit jurnal (kolom `updatedAt/updatedBy` atau tabel log).
- Batas waktu edit (per bulan / N hari).
- Mengganti label badge `Verified/Pending` menjadi `SAH/Draf`.
- Snapshot nama kepala sekolah per jurnal.
- Status verifikasi jurnal ekstrakurikuler (`verify_ekskul_journal`) dan RPP (`lesson_rpps`).
- Jurnal Bimbingan PKL (`pkl_jurnal`).
- Memperbaiki `teacherId` yang ikut berpindah saat Kepsek/ADMIN mengedit jurnal guru (R4),
  stack trace di respons `save_journal`, `get_config` tanpa auth, dan celah lain di CLAUDE.md
  "Security state" — dicatat, tidak diperbaiki di sini.
- Membatalkan SAH (kembali ke Pending) oleh Kepsek.
- Mengubah `jurnal_belum_verifikasi` di mode MANUAL agar mengecualikan draf.

## 7. Compatibility

- Respons API hanya bertambah field; konsumen lama tidak terpengaruh.
- Bundle lama (sebelum patch) + backend baru: jurnal tetap otomatis SAH, tetapi tombol Edit
  tetap tersembunyi untuk jurnal SAH (backend sudah mengizinkan). Karena itu backend dan bundle
  harus dipasang bersamaan.
- Tabel `journal_approval_batches` dan riwayatnya tidak disentuh; panel Persetujuan Bulanan
  kembali muncul bila mode diubah ke MANUAL.
- Auto-updater portal bisa menimpa `api/index.php`, `api/endpoints_v*.php`, `app.html`, atau
  bundle. Setelah update, jalankan checklist §9.6. Data (`isVerified = 1`, key config) tetap
  aman meski kode tertimpa; bila kode tertimpa, sistem kembali ke perilaku lama (Pending +
  terkunci) untuk jurnal baru.
- Laporan bulanan/cetak/CSV: jurnal SAH otomatis terhitung "Terverifikasi"/"SAH" seperti
  jurnal yang diverifikasi Kepsek.

## 8. Edge cases

| # | Kasus | Perilaku |
|---|---|---|
| E1 | Guru menyimpan draf (OTOMATIS) | `isVerified = 0`; tombol Edit tampil seperti sekarang |
| E2 | Draf dikirim (non-draf) | SAH otomatis, `verifiedAt` = waktu kirim |
| E3 | Guru mengedit jurnal SAH otomatis | Tetap SAH, `verifiedBy/verifiedAt` tidak berubah; presensi harian dihitung ulang |
| E4 | Guru mengedit jurnal SAH dan mengirim `isDraft: true` | `isDraft` dipaksa 0, tetap SAH |
| E5 | Guru mengedit jurnal yang dulu diverifikasi Kepsek (OTOMATIS) | Diizinkan; `verifiedBy` tetap uid Kepsek |
| E6 | Guru mengirim `isVerified`/`verifiedBy` di body | Diabaikan (seperti sekarang) |
| E7 | Guru mengedit/menghapus jurnal guru lain | 403 di kedua mode |
| E8 | Dua request bersamaan saat migrasi awal | `GET_LOCK` + cek ulang penanda → migrasi hanya sekali |
| E9 | Migrasi awal gagal (mis. DB error) | Rollback, penanda tidak ditulis, request tetap jalan; dicoba ulang |
| E10 | ADMIN ubah ke OTOMATIS, ada 0 Pending | Langsung tersimpan tanpa 409 |
| E11 | ADMIN ubah ke OTOMATIS, ada Pending, menekan Batal di konfirmasi | Mode tetap MANUAL, data tidak berubah |
| E12 | ADMIN kirim mode yang sama (OTOMATIS → OTOMATIS) | Tidak ada 409; tetap jalankan `vjSahkanPending()` (idempoten, menangkap sisa race) |
| E13 | Nilai key di DB tidak valid (mis. `otomatis ` atau `X`) | Dinormalisasi trim+uppercase; tetap tidak valid → efektif OTOMATIS |
| E14 | ADMIN ubah ke MANUAL | Jurnal SAH (termasuk SISTEM) tetap SAH dan terkunci untuk guru; jurnal berikutnya Pending |
| E15 | `kop_kepsek_nama` kosong dan tidak ada user KEPALA_SEKOLAH | `verifiedByName = 'Kepala Sekolah'` |
| E16 | Verifikator (uid) sudah dihapus dari `users` | `verifiedByName` = nama kepala sekolah |
| E17 | Kepsek/ADMIN memverifikasi lewat API saat OTOMATIS (UI lama/cache) | Tetap berhasil, idempoten |
| E18 | Frontend gagal memuat `get_config` | `__vjOtomatis()` = true (sama dengan default backend); backend tetap penentu akhir |
| E19 | Guru membuka halaman sebelum ADMIN mengubah mode ke MANUAL | Tombol Edit masih tampil; simpan ditolak 403 oleh backend dengan pesan lama |
| E20 | Lazy migrasi pada sekolah dengan ribuan jurnal Pending | Satu `UPDATE` ber-`WHERE` sederhana; diterima. Bila perlu, batasi dengan indeks yang ada |

## 9. Testing dan verifikasi end-to-end

### 9.1 Persiapan (Laragon lokal, dev mode)

1. Salin database sekolah ke lokal (dump → import ke DB Laragon).
2. `api/config.local.php` dengan `DB_*` lokal dan `define('SIMANTAP_DEV_MODE', true);`;
   buat `api/install.lock`.
3. Siapkan akun: `ADMIN`, `KEPALA_SEKOLAH`, dua `GURU` (A dan B). Isi `kop_kepsek_nama`.
4. Sebelum memasang patch, catat:
   `SELECT COUNT(*) FROM journal_entries WHERE isDraft=0 AND isVerified=0;` (= P0) dan
   `SELECT COUNT(*) FROM journal_entries WHERE isDraft=1;` (= D0).

### 9.2 Pemeriksaan statis

- `php -l` untuk `api/verifikasi_jurnal.php`, `api/index.php`, `api/endpoints_v2.php`, `api/endpoints_v3.php`.
- `node docs/patches/apply-verifikasi-otomatis-bundle.js` berhasil; setiap anchor cocok
  sesuai jumlah; `node --check assets/index-CGWL69P7-verifotomatis1.js`.
- `grep` nama action yang diubah di keempat file endpoint (tidak ada salinan bayangan baru).

### 9.3 Uji API otomatis (`node docs/patches/uji-verifikasi-otomatis-api.mjs <base-url>`)

Login sebagai tiap role lalu periksa:

1. Request pertama → penanda migrasi tertulis; `SELECT` Pending = 0; jumlah `verifiedBy='SISTEM'`
   bertambah P0; draf tetap D0 dengan `isVerified=0`.
2. GURU A: simpan draf → `isVerified=0`; kirim → `isVerified=1, verifiedBy='SISTEM'`, catat `verifiedAt`.
3. GURU A: edit jurnal tsb (ubah topik) → 200; `verifiedAt` sama; `isDraft=0`.
4. GURU A: edit dengan `isDraft:true, isVerified:false, verifiedBy:"x"` → tetap SAH, `isDraft=0`.
5. GURU B: edit/hapus jurnal GURU A → 403.
6. GURU A: hapus jurnal SAH miliknya → 200; baris hilang; presensi harian tanggal itu dihitung ulang.
7. `get_journals` (Kepsek): jurnal SISTEM → `verifiedByName` = `kop_kepsek_nama`; jurnal
   diverifikasi Kepsek → `displayName` Kepsek.
8. KEPALA_SEKOLAH `save_system_config {journal_verification_mode:"MANUAL"}` → 403.
9. ADMIN → `MANUAL` → 200. GURU A kirim jurnal baru → `isVerified=0`; edit jurnal SAH lama → 403
   `"Jurnal sudah diverifikasi dan tidak dapat diubah"`; hapus → 403.
10. ADMIN → `OTOMATIS` tanpa konfirmasi → 409 `peringatan_verifikasi.pending = 1`; data tidak berubah.
11. ADMIN → `OTOMATIS` + `konfirmasi_verifikasi:true` → 200; jurnal no. 9 menjadi SAH SISTEM.
12. ADMIN `save_system_config {journal_verification_mode:"X"}` → 400;
    `{journal_verification_migrated:"..."}` → nilai penanda tidak berubah.
13. ADMIN `save_config {config_key:"journal_verification_mode", ...}` → 400.
14. `get_dashboard_analytics` di OTOMATIS → `jurnal_belum_verifikasi = 0`, `mode_verifikasi_jurnal = "OTOMATIS"`.
15. Migrasi bersamaan: hapus penanda, buat beberapa Pending, kirim 5 request paralel →
    penanda tertulis sekali, tidak ada error 500.

### 9.4 Skenario end-to-end di browser (DevTools → Network terbuka)

1. **GURU A**: buat jurnal → kirim → kartu berbadge `Verified`; tombol **Edit** dan **Hapus**
   tampil; buka detail → "Diverifikasi Oleh" = nama dari `kop_kepsek_nama`; tidak ada teks
   "SISTEM" di UI.
2. Edit jurnal tsb → simpan → perubahan terlihat, badge tetap `Verified`.
3. **KEPALA_SEKOLAH**: daftar jurnal tanpa tombol **Verifikasi**, tanpa panel/tombol
   **Persetujuan Bulanan**; Pusat Analisis tanpa kalimat "jurnal menunggu verifikasi";
   Laporan Bulanan menampilkan `SAH`; ekspor CSV kolom "Diverifikasi Oleh" berisi nama kepsek.
4. **ADMIN**: Pengaturan Sistem → "Verifikasi Jurnal" = Otomatis → ubah ke **Perlu verifikasi**
   → simpan. Login GURU A → kirim jurnal baru → `Pending`, tombol Edit ada; jurnal SAH lama
   tanpa tombol Edit/Hapus. Login Kepsek → tombol Verifikasi dan Persetujuan Bulanan tampil;
   verifikasi jurnal baru → "Diverifikasi Oleh" = displayName Kepsek (setelah reload).
5. **ADMIN**: buat satu jurnal Pending lagi (via GURU), lalu ubah ke **Otomatis** → muncul
   konfirmasi "Ada 1 jurnal…"; Batal → mode tetap; ulangi, OK → tersimpan, jurnal menjadi SAH.
6. Tampilan HP (lebar 375px): select baru di Pengaturan tidak membuat scroll horizontal.

### 9.5 Pemeriksaan data akhir

```sql
SELECT config_key, config_value FROM system_config
 WHERE config_key LIKE 'journal_verification_%';
SELECT isDraft, isVerified, verifiedBy = 'SISTEM' AS sistem, COUNT(*)
  FROM journal_entries GROUP BY 1,2,3;
```

Mode OTOMATIS: tidak ada baris `isDraft=0, isVerified=0`.

### 9.6 Checklist pasca auto-update

`app.html` masih merujuk `verifotomatis1`; `grep -n "vjPastikanMigrasi\|vjOtomatis" api/index.php api/endpoints_v2.php api/endpoints_v3.php`
masih ada; `api/verifikasi_jurnal.php` masih ada. Bila tertimpa, jalankan ulang patch.

### 9.7 Rollback

Kembalikan `app.html` ke `index-CGWL69P7-jenjang1.js` dan file PHP dari backup. Data jurnal
yang sudah SAH otomatis **tidak** dikembalikan ke Pending (tidak diperlukan; bila mutlak perlu:
`UPDATE journal_entries SET isVerified=0, verifiedBy=NULL, verifiedAt=NULL WHERE verifiedBy='SISTEM'`
— hanya atas keputusan sekolah). Key config boleh dibiarkan.

## 10. Risiko dan trade-off

| # | Risiko / trade-off | Mitigasi / keputusan |
|---|---|---|
| R1 | Isi jurnal SAH bisa berubah setelah rekap bulanan dicetak/ditandatangani, tanpa jejak | Diterima (keputusan bisnis: tanpa audit, tanpa batas waktu). Dapat ditambahkan nanti (§6) |
| R2 | "SAH" tidak lagi berarti diperiksa Kepala Sekolah, padahal UI menampilkan nama kepsek | Diterima atas permintaan sekolah; DB tetap membedakan via `verifiedBy='SISTEM'` |
| R3 | Nama kepsek dinamis: jurnal lama menampilkan nama kepsek baru setelah pergantian | Diterima; snapshot di luar scope |
| R4 | Bug lama: `save_journal` mengisi `teacherId` dari sesi, sehingga Kepsek/ADMIN yang mengedit jurnal guru mengambil alih kepemilikan | Tidak diubah di sini; dicatat untuk perbaikan terpisah |
| R5 | Migrasi lazy menulis data pada request baca (`get_journals`/`get_config`) | Dibatasi sekali jalan dengan penanda + `GET_LOCK`; transaksi; gagal tidak memblokir request |
| R6 | Mode default OTOMATIS bila key hilang: sekolah lain/instalasi baru yang memakai bundle ini otomatis SAH | Disengaja (kebutuhan sekolah ini); ADMIN bisa beralih ke MANUAL |
| R7 | Auto-updater menimpa patch → jurnal baru kembali Pending + terkunci | Checklist §9.6; data yang sudah SAH tidak hilang |
| R8 | Hak hapus jurnal SAH oleh guru → presensi/insiden/poin terhitung ulang | Sama dengan menghapus jurnal belum terverifikasi saat ini; perilaku `presensiSinkronHarian` tidak berubah |
| R9 | Bundle lama ter-cache di browser setelah deploy | Nama file bundle baru (cache-busting via `app.html`) |

## 11. Acceptance criteria

1. Tanpa key `journal_verification_mode`, mode efektif `OTOMATIS`; request pertama setelah
   deploy mengesahkan semua jurnal non-draf Pending (`verifiedBy='SISTEM'`) tepat satu kali dan
   menulis `journal_verification_migrated`.
2. Mode OTOMATIS: jurnal non-draf yang dikirim GURU tersimpan `isVerified=1, verifiedBy='SISTEM'`,
   `verifiedAt` = waktu kirim pertama; draf tetap `isVerified=0`.
3. Mode OTOMATIS: pemilik bisa mengedit dan menghapus jurnal SAH miliknya (UI dan API); edit
   tidak mengubah `isVerified/verifiedBy/verifiedAt` dan tidak menjadikannya draf.
4. Di kedua mode, GURU tidak bisa mengedit/menghapus jurnal guru lain (403), dan status
   verifikasi dari body selalu diabaikan.
5. Mode OTOMATIS: tombol Verifikasi, "Verifikasi Sekarang", panel Persetujuan Bulanan, dan
   kalimat "jurnal menunggu verifikasi" tidak tampil; `jurnal_belum_verifikasi = 0`.
6. `get_journals` mengembalikan `verifiedByName`: jurnal SISTEM → `kop_kepsek_nama` (fallback
   displayName user KEPALA_SEKOLAH, lalu "Kepala Sekolah"); jurnal yang diverifikasi manual →
   `displayName` verifikator. Teks "SISTEM" tidak pernah tampil di UI, cetak, maupun CSV.
7. Hanya ADMIN yang bisa mengubah mode (Pengaturan Sistem); nilai selain `OTOMATIS`/`MANUAL`
   ditolak 400; `save_config` menolak key mode/penanda.
8. Mode MANUAL berperilaku identik dengan sebelum fitur ini (Pending, verifikasi Kepsek,
   jurnal terverifikasi terkunci untuk guru), termasuk jurnal yang dulu SAH otomatis.
9. Perubahan MANUAL → OTOMATIS dengan jurnal Pending menghasilkan 409 `peringatan_verifikasi`
   dan baru mengesahkan semuanya setelah dikonfirmasi; Batal tidak mengubah apa pun.
10. Presensi harian/insiden/poin tetap dihitung ulang setelah simpan/edit/hapus jurnal SAH.
11. `php -l` bersih, patch bundle berhasil dengan anchor yang tepat, skenario §9.3 dan §9.4 lulus.
