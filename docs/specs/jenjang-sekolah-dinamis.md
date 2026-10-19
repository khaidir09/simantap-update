# Spesifikasi: Jenjang Sekolah Dinamis (SD/SMP/SMA/SMK) — Level Kelas & Menu PKL

Status: draf siap implementasi · Tanggal: 2026-10-19
Bundle dasar: `assets/index-CGWL69P7-pkl2.js` (yang sedang dirujuk `app.html`)

Ringkasan keputusan wawancara:

| Topik | Keputusan |
|---|---|
| Siapa yang mengatur | ADMIN, di halaman Pengaturan (tab konfigurasi yang sama dengan Kop Surat) |
| Penyimpanan | Key baru `school_level` di `system_config`, disimpan lewat `save_system_config` yang sudah ada |
| Format level | Romawi: SD `I–VI`, SMP `VII–IX`, SMA/SMK `X–XII`. Input angka (`7A`, `1 B`) tetap dikenali |
| Default instalasi lama | Dideteksi dari nama sekolah (`kop_sekolah`), dipakai selama key belum disimpan |
| Ganti jenjang padahal ada data jenjang lama | Peringatan berisi jumlah data tidak cocok, lalu boleh disimpan setelah konfirmasi. Data lama tidak diubah |
| PKL di luar SMK | Menu disembunyikan **dan** semua aksi `pkl_*` ditolak. Tabel & data PKL tetap utuh |
| Cakupan tambahan | Fallback/parser level (frontend & backend) dan label/placeholder yang menyebut level |
| Guru kelas SD, Fase A–F, SMK 4 tahun (XIII) | Di luar scope |
| Pengujian | Pola yang sama dengan fitur sebelumnya: `php -l`, unit PHP, uji API `.mjs` + seed/cleanup SQL, skenario browser |

## 1. Latar belakang dan kebutuhan bisnis

SIMANTAP dibangun dengan asumsi sekolah SMA/SMK: level kelas yang bisa dipilih hanya X/XI/XII dan menu Bimbingan PKL selalu tampil. BTIKP ingin aplikasi yang sama bisa dipakai SD dan SMP tanpa fork. Akibat asumsi itu sekarang:

- SD/SMP tidak bisa memilih tingkat kelasnya di Kelola Kelas maupun penugasan mapel guru.
- Filter kelas di form jurnal (`id.startsWith("XII"/"XI"/"X")`) membuang semua kelas yang tidak berawalan X, sehingga guru SMP/SD tidak melihat kelasnya.
- Menu PKL muncul di sekolah yang tidak punya program PKL dan membingungkan pengguna.

Tujuannya, satu setting jenjang yang diatur ADMIN menentukan daftar level kelas, parser level, contoh/placeholder, dan ketersediaan fitur PKL.

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Frontend (`assets/index-CGWL69P7-pkl2.js`)

| # | Lokasi (penanda string) | Perilaku |
|---|---|---|
| F1 | Kelola Kelas, label `"Tingkat Kelas"` | `<select>` hardcode `Kelas X/XI/XII` |
| F2 | Tambah Penugasan Mapel, label `"Level Kelas"` (setelah `"Tambah Penugasan Baru"`) | `<select>` hardcode X/XI/XII |
| F3 | Kelola Guru, `"Mode Edit Parameter Guru"` → `"Level Kelas"` | `<select>` hardcode X/XI/XII |
| F4 | Profil guru, `"Status Beban Mengajar & Deskripsi Guru Mapel"` → `"Level Kelas"` | `<select>` hardcode X/XI/XII |
| F5 | Patch tahun ajaran (`__tmS ... :["X","XI","XII"]`) | Fallback level bila tidak ada data kelas |
| F6 | Form jurnal (`un.id.startsWith("XII")?"XII":un.id.startsWith("XI")?"XI":...`) | Level kelas diturunkan dari prefix ID; selain X* menjadi `""` lalu kelas tersaring keluar |
| F7 | Placeholder: `"Contoh: X IPA 1"`, `"Contoh: Kelas X (Sepuluh)"`, `"X / XI / XII (Fase E/F)"`, `"Contoh: RPP Matematika Trigonometri Kelas X"`, `"Contoh: Silabus Matematika Fase E Kelas X"`, `"Buku Siswa Matematika Kelas XI, Lembar Desain Proyek"` | Contoh khas SMA |
| F8 | Menu sidebar `{id:"bimbingan-pkl",label:"Bimbingan PKL",roles:["GURU","KEPALA_SEKOLAH","ADMIN"]}` | Selalu tampil untuk role tsb |
| F9 | Router `e==="bimbingan-pkl"&&a.jsx(__pklHalaman,{})` dan daftar halaman valid `[..."bimbingan-pkl",...].includes(e)` | Halaman PKL selalu bisa dirender |

