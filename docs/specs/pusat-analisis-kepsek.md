# Spesifikasi: Kartu "Pusat Analisis" Dashboard Kepala Sekolah

| | |
|---|---|
| Status | Diimplementasikan (patch lokal) — `docs/patches/apply-pusat-analisis-bundle.js` |
| Versi bundle acuan | SIMANTAP 1.0.2 (`api/version.php`) + patch penugasan mapel (`index-CGWL69P7-mapel1.js`) |
| Tanggal | 2026-09-17 |
| Jalur rilis | Patch lokal pada bundle ini **dan** usulan perubahan ke upstream BTIKP |
| Peran terdampak | `KEPALA_SEKOLAH`, `ADMIN` |

## 1. Latar belakang

Dashboard ADMIN / Kepala Sekolah (komponen `ape` di bundle) punya kartu **Pusat Analisis**
di kolom kanan atas. Isinya adalah kalimat yang **ditanam keras di bundle**:

> "Aktivitas pengisian jurnal guru hari ini mencapai 85%. Terdapat 4 guru yang belum melakukan presensi."

Kalimat itu tidak pernah berubah, tidak dihitung dari database, dan tidak ada permintaan
API apa pun yang mendukungnya. Pada basis data sekolah ini, kondisi sebenarnya pada
2026-09-17 adalah 60 akun guru, 0 jurnal masuk hari ini, dan 7 jurnal belum diverifikasi —
jauh berbeda dari "85%" dan "4 guru".

Karena angka itu dibaca kepala sekolah sebagai laporan, teks contoh tersebut menyesatkan
dan harus diganti dengan hitungan nyata.

## 2. Perilaku saat ini (hasil analisis kode)

### 2.1 Frontend (`assets/index-CGWL69P7-mapel1.js`)

- Komponen dashboard: `function ape({onQuickAction:e,user:t})`.
- `s = (role === "ADMIN") || (role === "KEPALA_SEKOLAH")` menentukan kartu mana yang tampil.
  Kartu Pusat Analisis hanya dirender jika `s` bernilai benar.
- State `i` hanya berisi `{ totalGuru, journalDates }`:
  - `totalGuru` diambil dari `et.tu.gtk.list().length` — **seluruh pegawai GTK** (61 baris),
    termasuk kepala sekolah dan tenaga kependidikan, bukan hanya guru;
  - `journalDates` dari `get_journal_stats` — dipakai untuk grafik batang 7 hari, bukan untuk kartu ini.
- Isi kartu Pusat Analisis adalah satu `<p>` berisi string statis. Tidak ada state, fetch,
  maupun pemakaian `i` di dalamnya.

### 2.2 Backend

Tidak ada endpoint yang menyediakan ringkasan ini. `get_journal_stats` (di `api/index.php`)
hanya mengembalikan daftar tanggal jurnal mentah, tanpa autentikasi.

## 3. Perubahan yang dispesifikasikan

### 3.1 Endpoint baru: `get_dashboard_analytics`

Lokasi: `api/endpoints_v3.php` (satu berkas dengan dashboard wali kelas).

- Otorisasi: `requireRole(['ADMIN', 'KEPALA_SEKOLAH'])`.
  Endpoint ini mengembalikan agregat seluruh sekolah, jadi tidak boleh terbuka seperti
  `get_journal_stats`.
- Tanggal memakai `date()` PHP (`APP_TIMEZONE`), bukan `CURDATE()` MySQL, agar tidak
  ikut zona waktu server basis data. Pola ini sama dengan `get_homeroom_dashboard`.
- Semua kueri memakai prepared statement.

Definisi angka:

