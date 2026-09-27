# Usulan Perbaikan SIMANTAP: Tahun Ajaran Mengikuti Tahun Ajaran Aktif (Hapus Hardcode 2025/2026)

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-27 |
| Lampiran | `backend-tahun-ajaran-aktif.diff` |
| Patch bundle lokal | `docs/patches/apply-tahun-ajaran-kesiswaan-bundle.js`, `docs/patches/apply-tahun-ajaran-aktif-bundle.js` |
| Prasyarat | Diff dibuat di atas usulan sekolah sebelumnya yang menambahkan helper `getActiveAcademicPeriod()` (`api/db.php`) dan `activeAcademicPeriod()` (`api/kesiswaan_access.php`). Bila helper itu belum ada di sumber resmi, lihat §3.2 untuk padanannya |

## 1. Masalah

Tahun ajaran **2025/2026** ditulis langsung (hardcode) di banyak tempat, baik di frontend maupun backend.
Setelah sekolah mengganti **Tahun Ajaran Aktif** di pengaturan sistem (`system_config.active_academic_year`),
beberapa halaman tetap menampilkan dan mencetak 2025/2026, dan beberapa data baru tersimpan dengan tahun yang
salah bila frontend tidak mengirim tahun.

Yang paling terlihat oleh pengguna: card **Riwayat Aktivitas** di halaman **Pelanggaran**, **Siswa Terlambat**,
**Izin Keluar**, dan **Panggilan Orang Tua** selalu bertuliskan "Tahun Ajaran 2025/2026", begitu pula surat
panggilan dan rekap yang dicetak dari halaman itu.

## 2. Kondisi saat ini (1.0.2)

### 2.1 Frontend (bundle `index-CGWL69P7.js`)

| Halaman / komponen | Hardcode |
|---|---|
| Pelanggaran, Terlambat, Izin Keluar, Panggilan Ortu | Card "Tahun Ajaran 2025/2026"; 3 header cetak "Semester X • Tahun Ajaran 2025/2026" (surat pemanggilan, rekap pelanggaran & sanksi, laporan akumulasi) |
| Presensi harian siswa (wali kelas) | `attendance.getRecords(kelas, tanggal, "2025/2026")` dan `attendance.saveRecords(data, "2025/2026")` |
| Laporan penilaian (harian/bulanan/semester) | 6 header layar & cetak "… • TAHUN AJARAN 2025/2026" |
| Manajemen siswa | Nilai awal filter tahun, tahun impor, dan form siswa = "2025/2026" |
| Laporan kehadiran staf & guru | Header "TAHUN AJARAN 2025/2026" (**belum** kami ubah, lihat §7) |

### 2.2 Backend

Nilai cadangan `?? '2025/2026'` dipakai bila body/query tidak membawa tahun, antara lain di `save_student`,
`import_students`, `save_parent_call_manual`, `save_attendance_records`, `get_attendance_summary`
(`endpoints_v2.php`), `get_class_attendance_summary`, `get_homeroom_dashboard` (`endpoints_v3.php`), serta
simpan insiden, `save_journal`, `get_student_grades`, `save_ujian_score`, `get_ujian_scores` (`index.php`).
Sebagian sudah diganti ke tahun ajaran aktif oleh usulan-usulan kami sebelumnya; usulan ini menuntaskan sisanya.

## 3. Perbaikan yang diusulkan

### 3.1 Aturan

| Topik | Keputusan |
|---|---|
| Sumber tahun ajaran | `system_config.active_academic_year` (diatur ADMIN di Pengaturan Sistem) |
| Bila konfigurasi kosong | Dihitung dari tanggal server: Juli–Desember → `tahun ini/tahun depan`, Januari–Juni → `tahun lalu/tahun ini`. Tidak ada lagi tahun tetap di kode |
| Bila frontend mengirim tahun | Tetap dipakai (misalnya ADMIN memilih tahun lain di manajemen siswa). String kosong dianggap tidak mengirim |
| Semester | Tidak diubah dalam usulan ini (tetap `active_semester`, cadangan `GANJIL`) |

### 3.2 Backend (lihat `backend-tahun-ajaran-aktif.diff`)

