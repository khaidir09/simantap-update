<?php
// instrumen_guru_wali.php - Impor hasil instrumen digital Guru Wali (Google Form "Formulir Awal Murid Guru Wali")
// Spesifikasi: docs/specs/impor-instrumen-guru-wali.md
// Hanya boleh dimuat lewat api/index.php (yang sudah melewati gerbang lisensi).
// Memakai helper dari db.php, profil_murid.php (profilMurid*), dan guru_wali.php (guruWali*).
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

const IGW_INSTRUMEN   = 'GW_FORMULIR_AWAL';
const IGW_MAKS_BARIS  = 501;    // 500 respons + header
const IGW_MAKS_KOLOM  = 120;
const IGW_MAKS_SEL    = 1000;
const IGW_LOCK_SKEMA  = 'simantap_igw_skema';
const IGW_MSG_PERAN   = 'Impor formulir guru wali hanya untuk guru wali.';
const IGW_MSG_KOSONG  = 'Anda belum memiliki murid dampingan aktif.';
const IGW_MSG_FORMAT  = 'Format file tidak dikenali sebagai respons Formulir Awal Murid Guru Wali (kolom Cap waktu, [A00], dan [A01] wajib ada).';
const IGW_PERSETUJUAN = 'Saya mengerti dan bersedia mengisi formulir ini dengan jujur';

// Kunci minat RIASEC (kuis Holland 42 butir): nomor pernyataan per tipe
const IGW_KUNCI_MINAT = [
    'r' => [1, 7, 14, 22, 30, 32, 37],
    'i' => [2, 11, 18, 21, 26, 33, 39],
    'a' => [3, 8, 17, 23, 27, 31, 41],
    's' => [4, 12, 13, 20, 28, 34, 40],
    'e' => [5, 10, 16, 19, 29, 36, 42],
    'c' => [6, 9, 15, 24, 25, 35, 38],
];
// Kompetensi RIASEC (A45, pilih 8 dari 24): 4 kegiatan per tipe, urutan sesuai form
const IGW_KEGIATAN = [
    'r' => ['Memperbaiki barang', 'Mengutak-atik barang', 'Membongkar-pasang barang', 'Merakit benda'],
    'i' => ['Mencari solusi', 'Menganalisis masalah', 'Menghitung', 'Berpikir logis'],
    'a' => ['Menari', 'Menulis cerita', 'Bermusik', 'Menggambar'],
    's' => ['Memberi saran', 'Memahami orang lain', 'Mengajar', 'Membantu'],
    'e' => ['Mengelola acara', 'Memimpin kelompok', 'Menjual benda', 'Membujuk orang'],
    'c' => ['Membereskan barang', 'Mengurutkan', 'Mengikuti aturan', 'Menjalankan rutin'],
];
// Urutan opsi A45 di form (untuk menyimpan daftar kegiatan sesuai urutan form)
const IGW_KEGIATAN_URUT = [
    'Memperbaiki barang', 'Mencari solusi', 'Memberi saran', 'Mengutak-atik barang', 'Menari', 'Mengelola acara',
    'Memahami orang lain', 'Menulis cerita', 'Menganalisis masalah', 'Bermusik', 'Membongkar-pasang barang',
    'Membereskan barang', 'Mengajar', 'Mengurutkan', 'Memimpin kelompok', 'Membantu', 'Menjual benda', 'Menghitung',
    'Berpikir logis', 'Membujuk orang', 'Mengikuti aturan', 'Merakit benda', 'Menggambar', 'Menjalankan rutin',
];
// Gaya belajar: opsi per soal [Visual, Auditori, Kinestetik]
const IGW_VAK = [
    'A30' => ['Melihat gambar, diagram, atau video tutorial', 'Mendengarkan penjelasan guru atau teman', 'Langsung mencoba sendiri sambil praktik'],
    'A31' => ['Membayangkan tulisan atau gambarnya', 'Mengucapkannya berulang-ulang', 'Menuliskannya berkali-kali'],
    'A32' => ['Menampilkan slide, gambar, atau tulisan di papan', 'Menjelaskan secara lisan dan mengajak diskusi', 'Memberi praktik atau kegiatan langsung'],
    'A33' => ['Menonton atau membaca', 'Mendengarkan musik/podcast atau mengobrol', 'Berolahraga atau membuat sesuatu'],
    'A34' => ['Membuat catatan berwarna atau peta pikiran', 'Membaca keras-keras atau belajar sambil berdiskusi', 'Mengerjakan latihan soal atau belajar sambil bergerak'],
];
// A20 -> titik tengah rentang (km). Kunci sudah dinormalisasi (instrumenGwNorm)
const IGW_JARAK = ['kurang dari 1 km' => 0.5, '1-3 km' => 2.0, '3-5 km' => 4.0, '5-10 km' => 7.5, 'lebih dari 10 km' => 10.0];
const IGW_RENCANA = ['bekerja' => 'KERJA', 'melanjutkan kuliah' => 'KULIAH', 'wirausaha' => 'WIRAUSAHA', 'belum tahu' => 'BELUM_TAHU'];
const IGW_KODE_DIKENAL = ['A00', 'A01', 'A02', 'A20', 'A21', 'A30', 'A31', 'A32', 'A33', 'A34', 'A35', 'A36', 'A37', 'A38', 'A39',
    'A45', 'A50', 'A51', 'A60', 'A61', 'A62', 'A63', 'A64'];