Frontend sudah membaca konfigurasi lewat `et.config.get()` (`get_config`, flat) dan `get_system_config` (bentuk `{key:{value,description}}`).

### 2.2 Backend

- `system_config` hanya menyimpan `kop_*`, `active_academic_year`, `active_semester`, `subject_categories`, dll. **Belum ada key jenjang.**
- `get_config` (`api/endpoints_v2.php`) **tanpa auth**, mengembalikan map flat. `get_system_config` (`api/index.php`) juga tanpa auth. `save_system_config` hanya untuk ADMIN, meng-upsert key apa pun tanpa validasi nilai.
- `classes.level` adalah `VARCHAR(50)` bebas. `get_classes` (`api/endpoints_v3.php`) mengisi `classes` dari `students` dengan `level = SUBSTRING_INDEX(class_id,' ',1)` (salah untuk `VII_A`, `7A`). `save_class` menerima level apa pun.
- `api/endpoints_tu.php:336` (`gtk_update_atribut`, sinkron `teacher_subjects`) menurunkan `class_level` dengan regex `^(X{1,3}|XI{0,2}|IX|VIII|VII|XII|XI|X)\b`. Regex ini tidak mengenali `I–VI` maupun angka.
- `teacherTeachesClassSubject` (`api/db.php`), `api/jadwal_kbm.php`, `api/pantau_nilai.php`, `api/teacher_subject_access.php` membandingkan `class_level` dengan `classes.level` atau prefix `level_` secara generik. **Tidak bergantung pada X/XI/XII** sehingga tidak perlu diubah.
- `api/bimbingan_pkl.php` mengunci semua logika ke kelas XII (`pklTokenXII`, `pklKelasXII`, syarat pembimbing). Dispatch-nya lewat 16 `case 'pkl_*'` di `api/index.php` (sekitar baris 2388–2450) yang memanggil `handlePkl*($db)`.

## 3. Expected behavior

### 3.1 Definisi

| Jenjang (`school_level`) | Level kelas (urut) | PKL |
|---|---|---|
| `SD` | `I, II, III, IV, V, VI` | nonaktif |
| `SMP` | `VII, VIII, IX` | nonaktif |
| `SMA` | `X, XI, XII` | nonaktif |
| `SMK` | `X, XI, XII` | aktif (tetap kelas XII seperti sekarang) |

**Jenjang efektif** = nilai `system_config.school_level` bila ada dan valid. Kalau tidak ada, hasil deteksi (§3.2). Nilai disimpan huruf besar.

### 3.2 Deteksi default (hanya bila key belum ada / tidak valid)

Teks sumber: `kop_sekolah`. Kalau kosong, pakai `kop_instansi`. Dicocokkan **case-insensitive** pada awal kata, dengan urutan:

1. `SMK`, `STM`, `SMEA`, `MAK` → `SMK`
2. `SMP`, `SLTP`, `MTS` → `SMP`
3. `SD`, `SDN`, `SDIT`, `MI`, `MIN`, `MIS` (sebagai kata utuh) → `SD`
4. selain itu (termasuk `SMA`, `MA`, `MAN`, kosong) → `SMA`

Hasil deteksi **tidak ditulis** ke database. Hasil itu hanya dikembalikan sebagai nilai efektif sampai ADMIN menyimpan pengaturan.

### 3.3 Parser level kelas (sama persis di PHP dan JS)

`parseLevel(teks, levelsJenjang) → string` ('' bila tidak dikenali):

1. Trim, huruf besar, buang prefiks `KELAS ` bila ada.
2. **Angka di depan** `^(1[0-2]|[1-9])(?![0-9])` → ubah ke Romawi (`7A` → `VII`, `10 IPA` → `X`).
3. **Romawi diikuti pemisah** (spasi, `_`, `-`, `.`, `/`) atau akhir teks. Coba dari yang terpanjang: `XII, VIII, XI, VII, III, IX, IV, VI, II, X, V, I`. Contoh: `VIII_A` → `VIII`, `X IPA 1` → `X`.
4. **Romawi menempel huruf** (`XIIA`, `IVB`): pakai prefiks Romawi terpanjang yang huruf berikutnya bukan `I/V/X`, **dan hanya bila level itu termasuk `levelsJenjang`**. Aturan ini mencegah `IPA 1` dibaca `I` di sekolah SMA.
5. Selain itu ''.

