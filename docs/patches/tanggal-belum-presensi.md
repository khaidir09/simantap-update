# Patch lokal: Tanggal Belum Ada Presensi di Dashboard Kelas + Pengaturan Hari Sekolah

- Tanggal patch: 2026-11-04
- Bundle acuan: `assets/index-CGWL69P7-verifotomatis1.js` (hasil patch Verifikasi Otomatis)
- Status upstream: **belum** ada fitur resmi.

Dashboard Kelas menunjukkan kepada wali kelas tanggal mana di semester yang dipilih yang belum ada pengisian presensi untuk kelasnya. ADMIN menentukan apakah sekolah berjalan **5 hari (Senin–Jumat)** atau **6 hari (Senin–Sabtu)**. Aturan ini dipakai oleh panel Dashboard Kelas dan oleh catatan "Tanggal belum ada presensi" di Cetak Rekap Bulanan.

## 1. Pengaturan hari sekolah

- Disimpan di `system_config.school_days_per_week` dengan nilai `'5'` atau `'6'`. Bila key belum ada atau nilainya tidak valid, sistem memakai **6**, yaitu perilaku lama Senin–Sabtu.
- **Mengubah nilai:** hanya ADMIN, lewat `save_system_config` dari menu Pengaturan → Konfigurasi → field "Hari Sekolah". Nilai selain 5/6 ditolak dengan 400. `save_config` menolak key ini supaya validasinya tidak bisa dilewati.
- **Membaca nilai:**
  - `get_system_config` selalu mengirim nilai efektif; bila belum diatur, deskripsinya "Bawaan".
  - `get_config` juga menyertakan `school_days_per_week` efektif.
- **Helper** (`api/presensi_kosong.php`): `pkNormalisasiHari`, `pkHariSekolah`, `pkHariMasuk`, `pkLabelHari`.

## 2. Aturan perhitungan tanggal kosong (`presensiTanggalKosong`)

- **Rentang semester:** GANJIL 1 Juli – 31 Desember tahun pertama, GENAP 1 Januari – 30 Juni tahun kedua dari tahun ajaran aktif. Batas akhir adalah hari ini.
- **Titik mulai:** tanggal presensi pertama di sekolah (kelas mana pun) pada semester itu, agar masa libur sebelum semester dimulai tidak ikut terhitung. Bila belum ada presensi sama sekali, `mulai = null` dan panel tidak tampil.
- **Hari yang dicek:** hari sekolah sesuai §1. Minggu selalu dilewati, Sabtu dilewati bila sekolah 5 hari.
- **"Ada presensi":** minimal satu baris `attendance_records` untuk kelas itu pada tanggal tersebut, dari sumber JURNAL maupun MANUAL. Kelas dicocokkan lewat `class_id` atau `class_name` (kunci dinormalisasi).
- **Kemungkinan libur:** tanggal hari sekolah yang tidak punya presensi di **seluruh** kelas dipisahkan ke `kemungkinan_libur`, misalnya libur nasional, dan tidak dihitung sebagai kekurangan kelas. Kelemahannya: bila seluruh sekolah lupa mengisi di hari yang sama, tanggal itu ikut dianggap libur.
- **Hari ini** ikut dicek dan ditandai "(hari ini)".

Respons `get_homeroom_dashboard` mendapat field baru:

```json
"presensi_kosong": {
  "mulai": "2026-09-01", "sampai": "2026-11-04", "hari_ini": "2026-11-04",
  "hari_sekolah": 6, "hari_dicek": 56,
  "tanggal": ["2026-09-23", "2026-09-24"],
  "kemungkinan_libur": ["2026-09-05", "..."]
}
```

## 3. Daftar file

| File | Jenis | SHA-256 setelah patch |
|---|---|---|
| `api/presensi_kosong.php` | baru: helper hari sekolah + `presensiTanggalKosong` | `c5a94158d7d655b6499cb958311e443eaa23b517500d7bd25062ccfa2d67982c` |
| `api/index.php` | ubah: `require_once …/presensi_kosong.php` setelah `rekap_bulanan.php`; `get_system_config` mengirim nilai efektif; `save_system_config` memvalidasi `school_days_per_week` dan mengisi deskripsinya | `692817c234a6d00f2518bb0b1a5ad7c50347a2be6dd32a4c71ed8600e991906e` |
| `api/endpoints_v2.php` | ubah: `get_config` menambah `school_days_per_week`; `save_config` menolak key itu | `4e49eed98048eb5753b6eaa2c7e5e23a3642dc681702679e9a542a25e9551f4d` |
| `api/endpoints_v3.php` | ubah: `get_homeroom_dashboard` menambah `'presensi_kosong' => presensiTanggalKosong(...)` | `9b972526b763d87c1fc5f6337f34610a25ca1e88f170f4cf785706e0c9e47308` |
| `api/rekap_bulanan.php` | ubah: tanggal belum presensi memakai hari sekolah dari `pkHariSekolah` (fallback 6 bila helper tidak dimuat), dengan label "Senin–Jumat"/"Senin–Sabtu" | `19cba35f7961db724fade81f155604e601aad187a16d1caa67c736ebec563a43` |
| `assets/index-CGWL69P7-tglpresensi1.js` | baru: `-verifotomatis1.js` + T0–T3 | `520e1781d84d900f96b21bc12a64ab469a75e03815f4158e941cdad31f7d2897` |
| `assets/index-CGWL69P7-harisekolah1.js` | baru: `-tglpresensi1.js` + H1 | `7a2edf32de2296dbcefbe2dae41986dc38bf763cf83b90225510a5c0808b4263` |
| `app.html` | ubah: `<script src>` ke `index-CGWL69P7-harisekolah1.js` | `19190d7a256bb84ec29ea30f816449fa40867c0c862dab42a211e67a1eaad20d` |
| `docs/patches/apply-tanggal-belum-presensi-bundle.js` | baru: skrip T0–T3 | – |
| `docs/patches/apply-hari-sekolah-bundle.js` | baru: skrip H1 | – |

