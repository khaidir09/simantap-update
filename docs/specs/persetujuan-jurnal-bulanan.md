# Spesifikasi: Persetujuan Massal Jurnal Bulanan oleh Kepala Sekolah

| | |
|---|---|
| Status | Disetujui untuk implementasi (belum dikerjakan) |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch detail jurnal/cetak (`assets/index-CGWL69P7-jurnal1.js`) |
| Tanggal | 2026-09-24 |
| Jalur rilis | Patch lokal pada bundle ini **dan** usulan perubahan ke upstream BTIKP |
| Peran terdampak | `KEPALA_SEKOLAH`, `ADMIN` (menyetujui); `GURU` (aturan kunci jurnal terverifikasi) |

## 1. Latar belakang dan kebutuhan bisnis

Kepala sekolah wajib memverifikasi jurnal mengajar yang dikirim guru. Saat ini verifikasi
hanya bisa dilakukan **satu jurnal per klik**. Dengan puluhan guru dan beberapa jurnal per hari,
jumlahnya mencapai ratusan hingga ribuan jurnal per bulan.

Masalah yang diselesaikan:

1. **Beban klik kepala sekolah.** Menyetujui ratusan jurnal satu per satu tidak realistis.
2. **Tenggat administrasi.** Jurnal harus berstatus terverifikasi sebelum rekap/cetak bulanan
   (Laporan Bulanan menampilkan "N Sesi Terverifikasi (M Pending)").

Solusi: kepala sekolah (atau ADMIN) dapat **menyetujui sekaligus semua jurnal terkirim pada
satu bulan**, umumnya dilakukan di akhir bulan berjalan. Verifikasi satu per satu tetap ada.

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Backend (`api/index.php`)

- `verify_journal` (sekitar baris 2040):
  - role `KEPALA_SEKOLAH`/`ADMIN`; selain itu `errorResponse('Akses ditolak')` (HTTP 400);
  - menerima satu `id` dari body atau query;
  - `UPDATE journal_entries SET isVerified = 1, verifiedBy = <uid sesi>, verifiedAt = NOW-app WHERE id = ?`;
  - **tidak** mengecek `isDraft` dan tidak mengecek keberadaan jurnal (id salah tetap `success`).
- `save_journal` (sekitar baris 456) memakai `REPLACE INTO journal_entries` dan **menerima
  `isVerified`, `verifiedBy`, `verifiedAt` dari body**. Akibatnya:
  - guru bisa mengirim `isVerified: true` dan memverifikasi jurnalnya sendiri;
  - bundle selalu mengirim `isVerified: false, isDraft: false` saat submit **maupun edit**,
    sehingga mengedit jurnal yang sudah terverifikasi (oleh siapa pun) diam-diam
    **menghapus status verifikasinya**.
- `delete_journal` (sekitar baris 646) hanya `requireAuth()`; tidak ada cek kepemilikan maupun
  status verifikasi.
- `get_journals` mengembalikan semua jurnal (GURU: miliknya saja) tanpa filter bulan.
- `get_dashboard_analytics` (`api/endpoints_v3.php`) menghitung `belumVerifikasi` dari
  `isVerified = 0` (termasuk draf).

### 2.2 Data (`install/schema.sql`, tabel `journal_entries`)

- Kolom terkait: `isDraft tinyint(1) DEFAULT 1`, `isVerified tinyint(1) DEFAULT 0`,
  `verifiedBy varchar(128)`, `verifiedAt timestamp NULL`, `date date`.
- Indeks: `PRIMARY(id)`, `idx_teacherId`, `idx_date`.
- Tidak ada jejak batch/riwayat verifikasi.

### 2.3 Frontend (`assets/index-CGWL69P7-jurnal1.js`)

- API client: `et.journals.verify(id, verifiedBy)` → `POST verify_journal {id, verifiedBy}`.
- Komponen daftar jurnal `XTe({onAddJournal, onEditJournal, user})`:
  - state filter bulan `N` (singkatan "Jan".."Des", default bulan berjalan), tahun `E`
    (default tahun berjalan), pencarian `R`, filter guru `L`;
  - daftar terfilter `Ee` **hanya mencocokkan singkatan bulan, bukan tahun**;
  - handler `ie(Q)` memverifikasi satu jurnal lalu memperbarui state lokal dan `alert(...)`;
  - tombol "Verifikasi" per baris dan "Verifikasi Sekarang" di detail jurnal, tampil hanya
    untuk `KEPALA_SEKOLAH`/`ADMIN` pada jurnal yang belum terverifikasi;
  - ringkasan `ke.verifiedCount / ke.totalMeetings` dan tombol "Laporan Bulanan".