Parser **tidak** menolak level di luar jenjang pada langkah 2–3. Hasilnya tetap dikembalikan, dan pemanggil yang menandai "di luar jenjang".

### 3.4 Backend

#### File baru `api/jenjang_sekolah.php` (helper saja, tanpa action)

Diawali `if (!defined('SIMANTAP_API')) exit;` lalu `require_once` di `api/index.php` setelah `db.php`. Fungsi:

- `jenjangDaftar(): array`: `['SD'=>[...], 'SMP'=>[...], 'SMA'=>[...], 'SMK'=>[...]]`.
- `jenjangDeteksi(string $namaSekolah): string`: aturan §3.2.
- `jenjangAktif(mysqli $db): array` → `['jenjang'=>'SMK', 'sumber'=>'pengaturan'|'deteksi', 'levels'=>[...], 'pkl_aktif'=>bool]`. Hasil di-cache statis per request.
- `jenjangParseLevel(?string $teks, array $levels): string`: aturan §3.3.
- `jenjangHitungTidakCocok(mysqli $db, string $jenjangBaru): array` → `['kelas'=>n, 'penugasan'=>n, 'siswa'=>n]`:
  - kelas: baris `classes` yang `parseLevel(level ?: id ?: name)` tidak kosong dan tidak termasuk level jenjang baru;
  - penugasan: `teacher_subjects.class_level` tidak kosong dan tidak termasuk level jenjang baru;
  - siswa: `students` berstatus aktif (`LOWER(status)='aktif'`, karena status bercampur huruf besar/kecil) yang level `class_id`-nya tidak termasuk.
- `jenjangWajibPkl(mysqli $db): void`: kalau `pkl_aktif` false, `errorResponse('Fitur Bimbingan PKL hanya tersedia untuk jenjang SMK.', 403)`.

#### `get_config` (`api/endpoints_v2.php`) dan `get_system_config` (`api/index.php`), aditif

Kalau `school_level` tidak ada di hasil query, tambahkan nilai efektif hasil deteksi:
- `get_config`: `school_level: "SMK"`.
- `get_system_config`: `school_level: {value: "SMK", description: "Terdeteksi otomatis dari nama sekolah"}`.

Kedua action tetap tanpa auth. Jenjang bukan data sensitif, dan halaman login pun boleh membacanya.

#### `save_system_config` (`api/index.php`), validasi tambahan khusus key `school_level`

1. Normalisasi huruf besar. Kalau bukan `SD|SMP|SMA|SMK`: 400 `Jenjang sekolah tidak valid.` dan tidak ada yang disimpan.
2. Kalau berbeda dari jenjang efektif saat ini **dan** `jenjangHitungTidakCocok` > 0 di salah satu hitungan, **dan** body tidak berisi `konfirmasi_jenjang: true`: respons **409** `{error:"...", peringatan_jenjang:{kelas, penugasan, siswa, dari, ke}}`. Tidak ada key yang disimpan, termasuk key `kop_*` di request yang sama.
3. `konfirmasi_jenjang` sendiri tidak disimpan sebagai key konfigurasi. Loop upsert harus melewatinya.
4. Data kelas/penugasan/siswa **tidak diubah**.

Catatan: `errorResponse` hanya mengirim `{error}`, jadi 409 di atas memakai `jsonResponse([...], 409)` (signature `jsonResponse(mixed $data, int $status = 200)` sudah ada di `api/db.php`).

#### Guard PKL (`api/index.php`)

Panggil `jenjangWajibPkl($db)` sebelum setiap `handlePkl*` di 16 `case 'pkl_*'` (atau satu blok bersama di awal kelompok case). Ini juga berlaku untuk halaman cetak `pkl_cetak_rekap`/`pkl_cetak_kartu`. Untuk keduanya, respons 403 boleh berupa HTML sederhana dengan pesan yang sama, mengikuti cara halaman cetak menangani error saat ini. Tabel `pkl_*` tidak dibuat/diubah ketika diblokir.

#### Parser level di backend

