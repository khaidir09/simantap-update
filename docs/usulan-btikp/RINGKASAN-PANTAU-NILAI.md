# Usulan Perbaikan SIMANTAP: Pantau Nilai Kelas untuk Wali Kelas dan Guru BK

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-26 |
| Lampiran | `backend-pantau-nilai.diff` |
| Spesifikasi lengkap | `docs/specs/pantau-nilai-wali-kelas.md` |
| Usulan lanjutan | `RINGKASAN-PORTAL-NILAI.md` (akses siswa/orang tua, memakai data dari usulan ini) |

## 1. Kebutuhan

Nilai tugas/kuis per pertemuan (di jurnal) dan nilai ujian (UTS/UAS) hanya bisa dilihat oleh guru
pengampu mapel. **Wali kelas tidak bisa melihat nilai mapel guru lain di kelas perwaliannya**,
sehingga tidak tahu siswa mana yang tertinggal tugas atau nilainya di bawah KKM sampai rapor
atau panggilan orang tua.

Kebutuhan sekolah:

1. wali kelas melihat **rekap nilai semua mapel** kelas perwaliannya (hanya baca);
2. menandai siswa yang **belum punya nilai** pada pertemuan yang sudah dinilai guru, serta siswa
   dengan mapel **di bawah KKM**;
3. **mencetak laporan nilai per siswa** (A4 ber-Kop) sebagai bahan panggilan orang tua;
4. guru BK melihat hal yang sama untuk semua kelas, untuk pendampingan.

## 2. Kondisi saat ini (1.0.2)

- `get_student_grades`, `get_daily_grades`, `get_monthly_grades`, `get_ujian_scores`, dan
  `save_ujian_score` hanya bekerja per **kelas + satu mapel**. Tidak ada tampilan lintas mapel.
- Daftar siswa di rekap nilai diambil dari `assessment_data` jurnal, bukan dari data kelas,
  sehingga kelas tanpa jurnal ber-penilaian tampil kosong dan siswa pindah ikut terhitung.
- Rekap semester tidak menyaring jurnal per rentang tanggal semester; tahun ajaran/semester default
  di-hardcode.
- Endpoint nilai **tidak membatasi guru** ke mapel/kelas yang diampunya (guru mana pun bisa membaca
  dan menyimpan nilai ujian mapel lain lewat API), tetapi juga tidak memberi jalur resmi untuk wali kelas.

## 3. Perbaikan yang diusulkan

### 3.1 Prasyarat: perbaikan endpoint Penilaian Siswa (ikut dalam diff)

Bagian ini sudah kami terapkan lebih dulu (commit terpisah) dan belum pernah kami usulkan. Kami
sertakan karena pantau nilai memakai helper yang sama dan angkanya sengaja dibuat identik dengan
`get_student_grades` versi ini.

| # | Lokasi | Perubahan |
|---|---|---|
| A1 | `api/db.php` | Helper baru: `getActiveAcademicPeriod`, `getSemesterDateRange`, `getActiveClassStudents`, `teacherTeachesClassSubject`, `requireGradeAccess` |
| A2 | `get_student_grades`, `get_daily_grades`, `get_monthly_grades` | Daftar siswa dari tabel `students` (siswa aktif); nilai jurnal digabung ke dalamnya |
| A3 | `get_student_grades` | Jurnal disaring per rentang tanggal semester (Ganjil = Juli–Desember, Genap = Januari–Juni) |
| A4 | Semua endpoint nilai | Tahun ajaran & semester default dari `system_config` (`active_academic_year`/`active_semester`) |
| A5 | Semua endpoint nilai | Role `GURU` hanya boleh membaca/menyimpan nilai mapel+kelas yang diampu (`teacher_subjects`; `class_ids` diutamakan, fallback `class_level`) → 403 |
| A6 | `save_ujian_score` | Hanya menyimpan siswa aktif kelas tersebut; nama siswa diambil dari database, bukan dari body |

### 3.2 Pantau nilai (lihat `backend-pantau-nilai.diff`)

File baru `api/pantau_nilai.php` dan tiga action baru. Endpoint nilai lama **tidak diubah** oleh
bagian ini; wali kelas tetap tidak bisa membuka atau menyimpan nilai mapel lain lewat endpoint lama.

| Action | Metode | Isi |
|---|---|---|
| `get_class_grade_overview` | GET `class_id`, `semester` | Rekap semua mapel satu kelas: per siswa × mapel, jumlah tertinggal, penanda "perlu perhatian" |
| `get_class_subject_grade_detail` | GET `class_id`, `subject_id`, `semester` | Nilai per pertemuan satu mapel (tugas, kuis, presensi, catatan guru) + nilai ujian |
| `cetak_rekap_nilai_siswa` | GET `student_id`, `semester`, `kota`, `tanggal` | Halaman HTML A4 ber-Kop siap cetak/PDF |

**Akses** (data user dibaca ulang dari DB setiap request):

| Peran | Akses |
|---|---|
| ADMIN, KEPALA_SEKOLAH | Semua kelas |
| GURU dengan `is_bk = 1` | Semua kelas |
| GURU wali kelas (`class_homeroom`) | Kelas perwaliannya saja; kelas lain 403 |
| TATA_USAHA, guru lain | 403 |

**Aturan hitung:**

- Daftar mapel = mapel yang diampu di kelas itu (`teacher_subjects`) + mapel yang punya jurnal
  ber-penilaian atau nilai ujian di semester itu. Mapel tanpa data tampil "Belum ada penilaian".