- Form jurnal mengirim `isVerified: !1, isDraft: !1` setiap kali submit, jadi semua jurnal dari
  UI berstatus terkirim (`isDraft = 0`). Draf (`isDraft = 1`) hanya muncul dari data lama atau
  klien API yang tidak mengirim `isDraft`.

## 3. Expected behavior

### 3.1 Alur pengguna (kepala sekolah / ADMIN)

1. Buka menu Jurnal. Di sebelah tombol "Laporan Bulanan" ada tombol baru
   **"Setujui Jurnal Bulanan"** (hanya untuk `KEPALA_SEKOLAH`/`ADMIN`).
2. Klik tombol untuk membuka modal **Persetujuan Jurnal Bulanan**:
   - pemilih bulan dan tahun, default mengikuti filter halaman (`N`/`E`), yaitu bulan berjalan;
     bulan setelah bulan berjalan tidak bisa dipilih;
   - setiap perubahan bulan/tahun memanggil `preview_journal_month_approval`;
   - modal menampilkan:
     - **Akan disetujui: N jurnal** (angka utama);
     - dikecualikan: `D` draf, `F` bertanggal setelah hari ini (hanya bulan berjalan);
     - sudah terverifikasi sebelumnya: `V`;
     - tabel rincian per guru (nama guru, jumlah yang akan disetujui), urut nama;
     - catatan: "Filter guru dan pencarian di halaman tidak berlaku. Semua guru ikut.";
   - tombol **Batal** dan **"Setujui N Jurnal"**. Tombol ini nonaktif saat memuat, saat
     `N = 0`, atau saat permintaan sedang diproses.
3. Klik "Setujui N Jurnal" untuk memanggil `approve_journals_month` dengan **daftar id dari
   preview**.
4. Setelah berhasil:
   - modal menampilkan hasil "X jurnal disetujui" dan, jika ada, "Y dilewati karena status
     berubah";
   - state daftar jurnal (`r`) diperbarui: jurnal dengan id di `approvedIds` diberi
     `isVerified: true`, `verifiedBy: <uid>`, `verifiedByName: <displayName>`,
     `verifiedAt: <waktu respons>`;
   - ringkasan "Status Verifikasi" di halaman langsung berubah tanpa reload.
5. Jika gagal, tampilkan pesan `error` dari server di modal. Tidak ada perubahan state.

Verifikasi satu per satu (`verify_journal`) tetap berjalan seperti sekarang, dengan tambahan
aturan pada §3.4.

### 3.2 Aturan kelayakan jurnal (dihitung di server)

Untuk bulan `M` (format `YYYY-MM`), zona waktu `APP_TIMEZONE`:

- `start` = tanggal 1 bulan `M`;
- `cutoff` = hari ini jika `M` adalah bulan berjalan, atau hari terakhir bulan `M` jika bulan lampau;
- **layak disetujui** jika `date BETWEEN start AND cutoff AND isDraft = 0 AND isVerified = 0`.

Kategori untuk ringkasan (semua dalam bulan `M`):

| Kategori | Kondisi |
|---|---|
| `eligible` | kondisi layak di atas |
| `excluded.draft` | `isDraft = 1 AND isVerified = 0 AND date BETWEEN start AND cutoff` |
| `excluded.futureDated` | `isVerified = 0 AND date > cutoff` (hanya mungkin untuk bulan berjalan) |
| `alreadyVerified` | `isVerified = 1` |

### 3.3 Kontrak endpoint

Kedua action ditaruh di `api/index.php` tepat setelah `case 'verify_journal'`. Nama action
belum dipakai di keempat file endpoint (sudah dicek dengan grep; cek ulang sebelum implementasi).

#### `GET api/index.php?action=preview_journal_month_approval&month=YYYY-MM`

- Otorisasi: `requireRole(['KEPALA_SEKOLAH', 'ADMIN'])` (401 tanpa sesi, 403 role lain).
- Validasi:
  - `month` wajib, regex `^\d{4}-(0[1-9]|1[0-2])$`, dan valid lewat `checkdate`; jika tidak → 400
    `Format bulan tidak valid (YYYY-MM)`;
  - `month` > bulan berjalan → 400 `Bulan belum berjalan`.
- Tidak mengubah data (read-only).
- Respons 200:

```json
{
  "month": "2026-09",
  "start": "2026-09-01",
  "cutoff": "2026-09-24",
  "isCurrentMonth": true,
  "eligible": {
    "count": 412,
    "ids": ["j_...", "..."],
    "byTeacher": [ { "teacherId": "u_...", "teacherName": "Ahmad", "count": 18 } ]
  },
  "excluded": { "draft": 2, "futureDated": 1 },
  "alreadyVerified": 37
}
```

`byTeacher` diurutkan berdasarkan `teacherName`. `ids` berisi semua jurnal yang layak, tanpa paging.

#### `POST api/index.php?action=approve_journals_month`