- `get_classes` (`api/endpoints_v3.php`): seeding awal dari `students` tidak lagi memakai `SUBSTRING_INDEX`, tetapi memilih distinct `class_id, class_name` lalu mengisi `level = jenjangParseLevel(class_id, levels)` (fallback `class_name`) lewat prepared `INSERT IGNORE`.
- `gtk_update_atribut` (`api/endpoints_tu.php:336`): regex diganti `jenjangParseLevel($firstKelas, $levels)`.
- `save_class` **tidak** menolak level di luar jenjang (agar record lama tetap bisa disimpan). Pembatasan dilakukan di UI. Lihat trade-off §10.
- `api/bimbingan_pkl.php` tidak berubah. Logika XII tetap benar karena PKL hanya aktif di SMK.

### 3.5 Frontend (patch bundle `docs/patches/apply-jenjang-sekolah-bundle.js`)

Sumber `index-CGWL69P7-pkl2.js` (cek SHA-256), keluaran `index-CGWL69P7-jenjang1.js`. `app.html` diarahkan ke file baru. Pola script sama dengan `apply-tahun-ajaran-aktif-bundle.js`: setiap penggantian string harus cocok tepat 1 kali (atau sejumlah yang disebut), dan bila tidak, script gagal. Hanya kelas Tailwind yang sudah ada di `assets/index-CC1PBzaO.css`.

- **J0 Helper global** disisipkan sekali: `__jjDaftar`, `__jjParseLevel(teks, levels)` (port 1:1 dari §3.3), `__jjMuat()` (memanggil `get_config` sekali; render root aplikasi menunggu hasilnya, maks. 5 detik, sehingga `__jjNow()` → `{jenjang, sumber, levels, pklAktif}` bisa dibaca sinkron di semua komponen tanpa hook tambahan. Kalau gagal/timeout: `{jenjang:"SMA", levels:["X","XI","XII"], pklAktif:false}`, dan menu PKL ikut tersembunyi). Konstanta string helper diekspor oleh script patch (`module.exports.SNIPPET_PARSER`) agar bisa diuji (§9.3).
- **J1–J4 Dropdown level (F1–F4):** opsi dibangun dari `levels` (`Kelas VII`, …). Kalau nilai record saat ini tidak kosong dan tidak ada di `levels`, tambahkan satu opsi `"<nilai> (di luar jenjang)"` dengan `value` asli agar nilai tidak hilang diam-diam. Opsi baru hanya level jenjang aktif.
- **J5 Fallback (F5):** `["X","XI","XII"]` → `levels` jenjang aktif.
- **J6 Form jurnal (F6):** rantai `startsWith` diganti `__jjParseLevel(un.id, levels) || __jjParseLevel(un.name, levels)`.
- **J7 Placeholder (F7):** dibangun dari jenjang:

  | Placeholder lama | SD | SMP | SMA | SMK |
  |---|---|---|---|---|
  | `Contoh: X IPA 1` | `Contoh: IV A` | `Contoh: VII A` | (tetap) | `Contoh: X TKJ 1` |
  | `Contoh: Kelas X (Sepuluh)` | `Contoh: Kelas IV (Empat)` | `Contoh: Kelas VII (Tujuh)` | (tetap) | (tetap) |
  | `X / XI / XII (Fase E/F)` | `I / II / III / IV / V / VI` | `VII / VIII / IX` | (tetap) | (tetap) |
  | `... Kelas X` / `... Kelas XI` (RPP, silabus, buku) | level pertama/kedua jenjang; frasa `Fase E` dihapus untuk SD/SMP | ← sama | (tetap) | (tetap) |

- **J8 Menu PKL (F8):** item `bimbingan-pkl` disaring keluar bila `!pklAktif`.
- **J9 Router (F9):** render `__pklHalaman` hanya bila `pklAktif`. Kalau halaman aktif `bimbingan-pkl` (mis. dari state/URL tersimpan) dan PKL nonaktif, pindah ke `dashboard`.
- **J10 Pengaturan (tab konfigurasi, dekat `n==="config"&&be()` / tombol simpan `save_system_config`):** kartu baru **"Jenjang Sekolah"** berisi `<select>` SD/SMP/SMA/SMK. Kalau sumbernya deteksi, ada teks bantu "Terdeteksi otomatis dari nama sekolah, simpan untuk menetapkan". Nilai ikut dikirim di body `save_system_config` yang sudah ada. Kalau respons 409, tampilkan modal peringatan:
  > Mengubah jenjang dari SMK ke SMP. Ada 12 kelas, 30 penugasan mapel, dan 340 siswa aktif dengan level di luar jenjang SMP. Data tersebut tidak diubah, tetapi akan ditandai "di luar jenjang" sampai diperbaiki. Lanjutkan?

  Tombol **Lanjutkan** mengirim ulang body yang sama ditambah `konfirmasi_jenjang:true`. Tombol **Batal** tidak menyimpan apa pun. Setelah berhasil dan jenjang berubah, halaman dimuat ulang agar menu dan dropdown ikut berubah.