- **Fungsi baru `defaultAcademicYear()`** di `api/db.php` berisi aturan cadangan §3.1.
- `getActiveAcademicPeriod()` (`db.php`) dan `activeAcademicPeriod()` (`kesiswaan_access.php`) memakai
  `defaultAcademicYear()` sebagai cadangan, menggantikan `'2025/2026'`.
- `save_student`, `import_students`, `save_parent_call_manual` (`endpoints_v2.php`) dan simpan insiden
  (`index.php`): `$body[...] ?? '2025/2026'` menjadi
  `trim((string)($body[...] ?? '')) ?: getActiveAcademicPeriod($db)['academic_year']`.

Bila sumber resmi belum memiliki helper tersebut, padanannya: satu fungsi yang membaca
`active_academic_year` dari `system_config` dengan cadangan `defaultAcademicYear()`, lalu dipakai di semua
tempat pada §2.2.

### 3.3 Frontend (patch bundle di sekolah kami)

- **Pelanggaran / Terlambat / Izin Keluar / Panggilan Ortu**: saat komponen dimuat, tahun ajaran aktif diambil
  lewat `get_config`; card Riwayat Aktivitas dan ketiga header cetak memakainya. Bila belum terisi tampil "-".
- **Presensi harian siswa**: tidak lagi mengirim tahun. Backend `save_attendance_records` sudah memakai tahun
  ajaran aktif, dan `get_attendance_records` tidak memfilter tahun (id record unik per siswa per tanggal).
- **Laporan penilaian**: keenam header memakai tahun ajaran aktif.
- **Manajemen siswa**: filter tahun, tahun impor, dan form siswa terisi tahun ajaran aktif (cadangan §3.1 bila
  gagal dimuat). Daftar siswa baru dimuat setelah tahun siap, sehingga tidak sempat menampilkan tahun yang salah.

Saran untuk sumber resmi: simpan tahun ajaran aktif di state aplikasi (seperti `active_semester` yang sudah
dimuat di komponen utama lewat `config.get()`) dan teruskan sebagai prop `activeAcademicYear`, agar tiap
halaman tidak perlu mengambil konfigurasi sendiri.

## 4. Kompatibilitas

- Tidak ada perubahan tabel maupun data lama.
- Tidak ada action baru; hanya nilai cadangan dan tampilan yang berubah.
- Perilaku bila frontend mengirim tahun secara eksplisit tidak berubah.

## 5. Hasil uji di sekolah

- `php -l` lulus untuk `db.php`, `endpoints_v2.php`, `index.php`, `kesiswaan_access.php`.
- Bundle hasil patch lulus `node --check`. Setelah patch, `2025/2026` hanya tersisa di header laporan
  kehadiran staf & guru (sengaja, §7) dan di contoh format pesan validasi impor siswa.
- Semua anchor patch diverifikasi berada di dalam komponen yang dimaksud (tidak ada komponen lain yang ikut
  berubah).
- Uji tampilan di browser **belum** dilakukan saat usulan ini disusun (server basis data lokal sedang tidak
  aktif).

## 6. Permintaan ke pengembang

1. Menghapus semua hardcode tahun ajaran di sumber resmi (frontend dan backend) dan memakai
   `active_academic_year` dengan aturan cadangan §3.1 atau setara.
2. Menyediakan satu helper backend untuk periode aktif (tahun ajaran + semester) agar tidak ada lagi nilai
   cadangan yang ditulis di tiap action.
3. Meneruskan tahun ajaran aktif dari komponen utama ke halaman-halaman seperti `active_semester` (§3.3).
4. Mempertimbangkan fitur **kenaikan / pindah tahun ajaran siswa**: filter manajemen siswa mencocokkan kolom
   `students.academic_year`, sehingga setelah tahun aktif diganti, siswa yang masih tercatat di tahun lama tidak
   tampil secara default.

## 7. Catatan tambahan

- Header **Laporan Kehadiran Staf & Guru** sengaja belum diubah atas keputusan sekolah; sebaiknya ikut
  diseragamkan di sumber resmi.
- Nilai cadangan semester `GANJIL` masih tetap; bisa diseragamkan dengan aturan tanggal yang sama
  (Juli–Desember GANJIL, Januari–Juni GENAP) bila dikehendaki.
