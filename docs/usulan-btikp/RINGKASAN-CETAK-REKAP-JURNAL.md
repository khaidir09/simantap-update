# Usulan Perbaikan SIMANTAP: Hasil Cetak Rekap Jurnal Mengajar Guru

| | |
|---|---|
| Sekolah | SMK Negeri 1 Amuntai (HSU) |
| ID Distribusi | 9E8DC8FBF4 |
| Versi | 1.0.2 |
| Tanggal | 2026-09-16 |
| Lampiran | `backend-cetak-rekap-jurnal.diff` |
| Terkait | Berbeda dari usulan "Rekap Bulanan Wali Kelas" (`RINGKASAN-REKAP-BULANAN.md`). Usulan itu menyangkut halaman cetak sisi server `api/rekap_bulanan.php`; usulan ini menyangkut cetak **Rekapitulasi Bulanan Jurnal Mengajar** yang dirender di sisi klien. |

## 1. Kebutuhan

Rekap bulanan jurnal mengajar (menu **Jurnal Mengajar** → **Rekapitulasi Bulanan** → tombol **Cetak Rekap**) adalah dokumen yang ditandatangani guru dan kepala sekolah, lalu diarsipkan dan dipakai saat supervisi. Berbeda dengan tampilan layar, dokumen ini keluar dari aplikasi dan dibaca orang lain, sehingga kerapian dan kebenaran identitas di dalamnya penting.

Enam hal membuat hasil cetaknya belum layak arsip:

1. **Tag HTML mentah ikut tercetak.** Kolom deskripsi kegiatan menampilkan `<p>…</p>` apa adanya.
2. **Kolom "Kelas / Jam" salah informasi.** Yang tampil bukan jam pertemuan, melainkan isi kolom `period`.
3. **Blok tanda tangan menumpuk vertikal.** Kepala Sekolah di atas, guru di baris bawahnya — bukan sejajar kiri–kanan seperti lazimnya dokumen dinas.
4. **Identitas guru berupa potongan ID user.** Tercetak `ID. A1B2C3D4`, bukan NIP.
5. **Ada badge kategori "Rencana Pembelajaran"** di bawah setiap deskripsi, yang hanya menambah keramaian tanpa dibaca.
6. **Kop surat menggabungkan alamat dengan nomor telepon, website, dan email dalam satu baris.**

## 2. Kondisi saat ini (1.0.2)

Kami menelusuri penyebab setiap poin pada bundle terpasang.

**Deskripsi kegiatan.** Kolom `journal_entries.activities` diisi lewat editor teks kaya, jadi isinya HTML. Cetak **detail** jurnal merendernya dengan `dangerouslySetInnerHTML`, tetapi tabel rekap meletakkannya sebagai teks biasa, sehingga tag ikut terbaca sebagai karakter.

**Kolom jam.** Tabel rekap menampilkan field `time`, yang dipetakan backend dari kolom `period`. Padahal `get_journals` sudah mengirim `jamMulai`/`jamSelesai` (dari `jam_mulai`/`jam_selesai`) dan form jurnal memang meminta jam mulai serta jam selesai pertemuan.

**Tanda tangan.** Mesin cetak bersama (fungsi clone + `window.print()`) sengaja memaksa `[class*="grid"] { display: block !important }` supaya tabel panjang dapat mengalir antar halaman. Aturan itu punya satu pengecualian, yaitu elemen ber-`id="print-signature-block"`. Blok tanda tangan pada **cetak detail** sudah memakai id tersebut, sedangkan pada **rekap** belum — karena itu grid dua kolomnya runtuh jadi satu kolom persis saat dicetak, walau di layar terlihat benar.

**NIP guru.** `get_journals` tidak pernah mengirim NIP sama sekali, sehingga frontend hanya punya `teacherId` dan menampilkan `teacherId.substring(0, 8)`. NIP guru sebenarnya tersedia di `users.nip` dan `gtk_pegawai.nip`.

**Kop surat.** Pembangun kop menggabungkan alamat, telepon, website, dan email menjadi satu array lalu `join(" • ")`, sehingga selalu menjadi satu baris panjang.

**Dua temuan tambahan selama penelusuran:**

- **Gambar berisiko tercetak kosong.** Mesin cetak memanggil `window.print()` 100 ms setelah salinan area cetak dimasukkan ke DOM, tanpa menunggu `<img>` selesai dimuat. Selama belum ada cetakan bergambar hal ini tidak terlihat, tetapi menjadi penghalang begitu dokumentasi foto ikut dicetak.
- **Label kop keliru.** Pada kop cadangan cetak "Laporan Jurnal Kegiatan Mengajar Guru", nilai `kop_website` diberi label `Email:`.

## 3. Perbaikan yang diusulkan