// Kolom baru student_asesmen (ditambahkan instrumenGwPastikanSkema)
const IGW_KOLOM_BARU = [
    'kompetensi_r' => 'decimal(5,2) DEFAULT NULL', 'kompetensi_i' => 'decimal(5,2) DEFAULT NULL',
    'kompetensi_a' => 'decimal(5,2) DEFAULT NULL', 'kompetensi_s' => 'decimal(5,2) DEFAULT NULL',
    'kompetensi_e' => 'decimal(5,2) DEFAULT NULL', 'kompetensi_c' => 'decimal(5,2) DEFAULT NULL',
    'kompetensi_kode' => 'varchar(3) DEFAULT NULL', 'kegiatan_dikuasai' => 'text DEFAULT NULL',
    'kesulitan_cerita' => 'text DEFAULT NULL', 'mapel_pilihan' => 'text DEFAULT NULL', 'mapel_pendukung' => 'text DEFAULT NULL',
    'bakat' => 'text DEFAULT NULL', 'keterampilan_target' => 'varchar(255) DEFAULT NULL', 'alasan_rencana' => 'text DEFAULT NULL',
    'instrumen_respons_id' => 'varchar(64) DEFAULT NULL',
];
// Field asesmen yang dapat ditulis instrumen (urutan = urutan kolom saat INSERT)
const IGW_FIELD = [
    'riasec_r', 'riasec_i', 'riasec_a', 'riasec_s', 'riasec_e', 'riasec_c', 'riasec_kode',
    'kompetensi_r', 'kompetensi_i', 'kompetensi_a', 'kompetensi_s', 'kompetensi_e', 'kompetensi_c', 'kompetensi_kode', 'kegiatan_dikuasai',
    'skor_visual', 'skor_auditori', 'skor_kinestetik', 'gaya_belajar',
    'kesulitan_belajar', 'kesulitan_cerita', 'mapel_termudah', 'mapel_tersulit', 'mapel_pilihan', 'mapel_pendukung',
    'bakat', 'keterampilan_target', 'rencana_lulus', 'cita_cita', 'institusi_tujuan', 'alasan_rencana',
];
const IGW_FIELD_JSON = ['kegiatan_dikuasai', 'mapel_termudah', 'mapel_tersulit', 'mapel_pilihan', 'mapel_pendukung'];
// Kelompok field untuk pesan "Mengganti: ..." di pratinjau
const IGW_KELOMPOK = [
    'minat RIASEC'      => ['riasec_r', 'riasec_i', 'riasec_a', 'riasec_s', 'riasec_e', 'riasec_c', 'riasec_kode'],
    'kompetensi RIASEC' => ['kompetensi_r', 'kompetensi_i', 'kompetensi_a', 'kompetensi_s', 'kompetensi_e', 'kompetensi_c', 'kompetensi_kode', 'kegiatan_dikuasai'],
    'gaya belajar'      => ['skor_visual', 'skor_auditori', 'skor_kinestetik', 'gaya_belajar'],
    'kesulitan belajar' => ['kesulitan_belajar', 'kesulitan_cerita'],
    'mapel'             => ['mapel_termudah', 'mapel_tersulit', 'mapel_pilihan', 'mapel_pendukung'],
    'bakat/keterampilan'=> ['bakat', 'keterampilan_target'],
    'rencana karir'     => ['rencana_lulus', 'cita_cita', 'institusi_tujuan', 'alasan_rencana'],
];

// =====================================================================
// Fungsi murni (diuji di docs/patches/uji-instrumen-guru-wali-unit.php)
// =====================================================================

/** Sel -> string satu baris yang dirapikan, maks. IGW_MAKS_SEL karakter (teks paragraf murid tidak dipotong 500). */
function instrumenGwSel($v): string
{
    if ($v === null || is_array($v) || is_object($v)) return '';
    if (is_bool($v)) return $v ? '1' : '';
    if (is_float($v) && floor($v) == $v && abs($v) < 1e15) $v = (string)(int)$v;
    $s = trim(preg_replace('/\s+/u', ' ', (string)$v) ?? '');
    return mb_substr($s, 0, IGW_MAKS_SEL, 'UTF-8');
}