## 4. Role, authorization, dan data model

| Aksi | ADMIN | KEPALA_SEKOLAH | TATA_USAHA | GURU | Tanpa login |
|---|---|---|---|---|---|
| Membaca jenjang (`get_config`/`get_system_config`) | ✓ | ✓ | ✓ | ✓ | ✓ (seperti sekarang) |
| Mengubah jenjang (`save_system_config`) | ✓ | ✗ 403 | ✗ 403 | ✗ 403 | ✗ |
| Aksi `pkl_*` saat jenjang ≠ SMK | 403 | 403 | 403 | 403 | 401/403 (auth tetap dicek seperti biasa) |

Data model:
- **Baru:** baris `system_config(config_key='school_level', config_value='SD|SMP|SMA|SMK', description='Jenjang sekolah')`. Tidak ada DDL, tidak ada migrasi, dan `install/schema.sql` (DIGENERATE) tidak disentuh.
- **Tidak berubah:** `classes.level`, `teacher_subjects.class_level`, `students.class_id`, tabel `pkl_*`.

## 5. File dan interface terkait

### 5.1 Backend
| File | Perubahan |
|---|---|
| `api/jenjang_sekolah.php` | **baru**: helper §3.4 |
| `api/index.php` | `require_once`, injeksi `school_level` di `get_system_config`, validasi + 409 di `save_system_config`, guard `jenjangWajibPkl` di 16 `case 'pkl_*'` |
| `api/endpoints_v2.php` | injeksi `school_level` di `get_config` |
| `api/endpoints_v3.php` | seeding `get_classes` memakai parser |
| `api/endpoints_tu.php` | `gtk_update_atribut`: regex level → parser |

Interface: `GET api/index.php?action=get_config`, `GET ...?action=get_system_config`, `POST ...?action=save_system_config` (body `{..., school_level, konfirmasi_jenjang?}`; 200/400/403/409), `...?action=pkl_*` (403 di luar SMK).

### 5.2 Frontend
| File | Perubahan |
|---|---|
| `docs/patches/apply-jenjang-sekolah-bundle.js` | **baru**: patch J0–J10 |
| `assets/index-CGWL69P7-jenjang1.js` | **hasil** patch |
| `app.html` | rujukan bundle → `jenjang1` |

### 5.3 Pengujian dan dokumen
| File | Isi |
|---|---|
| `docs/patches/uji-jenjang-sekolah-kasus.json` | tabel kasus parser & deteksi bersama (dibaca uji PHP dan JS) |
| `docs/patches/uji-jenjang-sekolah-unit.php` | uji `jenjangDeteksi` & `jenjangParseLevel` dari tabel kasus |
| `docs/patches/uji-jenjang-sekolah-parser.mjs` | tabel uji yang **sama** untuk `SNIPPET_PARSER` JS (paritas PHP↔JS) |
| `docs/patches/uji-jenjang-sekolah-seed.sql` / `-cleanup.sql` | kelas/penugasan/siswa dummy berprefiks `UJI_JJ_` untuk tiap jenjang, serta backup & restore baris `school_level` |
| `docs/patches/uji-jenjang-sekolah-api.mjs` | uji API §9.4 |
| `docs/patches/jenjang-sekolah.md` | catatan patch (pola seperti `bimbingan-pkl.md`) |
| `CLAUDE.md` | tambah baris tabel untuk `api/jenjang_sekolah.php` |
| `docs/usulan-btikp/` | `backend-jenjang-sekolah.diff` + `RINGKASAN-JENJANG-SEKOLAH.md` (+ zip) untuk diusulkan ke upstream, mengikuti fitur sebelumnya |

## 6. Di luar scope

- Konsep **guru kelas** SD (satu guru mengampu semua mapel satu kelas). Guru SD memakai penugasan mapel biasa.
- **Fase Kurikulum Merdeka** (A–F) sebagai data atau label baru.
- **SMK 4 tahun** (kelas XIII).
- Konversi/migrasi otomatis data level lama saat jenjang diganti, atau kenaikan kelas massal.
- Menyembunyikan fitur lain per jenjang (BK, ekskul, legalisir, dll.).
- Mengubah installer (`install/`, DIGENERATE) agar menanyakan jenjang.
- Validasi level di `save_class`/`save_teacher_subjects` di backend.
- Memperbaiki celah keamanan yang sudah ada (`get_config` tanpa auth, `save_class` hanya `requireAuth`, `delete_*` tanpa auth). Dicatat di §10, tidak diperbaiki di sini.
- Istilah lain yang khas SMA di luar level kelas (mis. "Wali Kelas", nama mapel default, jurusan).