- Otorisasi: `requireRole(['KEPALA_SEKOLAH', 'ADMIN'])`.
- Body: `{ "month": "YYYY-MM", "ids": ["j_...", ...] }`.
- Validasi:
  - `month` seperti di preview;
  - `ids` wajib berupa array string non-kosong, tanpa duplikat (dedup di server), maksimal
    **10.000** elemen. Jika kosong → 400 `Tidak ada jurnal yang dipilih`; jika melebihi batas → 400.
- Proses dalam satu transaksi:
  1. `SELECT id FROM journal_entries WHERE id IN (...) AND isDraft = 0 AND isVerified = 0
     AND date BETWEEN start AND cutoff FOR UPDATE`. Placeholder dibangun dinamis, dipecah per
     500 id (pola `get_class_attendance_summary`). `start`/`cutoff` **dihitung ulang saat
     eksekusi**, bukan diambil dari body.
  2. Jika hasilnya kosong: commit tanpa menulis apa pun, lalu kembalikan `approved: 0`
     tanpa `batchId`.
  3. `UPDATE journal_entries SET isVerified = 1, verifiedBy = <uid sesi>, verifiedAt = <now>
     WHERE id IN (<hasil langkah 1>)`, dipecah per 500.
  4. `INSERT` satu baris ke `journal_approval_batches` (§4.2).
  5. Commit. Jika ada exception: rollback, lalu 500 `Gagal menyetujui jurnal` (tanpa stack trace).
- Respons 200:

```json
{
  "success": true,
  "batchId": "jab_66f2...",
  "month": "2026-09",
  "approved": 410,
  "approvedIds": ["j_...", "..."],
  "skipped": 2,
  "verifiedAt": "2026-09-24 15:02:11"
}
```

`skipped` = jumlah id dalam body yang tidak lagi memenuhi kriteria: sudah diverifikasi pihak
lain, dihapus, diubah jadi draf, tanggal dipindah ke luar rentang, atau id asing.
**Id di luar kriteria tidak pernah diverifikasi**, sehingga jurnal yang masuk setelah preview
tetap pending untuk batch berikutnya.

### 3.4 Pengetatan terkait verifikasi (bagian dari scope)

| # | Lokasi | Aturan baru |
|---|---|---|
| S1 | `save_journal` | Abaikan `isVerified`, `verifiedBy`, `verifiedAt` dari body. Sebelum `REPLACE`, baca baris lama berdasarkan `id`. **Baru:** `isVerified=0`, `verifiedBy/At=NULL`. **Sudah ada:** pertahankan ketiga nilai lama. Jika baris lama terverifikasi, `isDraft` dipaksa `0`. |
| S2 | `save_journal` | Jika baris lama `isVerified = 1` dan role sesi **bukan** `KEPALA_SEKOLAH`/`ADMIN` (praktisnya GURU), tolak dengan 403 `Jurnal sudah diverifikasi dan tidak dapat diubah`. Penolakan terjadi **sebelum** transaksi, jadi presensi, insiden, poin, dan panggilan orang tua tidak ikut tertulis. |
| S3 | `delete_journal` | Jika jurnal ada, `isVerified = 1`, dan role sesi bukan `KEPALA_SEKOLAH`/`ADMIN`, tolak dengan 403 `Jurnal sudah diverifikasi dan tidak dapat dihapus`. Perilaku lain tidak berubah. |
| S4 | `verify_journal` | Ambil baris dulu. Tidak ada → 404 `Jurnal tidak ditemukan`. `isDraft = 1` → 400 `Jurnal masih draf dan belum dapat diverifikasi`. Sudah terverifikasi → 200 `success` tanpa menimpa `verifiedBy/At` (idempoten). |
| S5 | `save_journal` | **Sudah diterapkan (2026-09-24).** Jika `id` sudah ada, `teacherId` baris lama ≠ uid sesi, dan role bukan `ADMIN`/`KEPALA_SEKOLAH` → 403 `Anda tidak berhak mengubah jurnal guru lain`. Dicek sebelum transaksi. Berlaku juga untuk jurnal yang belum terverifikasi. |
| S6 | `delete_journal` | **Sudah diterapkan (2026-09-24).** Jika jurnal ada, `teacherId` ≠ uid sesi, dan role bukan `ADMIN`/`KEPALA_SEKOLAH` → 403 `Anda tidak berhak menghapus jurnal guru lain`. Id yang tidak ada tetap `success` seperti sebelumnya. |

Urutan cek di `save_journal`/`delete_journal`: kepemilikan (S5/S6) lebih dulu, lalu kunci
jurnal terverifikasi (S2/S3). GURU yang menyentuh jurnal terverifikasi milik guru lain
mendapat pesan kepemilikan.

### 3.5 Matriks akses