Hash `api/*.php` hanya berlaku bila file itu tidak berubah karena patch lain. Tidak ada perubahan skema; key `school_days_per_week` dibuat saat ADMIN menyimpan pengaturan.

## 4. Patch frontend

Jalankan berurutan:

```
node docs/patches/apply-tanggal-belum-presensi-bundle.js
node docs/patches/apply-hari-sekolah-bundle.js
```

| # | Anchor | Perubahan |
|---|---|---|
| T0 | `function dot({user:e,` | Helper global `__tbpFmt` (format "Sen, 3 Nov", locale `id-ID`). |
| T1 | `_rn=(e==null?void 0:e.class_homeroom_name)\|\|r,` (di `dot`, Dashboard Kelas) | State `[_tbp,_tbpSet]`. |
| T2 | `i(ne),l(K)}if(H.ok)` | `_tbpSet(Y.presensi_kosong\|\|null)` dari respons dashboard. |
| T3 | Tab bar `{id:"overview"...` | Panel di bawah kartu statistik. Bila ada tanggal kosong: panel amber berisi chip tanggal. Bila tidak ada: panel emerald "Presensi Kelas Lengkap". Panel menyebut "Senin–Jumat (sekolah 5 hari)" atau "Senin–Sabtu (sekolah 6 hari)" dan memberi catatan kecil daftar tanggal kemungkinan libur. |
| H1 | Akhir field "Verifikasi Jurnal" di Pengaturan (hasil V6) | Field **Hari Sekolah** berupa select 6 hari / 5 hari yang terikat ke `ie.school_days_per_week`. Nilainya ikut tersimpan oleh tombol simpan konfigurasi yang sama. |

Kedua skrip memverifikasi:
- hash bundle sumber;
- anchor muncul tepat 1 kali;
- class Tailwind ada di CSS bundle;
- ikon yang dipakai (`cr`, `Qu`, `mo`) ada di top-level bundle dan tidak dideklarasikan ulang di komponen tujuan.

## 5. Re-apply setelah auto-update

1. Salin ulang `api/presensi_kosong.php` bila hilang, lalu terapkan ulang perubahan di `api/index.php`, `api/endpoints_v2.php`, `api/endpoints_v3.php`, dan `api/rekap_bulanan.php` (§3).
2. Terapkan ulang patch bundle sebelumnya sampai `-verifotomatis1.js`, lalu jalankan kedua skrip. Bila hash sumber berbeda, cek anchor secara manual dan perbarui `SRC_SHA256`.
3. Jalankan `php -l` pada file PHP yang berubah dan `node --check assets/index-CGWL69P7-harisekolah1.js`.

## 6. Rollback

1. Di `app.html`, kembalikan `./assets/index-CGWL69P7-harisekolah1.js` menjadi `./assets/index-CGWL69P7-verifotomatis1.js`.
2. Hapus perubahan di `get_homeroom_dashboard`, `get_config`/`save_config`, `get_system_config`/`save_system_config`, dan `rekap_bulanan.php` (kembali ke `N === 7` dan teks "Senin–Sabtu"). Setelah itu hapus `require_once` lalu `api/presensi_kosong.php`.
3. Key `school_days_per_week` boleh dibiarkan karena tidak dibaca apa pun setelah rollback.

## 7. Hasil verifikasi (dev lokal, 2026-11-04)

- **Cek sintaks:** `php -l` untuk semua file PHP yang berubah dan `node --check` untuk kedua bundle hasil: lolos.
- **`presensiTanggalKosong`, kelas XII_TKJ_2, 2026/2027 GANJIL:**

  | Nilai config | Hari dicek | Kosong | Kemungkinan libur | Sabtu di daftar mana pun |
  |---|---|---|---|---|
  | belum diatur / `6` / `x` | 56 | 3 | 36 | 8 |
  | `5` | 47 | 3 | 28 | 0 |

  Key dikembalikan seperti semula (tidak ada) setelah uji.
- **Normalisasi:** `pkNormalisasiHari`: `"5"`→5, `" 6 "`→6, `"7"`/`"abc"`/`""`→0.
- **HTTP:**
  - `get_system_config` → `{"value":"6","description":"Bawaan"}`;
  - `save_system_config` tanpa login → 401;
  - `get_homeroom_dashboard` → `hari_sekolah: 6`.
- **Belum dicek:** tampilan panel dan field Pengaturan di browser, simpan sebagai ADMIN lewat UI, dan halaman Cetak Rekap Bulanan.

## 8. Catatan keamanan

`get_homeroom_dashboard` (sudah ada sebelum patch ini) **tidak** memanggil `requireAuth()`, sehingga rekap presensi per siswa (nama, pas foto) bisa dibaca tanpa login. Patch ini tidak mengubah itu. Field baru hanya berisi tanggal.