### 3.1 Backend

Satu perubahan, bersifat aditif, tanpa perubahan skema (lihat `backend-cetak-rekap-jurnal.diff`):

- `get_journals` mengembalikan field baru **`teacherNip`**. Nilainya diambil `LEFT JOIN` ke `users` dengan cadangan `gtk_pegawai`, memakai `COALESCE(NULLIF(u.nip,''), NULLIF(g.nip,''), '')`, sehingga NIP tetap terisi bila salah satu modul saja yang lengkap.
- Cadangan lewat `gtk_pegawai` aman karena `gtk_pegawai.id` memang setara `users.uid` dan sudah dijaga sinkron oleh `gtk_create`/`gtk_update`/`gtk_delete`/`save_user_v2`/`delete_user`.
- Aturan visibilitas tidak berubah: GURU tetap hanya menerima jurnalnya sendiri, ADMIN dan KEPALA_SEKOLAH tetap menerima semua.

### 3.2 Frontend — hasil cetak rekap

1. **Deskripsi kegiatan** dilewatkan helper pembersih tag: tag blok (`<br>`, `</p>`, `</div>`, `</li>`, `</tr>`, `</h1>`–`</h6>`) diganti spasi, sisa tag dibuang, entitas (`&nbsp;`, `&amp;`, `&lt;`, `&gt;`, `&quot;`, `&#39;`) didekode, lalu spasi berlebih dirapatkan.
2. **Kolom "Kelas / Jam"** menampilkan `jamMulai - jamSelesai` (detik dipangkas, misal `07:00 - 08:30`). Bila hanya satu yang terisi, yang terisi itu yang tampil; bila keduanya kosong, tampil `-`.
3. **Blok tanda tangan** diberi `id="print-signature-block"` sehingga mengikuti pengecualian yang sudah ada pada mesin cetak: sejajar dua kolom, **Kepala Sekolah di kiri, Guru Mata Pelajaran di kanan**.
4. **Identitas guru** menampilkan `NIP. <nilai>` dari `teacherNip`, dan `NIP. -` bila kosong. Perlakuan yang sama diterapkan pada cetak **detail** jurnal, yang mengandung cacat identik. Arah ini sejalan dengan cetak perangkat ajar, yang sudah memilih NIP lebih dahulu dan baru turun ke NUPTK, NIK, lalu ID user.
5. **Badge kategori** dihapus dari tabel rekap. Nilainya tetap ikut pada ekspor CSV, jadi tidak ada data yang hilang.
6. **Kop surat** dipecah menjadi dua baris: baris pertama alamat, baris kedua `Telp: … • Web: … • Email: …`. Baris NPSN/NSS tidak diubah.

Dua temuan tambahan pada §2 ikut diperbaiki: mesin cetak kini menunggu seluruh `<img>` selesai (`load`/`error`) dengan batas aman 10 detik sebelum mencetak, dan label `Email:` pada kop cadangan dibetulkan menjadi `Web:`.

### 3.3 Lampiran dokumentasi foto (opsional, default mati)

Foto bukti kegiatan (`journal_entries.photos`) saat ini **tidak pernah ikut tercetak di mana pun** — pada cetak detail jurnal pun blok "Bukti Foto & Lampiran" berada di area `no-print`. Setelah menimbang, kami memilih jalan tengah, bukan menaruh foto di dalam tabel:

- Rekap adalah tabel padat, satu baris satu sesi. Guru aktif memiliki 20–30 sesi per bulan; menaruh foto di dalam tabel merusak lebar kolom A4 dan menambah banyak halaman.
- Namun tanpa foto sama sekali, dokumen arsip hanya memuat klaim tekstual, sementara buktinya hilang begitu berkas keluar dari aplikasi.

**Yang kami terapkan:** centang **"Lampiran Foto"** di samping tombol Cetak Rekap, **default mati**. Bila dicentang, ditambahkan halaman terakhir "Lampiran: Dokumentasi Kegiatan" yang selalu dimulai di halaman baru, berisi **maksimal satu foto per sesi** dalam susunan tiga kolom, tiap foto bercaption `tanggal • kelas • mapel` dan topik. Sesi tanpa foto dilewati; bila tidak ada foto sama sekali, lampiran tidak muncul walau centang aktif.

Catatan teknis untuk pengembang: susunan tiga kolom itu kami buat dengan `<table>`, **bukan** CSS grid, justru karena aturan `display: block !important` pada §2. Efek sampingnya menguntungkan — aturan `tr { break-inside: avoid }` yang sudah ada membuat satu baris foto tidak terpotong antar halaman.

## 4. Kompatibilitas dan catatan migrasi