| Aksi | ADMIN | KEPALA_SEKOLAH | TATA_USAHA | GURU | Tanpa sesi |
|---|---|---|---|---|---|
| `preview_journal_month_approval` | ✅ | ✅ | 403 | 403 | 401 |
| `approve_journals_month` | ✅ | ✅ | 403 | 403 | 401 |
| `verify_journal` (satuan) | ✅ | ✅ | 400 (tetap) | 400 (tetap) | 401 |
| Edit jurnal terverifikasi (`save_journal`) | ✅ (status dipertahankan) | ✅ (status dipertahankan) | 403 | 403 | 401 |
| Hapus jurnal terverifikasi (`delete_journal`) | ✅ | ✅ | 403 | 403 | 401 |
| Edit/hapus jurnal milik guru lain | ✅ | ✅ | 403 | 403 | 401 |
| Tombol "Setujui Jurnal Bulanan" di UI | tampil | tampil | tidak | tidak | – |

## 4. Role, authorization, dan data model

### 4.1 Otorisasi

- Actor selalu dari sesi: `$user['uid']` → `verifiedBy`/`approved_by`, dan
  `$user['displayName']` → `approved_by_name`. Nilai `verifiedBy` dari body diabaikan di semua action.
- Sesuai `requireRole()`, perubahan role baru berlaku setelah login ulang (keterbatasan yang
  sudah ada, tidak diubah).

### 4.2 Tabel baru `journal_approval_batches`

Dibuat dengan `CREATE TABLE IF NOT EXISTS` di awal kedua action baru (pola `get_classes`).
DDL yang sama dimasukkan ke usulan BTIKP sebagai migrasi resmi.