/** Normalisasi untuk mencocokkan opsi: huruf kecil, tanda pisah/elipsis disamakan, spasi tunggal. */
function instrumenGwNorm($v): string
{
    $s = mb_strtolower(instrumenGwSel($v), 'UTF-8');
    $s = str_replace(['–', '—', '−', '…'], ['-', '-', '-', '...'], $s);
    $s = preg_replace('/\s*-\s*/u', '-', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

/** Teks dipotong ke $maks karakter; $peringatan diisi bila terpotong. */
function instrumenGwPotong(string $s, int $maks, string $label, array &$peringatan): ?string
{
    if ($s === '') return null;
    if (mb_strlen($s, 'UTF-8') > $maks) {
        $peringatan[] = "$label dipotong menjadi $maks karakter";
        return mb_substr($s, 0, $maks, 'UTF-8');
    }
    return $s;
}

/**
 * Peta kolom dari baris header.
 * Hasil: ['kolom' => kunci => indeks, 'judul' => kunci => teks pertanyaan, 'peringatan' => [], 'galat' => ?string]
 * Kunci: '_cap_waktu', 'A00'…'A64', 'A40_01'…'A41_42'.
 */
function instrumenGwPetaKolom(array $header): array
{
    $kolom = []; $judul = []; $peringatan = [];
    foreach (array_values($header) as $i => $h) {
        $teks = instrumenGwSel($h);
        if ($teks === '') continue;
        $lbl = mb_strtolower($teks, 'UTF-8');
        if ($lbl === 'cap waktu' || $lbl === 'timestamp') {
            if (isset($kolom['_cap_waktu'])) return ['kolom' => [], 'judul' => [], 'peringatan' => [], 'galat' => 'Kolom Cap waktu muncul lebih dari sekali.'];
            $kolom['_cap_waktu'] = $i;
            continue;
        }
        if (!preg_match('/^\[(A\d{2})\]\s*(.*)$/u', $teks, $m)) continue;
        $kode = $m[1];
        $sisa = $m[2];
        if ($kode === 'A40' || $kode === 'A41') {
            if (!preg_match('/\[(\d{1,2})\.\s*([^\]]*)\]\s*$/u', $sisa, $g)) {
                $peringatan[] = "Kolom [$kode] tanpa nomor pernyataan diabaikan";
                continue;
            }
            $no = (int)$g[1];
            $diBlok = $kode === 'A40' ? ($no >= 1 && $no <= 21) : ($no >= 22 && $no <= 42);
            if (!$diBlok) {
                $peringatan[] = "Pernyataan nomor $no tidak sesuai blok [$kode], diabaikan";
                continue;
            }
            $kunci = sprintf('%s_%02d', $kode, $no);
            $judul[$kunci] = trim($g[2]);
        } elseif (in_array($kode, IGW_KODE_DIKENAL, true)) {
            $kunci = $kode;
            $judul[$kunci] = trim($sisa);
        } else {
            $peringatan[] = "Kolom [$kode] tidak dikenal, diabaikan";
            continue;
        }
        if (isset($kolom[$kunci])) {
            return ['kolom' => [], 'judul' => [], 'peringatan' => [], 'galat' => "Kolom [$kunci] muncul lebih dari sekali."];
        }
        $kolom[$kunci] = $i;
    }
    foreach (['_cap_waktu', 'A00', 'A01'] as $wajib) {
        if (!isset($kolom[$wajib])) return ['kolom' => [], 'judul' => [], 'peringatan' => [], 'galat' => IGW_MSG_FORMAT];
    }
    // Kolom penskoran yang hilang
    $minat = count(array_filter(array_keys($kolom), fn($k) => preg_match('/^A4[01]_/', $k)));
    if ($minat === 0) $peringatan[] = 'Kolom pernyataan minat [A40]/[A41] tidak ada: minat RIASEC tidak diskor';
    elseif ($minat < 42) $peringatan[] = "Hanya $minat dari 42 kolom pernyataan minat yang ada: skor dihitung dari pernyataan yang ada";
    $vakHilang = array_values(array_filter(array_keys(IGW_VAK), fn($k) => !isset($kolom[$k])));
    if ($vakHilang) $peringatan[] = 'Kolom gaya belajar [' . implode('], [', $vakHilang) . '] tidak ada: skor V/A/K dihitung dari soal yang ada';
    if (!isset($kolom['A45'])) $peringatan[] = 'Kolom kegiatan [A45] tidak ada: kompetensi RIASEC tidak diskor';
    return ['kolom' => $kolom, 'judul' => $judul, 'peringatan' => $peringatan, 'galat' => null];
}

/**
 * Cap waktu -> ['waktu' => 'Y-m-d H:i:s', 'asumsi_dmy' => bool] atau null.
 * Menerima serial Excel (1900), 'YYYY-MM-DD[ HH:MM[:SS]]', 'D/M/YYYY[ H:MM[:SS]]' (lokal Indonesia).
 * $sekarang ('Y-m-d H:i:s') dipakai untuk menolak tanggal > 1 hari di masa depan.
 */
function instrumenGwCapWaktu($sel, ?string $sekarang = null): ?array
{
    $s = instrumenGwSel($sel);
    if ($s === '') return null;
    $asumsi = false;
    $jam = 0; $mnt = 0; $dtk = 0;
    if (preg_match('/^\d+(\.\d+)?$/', $s)) {
        $f = (float)$s;
        if ($f < 20000 || $f > 80000) return null;
        $hari = (int)floor($f);
        $detik = (int)round(($f - $hari) * 86400);
        if ($detik >= 86400) { $hari++; $detik -= 86400; }
        $dt = (new DateTimeImmutable('1899-12-30 00:00:00'))->modify("+$hari days")->modify("+$detik seconds");
        $hasil = $dt->format('Y-m-d H:i:s');
    } else {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $s, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:,?\s+(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?)?$/', $s, $m)) {
            [$p1, $p2, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            if ($p1 > 12) { $d = $p1; $mo = $p2; }
            elseif ($p2 > 12) { $d = $p2; $mo = $p1; }
            else { $d = $p1; $mo = $p2; $asumsi = $p1 !== $p2; }
        } else {
            return null;
        }
        $jam = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0;
        $mnt = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;
        $dtk = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
        if ($y < 2000 || $y > 2100 || !checkdate($mo, $d, $y) || $jam > 23 || $mnt > 59 || $dtk > 59) return null;
        $hasil = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $jam, $mnt, $dtk);
    }
    $now = new DateTimeImmutable($sekarang ?? 'now');
    if (new DateTimeImmutable($hasil) > $now->modify('+1 day')) return null;
    return ['waktu' => $hasil, 'asumsi_dmy' => $asumsi];
}

/** Daftar mapel (dipisah , ; atau baris baru), unik, maks. 3 (sisanya dibuang + peringatan). */
function instrumenGwDaftarMapel(string $s, string $label, array &$peringatan): ?array
{
    $hasil = [];
    foreach (preg_split('/[,;\n]/u', $s) as $it) {
        $x = mb_substr(trim($it), 0, 100, 'UTF-8');
        if ($x === '') continue;
        $ada = false;
        foreach ($hasil as $h) { if (mb_strtolower($h, 'UTF-8') === mb_strtolower($x, 'UTF-8')) { $ada = true; break; } }
        if (!$ada) $hasil[] = $x;
    }
    if (count($hasil) > 3) {
        $peringatan[] = "$label lebih dari 3, diambil 3 pertama";
        $hasil = array_slice($hasil, 0, 3);
    }
    return $hasil ?: null;
}

/**
 * Skor minat RIASEC dari A40_xx/A41_xx.
 * Hasil: ['skor' => ['r' => float, ...] | null, 'kode' => ?string, 'peringatan' => []]
 */
function instrumenGwSkorMinat(array $jawaban): array
{
    $peringatan = [];
    $adaKolom = false;
    $skor = [];
    $asing = 0;
    foreach (IGW_KUNCI_MINAT as $tipe => $nomor) {
        $suka = 0; $terjawab = 0;
        foreach ($nomor as $no) {
            $kunci = sprintf('%s_%02d', $no <= 21 ? 'A40' : 'A41', $no);
            if (!array_key_exists($kunci, $jawaban)) continue;
            $adaKolom = true;
            $v = instrumenGwNorm($jawaban[$kunci]);
            if ($v === 'suka') { $suka++; $terjawab++; }
            elseif ($v === 'tidak suka') { $terjawab++; }
            elseif ($v !== '') { $asing++; }
        }
        $skor[$tipe] = $terjawab > 0 ? round($suka / $terjawab * 100, 2) : null;
    }
    if (!$adaKolom) return ['skor' => null, 'kode' => null, 'peringatan' => []];
    if ($asing) $peringatan[] = "$asing jawaban pernyataan minat bukan Suka/Tidak suka, diabaikan";
    if (in_array(null, $skor, true)) {
        $peringatan[] = 'Pernyataan minat tidak lengkap untuk semua tipe: minat RIASEC tidak diskor';
        return ['skor' => null, 'kode' => null, 'peringatan' => $peringatan];
    }
    return ['skor' => $skor, 'kode' => profilMuridKodeRiasec($skor), 'peringatan' => $peringatan];
}

/**
 * Skor kompetensi RIASEC dari A45 (pilih 8 dari 24 kegiatan).
 * Hasil: ['skor' => [...] | null, 'kode' => ?string, 'kegiatan' => string[], 'peringatan' => []]
 */
function instrumenGwSkorKompetensi($sel): array
{
    $s = instrumenGwSel($sel);
    if ($s === '') return ['skor' => null, 'kode' => null, 'kegiatan' => [], 'peringatan' => []];
    $peta = [];
    foreach (IGW_KEGIATAN as $tipe => $daftar) foreach ($daftar as $k) $peta[instrumenGwNorm($k)] = [$tipe, $k];
    $dipilih = []; $asing = [];
    foreach (preg_split('/,\s*/u', $s) as $it) {
        $n = instrumenGwNorm($it);
        if ($n === '') continue;
        if (isset($peta[$n])) $dipilih[$peta[$n][1]] = $peta[$n][0];
        else $asing[] = trim($it);
    }
    $peringatan = [];
    if ($asing) $peringatan[] = 'Kegiatan tidak dikenal diabaikan: ' . implode(', ', $asing);
    if (!$dipilih) {
        $peringatan[] = 'Tidak ada kegiatan [A45] yang dikenal: kompetensi RIASEC tidak diskor';
        return ['skor' => null, 'kode' => null, 'kegiatan' => [], 'peringatan' => $peringatan];
    }
    if (count($dipilih) !== 8) $peringatan[] = 'Murid memilih ' . count($dipilih) . ' kegiatan (seharusnya 8)';
    $skor = [];
    foreach (array_keys(IGW_KEGIATAN) as $tipe) {
        $skor[$tipe] = round(count(array_filter($dipilih, fn($t) => $t === $tipe)) / 4 * 100, 2);
    }
    $kegiatan = array_values(array_filter(IGW_KEGIATAN_URUT, fn($k) => isset($dipilih[$k])));
    return ['skor' => $skor, 'kode' => profilMuridKodeRiasec($skor), 'kegiatan' => $kegiatan, 'peringatan' => $peringatan];
}

/**
 * Skor gaya belajar V/A/K dari A30–A34.
 * Hasil: ['skor' => ['skor_visual' => .., 'skor_auditori' => .., 'skor_kinestetik' => ..] | null, 'gaya' => ?string, 'peringatan' => []]
 */
function instrumenGwSkorVak(array $jawaban): array
{
    $hitung = [0, 0, 0]; $terjawab = 0; $peringatan = [];
    foreach (IGW_VAK as $kode => $opsi) {
        if (!array_key_exists($kode, $jawaban)) continue;
        $v = instrumenGwNorm($jawaban[$kode]);
        if ($v === '') continue;
        $idx = null;
        foreach ($opsi as $i => $o) { if (instrumenGwNorm($o) === $v) { $idx = $i; break; } }
        if ($idx === null) { $peringatan[] = "Jawaban [$kode] tidak dikenal, tidak dihitung"; continue; }
        $hitung[$idx]++; $terjawab++;
    }
    if ($terjawab === 0) return ['skor' => null, 'gaya' => null, 'peringatan' => $peringatan];
    $skor = [
        'skor_visual'     => round($hitung[0] / $terjawab * 100, 2),
        'skor_auditori'   => round($hitung[1] / $terjawab * 100, 2),
        'skor_kinestetik' => round($hitung[2] / $terjawab * 100, 2),
    ];
    $gaya = ['VISUAL', 'AUDITORI', 'KINESTETIK'];
    $terbaik = 0;
    for ($i = 1; $i < 3; $i++) if ($hitung[$i] > $hitung[$terbaik]) $terbaik = $i;
    return ['skor' => $skor, 'gaya' => $gaya[$terbaik], 'peringatan' => $peringatan];
}

/**
 * Petakan jawaban satu respons ke field asesmen.
 * $jawaban: kunci (A00…, A40_01…) => teks sel. Kunci yang tidak ada = kolom tidak ada di file.
 * Hasil: ['data' => field => nilai (hanya yang dihasilkan, tanpa null), 'jarak_kategori' => ?string, 'jarak_km' => ?float,
 *         'transportasi' => ?string, 'peringatan' => []]
 */
function instrumenGwPetakan(array $jawaban): array
{
    $data = []; $peringatan = [];
    $sel = fn(string $k) => instrumenGwSel($jawaban[$k] ?? '');

    $minat = instrumenGwSkorMinat($jawaban);
    array_push($peringatan, ...$minat['peringatan']);
    if ($minat['skor']) {
        foreach ($minat['skor'] as $t => $v) $data['riasec_' . $t] = $v;
        $data['riasec_kode'] = $minat['kode'];
    }
    if (array_key_exists('A45', $jawaban)) {
        $komp = instrumenGwSkorKompetensi($jawaban['A45']);
        array_push($peringatan, ...$komp['peringatan']);
        if ($komp['skor']) {
            foreach ($komp['skor'] as $t => $v) $data['kompetensi_' . $t] = $v;
            $data['kompetensi_kode'] = $komp['kode'];
            $data['kegiatan_dikuasai'] = $komp['kegiatan'];
        }
    }
    $vak = instrumenGwSkorVak($jawaban);
    array_push($peringatan, ...$vak['peringatan']);
    if ($vak['skor']) {
        $data += $vak['skor'];
        $data['gaya_belajar'] = $vak['gaya'];
    }

    foreach ([['A35', 'kesulitan_belajar', 1000, 'Kesulitan belajar'], ['A36', 'kesulitan_cerita', 1000, 'Cerita kesulitan'],
              ['A50', 'bakat', 1000, 'Bakat'], ['A51', 'keterampilan_target', 255, 'Keterampilan ingin ditingkatkan'],
              ['A61', 'cita_cita', 150, 'Profesi diminati'], ['A62', 'institusi_tujuan', 150, 'Sasaran kampus/perusahaan'],
              ['A64', 'alasan_rencana', 1000, 'Alasan rencana']] as [$kode, $f, $maks, $label]) {
        $v = instrumenGwPotong($sel($kode), $maks, $label, $peringatan);
        if ($v !== null) $data[$f] = $v;
    }
    foreach ([['A37', 'mapel_termudah', 'Mapel termudah'], ['A38', 'mapel_tersulit', 'Mapel tersulit'],
              ['A39', 'mapel_pilihan', 'Mapel pilihan'], ['A63', 'mapel_pendukung', 'Mapel pendukung']] as [$kode, $f, $label]) {
        $v = instrumenGwDaftarMapel($sel($kode), $label, $peringatan);
        if ($v !== null) $data[$f] = $v;
    }
    $rencana = instrumenGwNorm($sel('A60'));
    if ($rencana !== '') {
        if (isset(IGW_RENCANA[$rencana])) $data['rencana_lulus'] = IGW_RENCANA[$rencana];
        else $peringatan[] = 'Rencana setelah lulus tidak dikenal: ' . $sel('A60');
    }

    $jarakKat = $sel('A20');
    $jarakKm = null;
    if ($jarakKat !== '') {
        $n = instrumenGwNorm($jarakKat);
        if (isset(IGW_JARAK[$n])) $jarakKm = IGW_JARAK[$n];
        else $peringatan[] = 'Jarak rumah tidak dikenal: ' . $jarakKat;
    }
    $trans = $sel('A21');
    return [
        'data'           => $data,
        'jarak_kategori' => $jarakKat !== '' ? mb_substr($jarakKat, 0, 50, 'UTF-8') : null,
        'jarak_km'       => $jarakKm,
        'transportasi'   => $trans !== '' ? mb_substr($trans, 0, 100, 'UTF-8') : null,
        'peringatan'     => $peringatan,
    ];
}

/** Nama untuk dibandingkan: huruf kecil, hanya huruf/angka, spasi tunggal. */
function instrumenGwNormNama($v): string
{
    $s = mb_strtolower(instrumenGwSel($v), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
    return trim($s);
}

/** Nilai field untuk perbandingan/penyimpanan (array -> JSON, angka -> string 2 desimal). */
function instrumenGwNilaiDb(string $f, $v): ?string
{
    if ($v === null) return null;
    if (in_array($f, IGW_FIELD_JSON, true)) return json_encode(array_values((array)$v), JSON_UNESCAPED_UNICODE);
    if (is_float($v) || is_int($v)) return number_format((float)$v, 2, '.', '');
    return (string)$v;
}

/** Kelompok field yang berubah dibanding baris asesmen lama ($lama = baris DB atau null). */
function instrumenGwKelompokBerubah(array $data, ?array $lama): array
{
    $hasil = [];
    foreach (IGW_KELOMPOK as $label => $fields) {
        foreach ($fields as $f) {
            if (!array_key_exists($f, $data)) continue;
            $baru = instrumenGwNilaiDb($f, $data[$f]);
            $old = $lama[$f] ?? null;
            if ($old !== null && is_numeric($old) && is_numeric($baru)) $berubah = abs((float)$old - (float)$baru) > 0.004;
            elseif (in_array($f, IGW_FIELD_JSON, true)) {
                $o = json_decode((string)$old, true);
                $berubah = (is_array($o) ? array_values($o) : null) !== json_decode((string)$baru, true);
            } else $berubah = (string)$old !== (string)$baru || $old === null;
            if ($berubah) { $hasil[] = $label; break; }
        }
    }
    return $hasil;
}

// =====================================================================
// Skema
// =====================================================================

/** Tambah kolom/tabel/nilai enum untuk instrumen (idempoten; dipanggil dari profilMuridPastikanTabel). */
function instrumenGwPastikanSkema(mysqli $db): void
{
    static $sudah = false;
    if ($sudah) return;
    $cek = $db->query("SELECT
        (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_asesmen' AND COLUMN_NAME = 'instrumen_respons_id') AS kolom,
        (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_asesmen' AND COLUMN_NAME = 'sumber' AND COLUMN_TYPE LIKE '%INSTRUMEN%') AS sumber,
        (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_import_log' AND COLUMN_NAME = 'jenis' AND COLUMN_TYPE LIKE '%INSTRUMEN%') AS jenis,
        (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_instrumen_respons') AS tabel")->fetch_assoc();
    if ((int)$cek['kolom'] && (int)$cek['sumber'] && (int)$cek['jenis'] && (int)$cek['tabel']) { $sudah = true; return; }

    $db->query("SELECT GET_LOCK('" . IGW_LOCK_SKEMA . "', 15)");
    try {
        $ada = [];
        $res = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_asesmen'");
        while ($r = $res->fetch_assoc()) $ada[$r['COLUMN_NAME']] = $r['COLUMN_TYPE'];
        $tambah = [];
        foreach (IGW_KOLOM_BARU as $k => $def) if (!isset($ada[$k])) $tambah[] = "ADD COLUMN `$k` $def";
        if (stripos($ada['sumber'] ?? '', 'INSTRUMEN') === false) $tambah[] = "MODIFY `sumber` enum('IMPOR','MANUAL','INSTRUMEN') NOT NULL";
        if ($tambah) $db->query('ALTER TABLE `student_asesmen` ' . implode(', ', $tambah));

        $jenis = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_import_log' AND COLUMN_NAME = 'jenis'")->fetch_assoc();
        if ($jenis && stripos($jenis['COLUMN_TYPE'], 'INSTRUMEN') === false) {
            $db->query("ALTER TABLE `student_import_log` MODIFY `jenis` enum('DAPODIK','ASESMEN','INSTRUMEN') NOT NULL");
        }
        $db->query("CREATE TABLE IF NOT EXISTS `student_instrumen_respons` (
            `id` varchar(64) NOT NULL,
            `student_id` varchar(128) NOT NULL,
            `academic_year` varchar(20) NOT NULL,
            `semester` enum('GANJIL','GENAP') NOT NULL,
            `instrumen` varchar(50) NOT NULL,
            `cap_waktu` datetime NOT NULL,
            `nisn_form` varchar(20) NOT NULL,
            `nama_form` varchar(255) DEFAULT NULL,
            `jarak_kategori` varchar(50) DEFAULT NULL,
            `transportasi` varchar(100) DEFAULT NULL,
            `jawaban_json` mediumtext NOT NULL,
            `guru_uid` varchar(128) NOT NULL,
            `guru_nama` varchar(255) NOT NULL,
            `impor_id` varchar(64) NOT NULL,
            `diimpor_pada` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_instrumen_periode` (`student_id`, `instrumen`, `academic_year`, `semester`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } finally {
        $db->query("SELECT RELEASE_LOCK('" . IGW_LOCK_SKEMA . "')");
    }
    $sudah = true;
}

// =====================================================================
// Pembaca untuk modul lain
// =====================================================================

/** Respons instrumen satu murid (terbaru dulu) untuk profil_murid_get. */
function instrumenGwUntukProfil(mysqli $db, string $studentId): array
{
    $rows = rekapFetchAll($db,
        "SELECT id, academic_year, semester, instrumen, cap_waktu, nama_form, jarak_kategori, transportasi, jawaban_json, guru_nama, diimpor_pada
         FROM student_instrumen_respons WHERE student_id = ?
         ORDER BY LEFT(academic_year, 4) DESC, semester DESC, cap_waktu DESC", 's', [$studentId]);
    return array_map(function ($r) {
        $j = json_decode((string)$r['jawaban_json'], true);
        $j = is_array($j) ? $j : [];
        unset($r['jawaban_json']);
        $r['jawaban'] = is_array($j['jawaban'] ?? null) ? $j['jawaban'] : [];
        $r['judul'] = is_array($j['judul'] ?? null) ? $j['judul'] : [];
        return $r;
    }, $rows);
}

/** Cap waktu respons periode aktif per murid: student_id => 'Y-m-d H:i:s'. */
function instrumenGwCapPerMurid(mysqli $db, array $studentIds, string $ta, string $sem): array
{
    if (!$studentIds || !profilMuridTabelAda($db, 'student_instrumen_respons')) return [];
    $ph = implode(',', array_fill(0, count($studentIds), '?'));
    $hasil = [];
    foreach (rekapFetchAll($db, "SELECT student_id, cap_waktu FROM student_instrumen_respons
                                 WHERE instrumen = ? AND academic_year = ? AND semester = ? AND student_id IN ($ph)",
                           'sss' . str_repeat('s', count($studentIds)), array_merge([IGW_INSTRUMEN, $ta, $sem], $studentIds)) as $r) {
        $hasil[$r['student_id']] = (string)$r['cap_waktu'];
    }
    return $hasil;
}

// =====================================================================
// Handler
// =====================================================================

/** Validasi bentuk rows dari klien (array 2D), sel dirapikan instrumenGwSel. */
function instrumenGwRows($rows): array
{
    if (!is_array($rows) || !$rows) errorResponse('Data file kosong.', 400);
    if (count($rows) > IGW_MAKS_BARIS) errorResponse('File terlalu besar: maksimal ' . (IGW_MAKS_BARIS - 1) . ' respons.', 400);
    $hasil = [];
    foreach (array_values($rows) as $r) {
        if (!is_array($r)) $r = [];
        if (count($r) > IGW_MAKS_KOLOM) errorResponse('File terlalu besar: maksimal ' . IGW_MAKS_KOLOM . ' kolom.', 400);
        $hasil[] = array_map('instrumenGwSel', array_values($r));
    }
    return $hasil;
}

function instrumenGwTanggalTampil(string $ymdHis): string
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ymdHis);
    return $d ? $d->format('d/m/Y H:i') : $ymdHis;
}

/**
 * Klasifikasi semua baris. Dipakai mode pratinjau dan simpan (simpan menghitung ulang dari data terbaru).
 * Hasil: ['baris' => item[], 'tulis' => [[murid, item, petakan, jawaban, cap], ...], 'ringkasan' => [...]]
 */
function instrumenGwKlasifikasi(mysqli $db, array $rows, array $peta, array $dampingan, string $ta, string $sem, array $rentang, bool $kunci): array
{
    $kol = $peta['kolom'];
    $perNisn = [];
    foreach ($dampingan as $m) {
        $n = profilMuridNormalNomor($m['nisn'], 'nisn')['nilai'];
        if ($n !== null) $perNisn[$n] = $m;
    }
    $baris = []; $kandidat = [];
    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        if (count(array_filter($r, fn($x) => $x !== '')) === 0) continue;
        $jawaban = [];
        foreach ($kol as $k => $c) if ($k !== '_cap_waktu') $jawaban[$k] = $r[$c] ?? '';
        $item = ['baris' => $i + 1, 'status' => 'GALAT', 'nisn' => $jawaban['A01'], 'nama_form' => $jawaban['A02'] ?? '',
                 'nama' => null, 'kelas' => null, 'student_id' => null, 'cap_waktu' => null,
                 'riasec_kode' => null, 'kompetensi_kode' => null, 'gaya_belajar' => null, 'rencana_lulus' => null,
                 'field' => [], 'pesan' => [], 'peringatan' => []];
        $cap = instrumenGwCapWaktu($r[$kol['_cap_waktu']] ?? '');
        if ($cap === null) { $item['pesan'][] = 'Cap waktu tidak terbaca'; $baris[] = $item; continue; }
        $item['cap_waktu'] = $cap['waktu'];
        if ($cap['asumsi_dmy']) $item['peringatan'][] = 'Tanggal dibaca sebagai hari/bulan/tahun (' . instrumenGwTanggalTampil($cap['waktu']) . ')';
        if (instrumenGwNorm($jawaban['A00']) !== instrumenGwNorm(IGW_PERSETUJUAN)) {
            $item['status'] = 'LEWATI'; $item['pesan'][] = 'Murid tidak menyetujui pernyataan'; $baris[] = $item; continue;
        }
        $no = profilMuridNormalNomor($jawaban['A01'], 'nisn');
        if ($no['galat'] || $no['nilai'] === null) { $item['pesan'][] = $no['galat'] ?? 'NISN kosong'; $baris[] = $item; continue; }
        $item['nisn'] = $no['nilai'];
        if ($no['peringatan']) $item['peringatan'][] = $no['peringatan'];
        elseif (strlen(preg_replace('/\D/', '', $jawaban['A01'])) !== 10) $item['peringatan'][] = 'NISN di formulir tidak 10 digit, dilengkapi nol di depan';
        if (!isset($perNisn[$no['nilai']])) {
            $item['status'] = 'LEWATI'; $item['pesan'][] = 'Bukan murid dampingan aktif Anda'; $baris[] = $item; continue;
        }
        $tgl = substr($cap['waktu'], 0, 10);
        if ($tgl < $rentang[0] || $tgl > $rentang[1]) {
            $item['status'] = 'LEWATI';
            $item['pesan'][] = "Diisi di luar periode aktif (TA $ta, semester " . ($sem === 'GENAP' ? 'Genap' : 'Ganjil') . ')';
            $baris[] = $item; continue;
        }
        $m = $perNisn[$no['nilai']];
        $item['student_id'] = (string)$m['id'];
        $item['nama'] = (string)$m['name'];
        $item['kelas'] = (string)$m['class_name'];
        $namaForm = instrumenGwNormNama($item['nama_form']);
        if ($namaForm !== '' && $namaForm !== instrumenGwNormNama($m['name'])) {
            $item['peringatan'][] = "Nama di formulir ({$item['nama_form']}) berbeda dengan data SIMANTAP";
        }
        $idx = count($baris);
        $baris[] = $item;
        $kandidat[(string)$m['id']][] = [$idx, $cap['waktu'], $jawaban, $m];
    }

    // Data tersimpan untuk murid kandidat
    $ids = array_keys($kandidat);
    $asesmenLama = []; $responsLama = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $tp = 'ss' . str_repeat('s', count($ids));
        $kunciSql = $kunci ? ' FOR UPDATE' : '';
        foreach (rekapFetchAll($db, "SELECT * FROM student_asesmen WHERE academic_year = ? AND semester = ? AND student_id IN ($ph)$kunciSql",
                               $tp, array_merge([$ta, $sem], $ids)) as $r) $asesmenLama[$r['student_id']] = $r;
        foreach (rekapFetchAll($db, "SELECT id, student_id, cap_waktu FROM student_instrumen_respons
                                     WHERE instrumen = '" . IGW_INSTRUMEN . "' AND academic_year = ? AND semester = ? AND student_id IN ($ph)$kunciSql",
                               $tp, array_merge([$ta, $sem], $ids)) as $r) $responsLama[$r['student_id']] = $r;
    }

    $tulis = [];
    foreach ($kandidat as $sid => $list) {
        // Terbaru menang; seri -> baris terbawah
        usort($list, fn($x, $y) => [$y[1], $y[0]] <=> [$x[1], $x[0]]);
        [$idx, $capW, $jawaban, $m] = $list[0];
        for ($j = 1; $j < count($list); $j++) {
            $b = &$baris[$list[$j][0]];
            $b['status'] = 'LEWATI';
            $b['pesan'][] = 'Diganti respons lebih baru (baris ' . $baris[$idx]['baris'] . ')';
            unset($b);
        }
        $item = &$baris[$idx];
        if (isset($responsLama[$sid]) && (string)$responsLama[$sid]['cap_waktu'] > $capW) {
            $item['status'] = 'LEWATI';
            $item['pesan'][] = 'Sudah ada respons yang lebih baru (' . instrumenGwTanggalTampil((string)$responsLama[$sid]['cap_waktu']) . ')';
            unset($item); continue;
        }
        $p = instrumenGwPetakan($jawaban);
        array_push($item['peringatan'], ...$p['peringatan']);
        if (!$p['data']) {
            $item['status'] = 'LEWATI'; $item['pesan'][] = 'Tidak ada jawaban yang bisa diolah';
            unset($item); continue;
        }
        $lama = $asesmenLama[$sid] ?? null;
        $item['status'] = $lama ? 'TIMPA' : 'BARU';
        $item['field'] = array_keys($p['data']);
        $item['riasec_kode'] = $p['data']['riasec_kode'] ?? null;
        $item['kompetensi_kode'] = $p['data']['kompetensi_kode'] ?? null;
        $item['gaya_belajar'] = $p['data']['gaya_belajar'] ?? null;
        $item['rencana_lulus'] = $p['data']['rencana_lulus'] ?? null;
        if ($lama) {
            $kel = instrumenGwKelompokBerubah($p['data'], $lama);
            $item['pesan'][] = $kel ? 'Mengganti: ' . implode(', ', $kel) : 'Tidak ada perubahan nilai';
        }
        $tulis[] = ['murid' => $m, 'jawaban' => $jawaban, 'petakan' => $p, 'cap' => $capW, 'lama' => $lama,
                    'respons_lama' => $responsLama[$sid] ?? null, 'nama_form' => $item['nama_form'], 'nisn' => $item['nisn']];
        unset($item);
    }
    $hitung = fn(string $s) => count(array_filter($baris, fn($b) => $b['status'] === $s));
    return [
        'baris'     => $baris,
        'tulis'     => $tulis,
        'ringkasan' => ['baru' => $hitung('BARU'), 'timpa' => $hitung('TIMPA'), 'lewati' => $hitung('LEWATI'), 'galat' => $hitung('GALAT'), 'total' => count($baris)],
    ];
}

/** Tulis satu respons (dalam transaksi pemanggil). */
function instrumenGwTulis(mysqli $db, array $t, array $peta, string $ta, string $sem, array $user, string $imporId): void
{
    $m = $t['murid'];
    $sid = (string)$m['id'];
    $uid = (string)$user['uid'];
    $unama = (string)($user['displayName'] ?? '');
    $data = $t['petakan']['data'];

    // 1. Respons mentah
    $jawabanSimpan = [];
    foreach ($t['jawaban'] as $k => $v) $jawabanSimpan[$k] = mb_substr((string)$v, 0, PM_MAKS_SEL, 'UTF-8');
    $judul = [];
    foreach ($peta['judul'] as $k => $v) $judul[$k] = mb_substr((string)$v, 0, 300, 'UTF-8');
    $json = json_encode(['jawaban' => $jawabanSimpan, 'judul' => $judul], JSON_UNESCAPED_UNICODE);
    $namaForm = mb_substr((string)$t['nama_form'], 0, 255, 'UTF-8');
    $nisn = (string)$t['nisn'];
    $instr = IGW_INSTRUMEN;
    if ($t['respons_lama']) {
        $rid = (string)$t['respons_lama']['id'];
        $stmt = $db->prepare("UPDATE student_instrumen_respons SET cap_waktu = ?, nisn_form = ?, nama_form = ?, jarak_kategori = ?, transportasi = ?,
                              jawaban_json = ?, guru_uid = ?, guru_nama = ?, impor_id = ?, diimpor_pada = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param('ssssssssss', $t['cap'], $nisn, $namaForm, $t['petakan']['jarak_kategori'], $t['petakan']['transportasi'],
                          $json, $uid, $unama, $imporId, $rid);
    } else {
        $rid = uniqid('igr_');
        $stmt = $db->prepare("INSERT INTO student_instrumen_respons (id, student_id, academic_year, semester, instrumen, cap_waktu, nisn_form, nama_form,
                              jarak_kategori, transportasi, jawaban_json, guru_uid, guru_nama, impor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssssssssssss', $rid, $sid, $ta, $sem, $instr, $t['cap'], $nisn, $namaForm,
                          $t['petakan']['jarak_kategori'], $t['petakan']['transportasi'], $json, $uid, $unama, $imporId);
    }
    $stmt->execute();

    // 2. Asesmen: hanya field yang dihasilkan
    $lama = $t['lama'];
    $logs = $lama ? json_decode((string)$lama['logs'], true) : [];
    if (!is_array($logs)) $logs = [];
    $fields = array_values(array_filter(IGW_FIELD, fn($f) => array_key_exists($f, $data)));
    $logs[] = ['aksi' => 'INSTRUMEN', 'oleh' => $uid, 'oleh_nama' => $unama, 'waktu' => date('Y-m-d H:i:s'),
               'sumber' => 'INSTRUMEN', 'impor_id' => $imporId, 'field' => $fields];
    $logs = array_slice($logs, -PM_MAKS_LOG);
    $logJson = json_encode($logs, JSON_UNESCAPED_UNICODE);
    $vals = array_map(fn($f) => instrumenGwNilaiDb($f, $data[$f]), $fields);
    $kelasId = (string)$m['class_id'];
    $kelasNama = (string)$m['class_name'];
    if ($lama) {
        $set = implode(', ', array_map(fn($f) => "`$f` = ?", $fields));
        $stmt = $db->prepare("UPDATE student_asesmen SET $set, sumber = 'INSTRUMEN', class_id = ?, class_name = ?, logs = ?, diubah_oleh = ?,
                              diubah_oleh_nama = ?, instrumen_respons_id = ? WHERE id = ?");
        $params = array_merge($vals, [$kelasId, $kelasNama, $logJson, $uid, $unama, $rid, (string)$lama['id']]);
    } else {
        $cols = array_merge(['id', 'student_id', 'academic_year', 'semester', 'class_id', 'class_name'], $fields,
                            ['sumber', 'logs', 'dibuat_oleh', 'dibuat_oleh_nama', 'diubah_oleh', 'diubah_oleh_nama', 'instrumen_respons_id']);
        $params = array_merge([uniqid('asm_'), $sid, $ta, $sem, $kelasId, $kelasNama], $vals,
                              ['INSTRUMEN', $logJson, $uid, $unama, $uid, $unama, $rid]);
        $stmt = $db->prepare("INSERT INTO student_asesmen (`" . implode('`, `', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
    }
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();

    // 3. Biodata Dapodik: hanya baris yang sudah ada dan field yang masih kosong
    if ($t['petakan']['jarak_km'] !== null) {
        $km = number_format($t['petakan']['jarak_km'], 2, '.', '');
        $stmt = $db->prepare("UPDATE student_biodata SET jarak_rumah_km = ? WHERE student_id = ? AND jarak_rumah_km IS NULL");
        $stmt->bind_param('ss', $km, $sid);
        $stmt->execute();
    }
    if ($t['petakan']['transportasi'] !== null) {
        $tr = mb_substr($t['petakan']['transportasi'], 0, 50, 'UTF-8');
        $stmt = $db->prepare("UPDATE student_biodata SET alat_transportasi = ? WHERE student_id = ? AND (alat_transportasi IS NULL OR alat_transportasi = '')");
        $stmt->bind_param('ss', $tr, $sid);
        $stmt->execute();
    }
}

/** POST instrumen_gw_impor (guru wali; mode pratinjau|simpan) */
function handleInstrumenGwImpor(mysqli $db): void
{
    profilMuridWajibMetode('POST');
    $sesi = requireAuth();
    $user = loadFreshUser($db, $sesi);
    if (($user['role'] ?? '') !== 'GURU') errorResponse(IGW_MSG_PERAN, 403);
    guruWaliPastikanTabel($db);
    profilMuridPastikanTabel($db);
    instrumenGwPastikanSkema($db);
    profilMuridDalamTry('instrumen_gw_impor', function () use ($db, $user) {
        $uid = (string)$user['uid'];
        $idDampingan = guruWaliDampinganIds($db, $uid);
        if (!$idDampingan) errorResponse(IGW_MSG_KOSONG, 403);
        $body = getRequestBody();
        $mode = (string)($body['mode'] ?? 'pratinjau');
        if (!in_array($mode, ['pratinjau', 'simpan'], true)) errorResponse('Mode tidak valid (pratinjau atau simpan).', 400);
        $rows = instrumenGwRows($body['rows'] ?? null);
        $peta = instrumenGwPetaKolom($rows[0]);
        if ($peta['galat']) errorResponse($peta['galat'], 400);

        $period = getActiveAcademicPeriod($db);
        $ta = (string)$period['academic_year'];
        $sem = strtoupper((string)$period['semester']) === 'GENAP' ? 'GENAP' : 'GANJIL';
        $rentang = getSemesterDateRange($ta, $sem);
        if (!$rentang) errorResponse("Tahun ajaran aktif ($ta) tidak valid. Hubungi admin.", 400);

        $ph = implode(',', array_fill(0, count($idDampingan), '?'));
        $muridSql = "SELECT id, name, nisn, class_id, class_name, status FROM students WHERE id IN ($ph)";
        $muridTp = str_repeat('s', count($idDampingan));

        $respon = fn(array $k) => ['mode' => $mode, 'academic_year' => $ta, 'semester' => $sem, 'ringkasan' => $k['ringkasan'],
                                   'peringatan_file' => $peta['peringatan'], 'baris' => $k['baris']];
        if ($mode === 'pratinjau') {
            $k = instrumenGwKlasifikasi($db, $rows, $peta, rekapFetchAll($db, $muridSql, $muridTp, $idDampingan), $ta, $sem, $rentang, false);
            jsonResponse($respon($k));
        }

        $imporId = uniqid('igi_');
        $db->begin_transaction();
        try {
            $k = instrumenGwKlasifikasi($db, $rows, $peta, rekapFetchAll($db, $muridSql, $muridTp, $idDampingan), $ta, $sem, $rentang, true);
            if (!$k['tulis']) { $db->rollback(); errorResponse('Tidak ada baris valid untuk disimpan.', 400); }
            foreach ($k['tulis'] as $t) instrumenGwTulis($db, $t, $peta, $ta, $sem, $user, $imporId);
            $namaFile = mb_substr(instrumenGwSel($body['nama_file'] ?? ''), 0, 255, 'UTF-8');
            $jTulis = count($k['tulis']);
            $unama = (string)($user['displayName'] ?? '');
            $stmt = $db->prepare("INSERT INTO student_import_log (id, jenis, nama_file, class_id, academic_year, semester, jml_baris, jml_tulis, jml_lewati, jml_galat, oleh, oleh_nama)
                                  VALUES (?, 'INSTRUMEN', ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssiiiiss', $imporId, $namaFile, $ta, $sem, $k['ringkasan']['total'], $jTulis, $k['ringkasan']['lewati'], $k['ringkasan']['galat'], $uid, $unama);
            $stmt->execute();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        $hasil = $respon($k);
        $hasil['impor_id'] = $imporId;
        $hasil['disimpan'] = count($k['tulis']);
        jsonResponse($hasil);
    });
}