- Tidak ada tabel, kolom, index, atau migrasi baru. `teacherNip` bersifat aditif; klien lama mengabaikannya.
- **Perubahan yang terlihat pengguna:**
  - kop pada **seluruh** cetakan ikut menjadi dua baris, karena pembangun kop dipakai bersama. Cetak perangkat ajar memakai pembangun kop terpisah dengan pola yang sama, dan kami samakan sekalian agar kop tidak berbeda antar dokumen;
  - kolom jam pada rekap berubah arti, dari `period` menjadi jam pertemuan;
  - identitas guru pada rekap dan pada cetak detail berubah dari ID user menjadi NIP.
- **Perlu diperiksa sebelum rilis:** jurnal lama yang `jam_mulai`/`jam_selesai`-nya kosong akan menampilkan `-` pada kolom jam. Silakan cek proporsinya dengan
  `SELECT COUNT(*) FROM journal_entries WHERE jam_mulai IS NULL OR jam_mulai = '';`
  Bila banyak, kami menyarankan menambahkan cadangan ke `period` daripada mengosongkan kolom. Data baru aman, karena form jurnal selalu mengisi jam mulai dan jam selesai.
- Guru yang `users.nip`-nya kosong akan tercetak `NIP. -`. Ini disengaja, agar tidak ada nomor karangan pada dokumen bertanda tangan.

## 5. Hasil uji di sekolah (dev lokal)

- **Statis:** `php -l api/index.php` lolos. Bundle hasil patch diparse penuh sebagai ES module lewat `import()` di Node.
- **Catatan alat uji yang mungkin berguna untuk pengembang:** `node --check` **tidak** menangkap satu kesalahan kurung pada bundle ini, karena file diparse sebagai skrip CommonJS dan berhenti lebih awal. Kesalahan yang sama langsung terlihat saat file diimpor sebagai modul. Kami menyarankan verifikasi bundle memakai `import()`, bukan `node --check`.
- **Helper baru diuji terpisah di Node:** pembersih tag mengubah `<p>Diskusi &amp; tanya jawab</p><p>Latihan soal</p>` menjadi `Diskusi & tanya jawab Latihan soal`, dan masukan kosong menghasilkan string kosong. Pembentuk jam mengubah `07:00:00` + `08:30` menjadi `07:00 - 08:30`, dan objek kosong menjadi `-`.
- **Pembangun kop dieksekusi terpisah** dengan data kop contoh, dan menghasilkan dua baris terpisah sesuai §3.2 poin 6.
- **Belum diuji:** render cetak sebenarnya di browser (dialog Print/Save as PDF), serta halaman lampiran foto dengan data produksi. Keduanya perlu uji manual di staging sebelum rilis.

## 6. Permintaan ke pengembang

1. Menyertakan NIP guru pada payload jurnal di sumber resmi — lewat `teacherNip` seperti lampiran diff, atau field setara.
2. Menerapkan enam perbaikan tampilan cetak pada §3.2 di sumber React.
3. Menyeragamkan perlakuan `activities`: kolom ini menyimpan HTML, tetapi dirender sebagai HTML di satu tempat dan sebagai teks biasa di tempat lain. Sebaiknya ada satu helper bersama untuk versi teks polos.
4. Membuat mesin cetak menunggu gambar selesai dimuat sebelum `window.print()`. Ini berlaku untuk semua cetakan bergambar, bukan hanya rekap.
5. Membetulkan label `Email:` yang sebenarnya mengisi `kop_website` pada kop cadangan cetak jurnal harian.
6. Mempertimbangkan lampiran dokumentasi foto (§3.3) sebagai opsi resmi, sekaligus memutuskan apakah foto juga perlu muncul pada cetak detail jurnal per sesi.

## 7. Catatan tambahan

- **Deskripsi masih terpotong.** Sel deskripsi memakai `line-clamp-2`, sehingga deskripsi panjang terpotong dua baris pada hasil cetak. Kami tidak mengubahnya karena menyangkut tinggi baris dan jumlah halaman, tetapi untuk dokumen arsip idealnya deskripsi tercetak utuh. Perlu keputusan pengembang.
- **Ukuran berkas foto.** Foto unggahan disimpan apa adanya, tanpa thumbnail. Foto kamera ponsel 2–5 MB membuat render cetak berat dan PDF membengkak begitu lampiran diaktifkan. Pembuatan thumbnail saat unggah akan sangat membantu.
- **Nama guru pada rekap.** Judul rekap mengambil nama guru dari jurnal pertama pada bulan terpilih. Untuk Kepala Sekolah dan Admin, yang melihat jurnal semua guru, nama itu tidak mewakili isi tabel. Filter guru di layar juga belum diteruskan ke rekap. Kami belum mengubahnya, tetapi perlu ditinjau.