## 7. Compatibility

- **SMK yang sudah berjalan:** deteksi `SMK…` membuat jenjang efektif SMK tanpa tindakan apa pun. Level X/XI/XII dan PKL tetap seperti sekarang. Kalau `kop_sekolah` kosong/tidak mengandung SMK, jenjang terdeteksi sebagai SMA dan **menu PKL hilang** sampai ADMIN memilih SMK. Ini harus ada di catatan rilis (§10).
- **SMA:** level tidak berubah, menu PKL hilang (sesuai kebutuhan).
- Respons `get_config`/`get_system_config` hanya bertambah satu key. Konsumen lama tidak terpengaruh.
- `save_system_config` dari bundle lama (tanpa key `school_level`) tetap berperilaku sama: validasi hanya berjalan bila key itu ada di body.
- **Auto-updater upstream** dapat menimpa `api/index.php`, `endpoints_*.php`, dan `app.html`. Patch harus bisa dijalankan ulang (script gagal dengan jelas bila SHA sumber berbeda), dan perubahan diusulkan ke BTIKP lewat `docs/usulan-btikp/`.
- Data PKL tidak dihapus. Kembali ke SMK membuat semuanya aktif lagi.

## 8. Edge cases

| Kasus | Perilaku yang diharapkan |
|---|---|
| `school_level` berisi nilai tidak valid di DB (diedit manual) | Diabaikan dan dipakai hasil deteksi. `get_*_config` mengembalikan nilai efektif |
| `kop_sekolah` = "SMA Negeri 1 …" tetapi sekolahnya SMK berbasis nama lama | Deteksi SMA. ADMIN memilih SMK manual |
| "MAN 2 Banjar" / "MTsN 1" / "MIN 3" | SMA / SMP / SD |
| Kata "SD" di tengah nama, mis. "SMA Pusdik SDM" | Hanya dicocokkan sebagai kata utuh. "SDM" ≠ "SD" → SMA |
| Kelas `IPA 1` di SMA | `parseLevel` = '' (langkah 4 menolak `I` di luar jenjang) |
| Kelas `7A`, `7 A`, `VII-A`, `VII_A`, `Kelas 7A` di SMP | `VII` |
| Kelas `VIIIA` di SMP | `VIII` (prefiks terpanjang yang termasuk jenjang) |
| Kelas `XIIA` di SMK | `XII` |
| Record lama level `X` saat jenjang SMP | Dropdown menampilkan `X (di luar jenjang)` terpilih. Menyimpan tanpa mengubahnya tetap mempertahankan `X` |
| Ganti SMK → SMP lalu kembali ke SMK | Peringatan muncul tiap kali ada data tidak cocok. Data PKL utuh dan aktif lagi |
| Ganti jenjang tanpa data tidak cocok (sekolah baru) | Tersimpan langsung tanpa modal |
| Request `save_system_config` ganda (dua tab) | Last write wins, seperti key konfigurasi lain |
| Pengguna sedang di halaman PKL saat ADMIN mengganti jenjang | Aksi berikutnya mendapat 403 dengan pesan jelas. Setelah reload, menu hilang dan halaman pindah ke dashboard |
| URL cetak PKL dibuka langsung di non-SMK | 403 dengan pesan, tanpa stack trace |
| `get_config` gagal dimuat di frontend | Fallback SMA (X–XII), PKL disembunyikan. Backend tetap menjadi penentu akses |
| Siswa/penugasan dengan `class_level` kosong | Tidak dihitung sebagai tidak cocok |

## 9. Testing dan verifikasi end-to-end

### 9.1 Persiapan staging
1. Salin bundle produksi ke staging (atau Laragon lokal dengan `api/config.local.php` berisi `SIMANTAP_DEV_MODE` dan `api/install.lock`).
2. Backup tabel `system_config`, `classes`, `teacher_subjects`.
3. Jalankan `docs/patches/uji-jenjang-sekolah-seed.sql`.