```sql
CREATE TABLE IF NOT EXISTS journal_approval_batches (
  id               VARCHAR(64)  NOT NULL PRIMARY KEY,   -- uniqid('jab_')
  month            CHAR(7)      NOT NULL,               -- 'YYYY-MM'
  cutoff_date      DATE         NOT NULL,
  approved_by      VARCHAR(128) NOT NULL,               -- users.uid
  approved_by_name VARCHAR(255) NOT NULL,
  approved_at      DATETIME     NOT NULL,
  journal_count    INT          NOT NULL,
  journal_ids      LONGTEXT     NOT NULL,               -- json_encode(array id)
  KEY idx_month (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- Satu baris per aksi massal yang menyetujui ≥ 1 jurnal.
- `approved_at` sama persis dengan `verifiedAt` yang ditulis ke jurnal.
- Tidak ada action untuk membaca atau membatalkan batch (lihat §6).

### 4.3 `journal_entries`

Tidak ada perubahan skema. Semantik kolom tetap: `isVerified`, `verifiedBy` (uid), `verifiedAt`.

## 5. File dan interface terkait

### 5.1 Backend

| File | Perubahan |
|---|---|
| `api/index.php` — `case 'save_journal'` | S1, S2 (§3.4) |
| `api/index.php` — `case 'delete_journal'` | S3 |
| `api/index.php` — `case 'verify_journal'` | S4 |
| `api/index.php` — action baru `preview_journal_month_approval`, `approve_journals_month` | §3.3, ditaruh setelah `verify_journal` |
| `api/db.php` | tidak diubah (pakai `requireRole`, `getRequestBody`, `jsonResponse`, `errorResponse`) |

Helper bersama untuk menghitung `start`/`cutoff` dan memvalidasi `month` boleh didefinisikan
sebagai fungsi lokal di `api/index.php` dengan `function_exists` guard, agar tidak bentrok saat
endpoint file di-`require` ulang.

### 5.2 Frontend (patch bundle lokal)

- Skrip patch baru `docs/patches/apply-persetujuan-jurnal-bundle.js`, mengikuti pola
  `apply-pusat-analisis-bundle.js`:
  - sumber `assets/index-CGWL69P7-jurnal1.js`, dicek SHA-256 (prasyarat: patch detail
    jurnal/cetak sudah diterapkan);
  - hasil ditulis ke `assets/index-CGWL69P7-setuju1.js`, lalu `app.html` diarahkan ke file itu;
    file sumber tidak diubah;
  - setiap anchor dicari persis satu kali; jika tidak cocok, skrip gagal dengan pesan jelas.
- Titik patch:
  - **P1** API client: tambah `et.journals.previewMonth(month)` dan
    `et.journals.approveMonth(month, ids)`;
  - **P2** `XTe`: state modal, tombol "Setujui Jurnal Bulanan" di samping "Laporan Bulanan"
    (hanya `KEPALA_SEKOLAH`/`ADMIN`), komponen modal (§3.1), dan pembaruan state `r` setelah sukses;
  - **P3** konversi `N` (singkatan bulan) + `E` (tahun) menjadi `YYYY-MM` untuk default modal.
- Penanganan error: tampilkan `error` dari body JSON; untuk HTTP 500 tanpa body, tampilkan
  "Terjadi kesalahan server".

### 5.3 Dokumen

| File | Isi |
|---|---|
| `docs/specs/persetujuan-jurnal-bulanan.md` | dokumen ini |
| `docs/patches/persetujuan-jurnal-bulanan.md` | cara menerapkan patch, checklist pasca auto-update, rollback |
| `docs/patches/uji-persetujuan-jurnal-api.mjs` | skrip uji API (§12) |
| `docs/usulan-btikp/backend-persetujuan-jurnal.diff` | diff `api/index.php` + DDL migrasi |
| `docs/usulan-btikp/RINGKASAN-PERSETUJUAN-JURNAL.md` | ringkasan untuk pengembang upstream |

## 6. Di luar scope

- Pembatalan (undo) batch atau pembatalan verifikasi satuan.
- Notifikasi ke guru saat jurnal disetujui.
- Penjadwalan otomatis (auto-approve di akhir bulan).
- Pembatasan tombol hanya pada N hari terakhir bulan: tombol tersedia kapan saja.
- Persetujuan per guru atau dengan checkbox per jurnal.
- Halaman riwayat batch (data hanya tersimpan di tabel).
- Penolakan jurnal atau permintaan revisi ke guru.
- Perbaikan filter tahun di daftar jurnal (`Ee` mengabaikan `E`). Modal memakai bulan+tahun
  sendiri, jadi tidak terdampak.
- `save_journal` oleh ADMIN/KS masih menimpa `teacherId`/`teacherName` dengan akun pengedit
  (cek kepemilikan untuk GURU sudah masuk scope: S5/S6).
- Mengubah `belumVerifikasi` di dashboard agar mengecualikan draf.
- Menambahkan `verifiedByName` ke respons `get_journals`.
- Celah keamanan lain di CLAUDE.md (auth hilang di action lain, CSRF lewat GET, dll.).

## 7. Compatibility

- **Bundle lama** (tanpa patch) tetap berfungsi: action baru tidak dipanggil, dan
  `verify_journal` tetap menerima payload yang sama.
- **Perubahan perilaku yang disengaja:**
  - edit jurnal terverifikasi oleh ADMIN/KS tidak lagi me-reset verifikasi;
  - GURU yang mencoba mengedit/menghapus jurnal terverifikasi lewat API mendapat 403
    (UI sudah menyembunyikan tombolnya, jadi dampaknya hanya pada klien non-UI);
  - `verify_journal` pada draf mendapat 400 (bundle hanya mencatat ke console).
- **Auto-updater BTIKP** dapat menimpa `api/index.php` dan `app.html`/bundle. Setelah update,
  jalankan checklist di `docs/patches/persetujuan-jurnal-bulanan.md`. Tabel
  `journal_approval_batches` tetap ada dan tidak mengganggu.
- **Rollback aplikasi** tidak menghapus tabel baru. Status `isVerified` yang sudah ditulis tetap valid.
- **Zona waktu:** `start`/`cutoff`/`verifiedAt` memakai `date()` PHP (`APP_TIMEZONE`),
  konsisten dengan `verify_journal`.
- Tidak butuh ekstensi PHP baru. Tetap kompatibel PHP 8.x dan MySQL/MariaDB bawaan installer.

## 8. Edge cases

| # | Kasus | Perilaku |
|---|---|---|
| E1 | Tidak ada jurnal layak di bulan terpilih | Preview `eligible.count = 0`, tombol setujui nonaktif, pesan "Tidak ada jurnal yang perlu disetujui". |
| E2 | Guru mengirim jurnal baru setelah preview dibuka | Tidak ikut disetujui (tidak ada di `ids`), tetap pending. |
| E3 | Jurnal di `ids` disetujui satuan oleh pengguna lain di sela waktu | Masuk `skipped`; `verifiedBy/At` lama tidak ditimpa. |
| E4 | Jurnal di `ids` dihapus atau tanggalnya dipindah ke luar bulan/cutoff | Masuk `skipped`. |
| E5 | Guru mengedit isi jurnal pending di sela preview dan klik setujui | Jurnal tetap disetujui dengan isi terbaru (tidak ada versi/`updatedAt`). Diterima sebagai trade-off (§10). |
| E6 | Kepala sekolah dan ADMIN menyetujui bulan yang sama bersamaan | `FOR UPDATE` + kondisi `isVerified = 0`: setiap jurnal disetujui tepat sekali dan hanya masuk satu batch; permintaan kedua melihatnya sebagai `skipped`. |
| E7 | Klik ganda tombol setujui | Tombol nonaktif selama proses. Jika tetap terkirim dua kali, permintaan kedua menghasilkan `approved: 0` tanpa baris batch baru. |
| E8 | Bulan berjalan dengan jurnal bertanggal setelah hari ini | Masuk `excluded.futureDated`, tidak disetujui. |
| E9 | Bulan lampau yang tertunggak | Bisa dipilih. Cutoff = akhir bulan tersebut. |
| E10 | `month` bulan depan, format salah (`2026-9`, `2026-13`) | 400. |
| E11 | Hari terakhir bulan | Cutoff = hari ini = akhir bulan, jadi seluruh bulan tercakup. |
| E12 | `ids` berisi id asing/bulan lain | Tidak cocok kriteria, masuk `skipped`, tidak diverifikasi. |
| E13 | Jumlah jurnal > 10.000 dalam satu bulan | 400. Tidak realistis untuk satu sekolah; batas mencegah payload berlebih. |
| E14 | Draf lama di bulan terpilih | Dikecualikan dan ditampilkan sebagai `excluded.draft`. Verifikasi satuan pada draf juga ditolak (S4). |
| E15 | Sesi kepsek kedaluwarsa saat modal terbuka | 401, modal menampilkan pesan dan tidak mengubah state. |
| E16 | Akun kepsek sudah dihapus tapi sesi masih aktif | Tetap bisa menyetujui (keterbatasan `requireAuth` berbasis sesi). Dicatat sebagai risiko. |
| E17 | Filter guru/pencarian aktif di halaman | Diabaikan oleh aksi massal; modal menyatakan semua guru ikut. |

## 9. Asumsi

- "Jurnal yang dikirim guru" = `isDraft = 0`. Semua jurnal dari UI saat ini berstatus demikian.
- Verifikasi bulanan adalah persetujuan administratif. Kepala sekolah tidak diharuskan membuka
  setiap jurnal sebelum menyetujui massal.
- Volume per sekolah jauh di bawah 10.000 jurnal/bulan.

## 10. Risiko dan trade-off

| Risiko / trade-off | Mitigasi / alasan |
|---|---|
| Persetujuan massal mengurangi pemeriksaan isi jurnal | Keputusan bisnis. Modal menampilkan rincian per guru, verifikasi satuan tetap ada, dan setiap batch tercatat. |
| Tidak ada undo; salah klik menyetujui satu bulan penuh | Modal konfirmasi eksplisit dengan jumlah. `journal_ids` di log memungkinkan pemulihan manual lewat SQL jika benar-benar diperlukan. |
| Konten jurnal bisa berubah antara preview dan approve (E5) | Diterima. Menambah versi/`updatedAt` butuh perubahan skema yang lebih besar. |
| DDL di handler (`CREATE TABLE IF NOT EXISTS`) | Pola yang sudah ada (`get_classes`). Biayanya kecil (no-op setelah tabel ada). Migrasi resmi diusulkan ke upstream. |
| Auto-updater menimpa patch lokal | Checklist pasca-update + usulan upstream agar fitur menjadi bawaan. |
| S2/S3 memutus alur klien non-UI yang mengedit jurnal terverifikasi | Disengaja. UI sudah memperlakukan jurnal terverifikasi sebagai terkunci untuk guru. |
| Mengabaikan `isVerified` dari body mengubah perilaku lama "edit = reset verifikasi" | Disengaja. Perilaku lama adalah bug. Jika guru perlu merevisi jurnal terverifikasi, ADMIN/KS yang mengedit (di luar scope: alur revisi). |
| `requireRole` tidak cek ulang DB (E16) | Keterbatasan yang sudah ada, di luar scope. |
| Payload `ids` besar (≈ 100 KB untuk 6.000 jurnal) | Masih wajar. Query dipecah per 500 id. |

## 11. Acceptance criteria

1. KEPALA_SEKOLAH dan ADMIN melihat tombol "Setujui Jurnal Bulanan" di halaman Jurnal; GURU dan TATA_USAHA tidak.
2. Modal default ke bulan/tahun filter halaman. Bulan setelah bulan berjalan tidak bisa dipilih.
3. Preview menampilkan jumlah layak, draf, bertanggal setelah hari ini, sudah terverifikasi, dan
   rincian per guru. Angkanya sama dengan query referensi §12.3.
4. "Setujui N Jurnal" memverifikasi tepat jurnal di preview yang masih layak, lalu menampilkan
   `approved` dan `skipped`.
5. Setelah approve, semua jurnal tersebut punya `isVerified = 1`, `verifiedBy = uid` penyetuju,
   dan `verifiedAt` yang sama. Tercatat satu baris `journal_approval_batches` dengan
   `journal_count` = `approved` dan `journal_ids` = `approvedIds`.
6. Draf, jurnal bertanggal setelah hari ini (bulan berjalan), dan jurnal bulan lain tidak pernah
   berubah status oleh aksi massal.
7. Jurnal yang dikirim setelah preview tetap `isVerified = 0`.
8. Approve ulang dengan `ids` yang sama menghasilkan `approved: 0`, tanpa baris batch baru, dan
   `verifiedAt` tidak berubah.
9. TATA_USAHA/GURU mendapat 403 dan tanpa sesi 401 untuk kedua action baru.
10. `month` tidak valid atau bulan depan menghasilkan 400.
11. `save_journal` mengabaikan `isVerified/verifiedBy/verifiedAt` dari body: jurnal baru selalu
    `isVerified = 0`, dan edit oleh ADMIN/KS mempertahankan status verifikasi.
12. GURU mendapat 403 saat mengedit/menghapus jurnal terverifikasi. Tidak ada presensi, insiden,
    atau panggilan orang tua baru yang tertulis dari permintaan yang ditolak.
13. `verify_journal` mengembalikan 404 untuk id tidak ada, 400 untuk draf, dan idempoten untuk
    jurnal yang sudah terverifikasi.
14. Verifikasi satuan dari UI tetap berfungsi.
15. Ringkasan "Status Verifikasi" di halaman Jurnal berubah tanpa reload setelah approve.
16. GURU/TATA_USAHA mendapat 403 saat mengedit (`save_journal` dengan `id` milik orang lain)
    atau menghapus jurnal guru lain, terverifikasi maupun belum. Jurnal tersebut tidak
    berubah, dan tidak ada presensi/insiden yang tertulis. Pemilik tetap bisa mengedit dan
    menghapus jurnal miliknya yang belum terverifikasi.
17. `php -l api/index.php` lolos dan skrip patch bundle berjalan tanpa error.

## 12. Testing dan verifikasi end-to-end

### 12.1 Persiapan

- Lingkungan **dev/staging saja** (Laragon lokal). `api/config.local.php` dengan
  `SIMANTAP_DEV_MODE` dan `api/install.lock` sudah ada.
- Akun uji (email `<uid>@uji.test`, password `UJI_PASSWORD`): `uji_admin` (ADMIN), `uji_ks`
  (KEPALA_SEKOLAH), `uji_tu` (TATA_USAHA), `uji_guru` dan `uji_wali` (GURU). Akun yang sudah
  dipakai skrip uji ekskul bisa dipakai ulang.
- **Bulan uji terisolasi** `TEST_MONTH` (default `2020-01`) yang tidak berisi jurnal nyata.
  Skrip membatalkan diri jika preview bulan itu tidak kosong sebelum data uji dibuat, sehingga
  data sekolah tidak ikut disetujui.

### 12.2 Pemeriksaan statis

```
php -l api/index.php
node docs/patches/apply-persetujuan-jurnal-bundle.js
```

Pastikan `app.html` merujuk `index-CGWL69P7-setuju1.js`, dan anchor patch ditemukan tepat satu kali.

### 12.3 Query referensi

```sql
-- ganti :start, :cutoff sesuai bulan
SELECT
  SUM(isDraft = 0 AND isVerified = 0 AND date BETWEEN :start AND :cutoff) AS eligible,
  SUM(isDraft = 1 AND isVerified = 0 AND date BETWEEN :start AND :cutoff) AS draft,
  SUM(isVerified = 0 AND date > :cutoff)                                   AS future_dated,
  SUM(isVerified = 1)                                                      AS already_verified