| Field | Definisi |
|---|---|
| `tanggal` | Tanggal hari ini (`Y-m-d`) menurut `APP_TIMEZONE` |
| `hari` | Nama hari Indonesia untuk `tanggal` |
| `total_guru` | `COUNT(*) FROM users WHERE role = 'GURU'`. Tabel `users` tidak punya kolom status, jadi semua akun ber-role GURU dihitung |
| `guru_mengisi` | `COUNT(DISTINCT teacherId)` pada `journal_entries` untuk `tanggal`, dibatasi ke `teacherId` yang ber-role GURU agar sebanding dengan `total_guru` |
| `guru_belum` | `max(0, total_guru - guru_mengisi)` |
| `persen` | `round(guru_mengisi / total_guru * 100)`, 0 bila `total_guru = 0` |
| `jurnal_hari_ini` | Jumlah baris `journal_entries` untuk `tanggal` (bisa lebih dari satu per guru) |
| `jurnal_7_hari` | Jumlah baris `journal_entries` pada rentang 6 hari lalu s.d. hari ini |
| `jurnal_belum_verifikasi` | `COUNT(*) WHERE isVerified = 0` |
| `jurnal_terakhir` | `MAX(date)`, atau `null` bila belum ada jurnal sama sekali |

Catatan sengaja: `guru_belum` dihitung terhadap **seluruh akun guru**, bukan terhadap guru
yang punya jadwal mengajar hari itu. Alasannya, kolom `gtk_pegawai.jadwal_mengajar_json`
baru terisi pada 1 dari 61 pegawai, sehingga penyaring berbasis jadwal akan menghasilkan
angka yang jauh lebih menyesatkan daripada pembagi yang apa adanya. Label di kartu karena
itu berbunyi "dari N guru", bukan "dari N guru yang mengajar hari ini".

### 3.2 Frontend: komponen `smtPusatAnalisis`

Patch menyisipkan komponen baru di scope modul yang sama dengan `ape`, lalu mengganti
`<p>` statis dengan `a.jsx(smtPusatAnalisis, { onOpen: e })`.

Perilaku:

- Sekali saat mount, `fetch(`${nr}?action=get_dashboard_analytics`)` dengan
  `credentials: "same-origin"` agar cookie sesi ikut terkirim.
- Tiga keadaan:
  - `memuat` → "Memuat data analisis…";
  - `gagal` (HTTP bukan 2xx atau JSON gagal) → "Data analisis belum bisa dimuat.",
    kesalahan dicatat ke console dengan awalan `Pusat Analisis:`;
  - `siap` → tampilan angka.
- Tampilan `siap`:
  - angka besar `persen` + "%";
  - label "PENGISIAN JURNAL HARI INI";
  - kalimat "`guru_mengisi` dari `total_guru` guru sudah mengisi jurnal hari ini.
    `guru_belum` guru belum mengisi." (kalimat kedua hilang bila `guru_belum = 0`;
    bila `total_guru = 0` kalimatnya jadi "Belum ada akun guru terdaftar");
  - baris kecil: "`jurnal_hari_ini` jurnal masuk hari ini • `jurnal_7_hari` dalam 7 hari terakhir."
    ditambah "Jurnal terakhir masuk `tanggal`." (hanya bila hari ini nol) dan
    "`jurnal_belum_verifikasi` jurnal menunggu verifikasi." (hanya bila lebih dari nol).
- Tombol "Buka Laporan" di bawah kartu tidak diubah.

## 4. Di luar cakupan

- Kartu "**N** Guru" di dashboard yang sama masih memakai `et.tu.gtk.list().length`
  (seluruh pegawai GTK, 61) dan bukan jumlah guru (60). Ini masalah terpisah dari
  kartu Pusat Analisis dan tidak diubah oleh patch ini.
- `get_journal_stats` tetap tanpa autentikasi; memperbaikinya menyentuh dashboard guru.

## 5. Verifikasi

- `php -l api/endpoints_v3.php` bersih.
- Endpoint dijalankan langsung terhadap basis data `simantap` dengan sesi
  `KEPALA_SEKOLAH`, hasil 2026-09-17:
  `{"tanggal":"2026-09-17","hari":"Kamis","total_guru":60,"guru_mengisi":0,"guru_belum":60,`
  `"persen":0,"jurnal_hari_ini":0,"jurnal_7_hari":5,"jurnal_belum_verifikasi":7,`
  `"jurnal_terakhir":"2026-09-16"}` — cocok dengan isi tabel `users` dan `journal_entries`.
- Bundle hasil patch lolos `node --check`.