### 9.2 Pemeriksaan statis
- `php -l` untuk `api/jenjang_sekolah.php`, `api/index.php`, `api/endpoints_v2.php`, `api/endpoints_v3.php`, `api/endpoints_tu.php`.
- `node docs/patches/apply-jenjang-sekolah-bundle.js` berhasil. Jalankan kedua kali harus gagal/no-op dengan pesan jelas. `node --check assets/index-CGWL69P7-jenjang1.js` lulus.
- Grep di bundle hasil: tidak ada lagi `value:"XII",children:"Kelas XII"` dan `["X","XI","XII"]` di luar helper `__jjDaftar`.

### 9.3 Uji unit (paritas parser)
- `php docs/patches/uji-jenjang-sekolah-unit.php` dan `node docs/patches/uji-jenjang-sekolah-parser.mjs` memakai tabel yang sama, minimal semua baris parser/deteksi di §8, ditambah `""`, `null`, `"12 IPS"`, `"1A"` (SD → `I`), `"10"`, `"13 A"` (→ ''), `"XIII"` (→ '' atau tidak termasuk; dicatat).
- Keduanya harus lulus 100% dengan hasil identik.

### 9.4 Uji API otomatis (`node docs/patches/uji-jenjang-sekolah-api.mjs <base-url>`)
Login sebagai ADMIN, GURU (pembimbing PKL seed), dan TATA_USAHA:
1. Hapus `school_level`, set `kop_sekolah="SMKN 9 Uji"` → `get_config.school_level === "SMK"`, `get_system_config.school_level.description` berisi "Terdeteksi".
2. TU `save_system_config {school_level:"SD"}` → 403.
3. ADMIN `{school_level:"TK"}` → 400. Tidak ada perubahan.
4. ADMIN `{school_level:"SMP", kop_telp:"X"}` dengan seed kelas X → 409 + `peringatan_jenjang.kelas ≥ 1`. `kop_telp` **tidak** berubah.
5. Ulangi dengan `konfirmasi_jenjang:true` → 200. `school_level = SMP`. `konfirmasi_jenjang` tidak tersimpan sebagai key.
6. GURU `pkl_konteks`, `pkl_jurnal_list`, `pkl_jurnal_simpan`, `pkl_cetak_rekap` → 403 + pesan PKL. Jumlah baris `pkl_jurnal` tidak berubah.
7. ADMIN set `SMK` (konfirmasi bila perlu) → GURU `pkl_konteks` 200 dan data seed PKL masih ada.
8. `gtk_update_atribut` (ADMIN) dengan `kelas_ajar="VII A, VII B"` (SMP) → `class_level = "VII"`; `"Kelas 8B"` → `VIII`; di SD `"IVB, IV C"` → `IV`.
9. Body flat `{school_level:"sd", konfirmasi_jenjang:true}` diterima sebagai `SD`; simpan ulang jenjang yang sama tidak memicu 409. Seeding `get_classes` hanya berjalan saat tabel `classes` kosong, jadi diuji manual di DB baru (siswa `1A`, `IV_B` → level `I`, `IV`), tidak di skrip API.
10. Cleanup mengembalikan `school_level` dan konfigurasi awal.

### 9.5 Skenario end-to-end di browser (DevTools → Network terbuka)
Untuk **setiap** jenjang SD, SMP, SMA, SMK:
1. ADMIN → Pengaturan → pilih jenjang → Simpan. Kalau ada data tidak cocok, modal muncul dengan angka yang sama dengan respons 409. Batal tidak menyimpan, Lanjutkan menyimpan dan memuat ulang.
2. Sidebar: **Bimbingan PKL** hanya ada di SMK (cek sebagai GURU, KEPALA_SEKOLAH, ADMIN).
3. Kelola Kelas → Tambah: "Tingkat Kelas" hanya berisi level jenjang. Edit kelas lama ber-level jenjang lain menampilkan "(di luar jenjang)" dan tetap tersimpan tanpa berubah.
4. Profil guru → Tambah Penugasan Mapel, serta Kelola Guru → Mode Edit Parameter Guru: dropdown level sesuai jenjang.
5. GURU dengan penugasan `VII` (SMP) / `IV` (SD) membuka form Jurnal: kelas `VII A` / `IV B` muncul di pilihan kelas (regresi F6).
6. Placeholder nama kelas dan contoh RPP/silabus sesuai tabel J7.
7. Di non-SMK, membuka langsung URL cetak PKL lama → pesan 403.
8. Console bebas error baru.