FROM journal_entries
WHERE date BETWEEN :start AND LAST_DAY(:start);

SELECT * FROM journal_approval_batches ORDER BY approved_at DESC LIMIT 5;
```

### 12.4 Skrip uji API `docs/patches/uji-persetujuan-jurnal-api.mjs`

Pola sama dengan `uji-pembina-ekskul-api.mjs` (fetch + cookie jar, `check(id, cond)`, keluar
dengan kode ≠ 0 jika ada FAIL). Jalankan:
`BASE=http://localhost/simantap/api/index.php TEST_MONTH=2020-01 node docs/patches/uji-persetujuan-jurnal-api.mjs`

| ID | Skenario | Harapan |
|---|---|---|
| T0 | Preview `TEST_MONTH` sebagai `uji_ks` sebelum membuat data | `eligible.count = 0` dan `alreadyVerified = 0`, jika tidak skrip berhenti |
| T1 | `uji_guru` membuat 3 jurnal terkirim, `uji_wali` 2 jurnal terkirim, `uji_guru` 1 jurnal `isDraft: true` di `TEST_MONTH` | 200 |
| T2 | `uji_guru` membuat jurnal dengan `isVerified: true, verifiedBy: 'uji_guru'` | tersimpan dengan `isVerified = false` (S1) |
| T3 | Preview sebagai `uji_ks` | `eligible.count = 6` (T1 + T2), `excluded.draft = 1`, `byTeacher` 2 guru dengan jumlah benar |
| T4 | Preview/approve sebagai `uji_tu`, `uji_guru`, dan tanpa sesi | 403 / 403 / 401 |
| T5 | Preview `month` = `2020-13`, `2020-1`, bulan depan | 400 |
| T6 | Setelah preview, `uji_wali` membuat 1 jurnal baru di `TEST_MONTH` | – |
| T7 | `uji_admin` memverifikasi satuan 1 jurnal dari daftar preview | 200 |
| T8 | Approve sebagai `uji_ks` dengan `ids` preview T3 + 1 id asing | `approved = 5`, `skipped = 2` |
| T9 | `get_journals` sebagai `uji_ks` | 5 jurnal: `verifiedBy = uji_ks` dengan `verifiedAt` sama; jurnal T7: `verifiedBy = uji_admin`; jurnal T6 dan draf: belum terverifikasi |
| T10 | Approve ulang dengan `ids` T8 | `approved = 0`, tanpa `batchId` |
| T11 | `uji_guru` edit jurnal terverifikasi (`save_journal` id sama) | 403 |
| T12 | `uji_guru` hapus jurnal terverifikasi | 403 |
| T13 | `uji_admin` edit jurnal terverifikasi dengan body `isVerified: false` | 200, jurnal tetap terverifikasi, `verifiedBy` tidak berubah |
| T14 | `verify_journal` pada draf / id tidak ada / jurnal terverifikasi | 400 / 404 / 200 tanpa perubahan `verifiedAt` |
| T15 | Approve dengan `ids: []` | 400 |
| T16 | Preview bulan berjalan (read-only) setelah `uji_guru` membuat jurnal bertanggal besok (dilewati jika hari ini akhir bulan) | jurnal itu tidak ada di `eligible.ids` dan terhitung di `excluded.futureDated`; jurnal lalu dihapus |
| T17 | Approve paralel dua permintaan identik (`Promise.all`, `uji_ks` + `uji_admin`) atas jurnal baru di `TEST_MONTH` | total `approved` = jumlah jurnal, masing-masing tepat sekali |
| T18 | `uji_guru` mengirim `save_journal` dengan `id` jurnal pending milik `uji_wali` | 403, isi dan `teacherId` jurnal tidak berubah |
| T19 | `uji_guru` menghapus jurnal pending milik `uji_wali` | 403, jurnal masih ada |
| T20 | `uji_wali` mengedit lalu menghapus jurnal pending miliknya sendiri | 200 / 200 |
| T21 | `uji_ks` menghapus jurnal pending milik `uji_wali` | 200 |
| Bersih | `uji_admin` menghapus semua jurnal uji | 200 |