- **Tertinggal**: pada satu pertemuan, jenis nilai (tugas/kuis) dianggap diberikan jika minimal satu
  siswa punya nilai itu; siswa aktif yang nilainya kosong dihitung tertinggal. Nilai 0 bukan tertinggal.
  Status presensi pertemuan ditampilkan sebagai keterangan, tidak mengubah hitungan. Jurnal draf diabaikan.
- **Nilai acuan**: nilai akhir (rumus yang sama dengan `get_student_grades`); jika nilai ujian belum ada,
  rata-rata tugas dan kuis yang tersedia ditandai "sementara".
- **Perlu perhatian**: ≥ 1 tertinggal atau ≥ 1 mapel di bawah `kkm_default`.
- Presensi di dokumen cetak disaring per **tanggal**, bukan kolom `semester`/`academic_year`
  `attendance_records` (data lama bisa berisi `2025/2026` hardcoded).

Dokumen cetak: kop sekolah, identitas siswa, A. nilai per mapel, B. daftar tugas/kuis tertinggal
beserta catatan guru, C. ringkasan presensi semester, tanda tangan "Orang Tua/Wali" dan "Wali Kelas".
Error ditampilkan sebagai halaman HTML (401/403/404/405), bukan JSON, karena dibuka di tab baru.

**Tanpa perubahan skema** dan tanpa key `system_config` baru.

### 3.3 Frontend (patch bundle di sekolah kami)

- Dashboard Kelas Saya (wali kelas): tab baru **"📝 Nilai"** berisi kartu ringkasan, daftar mapel,
  tabel siswa × mapel (urut/filter "perlu perhatian", cari nama/NISN), detail per pertemuan saat
  mapel diklik, dan panel siswa dengan tombol **"Cetak Laporan Nilai"**.
- Guru BK: menu **"Pantau Nilai Kelas"** di bagian Bimbingan & Konseling, dengan pemilih kelas.
- Tidak ada tombol simpan/edit di tampilan ini.

## 4. Kompatibilitas

- Action pantau nilai bersifat aditif; tidak ada perubahan skema.
- **Perubahan perilaku yang disengaja (bagian prasyarat §3.1):** klien non-UI yang membaca/menyimpan
  nilai mapel yang tidak diampu sebagai `GURU` kini mendapat 403; rekap nilai kini hanya berisi
  siswa aktif kelas dan jurnal dalam rentang semester.
- Bergantung pada helper dari usulan sebelumnya: `api/kesiswaan_access.php` (usulan wali kelas &
  kesiswaan: `loadFreshUser`, `homeroomClassScope`, `resolveClassRef`, `classInScope`) dan
  `api/rekap_bulanan.php` (usulan rekap bulanan: kop dan helper cetak). Kolom `teacher_subjects.class_ids`
  dari usulan penugasan mapel dipakai bila ada.

## 5. Hasil uji di sekolah (dev lokal)

- Uji API otomatis **44/44 lulus**: matriks akses, hitungan tertinggal (nilai 0, jurnal hanya kuis,
  draf, siswa nonaktif, presensi sakit), mapel tanpa data, **angka identik dengan `get_student_grades`**,
  nilai sementara di bawah KKM, detail per pertemuan, dokumen cetak (termasuk escape catatan guru
  berisi HTML), regresi endpoint lama, dan pencabutan wali kelas yang berlaku tanpa login ulang.
- Uji unit fungsi murni **14/14 lulus** (parser presensi format map dan array, rumus nilai).
- Uji UI di Chrome: tab Nilai wali kelas, detail mapel, panel siswa, halaman cetak, menu BK dengan
  pemilih kelas; guru biasa dan Kepala Sekolah tidak terdampak; tanpa error console.

## 6. Permintaan ke pengembang

1. Menerapkan perbaikan endpoint Penilaian Siswa §3.1, terutama pembatasan guru ke mapel yang diampu (A5).
2. Menyediakan tampilan nilai lintas mapel untuk wali kelas (dan BK) di sumber resmi, dengan kontrak
   seperti §3.2 atau setara.
3. Menjadikan rumus nilai akhir satu helper bersama. Saat ini rumus di `pantau_nilai.php` sengaja
   disalin dari `get_student_grades` agar tidak mengubah endpoint lama; kesamaannya dijaga oleh uji.

## 7. Catatan tambahan

- **Format `journal_entries.studentAttendance` tidak seragam.** `save_journal`/`get_journals` memakai
  map `{student_id: status}`, sedangkan `get_daily_grades` membacanya sebagai array objek, sehingga
  semua siswa tampil "HADIR" di nilai harian. Pantau nilai mendukung kedua format.
- **`save_ujian_score` tidak memeriksa metode HTTP** dan dapat dipicu lintas situs oleh guru yang sedang login.
- **Menu Bimbingan & Konseling menempel pada grup "Kepegawaian".** Di sekolah kami grup itu hilang
  setelah menu presensi pegawai dihapus, sehingga menu "Insiden BK" ikut hilang. Kami memindahkan
  blok BK ke grup menu pertama. Jika sumber resmi mengubah grup Kepegawaian, hal yang sama akan terjadi.
- **Jurnal lama dengan `classId` bukan `classes.id`** (mis. nama kelas) tidak terhitung, sama dengan
  halaman Penilaian guru.