### 9.6 Checklist pasca auto-update
Setelah update dari portal: periksa `app.html` masih merujuk `jenjang1` (atau sudah ada versi upstream), `api/jenjang_sekolah.php` ada, guard PKL dan injeksi config masih ada (`grep jenjangWajibPkl api/index.php`). Kalau tertimpa, jalankan ulang patch dari bundle baru (perbarui `SRC_NAME`/`SRC_SHA256`).

### 9.7 Rollback
Arahkan `app.html` kembali ke `index-CGWL69P7-pkl2.js`, kembalikan file PHP dari backup, lalu hapus `api/jenjang_sekolah.php`. Baris `school_level` boleh dibiarkan karena tidak dibaca kode lama.

## 10. Risiko dan trade-off

- **SMK dengan nama sekolah yang tidak memuat "SMK" kehilangan menu PKL setelah update.** Mitigasi: catatan rilis + teks bantu "Terdeteksi otomatis" di Pengaturan. Alternatif yang ditolak: default selalu SMK, yang membuat PKL tetap tampil di SMA.
- **Validasi level hanya di UI.** `save_class` dan penugasan tetap menerima level bebas (kompatibel dengan data lama dan import). Trade-off: data level di luar jenjang masih bisa masuk lewat API/import. Hitungan peringatan menangkapnya saat jenjang diganti.
- **Duplikasi parser PHP↔JS** bisa menyimpang. Mitigasi: satu tabel uji bersama (§9.3).
- **Langkah 4 parser** (Romawi menempel huruf) bersifat heuristik. Nama kelas ganjil bisa terbaca '' dan kelas tersaring keluar di form jurnal. Mitigasi: aturan dibatasi ke level jenjang aktif, dan kasus ini ada di tabel uji.
- **409 membatalkan seluruh simpan konfigurasi**, termasuk kop. Ini dipilih agar atomik, tetapi pengguna harus menekan Lanjutkan untuk menyimpan semuanya.
- **`get_config`/`get_system_config` tanpa auth** (celah lama) kini juga mengekspos jenjang. Dampaknya rendah karena data publik, tetapi celah utamanya tetap ada dan tidak ditangani di sini.
- **`save_system_config` menerima key apa pun** dari ADMIN (perilaku lama). Validasi hanya ditambahkan untuk `school_level`.
- **Auto-updater** dapat menimpa perubahan, sehingga perlu diusulkan ke upstream.

## 11. Acceptance criteria

1. ADMIN dapat memilih jenjang SD/SMP/SMA/SMK di Pengaturan. Nilai tersimpan sebagai `system_config.school_level`, dan role lain mendapat 403 saat menyimpan.
2. Tanpa `school_level`, jenjang efektif dideteksi dari `kop_sekolah` sesuai §3.2 dan ditampilkan sebagai "Terdeteksi otomatis".
3. Nilai di luar SD/SMP/SMA/SMK ditolak 400 tanpa mengubah konfigurasi apa pun.
4. Ganti jenjang saat ada kelas/penugasan/siswa aktif ber-level di luar jenjang baru menghasilkan 409 dengan hitungan, dan modal peringatan di UI. Tidak ada yang tersimpan sebelum dikonfirmasi. Setelah konfirmasi tersimpan, dan data kelas/penugasan/siswa tidak berubah.
5. Keempat dropdown level (F1–F4) hanya menawarkan level jenjang aktif (SD I–VI, SMP VII–IX, SMA/SMK X–XII). Record lama di luar jenjang ditampilkan "(di luar jenjang)" dan tidak berubah bila disimpan ulang.
6. Fallback level (F5) dan filter kelas form jurnal (F6) memakai parser. Guru SMP/SD melihat kelasnya di form jurnal.
7. `get_classes` (seeding) dan `gtk_update_atribut` mengisi level dengan parser yang mengenali Romawi I–XII dan angka 1–12.
8. Menu dan halaman Bimbingan PKL hanya tersedia bila jenjang SMK. Di jenjang lain, ke-16 aksi `pkl_*` (termasuk cetak) mengembalikan 403 "Fitur Bimbingan PKL hanya tersedia untuk jenjang SMK." dan data PKL tetap utuh.
9. Placeholder/contoh pada F7 mengikuti tabel J7.
10. Instalasi SMK yang `kop_sekolah`-nya memuat "SMK" tidak mengalami perubahan perilaku setelah patch dipasang.
11. Uji unit PHP dan JS parser lulus dengan tabel yang identik. `uji-jenjang-sekolah-api.mjs` lulus. `php -l` bersih. Skenario browser §9.5 lulus untuk keempat jenjang.