Karena belum ada action untuk menghapus batch, baris log uji dibersihkan manual:
`DELETE FROM journal_approval_batches WHERE month = '2020-01';`. Jumlah baris
`journal_approval_batches` untuk `TEST_MONTH` sebelum pembersihan harus **2** (T8 dan T17),
dan skrip menampilkan perintah pembersihan ini di akhir.

### 12.5 Verifikasi end-to-end di browser (manual, pelengkap)

1. Login `uji_ks`, buka Jurnal, lalu klik "Setujui Jurnal Bulanan". Modal menampilkan bulan
   berjalan dan angka yang sama dengan query §12.3.
2. Ganti ke `TEST_MONTH` (setelah membuat data uji), lalu setujui. Pesan hasil tampil, dan
   ringkasan "Status Verifikasi" serta badge di daftar berubah tanpa reload.
3. Login `uji_guru`: jurnal terverifikasi tidak menampilkan tombol edit/hapus. Coba lewat API → 403.
4. Buka "Laporan Bulanan" bulan uji: "N Sesi Terverifikasi (0 Pending)" sesuai.
5. Login `uji_tu`/`uji_guru`: tombol "Setujui Jurnal Bulanan" tidak tampil.

### 12.6 Checklist pasca auto-update (masuk ke `docs/patches/persetujuan-jurnal-bulanan.md`)

- `grep -n "approve_journals_month" api/index.php`: jika hilang, terapkan ulang perubahan backend dari diff usulan.
- `app.html` masih merujuk `index-CGWL69P7-setuju1.js`: jika bundle upstream berubah, cari ulang anchor lalu perbarui skrip patch.
- Jalankan ulang skrip uji §12.4.

### 12.7 Rollback

- Frontend: arahkan `app.html` kembali ke `index-CGWL69P7-jurnal1.js`.
- Backend: kembalikan `api/index.php` dari git (`git checkout -- api/index.php` pada commit sebelum fitur).
- Tabel `journal_approval_batches` dan status verifikasi yang sudah ditulis boleh dibiarkan.
