# Usulan Perbaikan SIMANTAP: Badge Sanksi di Halaman Data Pelanggaran

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-27 |
| Lampiran | Tidak ada. Perubahan hanya di frontend dan tidak ada diff backend. |
| Patch bundle lokal | `docs/patches/apply-badge-sanksi-bundle.js` |

## 1. Masalah

Di halaman **Pelanggaran** (juga Terlambat dan Izin Keluar), kolom nama siswa menampilkan badge
**"Sanksi: 10 Poin"**, sementara kolom **Pelanggaran** di baris yang sama bertuliskan
"Tidak hadir tanpa keterangan (dari Jurnal …) **+5 poin**". Guru dan BK mengira poin sanksi salah hitung.

Setelah diperiksa, data di database sudah benar: poin ALFA di `violation_types` = 5, dan siswa tersebut
tercatat ALFA pada dua tanggal berbeda (2 × 5 = 10). `students.total_violation_points` juga sama dengan jumlah
poin di tabel `incidents` untuk semua siswa. Masalahnya ada di tampilan:

1. Badge adalah **akumulasi** poin semua insiden siswa, sedangkan "+5 poin" adalah poin **satu** insiden.
   Label "Sanksi:" tidak membedakan keduanya.
2. Badge dan rekap siswa dikelompokkan per **nama siswa**, bukan per id. Dua siswa bernama sama akan digabung
   poinnya.
3. Badge menjumlahkan poin **semua** data yang dimuat. Di tab **Panggilan Ortu**, yang dijumlahkan adalah
   `total_points` tiap panggilan (snapshot total poin saat panggilan dibuat), sehingga angkanya menyesatkan.
4. Warna badge merah dipakai bila poin **> 20**, sedangkan kolom **Kategori Sanksi** di tabel rekap memakai
   **≥ 20** untuk Tingkat III. Siswa dengan tepat 20 poin tampil kuning di badge tetapi Tingkat III di rekap.

## 2. Kondisi saat ini (1.0.2)

Komponen halaman kesiswaan di bundle `index-CGWL69P7.js`:

```js
// badge
it = useMemo(() => o.reduce((acc, s) => (s.points && (acc[s.studentName] = (acc[s.studentName]||0) + s.points), acc), {}), [o])
// rekap siswa (tabel rekap, grafik 6 teratas, cetak laporan siswa)
o.forEach(s => { const key = s.studentName; ... })
// tampilan badge
it[ze.studentName] > 20 ? "text-red-500" : it[ze.studentName] >= 10 ? "text-amber-500" : "text-emerald-500"
["Sanksi: ", it[ze.studentName], " Poin"]
```

## 3. Perbaikan yang diusulkan (sudah dipasang sebagai patch bundle di sekolah kami)

| Bagian | Perubahan |
|---|---|
| Label badge | "Sanksi: X Poin" → **"Total Sanksi: X Poin"** |
| Kunci pengelompokan | `studentId`, dengan cadangan nama siswa bila `studentId` kosong. Berlaku untuk badge dan rekap siswa |
| Jenis insiden yang dijumlahkan badge | Hanya `VIOLATION` dan `LATE`, sama seperti "Poin Sanksi" di rekap siswa |
| Warna badge | Merah **≥ 20**, kuning 10–19, hijau < 10, sama dengan batas Kategori Sanksi |

Akibat samping: di tab **Panggilan Ortu** badge tidak lagi tampil, karena data tab itu berisi panggilan, bukan
insiden. Bila badge di tab itu dikehendaki, sebaiknya diambil dari `students.total_violation_points`.

## 4. Kompatibilitas

- Tidak ada perubahan backend, tabel, maupun data.
- `get_incidents` dan `get_parent_calls` sudah mengembalikan `studentId` / `student_id`, dan frontend sudah
  memetakannya ke `studentId`. Tidak ada field baru yang dibutuhkan.

## 5. Hasil uji di sekolah

- Bundle hasil patch lulus `node --check`. Semua anchor patch cocok tepat pada jumlah yang diharapkan dan
  berada di dalam komponen halaman kesiswaan.
- Data lokal: 14 siswa dengan lebih dari satu insiden ALFA; total per siswa (10 atau 15 poin) sama dengan
  `students.total_violation_points`, tidak ada insiden ALFA ganda pada tanggal yang sama.
- Uji tampilan di browser **belum** dilakukan saat usulan ini disusun.

## 6. Permintaan ke pengembang

1. Mengganti label badge agar jelas merupakan total/akumulasi poin.
2. Mengelompokkan badge dan rekap siswa berdasarkan `studentId`, bukan nama.
3. Menyamakan batas warna badge dengan batas Kategori Sanksi (≥ 20 dan ≥ 10), idealnya dari satu konstanta
   atau dari pengaturan ambang sanksi sekolah.
4. Mempertimbangkan memakai `students.total_violation_points` sebagai sumber badge, agar angkanya sama dengan
   yang dipakai backend untuk memicu panggilan orang tua otomatis.
