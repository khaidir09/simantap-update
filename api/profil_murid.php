<?php
// profil_murid.php - Profil Murid 360° (biodata Dapodik, asesmen non-kognitif, rencana karir, ringkasan)
// Spesifikasi: docs/specs/profil-murid-360.md
// Hanya boleh dimuat lewat api/index.php (yang sudah melewati gerbang lisensi).
// Memakai helper dari db.php, kesiswaan_access.php, rekap_bulanan.php, dan pantau_nilai.php.
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

const PM_MSG_DENY      = 'Anda tidak memiliki akses ke Profil Murid.';
const PM_MSG_404       = 'Murid tidak ditemukan.';
const PM_MSG_SERVER    = 'Terjadi kesalahan pada server. Coba lagi atau hubungi admin.';
const PM_MAKS_DAPODIK_BARIS = 2020;   // 2.000 baris data + baris judul/header
const PM_MAKS_DAPODIK_KOLOM = 120;
const PM_MAKS_ASESMEN_BARIS = 101;    // 100 murid + header
const PM_MAKS_ASESMEN_KOLOM = 30;
const PM_MAKS_SEL           = 500;
const PM_MAKS_LOG           = 50;
const PM_TREN_MAKS_PERIODE  = 12;

// Label kolom Dapodik (sudah dinormalisasi, lihat profilMuridNormalLabel) -> tujuan.
// Prefiks: s: = kolom students, b: = kolom student_biodata, o:<ayah|ibu|wali>:<field> = data orang tua.
const PM_DAPODIK_PETA = [
    'nisn'                              => 'nisn',
    'nik'                               => 'nik',
    'nama'                              => 'nama',
    'nama peserta didik'                => 'nama',
    'nipd'                              => 'b:nipd',
    'jk'                                => 's:jenis_kelamin',
    'jenis kelamin'                     => 's:jenis_kelamin',
    'tempat lahir'                      => 's:tempat_lahir',
    'tanggal lahir'                     => 's:tanggal_lahir',
    'agama'                             => 's:agama',
    'alamat'                            => 's:alamat',
    'rt'                                => 'b:rt',
    'rw'                                => 'b:rw',
    'dusun'                             => 'b:dusun',
    'kelurahan'                         => 'b:kelurahan',
    'desa/kelurahan'                    => 'b:kelurahan',
    'kecamatan'                         => 'b:kecamatan',
    'kode pos'                          => 'b:kode_pos',
    'jenis tinggal'                     => 'b:jenis_tinggal',
    'alat transportasi'                 => 'b:alat_transportasi',
    'telepon'                           => 'b:telepon',
    'hp'                                => 'b:hp',
    'e-mail'                            => 'b:email',
    'email'                             => 'b:email',
    'penerima kps'                      => 'b:penerima_kps',
    'no kps'                            => 'b:no_kps',
    'nomor kps'                         => 'b:no_kps',
    'penerima kip'                      => 'b:penerima_kip',
    'nomor kip'                         => 'b:no_kip',
    'no kip'                            => 'b:no_kip',
    'nama di kip'                       => 'b:nama_di_kip',
    'nomor kks'                         => 'b:no_kks',
    'no kks'                            => 'b:no_kks',
    'layak pip (usulan dari sekolah)'   => 'b:layak_pip',
    'layak pip'                         => 'b:layak_pip',
    'alasan layak pip'                  => 'b:alasan_layak_pip',
    'kebutuhan khusus'                  => 'b:kebutuhan_khusus',
    'sekolah asal'                      => 'b:sekolah_asal',
    'anak ke-berapa'                    => 'b:anak_ke',
    'anak ke berapa'                    => 'b:anak_ke',
    'anak ke'                           => 'b:anak_ke',
    'no kk'                             => 'b:no_kk',
    'nomor kk'                          => 'b:no_kk',
    'berat badan'                       => 'b:berat_badan',
    'tinggi badan'                      => 'b:tinggi_badan',
    'lingkar kepala'                    => 'b:lingkar_kepala',
    'jml saudara kandung'               => 'b:jml_saudara',
    'jumlah saudara kandung'            => 'b:jml_saudara',
    'jarak rumah ke sekolah (km)'       => 'b:jarak_rumah_km',
    'jarak rumah ke sekolah'            => 'b:jarak_rumah_km',
    'rombel saat ini'                   => 'b:rombel_dapodik',
    'rombel'                            => 'b:rombel_dapodik',
];
// Sub-kolom data orang tua (setelah "data ayah|ibu|wali ")
const PM_DAPODIK_ORTU = [
    'nama'               => 'nama',
    'tahun lahir'        => 'tahun_lahir',
    'jenjang pendidikan' => 'pendidikan',
    'pendidikan'         => 'pendidikan',
    'pekerjaan'          => 'pekerjaan',
    'penghasilan'        => 'penghasilan',
    'nik'                => 'nik',
];
// Kolom sensitif yang tidak pernah disimpan
const PM_DAPODIK_BUANG = ['bank', 'nomor rekening bank', 'no rekening bank', 'nomor rekening', 'rekening atas nama', 'lintang', 'bujur'];

// Kolom angka di student_biodata: field => [jenis, maks]
const PM_BIODATA_ANGKA = [
    'anak_ke'        => ['int', 50],
    'jml_saudara'    => ['int', 50],
    'berat_badan'    => ['int', 500],
    'tinggi_badan'   => ['int', 300],
    'lingkar_kepala' => ['int', 200],
    'jarak_rumah_km' => ['dec', 9999],
];
// Panjang maksimum kolom teks student_biodata
const PM_BIODATA_TEKS = [
    'nipd' => 30, 'no_kk' => 20, 'kebutuhan_khusus' => 100, 'rt' => 5, 'rw' => 5, 'dusun' => 100,
    'kelurahan' => 100, 'kecamatan' => 100, 'kode_pos' => 10, 'jenis_tinggal' => 50, 'alat_transportasi' => 50,
    'telepon' => 20, 'hp' => 20, 'email' => 150, 'sekolah_asal' => 150, 'penerima_kps' => 10, 'no_kps' => 50,
    'penerima_kip' => 10, 'no_kip' => 50, 'nama_di_kip' => 150, 'no_kks' => 50, 'layak_pip' => 10,
    'alasan_layak_pip' => 150, 'rombel_dapodik' => 100,
];

// Kolom template asesmen (label dinormalisasi -> field)
const PM_ASESMEN_KOLOM = [
    'nisn' => 'nisn', 'nama' => 'nama',
    'r' => 'riasec_r', 'i' => 'riasec_i', 'a' => 'riasec_a', 's' => 'riasec_s', 'e' => 'riasec_e', 'c' => 'riasec_c',
    'kode riasec' => 'riasec_kode',
    'gaya belajar' => 'gaya_belajar',
    'skor visual' => 'skor_visual', 'skor auditori' => 'skor_auditori', 'skor kinestetik' => 'skor_kinestetik',
    'kesulitan belajar' => 'kesulitan_belajar',
    'mapel termudah' => 'mapel_termudah', 'mapel tersulit' => 'mapel_tersulit',
    'cita-cita' => 'cita_cita', 'cita cita' => 'cita_cita',
    'rencana setelah lulus' => 'rencana_lulus',
    'jurusan tujuan' => 'jurusan_tujuan',
    'institusi tujuan' => 'institusi_tujuan',
    'catatan karir' => 'catatan_karir',
];
const PM_ASESMEN_HEADER = [
    'NISN', 'Nama', 'R', 'I', 'A', 'S', 'E', 'C', 'Kode RIASEC', 'Gaya Belajar', 'Skor Visual', 'Skor Auditori',
    'Skor Kinestetik', 'Kesulitan Belajar', 'Mapel Termudah', 'Mapel Tersulit', 'Cita-cita', 'Rencana Setelah Lulus',
    'Jurusan Tujuan', 'Institusi Tujuan', 'Catatan Karir',
];
const PM_ASESMEN_FIELD = [
    'riasec_r', 'riasec_i', 'riasec_a', 'riasec_s', 'riasec_e', 'riasec_c', 'riasec_kode', 'gaya_belajar',
    'skor_visual', 'skor_auditori', 'skor_kinestetik', 'kesulitan_belajar', 'mapel_termudah', 'mapel_tersulit',
    'cita_cita', 'rencana_lulus', 'jurusan_tujuan', 'institusi_tujuan', 'catatan_karir',
];
const PM_RENCANA_LABEL = [
    'KULIAH' => 'Kuliah', 'KERJA' => 'Kerja', 'WIRAUSAHA' => 'Wirausaha', 'KULIAH_KERJA' => 'Kuliah sambil kerja',
    'BELUM_TAHU' => 'Belum tahu', 'LAINNYA' => 'Lainnya',
];
const PM_GAYA_LABEL = ['VISUAL' => 'Visual', 'AUDITORI' => 'Auditori', 'KINESTETIK' => 'Kinestetik'];

// =====================================================================
// Fungsi murni (diuji di docs/patches/uji-profil-murid-unit.php)
// =====================================================================

/** Sel apa pun -> string satu baris yang sudah dirapikan, maks. PM_MAKS_SEL karakter. */
function profilMuridSel($v): string
{
    if ($v === null || is_array($v) || is_object($v)) return '';
    if (is_bool($v)) return $v ? '1' : '';
    if (is_float($v) && floor($v) == $v && abs($v) < 1e15) $v = (string)(int)$v;
    $s = trim(preg_replace('/\s+/u', ' ', (string)$v) ?? '');
    return mb_substr($s, 0, PM_MAKS_SEL, 'UTF-8');
}

/** Label kolom: huruf kecil, tanpa titik, spasi tunggal. */
function profilMuridNormalLabel($v): string
{
    $s = mb_strtolower(profilMuridSel($v), 'UTF-8');
    $s = str_replace('.', '', $s);
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

/**
 * Nomor identitas (NISN/NIK/KK): hanya digit.
 * Hasil: ['nilai' => ?string, 'galat' => ?string, 'peringatan' => ?string]
 * $jenis: 'nisn' (dipad ke 10 digit bila 8–9 digit) atau 'nik' (harus 16 digit, selain itu peringatan).
 */
function profilMuridNormalNomor($v, string $jenis): array
{
    $s = profilMuridSel($v);
    if ($s === '') return ['nilai' => null, 'galat' => null, 'peringatan' => null];
    if (preg_match('/^\d+([.,]\d+)?e\+?\d+$/i', $s)) {
        return ['nilai' => null, 'galat' => strtoupper($jenis) . ' rusak (format angka Excel, mis. 6.37E+15)', 'peringatan' => null];
    }
    $digit = preg_replace('/\D/', '', $s);
    if ($digit === '') return ['nilai' => null, 'galat' => strtoupper($jenis) . ' tidak berisi angka', 'peringatan' => null];
    $peringatan = null;
    if ($jenis === 'nisn') {
        $len = strlen($digit);
        if ($len >= 8 && $len < 10) $digit = str_pad($digit, 10, '0', STR_PAD_LEFT);
        elseif ($len !== 10) $peringatan = 'NISN tidak 10 digit';
    } elseif (strlen($digit) !== 16) {
        $peringatan = strtoupper($jenis) . ' tidak 16 digit';
    }
    return ['nilai' => $digit, 'galat' => null, 'peringatan' => $peringatan];
}

/** Tanggal -> YYYY-MM-DD atau null (YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, serial Excel). */
function profilMuridNormalTanggal($v): ?string
{
    $s = profilMuridSel($v);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $s, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^\d{1,5}(\.\d+)?$/', $s) && (float)$s >= 1 && (float)$s <= 60000) {
        $dt = (new DateTimeImmutable('1899-12-30'))->modify('+' . (int)$s . ' days');
        return $dt->format('Y-m-d');
    } else {
        return null;
    }
    if ($y < 1900 || $y > 2100 || !checkdate($mo, $d, $y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** Angka (koma desimal diterima). Hasil float, null (kosong), atau false (tidak valid). */
function profilMuridAngka($v)
{
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = profilMuridSel($v);
    if ($s === '') return null;
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) return false;
    return (float)$s;
}

/**
 * Deteksi header ekspor Dapodik.
 * Hasil null bila tidak dikenali, selain itu:
 * ['baris_header' => int, 'mulai' => int, 'kolom' => [idx => ['label' => asli, 'kunci' => normal, 'tujuan' => string]]]
 * tujuan: nisn | nik | nama | s:<kolom> | b:<kolom> | o:<ayah|ibu|wali>:<field> | lain | buang
 */
function profilMuridDapodikHeader(array $rows): ?array
{
    $h = null;
    $batas = min(15, count($rows));
    for ($i = 0; $i < $batas; $i++) {
        $labels = is_array($rows[$i]) ? array_map('profilMuridNormalLabel', $rows[$i]) : [];
        if (in_array('nisn', $labels, true) && (in_array('nama', $labels, true) || in_array('nama peserta didik', $labels, true))) {
            $h = $i;
            break;
        }
    }
    if ($h === null) return null;

    $top = array_values($rows[$h]);
    $sub = isset($rows[$h + 1]) && is_array($rows[$h + 1]) ? array_values($rows[$h + 1]) : [];
    $adaGrup = false;
    foreach ($top as $t) {
        if (preg_match('/^data (ayah|ibu|wali)/', profilMuridNormalLabel($t))) { $adaGrup = true; break; }
    }
    $duaTingkat = $adaGrup && count(array_filter($sub, fn($x) => profilMuridSel($x) !== '')) > 0;

    $kolom = [];
    $grup = ''; $grupAsli = '';
    $n = max(count($top), $duaTingkat ? count($sub) : 0);
    for ($c = 0; $c < $n; $c++) {
        $tAsli = profilMuridSel($top[$c] ?? '');
        $sAsli = $duaTingkat ? profilMuridSel($sub[$c] ?? '') : '';
        $t = profilMuridNormalLabel($tAsli);
        $s = profilMuridNormalLabel($sAsli);
        $label = ''; $asli = '';
        if ($t !== '') {
            if ($duaTingkat && preg_match('/^data (ayah|ibu|wali)/', $t) && $s !== '') {
                $grup = $t; $grupAsli = $tAsli;
                $label = $t . ' ' . $s; $asli = $tAsli . ' ' . $sAsli;
            } else {
                $grup = ''; $grupAsli = '';
                $label = $t; $asli = $tAsli;
            }
        } elseif ($grup !== '' && $s !== '') {
            $label = $grup . ' ' . $s; $asli = $grupAsli . ' ' . $sAsli;
        } elseif ($s !== '') {
            $label = $s; $asli = $sAsli;
        }
        if ($label === '') continue;
        $kolom[$c] = ['label' => $asli, 'kunci' => $label, 'tujuan' => profilMuridTujuanDapodik($label)];
    }
    return ['baris_header' => $h, 'mulai' => $h + ($duaTingkat ? 2 : 1), 'kolom' => $kolom];
}

/** Tujuan satu label Dapodik (sudah dinormalisasi). */
function profilMuridTujuanDapodik(string $label): string
{
    if (in_array($label, PM_DAPODIK_BUANG, true) || str_contains($label, 'rekening')) return 'buang';
    if (preg_match('/^data (ayah|ibu|wali) (.+)$/', $label, $m)) {
        $f = PM_DAPODIK_ORTU[$m[2]] ?? null;
        return $f !== null ? 'o:' . $m[1] . ':' . $f : 'lain';
    }
    if ($label === 'no') return 'abaikan';
    return PM_DAPODIK_PETA[$label] ?? 'lain';
}

/**
 * Petakan satu baris data Dapodik.
 * Hasil: ['kosong' => bool, 'nisn', 'nik', 'nama', 'students' => [], 'biodata' => [], 'ortu' => ['ayah'=>[],..],
 *         'lain' => [label => nilai], 'galat' => [], 'peringatan' => []]
 */
function profilMuridPetakanDapodik(array $kolom, array $row): array
{
    $row = array_values($row);
    $out = ['kosong' => true, 'nisn' => null, 'nik' => null, 'nama' => '', 'students' => [], 'biodata' => [],
            'ortu' => ['ayah' => [], 'ibu' => [], 'wali' => []], 'lain' => [], 'galat' => [], 'peringatan' => []];
    foreach ($row as $v) { if (profilMuridSel($v) !== '') { $out['kosong'] = false; break; } }
    if ($out['kosong']) return $out;

    foreach (PM_BIODATA_TEKS as $f => $_) $out['biodata'][$f] = null;
    foreach (PM_BIODATA_ANGKA as $f => $_) $out['biodata'][$f] = null;

    foreach ($kolom as $c => $k) {
        $raw = $row[$c] ?? '';
        $val = profilMuridSel($raw);
        $t = $k['tujuan'];
        if ($t === 'buang' || $t === 'abaikan') continue;
        if ($t === 'lain') {
            if ($val !== '') $out['lain'][$k['label']] = $val;
            continue;
        }
        if ($t === 'nisn' || $t === 'nik') {
            $r = profilMuridNormalNomor($raw, $t);
            if ($r['galat']) $out['galat'][] = $r['galat'];
            if ($r['peringatan']) $out['peringatan'][] = $r['peringatan'];
            $out[$t] = $r['nilai'];
            continue;
        }
        if ($t === 'nama') { $out['nama'] = $val; continue; }
        if (str_starts_with($t, 'o:')) {
            [, $siapa, $f] = explode(':', $t);
            if ($f === 'nik') {
                $r = profilMuridNormalNomor($raw, 'nik');
                if ($r['galat']) { $out['peringatan'][] = 'NIK ' . $siapa . ' rusak, dikosongkan'; $r['nilai'] = null; }
                $out['ortu'][$siapa][$f] = $r['nilai'];
            } else {
                $out['ortu'][$siapa][$f] = $val !== '' ? mb_substr($val, 0, 150, 'UTF-8') : null;
            }
            continue;
        }
        [$jenis, $f] = explode(':', $t, 2);
        if ($jenis === 's') {
            switch ($f) {
                case 'tanggal_lahir':
                    $tgl = profilMuridNormalTanggal($raw);
                    if ($val !== '' && $tgl === null) $out['peringatan'][] = 'Tanggal lahir tidak valid, dikosongkan';
                    $out['students'][$f] = $tgl;
                    break;
                case 'jenis_kelamin':
                    $jk = strtoupper($val);
                    if ($jk === 'L' || $jk === 'LAKI-LAKI') $out['students'][$f] = 'Laki-laki';
                    elseif ($jk === 'P' || $jk === 'PEREMPUAN') $out['students'][$f] = 'Perempuan';
                    break; // kosong/tidak dikenal: tidak diubah
                case 'agama':
                    if ($val !== '') $out['students'][$f] = mb_substr($val, 0, 30, 'UTF-8');
                    break;
                case 'tempat_lahir':
                    $out['students'][$f] = $val !== '' ? mb_substr($val, 0, 100, 'UTF-8') : null;
                    break;
                default:
                    $out['students'][$f] = $val !== '' ? $val : null;
            }
            continue;
        }
        // b:<kolom>
        if ($f === 'no_kk') {
            $r = profilMuridNormalNomor($raw, 'kk');
            if ($r['galat']) { $out['peringatan'][] = 'No. KK rusak, dikosongkan'; $r['nilai'] = null; }
            elseif ($r['peringatan']) $out['peringatan'][] = $r['peringatan'];
            $out['biodata'][$f] = $r['nilai'];
        } elseif (isset(PM_BIODATA_ANGKA[$f])) {
            $a = profilMuridAngka($raw);
            [$jenisA, $maks] = PM_BIODATA_ANGKA[$f];
            if ($a === false || ($a !== null && ($a < 0 || $a > $maks))) {
                $out['peringatan'][] = $k['label'] . ' tidak valid, dikosongkan';
                $a = null;
            }
            $out['biodata'][$f] = $a === null ? null : ($jenisA === 'int' ? (int)round($a) : round($a, 2));
        } else {
            $maks = PM_BIODATA_TEKS[$f] ?? 150;
            $out['biodata'][$f] = $val !== '' ? mb_substr($val, 0, $maks, 'UTF-8') : null;
        }
    }
    if ($out['nisn'] === null && $out['nik'] === null && !$out['galat']) $out['galat'][] = 'NISN dan NIK kosong';
    return $out;
}

/** Kode RIASEC 3 huruf dari skor tertinggi (seri: urutan R,I,A,S,E,C). Skor null diabaikan. */
function profilMuridKodeRiasec(array $skor): string
{
    $urut = [];
    foreach (['r', 'i', 'a', 's', 'e', 'c'] as $idx => $h) {
        $v = $skor[$h] ?? null;
        if ($v === null) continue;
        $urut[] = [$h, (float)$v, $idx];
    }
    usort($urut, fn($a, $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
    return strtoupper(implode('', array_map(fn($x) => $x[0], array_slice($urut, 0, 3))));
}

/** Daftar mapel dari teks ("a, b; c") atau array. Hasil array string atau false bila > 3. */
function profilMuridDaftarMapel($v)
{
    $items = is_array($v) ? $v : preg_split('/[,;\n]/u', profilMuridSel($v));
    $hasil = [];
    foreach ($items as $it) {
        $s = mb_substr(profilMuridSel($it), 0, 100, 'UTF-8');
        if ($s === '') continue;
        $ada = false;
        foreach ($hasil as $h) { if (mb_strtolower($h) === mb_strtolower($s)) { $ada = true; break; } }
        if (!$ada) $hasil[] = $s;
    }
    return count($hasil) > 3 ? false : $hasil;
}

/**
 * Validasi + normalisasi data asesmen/karir (impor dan simpan manual).
 * Hasil: ['data' => field => nilai, 'galat' => [], 'peringatan' => [], 'kosong' => bool]
 */
function profilMuridValidasiAsesmen(array $in): array
{
    $galat = []; $peringatan = [];
    $data = array_fill_keys(PM_ASESMEN_FIELD, null);
    $kosong = true;
    foreach (PM_ASESMEN_FIELD as $f) {
        $v = $in[$f] ?? null;
        if (is_array($v) ? count(array_filter($v, fn($x) => profilMuridSel($x) !== '')) > 0 : profilMuridSel($v) !== '') { $kosong = false; break; }
    }
    if ($kosong) return ['data' => $data, 'galat' => [], 'peringatan' => [], 'kosong' => true];

    $skor = function (string $f, string $label) use ($in, &$galat) {
        $a = profilMuridAngka($in[$f] ?? null);
        if ($a === false || ($a !== null && ($a < 0 || $a > 100))) { $galat[] = "$label harus angka 0–100"; return null; }
        return $a === null ? null : round($a, 2);
    };
    $riasec = [];
    foreach (['r' => 'R', 'i' => 'I', 'a' => 'A', 's' => 'S', 'e' => 'E', 'c' => 'C'] as $h => $label) {
        $data['riasec_' . $h] = $skor('riasec_' . $h, "Skor $label");
        $riasec[$h] = $data['riasec_' . $h];
    }
    $kodeFile = strtoupper(preg_replace('/\s+/', '', profilMuridSel($in['riasec_kode'] ?? '')));
    if (count(array_filter($riasec, fn($x) => $x !== null)) > 0) {
        $data['riasec_kode'] = profilMuridKodeRiasec($riasec);
        if ($kodeFile !== '' && $kodeFile !== $data['riasec_kode']) {
            $peringatan[] = "Kode RIASEC di file ($kodeFile) diganti hasil hitung ({$data['riasec_kode']})";
        }
    } elseif ($kodeFile !== '') {
        if (!preg_match('/^[RIASEC]{1,3}$/', $kodeFile) || count(array_unique(str_split($kodeFile))) !== strlen($kodeFile)) {
            $galat[] = 'Kode RIASEC harus 1–3 huruf berbeda dari R, I, A, S, E, C';
        } else {
            $data['riasec_kode'] = $kodeFile;
        }
    }

    $data['skor_visual']     = $skor('skor_visual', 'Skor Visual');
    $data['skor_auditori']   = $skor('skor_auditori', 'Skor Auditori');
    $data['skor_kinestetik'] = $skor('skor_kinestetik', 'Skor Kinestetik');
    $gaya = mb_strtolower(profilMuridSel($in['gaya_belajar'] ?? ''), 'UTF-8');
    $petaGaya = ['v' => 'VISUAL', 'visual' => 'VISUAL', 'a' => 'AUDITORI', 'auditori' => 'AUDITORI', 'auditorial' => 'AUDITORI',
                 'auditory' => 'AUDITORI', 'k' => 'KINESTETIK', 'kinestetik' => 'KINESTETIK', 'kinestetis' => 'KINESTETIK',
                 'kinesthetic' => 'KINESTETIK'];
    if ($gaya !== '') {
        if (isset($petaGaya[$gaya])) $data['gaya_belajar'] = $petaGaya[$gaya];
        else $galat[] = 'Gaya belajar harus Visual, Auditori, atau Kinestetik';
    } else {
        $vak = ['VISUAL' => $data['skor_visual'], 'AUDITORI' => $data['skor_auditori'], 'KINESTETIK' => $data['skor_kinestetik']];
        $terbaik = null;
        foreach ($vak as $g => $v) { if ($v !== null && ($terbaik === null || $v > $vak[$terbaik])) $terbaik = $g; }
        $data['gaya_belajar'] = $terbaik;
    }

    foreach (['kesulitan_belajar' => [1000, 'Kesulitan belajar'], 'catatan_karir' => [1000, 'Catatan karir'],
              'cita_cita' => [150, 'Cita-cita'], 'jurusan_tujuan' => [150, 'Jurusan tujuan'],
              'institusi_tujuan' => [150, 'Institusi tujuan']] as $f => [$maks, $label]) {
        $v = $in[$f] ?? null;
        $s = is_scalar($v) ? trim(str_replace("\r", '', (string)$v)) : '';
        if (mb_strlen($s, 'UTF-8') > $maks) { $galat[] = "$label maksimal $maks karakter"; continue; }
        $data[$f] = $s !== '' ? $s : null;
    }

    foreach (['mapel_termudah' => 'Mapel termudah', 'mapel_tersulit' => 'Mapel tersulit'] as $f => $label) {
        $daftar = profilMuridDaftarMapel($in[$f] ?? '');
        if ($daftar === false) $galat[] = "$label maksimal 3 mapel";
        else $data[$f] = $daftar ?: null;
    }

    $rencana = mb_strtolower(profilMuridSel($in['rencana_lulus'] ?? ''), 'UTF-8');
    if ($rencana !== '') {
        $petaRencana = [
            'kuliah' => 'KULIAH', 'kerja' => 'KERJA', 'bekerja' => 'KERJA', 'wirausaha' => 'WIRAUSAHA',
            'berwirausaha' => 'WIRAUSAHA', 'kuliah sambil kerja' => 'KULIAH_KERJA', 'kuliah dan kerja' => 'KULIAH_KERJA',
            'kuliah & kerja' => 'KULIAH_KERJA', 'kuliah_kerja' => 'KULIAH_KERJA', 'belum tahu' => 'BELUM_TAHU',
            'belum tau' => 'BELUM_TAHU', 'belum_tahu' => 'BELUM_TAHU', 'lainnya' => 'LAINNYA', 'lain-lain' => 'LAINNYA',
        ];
        if (isset($petaRencana[$rencana])) $data['rencana_lulus'] = $petaRencana[$rencana];
        else $galat[] = 'Rencana setelah lulus harus Kuliah, Kerja, Wirausaha, Kuliah sambil kerja, Belum tahu, atau Lainnya';
    }

    return ['data' => $data, 'galat' => $galat, 'peringatan' => $peringatan, 'kosong' => false];
}

/** Periode akademik dari tanggal: Jul–Des -> [Y/Y+1, GANJIL], Jan–Jun -> [Y-1/Y, GENAP]. */
function profilMuridPeriodeDariTanggal(string $ymd): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})/', $ymd, $m)) return null;
    $y = (int)$m[1]; $mo = (int)$m[2];
    return $mo >= 7 ? [$y . '/' . ($y + 1), 'GANJIL'] : [($y - 1) . '/' . $y, 'GENAP'];
}

/** Samarkan nomor: <= 8 karakter -> semua *, selain itu 4 awal + * + 4 akhir. */
function profilMuridSamarkan($v): ?string
{
    if ($v === null) return null;
    $s = (string)$v;
    $len = strlen($s);
    if ($len === 0) return $s;
    if ($len <= 8) return str_repeat('*', $len);
    return substr($s, 0, 4) . str_repeat('*', $len - 8) . substr($s, -4);
}

/** Urutan kronologis periode "YYYY/YYYY" + semester. */
function profilMuridKunciPeriode(string $ta, string $sem): string
{
    return substr($ta, 0, 4) . ($sem === 'GENAP' ? '2' : '1');
}

// =====================================================================
// Infrastruktur
// =====================================================================

function profilMuridPastikanTabel(mysqli $db): void
{
    static $sudah = false;
    if ($sudah) return;
    $db->query("CREATE TABLE IF NOT EXISTS `student_biodata` (
        `student_id` varchar(128) NOT NULL,
        `nipd` varchar(30) DEFAULT NULL,
        `no_kk` varchar(20) DEFAULT NULL,
        `anak_ke` tinyint(3) UNSIGNED DEFAULT NULL,
        `jml_saudara` tinyint(3) UNSIGNED DEFAULT NULL,
        `kebutuhan_khusus` varchar(100) DEFAULT NULL,
        `rt` varchar(5) DEFAULT NULL,
        `rw` varchar(5) DEFAULT NULL,
        `dusun` varchar(100) DEFAULT NULL,
        `kelurahan` varchar(100) DEFAULT NULL,
        `kecamatan` varchar(100) DEFAULT NULL,
        `kode_pos` varchar(10) DEFAULT NULL,
        `jenis_tinggal` varchar(50) DEFAULT NULL,
        `alat_transportasi` varchar(50) DEFAULT NULL,
        `jarak_rumah_km` decimal(6,2) DEFAULT NULL,
        `telepon` varchar(20) DEFAULT NULL,
        `hp` varchar(20) DEFAULT NULL,
        `email` varchar(150) DEFAULT NULL,
        `berat_badan` smallint(5) UNSIGNED DEFAULT NULL,
        `tinggi_badan` smallint(5) UNSIGNED DEFAULT NULL,
        `lingkar_kepala` smallint(5) UNSIGNED DEFAULT NULL,
        `sekolah_asal` varchar(150) DEFAULT NULL,
        `penerima_kps` varchar(10) DEFAULT NULL,
        `no_kps` varchar(50) DEFAULT NULL,
        `penerima_kip` varchar(10) DEFAULT NULL,
        `no_kip` varchar(50) DEFAULT NULL,
        `nama_di_kip` varchar(150) DEFAULT NULL,
        `no_kks` varchar(50) DEFAULT NULL,
        `layak_pip` varchar(10) DEFAULT NULL,
        `alasan_layak_pip` varchar(150) DEFAULT NULL,
        `ayah_json` text DEFAULT NULL,
        `ibu_json` text DEFAULT NULL,
        `wali_json` text DEFAULT NULL,
        `rombel_dapodik` varchar(100) DEFAULT NULL,
        `dapodik_lain_json` mediumtext DEFAULT NULL,
        `diimpor_oleh` varchar(128) NOT NULL,
        `diimpor_oleh_nama` varchar(255) NOT NULL,
        `diimpor_pada` timestamp NOT NULL DEFAULT current_timestamp(),
        `impor_id` varchar(64) DEFAULT NULL,
        PRIMARY KEY (`student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS `student_asesmen` (
        `id` varchar(64) NOT NULL,
        `student_id` varchar(128) NOT NULL,
        `academic_year` varchar(20) NOT NULL,
        `semester` enum('GANJIL','GENAP') NOT NULL,
        `class_id` varchar(50) NOT NULL,
        `class_name` varchar(100) NOT NULL,
        `riasec_r` decimal(5,2) DEFAULT NULL,
        `riasec_i` decimal(5,2) DEFAULT NULL,
        `riasec_a` decimal(5,2) DEFAULT NULL,
        `riasec_s` decimal(5,2) DEFAULT NULL,
        `riasec_e` decimal(5,2) DEFAULT NULL,
        `riasec_c` decimal(5,2) DEFAULT NULL,
        `riasec_kode` varchar(3) DEFAULT NULL,
        `gaya_belajar` enum('VISUAL','AUDITORI','KINESTETIK') DEFAULT NULL,
        `skor_visual` decimal(5,2) DEFAULT NULL,
        `skor_auditori` decimal(5,2) DEFAULT NULL,
        `skor_kinestetik` decimal(5,2) DEFAULT NULL,
        `kesulitan_belajar` text DEFAULT NULL,
        `mapel_termudah` text DEFAULT NULL,
        `mapel_tersulit` text DEFAULT NULL,
        `cita_cita` varchar(150) DEFAULT NULL,
        `rencana_lulus` enum('KULIAH','KERJA','WIRAUSAHA','KULIAH_KERJA','BELUM_TAHU','LAINNYA') DEFAULT NULL,
        `jurusan_tujuan` varchar(150) DEFAULT NULL,
        `institusi_tujuan` varchar(150) DEFAULT NULL,
        `catatan_karir` text DEFAULT NULL,
        `sumber` enum('IMPOR','MANUAL') NOT NULL,
        `logs` longtext DEFAULT NULL,
        `dibuat_oleh` varchar(128) NOT NULL,
        `dibuat_oleh_nama` varchar(255) NOT NULL,
        `diubah_oleh` varchar(128) NOT NULL,
        `diubah_oleh_nama` varchar(255) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_asesmen_periode` (`student_id`, `academic_year`, `semester`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS `student_import_log` (
        `id` varchar(64) NOT NULL,
        `jenis` enum('DAPODIK','ASESMEN') NOT NULL,
        `nama_file` varchar(255) DEFAULT NULL,
        `class_id` varchar(50) DEFAULT NULL,
        `academic_year` varchar(20) DEFAULT NULL,
        `semester` varchar(10) DEFAULT NULL,
        `jml_baris` int(11) NOT NULL,
        `jml_tulis` int(11) NOT NULL,
        `jml_lewati` int(11) NOT NULL,
        `jml_galat` int(11) NOT NULL,
        `oleh` varchar(128) NOT NULL,
        `oleh_nama` varchar(255) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $sudah = true;
}

function profilMuridWajibMetode(string $metode): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $metode) {
        header('Allow: ' . $metode);
        errorResponse('Metode tidak diizinkan', 405);
    }
}

/** Scope = Pantau Nilai (ALL: ADMIN, KS, GURU BK; CLASS: wali kelas; DENY: lainnya). */
function profilMuridScope(mysqli $db, array $sessionUser): array
{
    return pantauNilaiScope($db, $sessionUser);
}

function profilMuridAktif(?string $status): bool
{
    $s = strtoupper(trim((string)$status));
    return $s === '' || $s === 'AKTIF';
}

/**
 * Ambil murid + cek cakupan. $fail(int status, string pesan) menghentikan request.
 * Mode CLASS: murid harus aktif dan kelas saat ini termasuk cakupan.
 */
function profilMuridAmbilMurid(mysqli $db, array $scope, string $studentId, callable $fail): array
{
    if ($studentId === '') $fail(400, 'Parameter student_id wajib diisi.');
    $rows = rekapFetchAll($db,
        "SELECT id, name, nisn, class_id, class_name, status, total_violation_points, nik, tempat_lahir, tanggal_lahir,
                jenis_kelamin, agama, alamat, address, parent_name, parent_phone, pekerjaan_ortu,
                IF(JSON_VALID(docs_json), JSON_UNQUOTE(JSON_EXTRACT(docs_json, '$.pas_foto')), NULL) AS pas_foto
         FROM students WHERE id = ? LIMIT 1", 's', [$studentId]);
    if (!$rows) $fail(404, PM_MSG_404);
    $m = $rows[0];
    if ($scope['mode'] === 'CLASS') {
        $diKelas = classInScope($scope, $m['class_id']) || classInScope($scope, $m['class_name']);
        if (!$diKelas || !profilMuridAktif($m['status'])) $fail(403, KESISWAAN_MSG_KELAS);
    }
    return $m;
}

/** Guard JSON: metode, login, scope bukan DENY. */
function profilMuridGuard(mysqli $db, string $metode): array
{
    profilMuridWajibMetode($metode);
    $user  = requireAuth();
    $scope = profilMuridScope($db, $user);
    if ($scope['mode'] === 'DENY') errorResponse(PM_MSG_DENY, 403);
    profilMuridPastikanTabel($db);
    return [$user, $scope];
}

function profilMuridDalamTry(string $action, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        error_log('[SIMANTAP] ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        errorResponse(PM_MSG_SERVER, 500);
    }
}

/** Kelas dari parameter (wali: default & wajib kelasnya). */
function profilMuridKelas(mysqli $db, array $scope, string $raw): array
{
    $raw = trim($raw);
    if ($scope['mode'] === 'CLASS') {
        if ($raw === '') $raw = (string)$scope['classId'];
        elseif (!classInScope($scope, $raw)) errorResponse(KESISWAAN_MSG_KELAS, 403);
    } elseif ($raw === '') {
        errorResponse('Parameter class_id wajib diisi.', 400);
    }
    $ref = resolveClassRef($db, $raw);
    if ($scope['mode'] === 'CLASS' && !classInScope($scope, $ref['id'])) errorResponse(KESISWAAN_MSG_KELAS, 403);
    return $ref;
}

/** Rentang tanggal tahun ajaran penuh, atau null. */
function profilMuridRentangTA(string $ta): ?array
{
    $g = getSemesterDateRange($ta, 'GANJIL');
    $e = getSemesterDateRange($ta, 'GENAP');
    return ($g && $e) ? [$g[0], $e[1]] : null;
}

/** Pola LIKE aman untuk mencari id siswa di kolom JSON. */
function profilMuridPolaLike(string $sid): string
{
    return '%"' . addcslashes($sid, '%_\\') . '"%';
}

function profilMuridTabelAda(mysqli $db, string $tabel): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $tabel);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['n'] > 0;
}

/** Validasi bentuk rows (array 2D) dari klien. */
function profilMuridRows($rows, int $maksBaris, int $maksKolom): array
{
    if (!is_array($rows) || !$rows) errorResponse('Data file kosong.', 400);
    if (count($rows) > $maksBaris) errorResponse('File terlalu besar: maksimal ' . ($maksBaris - 1) . ' baris data.', 400);
    $hasil = [];
    foreach (array_values($rows) as $r) {
        if (!is_array($r)) $r = [];
        if (count($r) > $maksKolom) errorResponse("File terlalu besar: maksimal $maksKolom kolom.", 400);
        $hasil[] = array_map('profilMuridSel', array_values($r));
    }
    return $hasil;
}

// =====================================================================
// Pengumpulan data profil
// =====================================================================

function profilMuridBiodata(mysqli $db, string $sid, bool $samar): ?array
{
    $rows = rekapFetchAll($db, "SELECT * FROM student_biodata WHERE student_id = ? LIMIT 1", 's', [$sid]);
    if (!$rows) return null;
    $b = $rows[0];
    foreach (['ayah', 'ibu', 'wali'] as $o) {
        $d = json_decode((string)($b[$o . '_json'] ?? ''), true);
        $d = is_array($d) ? $d : [];
        if ($samar && isset($d['nik'])) $d['nik'] = profilMuridSamarkan($d['nik']);
        $b[$o] = $d;
        unset($b[$o . '_json']);
    }
    $lain = json_decode((string)($b['dapodik_lain_json'] ?? ''), true);
    $lain = is_array($lain) ? $lain : [];
    if ($samar) {
        foreach ($lain as $k => $v) {
            if (preg_match('/\bnik\b|\bkk\b/i', profilMuridNormalLabel($k))) $lain[$k] = profilMuridSamarkan(preg_replace('/\D/', '', (string)$v) ?: (string)$v);
        }
    }
    $b['dapodik_lain'] = $lain;
    unset($b['dapodik_lain_json'], $b['impor_id'], $b['diimpor_oleh']);
    if ($samar) $b['no_kk'] = profilMuridSamarkan($b['no_kk']);
    foreach (['anak_ke', 'jml_saudara', 'berat_badan', 'tinggi_badan', 'lingkar_kepala'] as $f) {
        $b[$f] = $b[$f] !== null ? (int)$b[$f] : null;
    }
    $b['jarak_rumah_km'] = $b['jarak_rumah_km'] !== null ? (float)$b['jarak_rumah_km'] : null;
    return $b;
}

function profilMuridBarisAsesmen(array $r): array
{
    foreach (['riasec_r', 'riasec_i', 'riasec_a', 'riasec_s', 'riasec_e', 'riasec_c', 'skor_visual', 'skor_auditori', 'skor_kinestetik'] as $f) {
        $r[$f] = $r[$f] !== null ? (float)$r[$f] : null;
    }
    foreach (['mapel_termudah', 'mapel_tersulit'] as $f) {
        $d = json_decode((string)($r[$f] ?? ''), true);
        $r[$f] = is_array($d) ? array_values($d) : [];
    }
    unset($r['logs'], $r['dibuat_oleh'], $r['diubah_oleh']);
    return $r;
}

function profilMuridAsesmen(mysqli $db, string $sid): array
{
    $rows = rekapFetchAll($db,
        "SELECT * FROM student_asesmen WHERE student_id = ?
         ORDER BY LEFT(academic_year, 4) DESC, semester DESC", 's', [$sid]);
    return array_map('profilMuridBarisAsesmen', $rows);
}

/** Satu siswa sebagai override pantauNilaiCollect. */
function profilMuridOverride(array $m): array
{
    return [(string)$m['id'] => ['name' => (string)$m['name'], 'nisn' => (string)($m['nisn'] ?? ''), 'pas_foto' => $m['pas_foto'] ?? null]];
}

function profilMuridAkademik(mysqli $db, array $m, string $ta, string $sem): array
{
    $sid = (string)$m['id'];
    $weights = pantauNilaiWeights($db);
    $hasil = ['kkm' => $weights['kkm_default'], 'semester_aktif' => [], 'tren' => ['periode' => [], 'mapel' => [], 'rata_rata' => []],
              'target' => ['tersedia' => false]];

    if (profilMuridAktif($m['status'])) {
        $data = pantauNilaiCollect($db, (string)$m['class_id'], $ta, $sem);
        $row = $data['students'][$sid] ?? null;
        if ($row) {
            foreach ($data['subjects'] as $code => $s) {
                $g = $row['grades'][$code];
                $hasil['semester_aktif'][] = [
                    'subject_id'         => (string)$code,
                    'subject_name'       => $s['subject_name'],
                    'teachers'           => array_map(fn($t) => $t['name'], $s['teachers']),
                    'status_mapel'       => $s['status'],
                    'ref_score'          => $g['ref_score'],
                    'ref_is_provisional' => $g['ref_is_provisional'],
                    'final_score'        => $g['final_score'],
                    'avg_tugas'          => $g['avg_tugas'],
                    'avg_kuis'           => $g['avg_kuis'],
                    'below_kkm'          => $g['below_kkm'],
                    'missing_tugas'      => $g['missing_tugas'],
                    'missing_kuis'       => $g['missing_kuis'],
                ];
            }
        }
    }

    // --- Tren: periode (TA, semester, kelas) tempat murid punya data ---
    $periode = []; // kunci => ['ta','sem','kelas' => [class_id => true]]
    $tambah = function (string $ta, string $sem, string $kelas) use (&$periode) {
        if ($ta === '' || $kelas === '') return;
        $k = profilMuridKunciPeriode($ta, $sem);
        $periode[$k] ??= ['ta' => $ta, 'sem' => $sem, 'kelas' => []];
        $periode[$k]['kelas'][$kelas] = true;
    };
    foreach (rekapFetchAll($db, "SELECT DISTINCT academic_year, semester, class_id FROM ujian_scores WHERE student_id = ?", 's', [$sid]) as $u) {
        $s = pantauNilaiSemester((string)$u['semester'], 'GANJIL');
        if ($s !== null && preg_match('/^\d{4}\/\d{4}$/', (string)$u['academic_year'])) $tambah((string)$u['academic_year'], $s, (string)$u['class_id']);
    }
    $jur = rekapFetchAll($db,
        "SELECT classId, date, assessment_data FROM journal_entries
         WHERE has_assessment = 1 AND isDraft = 0 AND assessment_data LIKE ?", 's', [profilMuridPolaLike($sid)]);
    foreach ($jur as $j) {
        $ad = json_decode((string)$j['assessment_data'], true);
        if (!is_array($ad)) continue;
        $ada = false;
        foreach ($ad as $e) { if (is_array($e) && (string)($e['student_id'] ?? '') === $sid) { $ada = true; break; } }
        if (!$ada) continue;
        $p = profilMuridPeriodeDariTanggal((string)$j['date']);
        if ($p) $tambah($p[0], $p[1], (string)$j['classId']);
    }
    ksort($periode);
    $periode = array_slice($periode, -PM_TREN_MAKS_PERIODE, null, true);

    $override = profilMuridOverride($m);
    $nilai = []; // code => [kunci => ref]
    $nama = [];  // code => nama
    foreach ($periode as $k => $p) {
        $pilih = []; // code => [bobot, ref]
        foreach (array_keys($p['kelas']) as $kelas) {
            $data = pantauNilaiCollect($db, (string)$kelas, $p['ta'], $p['sem'], null, $override);
            $row = $data['students'][$sid] ?? null;
            if (!$row) continue;
            foreach ($row['grades'] as $code => $g) {
                if ($g['ref_score'] === null) continue;
                $bobot = $g['tugas_count'] + $g['kuis_count'] + ($g['ujian_combined'] !== null ? 1 : 0);
                if (!isset($pilih[$code]) || $bobot > $pilih[$code][0]) $pilih[$code] = [$bobot, $g['ref_score']];
                $nama[$code] = $data['subjects'][$code]['subject_name'] ?? (string)$code;
            }
        }
        foreach ($pilih as $code => [$_, $ref]) $nilai[(string)$code][$k] = $ref;
    }
    $kunci = array_keys($periode);
    foreach ($kunci as $k) {
        $p = $periode[$k];
        $hasil['tren']['periode'][] = $p['ta'] . ' ' . $p['sem'];
    }
    uksort($nilai, fn($a, $b) => strcasecmp($nama[$a] ?? $a, $nama[$b] ?? $b));
    foreach ($nilai as $code => $per) {
        $hasil['tren']['mapel'][] = [
            'subject_id'   => (string)$code,
            'subject_name' => $nama[$code] ?? (string)$code,
            'nilai'        => array_map(fn($k) => $per[$k] ?? null, $kunci),
        ];
    }
    foreach ($kunci as $k) {
        $v = [];
        foreach ($nilai as $per) { if (isset($per[$k])) $v[] = $per[$k]; }
        $hasil['tren']['rata_rata'][] = $v ? round(array_sum($v) / count($v), 1) : null;
    }
    return $hasil;
}

function profilMuridKehadiran(mysqli $db, array $m, string $ta, string $sem): array
{
    $sid = (string)$m['id'];
    $range = getSemesterDateRange($ta, $sem);
    $hasil = ['harian' => ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alfa' => 0, 'persen_hadir' => null, 'per_bulan' => []],
              'per_mapel' => [], 'tidak_hadir_terakhir' => []];

    $sql = "SELECT DATE_FORMAT(date, '%Y-%m') AS bulan, status, COUNT(*) AS c FROM attendance_records WHERE student_id = ?";
    $types = 's'; $params = [$sid];
    if ($range) { $sql .= " AND date BETWEEN ? AND ?"; $types .= 'ss'; $params[] = $range[0]; $params[] = $range[1]; }
    $sql .= " GROUP BY bulan, status ORDER BY bulan";
    $perBulan = [];
    foreach (rekapFetchAll($db, $sql, $types, $params) as $r) {
        $st = pantauNilaiAttendanceStatus($r['status']);
        if ($st === null) continue;
        $key = strtolower($st);
        $hasil['harian'][$key] += (int)$r['c'];
        $perBulan[$r['bulan']] ??= ['bulan' => $r['bulan'], 'hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alfa' => 0];
        $perBulan[$r['bulan']][$key] += (int)$r['c'];
    }
    $hasil['harian']['per_bulan'] = array_values($perBulan);
    $total = $hasil['harian']['hadir'] + $hasil['harian']['sakit'] + $hasil['harian']['izin'] + $hasil['harian']['alfa'];
    $hasil['harian']['persen_hadir'] = $total > 0 ? round($hasil['harian']['hadir'] / $total * 100, 1) : null;

    $sql = "SELECT date, status, source FROM attendance_records WHERE student_id = ? AND status <> 'HADIR'";
    if ($range) $sql .= " AND date BETWEEN ? AND ?";
    $sql .= " ORDER BY date DESC LIMIT 20";
    foreach (rekapFetchAll($db, $sql, $types, $params) as $r) {
        $hasil['tidak_hadir_terakhir'][] = ['tanggal' => $r['date'], 'status' => pantauNilaiAttendanceStatus($r['status']) ?? $r['status'], 'sumber' => $r['source']];
    }

    if (profilMuridAktif($m['status'])) {
        $sql = "SELECT subjectId, subjectName, studentAttendance FROM journal_entries
                WHERE classId = ? AND isDraft = 0 AND studentAttendance LIKE ?";
        $types = 'ss'; $params = [(string)$m['class_id'], profilMuridPolaLike($sid)];
        if ($range) { $sql .= " AND date BETWEEN ? AND ?"; $types .= 'ss'; $params[] = $range[0]; $params[] = $range[1]; }
        $mapel = [];
        foreach (rekapFetchAll($db, $sql, $types, $params) as $j) {
            $st = pantauNilaiParseAttendance($j['studentAttendance'])[$sid] ?? null;
            if ($st === null) continue;
            $code = (string)$j['subjectId'];
            $mapel[$code] ??= ['subject_id' => $code, 'subject_name' => (string)$j['subjectName'], 'hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alfa' => 0, 'pertemuan' => 0];
            $mapel[$code][strtolower($st)]++;
            $mapel[$code]['pertemuan']++;
        }
        usort($mapel, fn($a, $b) => strcasecmp($a['subject_name'], $b['subject_name']));
        $hasil['per_mapel'] = array_values($mapel);
    }
    return $hasil;
}

function profilMuridRiwayat(mysqli $db, array $m, string $ta): array
{
    $sid = (string)$m['id'];
    $rTA = profilMuridRentangTA($ta);
    $hasil = ['catatan_guru' => [], 'pelanggaran' => ['rekap' => [], 'daftar' => []], 'panggilan_ortu' => [], 'dispensasi' => [], 'ekskul' => []];

    // Catatan guru per siswa di jurnal (tahun ajaran aktif)
    $sql = "SELECT date, subjectName, teacherName, assessment_data FROM journal_entries
            WHERE isDraft = 0 AND has_assessment = 1 AND assessment_data LIKE ?";
    $types = 's'; $params = [profilMuridPolaLike($sid)];
    if ($rTA) { $sql .= " AND date BETWEEN ? AND ?"; $types .= 'ss'; $params[] = $rTA[0]; $params[] = $rTA[1]; }
    $sql .= " ORDER BY date DESC, createdAt DESC";
    foreach (rekapFetchAll($db, $sql, $types, $params) as $j) {
        $ad = json_decode((string)$j['assessment_data'], true);
        if (!is_array($ad)) continue;
        foreach ($ad as $e) {
            if (!is_array($e) || (string)($e['student_id'] ?? '') !== $sid) continue;
            $catatan = is_scalar($e['notes'] ?? null) ? trim((string)$e['notes']) : '';
            if ($catatan === '') continue;
            $hasil['catatan_guru'][] = ['tanggal' => $j['date'], 'subject_name' => $j['subjectName'], 'teacher_name' => $j['teacherName'], 'catatan' => $catatan];
        }
        if (count($hasil['catatan_guru']) >= 100) { $hasil['catatan_guru'] = array_slice($hasil['catatan_guru'], 0, 100); break; }
    }

    // Insiden tahun ajaran aktif
    $sql = "SELECT id, date, type, details, points, status, violation_code FROM incidents WHERE studentId = ? AND (academic_year = ?";
    $types = 'ss'; $params = [$sid, $ta];
    if ($rTA) { $sql .= " OR ((academic_year IS NULL OR academic_year = '') AND date BETWEEN ? AND ?)"; $types .= 'ss'; $params[] = $rTA[0]; $params[] = $rTA[1]; }
    $sql .= ") ORDER BY date DESC, createdAt DESC";
    $rekap = [];
    foreach (rekapFetchAll($db, $sql, $types, $params) as $i) {
        $i['points'] = (int)$i['points'];
        $hasil['pelanggaran']['daftar'][] = $i;
        $rekap[$i['type']] ??= ['type' => $i['type'], 'jumlah' => 0, 'poin' => 0];
        $rekap[$i['type']]['jumlah']++;
        if (strtoupper((string)$i['status']) !== 'REJECTED') $rekap[$i['type']]['poin'] += $i['points'];
    }
    $hasil['pelanggaran']['rekap'] = array_values($rekap);

    $hasil['panggilan_ortu'] = array_map(function ($p) {
        $p['total_points'] = (int)$p['total_points'];
        return $p;
    }, rekapFetchAll($db,
        "SELECT id, scheduled_date, status, total_points, trigger_type, notes, created_at FROM parent_calls
         WHERE student_id = ? ORDER BY COALESCE(scheduled_date, DATE(created_at)) DESC, created_at DESC", 's', [$sid]));

    if (profilMuridTabelAda($db, 'student_dispensations')) {
        $sql = "SELECT tanggal_mulai, tanggal_selesai, jam_ke_mulai, jam_ke_selesai, kegiatan, nomor_surat FROM student_dispensations WHERE student_id = ?";
        $types = 's'; $params = [$sid];
        if ($rTA) { $sql .= " AND tanggal_mulai <= ? AND tanggal_selesai >= ?"; $types .= 'ss'; $params[] = $rTA[1]; $params[] = $rTA[0]; }
        $sql .= " ORDER BY tanggal_mulai DESC";
        $hasil['dispensasi'] = rekapFetchAll($db, $sql, $types, $params);
    }

    $hasil['ekskul'] = rekapFetchAll($db,
        "SELECT em.extracurricular_id, COALESCE(e.name, em.extracurricular_id) AS name, em.status, em.joined_at
         FROM extracurricular_members em LEFT JOIN extracurriculars e ON e.id = em.extracurricular_id
         WHERE em.student_id = ? ORDER BY em.status = 'AKTIF' DESC, name ASC", 's', [$sid]);
    return $hasil;
}

/** Seluruh isi profil (dipakai JSON dan cetak). */
function profilMuridKumpulkan(mysqli $db, array $scope, array $m): array
{
    $period = getActiveAcademicPeriod($db);
    $ta = $period['academic_year'];
    $sem = strtoupper($period['semester']) === 'GENAP' ? 'GENAP' : 'GANJIL';
    $samar = $scope['mode'] === 'CLASS';
    $murid = [
        'student_id'     => (string)$m['id'],
        'name'           => (string)$m['name'],
        'nisn'           => (string)($m['nisn'] ?? ''),
        'class_id'       => (string)$m['class_id'],
        'class_name'     => (string)$m['class_name'],
        'status'         => (string)($m['status'] ?? ''),
        'aktif'          => profilMuridAktif($m['status']),
        'pas_foto'       => $m['pas_foto'],
        'total_poin'     => (int)$m['total_violation_points'],
        'nik'            => $samar ? profilMuridSamarkan($m['nik']) : $m['nik'],
        'tempat_lahir'   => $m['tempat_lahir'],
        'tanggal_lahir'  => $m['tanggal_lahir'],
        'jenis_kelamin'  => $m['jenis_kelamin'],
        'agama'          => $m['agama'],
        'alamat'         => ($m['alamat'] ?? '') !== '' ? $m['alamat'] : $m['address'],
        'parent_name'    => $m['parent_name'],
        'parent_phone'   => $m['parent_phone'],
        'pekerjaan_ortu' => $m['pekerjaan_ortu'],
    ];
    return [
        'akses'         => ['mode' => $scope['mode'], 'bisa_edit_asesmen' => true, 'disamarkan' => $samar],
        'periode_aktif' => ['academic_year' => $ta, 'semester' => $sem],
        'murid'         => $murid,
        'biodata'       => profilMuridBiodata($db, (string)$m['id'], $samar),
        'asesmen'       => profilMuridAsesmen($db, (string)$m['id']),
        'akademik'      => profilMuridAkademik($db, $m, $ta, $sem),
        'kompetensi'    => ['tersedia' => false],
        'kehadiran'     => profilMuridKehadiran($db, $m, $ta, $sem),
        'riwayat'       => profilMuridRiwayat($db, $m, $ta),
    ];
}

// =====================================================================
// Handler action
// =====================================================================

/** GET profil_murid_daftar: daftar murid satu kelas, atau pencarian lintas kelas (q, scope ALL). */
function handleProfilMuridDaftar(mysqli $db): void
{
    [, $scope] = profilMuridGuard($db, 'GET');
    profilMuridDalamTry('profil_murid_daftar', function () use ($db, $scope) {
        $period = getActiveAcademicPeriod($db);
        $ta = $period['academic_year'];
        $sem = strtoupper($period['semester']) === 'GENAP' ? 'GENAP' : 'GANJIL';
        $q = trim((string)($_GET['q'] ?? ''));
        $kelas = null;
        if ($q !== '') {
            if ($scope['mode'] !== 'ALL') errorResponse('Pencarian lintas kelas hanya untuk Guru BK, Kepala Sekolah, dan Admin.', 403);
            if (mb_strlen($q, 'UTF-8') < 3) errorResponse('Kata kunci pencarian minimal 3 karakter.', 400);
            $pola = '%' . addcslashes($q, '%_\\') . '%';
            $murid = rekapFetchAll($db,
                "SELECT id, name, nisn, class_id, class_name, status, total_violation_points,
                        IF(JSON_VALID(docs_json), JSON_UNQUOTE(JSON_EXTRACT(docs_json, '$.pas_foto')), NULL) AS pas_foto
                 FROM students WHERE name LIKE ? OR nisn LIKE ? ORDER BY name ASC LIMIT 50", 'ss', [$pola, $pola]);
        } else {
            $kelas = profilMuridKelas($db, $scope, (string)($_GET['class_id'] ?? ''));
            $murid = rekapFetchAll($db,
                "SELECT id, name, nisn, class_id, class_name, status, total_violation_points,
                        IF(JSON_VALID(docs_json), JSON_UNQUOTE(JSON_EXTRACT(docs_json, '$.pas_foto')), NULL) AS pas_foto
                 FROM students WHERE class_id = ? AND (status IS NULL OR status = '' OR UPPER(status) = 'AKTIF')
                 ORDER BY name ASC", 's', [$kelas['id']]);
        }
        $ids = array_map(fn($r) => (string)$r['id'], $murid);
        $bio = []; $asm = []; $hadir = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $t = str_repeat('s', count($ids));
            foreach (rekapFetchAll($db, "SELECT student_id FROM student_biodata WHERE student_id IN ($ph)", $t, $ids) as $r) $bio[$r['student_id']] = true;
            foreach (rekapFetchAll($db, "SELECT student_id FROM student_asesmen WHERE academic_year = ? AND semester = ? AND student_id IN ($ph)",
                                   'ss' . $t, array_merge([$ta, $sem], $ids)) as $r) $asm[$r['student_id']] = true;
            $range = getSemesterDateRange($ta, $sem);
            $sql = "SELECT student_id, SUM(status = 'HADIR') AS h, COUNT(*) AS n FROM attendance_records WHERE student_id IN ($ph)";
            $types = $t; $params = $ids;
            if ($range) { $sql .= " AND date BETWEEN ? AND ?"; $types .= 'ss'; $params[] = $range[0]; $params[] = $range[1]; }
            $sql .= " GROUP BY student_id";
            foreach (rekapFetchAll($db, $sql, $types, $params) as $r) {
                $hadir[$r['student_id']] = (int)$r['n'] > 0 ? round((int)$r['h'] / (int)$r['n'] * 100, 1) : null;
            }
        }
        $hasil = [];
        foreach ($murid as $r) {
            $sid = (string)$r['id'];
            $hasil[] = [
                'student_id'        => $sid,
                'name'              => (string)$r['name'],
                'nisn'              => (string)($r['nisn'] ?? ''),
                'class_id'          => (string)$r['class_id'],
                'class_name'        => (string)$r['class_name'],
                'status'            => (string)($r['status'] ?? ''),
                'aktif'             => profilMuridAktif($r['status']),
                'pas_foto'          => $r['pas_foto'],
                'ada_biodata'       => isset($bio[$sid]),
                'ada_asesmen_aktif' => isset($asm[$sid]),
                'poin'              => (int)$r['total_violation_points'],
                'persen_hadir'      => $hadir[$sid] ?? null,
            ];
        }
        jsonResponse([
            'mode'          => $scope['mode'],
            'kelas'         => $kelas,
            'periode_aktif' => ['academic_year' => $ta, 'semester' => $sem],
            'murid'         => $hasil,
        ]);
    });
}

/** GET profil_murid_get */
function handleProfilMuridGet(mysqli $db): void
{
    [, $scope] = profilMuridGuard($db, 'GET');
    profilMuridDalamTry('profil_murid_get', function () use ($db, $scope) {
        $m = profilMuridAmbilMurid($db, $scope, trim((string)($_GET['student_id'] ?? '')), fn(int $s, string $msg) => errorResponse($msg, $s));
        jsonResponse(profilMuridKumpulkan($db, $scope, $m));
    });
}

/**
 * Upsert satu catatan asesmen (dalam transaksi pemanggil).
 * Hasil: 'BUAT' | 'UBAH'
 */
function profilMuridUpsertAsesmen(mysqli $db, array $m, string $ta, string $sem, array $data, string $sumber, array $user, ?string $imporId): string
{
    $sid = (string)$m['id'];
    $ada = rekapFetchAll($db, "SELECT id, logs, dibuat_oleh, dibuat_oleh_nama FROM student_asesmen
                               WHERE student_id = ? AND academic_year = ? AND semester = ? FOR UPDATE", 'sss', [$sid, $ta, $sem]);
    $logs = $ada ? json_decode((string)$ada[0]['logs'], true) : [];
    if (!is_array($logs)) $logs = [];
    $aksi = $ada ? ($sumber === 'IMPOR' ? 'IMPOR_TIMPA' : 'UBAH') : ($sumber === 'IMPOR' ? 'IMPOR' : 'BUAT');
    $logs[] = ['aksi' => $aksi, 'oleh' => (string)$user['uid'], 'oleh_nama' => (string)($user['displayName'] ?? ''),
               'waktu' => date('Y-m-d H:i:s'), 'sumber' => $sumber, 'impor_id' => $imporId];
    $logs = array_slice($logs, -PM_MAKS_LOG);

    $nilai = $data;
    foreach (['mapel_termudah', 'mapel_tersulit'] as $f) {
        $nilai[$f] = $data[$f] ? json_encode(array_values($data[$f]), JSON_UNESCAPED_UNICODE) : null;
    }
    $kolom = PM_ASESMEN_FIELD;
    $vals = array_map(fn($f) => $nilai[$f] === null ? null : (string)$nilai[$f], $kolom);
    $logJson = json_encode($logs, JSON_UNESCAPED_UNICODE);
    $uid = (string)$user['uid']; $unama = (string)($user['displayName'] ?? '');

    if ($ada) {
        $set = implode(', ', array_map(fn($f) => "`$f` = ?", $kolom));
        $stmt = $db->prepare("UPDATE student_asesmen SET $set, class_id = ?, class_name = ?, sumber = ?, logs = ?, diubah_oleh = ?, diubah_oleh_nama = ? WHERE id = ?");
        $params = array_merge($vals, [(string)$m['class_id'], (string)$m['class_name'], $sumber, $logJson, $uid, $unama, (string)$ada[0]['id']]);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        return 'UBAH';
    }
    $cols = array_merge(['id', 'student_id', 'academic_year', 'semester', 'class_id', 'class_name'], $kolom,
                        ['sumber', 'logs', 'dibuat_oleh', 'dibuat_oleh_nama', 'diubah_oleh', 'diubah_oleh_nama']);
    $params = array_merge([uniqid('asm_'), $sid, $ta, $sem, (string)$m['class_id'], (string)$m['class_name']], $vals,
                          [$sumber, $logJson, $uid, $unama, $uid, $unama]);
    $stmt = $db->prepare("INSERT INTO student_asesmen (`" . implode('`, `', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    return 'BUAT';
}

/** Semester tulis asesmen (TA selalu aktif). */
function profilMuridSemesterTulis(mysqli $db, $raw): array
{
    $period = getActiveAcademicPeriod($db);
    $sem = pantauNilaiSemester(is_scalar($raw) ? (string)$raw : '', $period['semester']);
    if ($sem === null) errorResponse('Semester tidak valid (gunakan 1, 2, GANJIL, atau GENAP).', 400);
    return [$period['academic_year'], $sem];
}

/** POST profil_murid_asesmen_simpan */
function handleProfilMuridAsesmenSimpan(mysqli $db): void
{
    [$user, $scope] = profilMuridGuard($db, 'POST');
    profilMuridDalamTry('profil_murid_asesmen_simpan', function () use ($db, $scope, $user) {
        $body = getRequestBody();
        $m = profilMuridAmbilMurid($db, $scope, trim((string)($body['student_id'] ?? '')), fn(int $s, string $msg) => errorResponse($msg, $s));
        [$ta, $sem] = profilMuridSemesterTulis($db, $body['semester'] ?? '');
        $v = profilMuridValidasiAsesmen($body);
        if ($v['kosong']) errorResponse('Isi minimal satu data asesmen atau rencana karir.', 400);
        if ($v['galat']) errorResponse(implode('; ', $v['galat']), 400);
        $db->begin_transaction();
        try {
            $aksi = profilMuridUpsertAsesmen($db, $m, $ta, $sem, $v['data'], 'MANUAL', $user, null);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        $row = rekapFetchAll($db, "SELECT * FROM student_asesmen WHERE student_id = ? AND academic_year = ? AND semester = ?", 'sss', [(string)$m['id'], $ta, $sem]);
        jsonResponse(['success' => true, 'aksi' => $aksi, 'peringatan' => $v['peringatan'], 'asesmen' => profilMuridBarisAsesmen($row[0])]);
    });
}

/** POST profil_murid_asesmen_hapus */
function handleProfilMuridAsesmenHapus(mysqli $db): void
{
    [$user, $scope] = profilMuridGuard($db, 'POST');
    profilMuridDalamTry('profil_murid_asesmen_hapus', function () use ($db, $scope, $user) {
        $body = getRequestBody();
        $m = profilMuridAmbilMurid($db, $scope, trim((string)($body['student_id'] ?? '')), fn(int $s, string $msg) => errorResponse($msg, $s));
        $period = getActiveAcademicPeriod($db);
        $ta = trim((string)($body['academic_year'] ?? ''));
        if ($ta !== $period['academic_year']) errorResponse('Hanya data asesmen tahun ajaran aktif yang dapat dihapus.', 400);
        $sem = pantauNilaiSemester(is_scalar($body['semester'] ?? null) ? (string)$body['semester'] : '', '');
        if ($sem === null) errorResponse('Semester tidak valid (gunakan 1, 2, GANJIL, atau GENAP).', 400);
        $stmt = $db->prepare("DELETE FROM student_asesmen WHERE student_id = ? AND academic_year = ? AND semester = ?");
        $sid = (string)$m['id'];
        $stmt->bind_param('sss', $sid, $ta, $sem);
        $stmt->execute();
        if ($stmt->affected_rows < 1) errorResponse('Data asesmen tidak ditemukan.', 404);
        error_log(sprintf('[SIMANTAP] profil_murid_asesmen_hapus: student_id=%s periode=%s %s oleh=%s (%s)',
            $sid, $ta, $sem, (string)$user['uid'], (string)($user['displayName'] ?? '')));
        jsonResponse(['success' => true]);
    });
}

/** Indeks kolom header template asesmen: field => idx. */
function profilMuridKolomAsesmen(array $header): array
{
    $idx = [];
    foreach ($header as $c => $h) {
        $f = PM_ASESMEN_KOLOM[profilMuridNormalLabel($h)] ?? null;
        if ($f !== null && !isset($idx[$f])) $idx[$f] = $c;
    }
    return $idx;
}

/** GET profil_murid_asesmen_template */
function handleProfilMuridAsesmenTemplate(mysqli $db): void
{
    [, $scope] = profilMuridGuard($db, 'GET');
    profilMuridDalamTry('profil_murid_asesmen_template', function () use ($db, $scope) {
        $kelas = profilMuridKelas($db, $scope, (string)($_GET['class_id'] ?? ''));
        [$ta, $sem] = profilMuridSemesterTulis($db, $_GET['semester'] ?? '');
        $murid = pantauNilaiStudents($db, (string)$kelas['id']);
        $ada = [];
        if ($murid) {
            $ids = array_map('strval', array_keys($murid));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (rekapFetchAll($db, "SELECT * FROM student_asesmen WHERE academic_year = ? AND semester = ? AND student_id IN ($ph)",
                                   'ss' . str_repeat('s', count($ids)), array_merge([$ta, $sem], $ids)) as $r) {
                $ada[$r['student_id']] = profilMuridBarisAsesmen($r);
            }
        }
        $rows = [];
        foreach ($murid as $sid => $st) {
            $a = $ada[(string)$sid] ?? null;
            $f = fn($k) => $a && $a[$k] !== null ? $a[$k] : '';
            $rows[] = [
                $st['nisn'], $st['name'], $f('riasec_r'), $f('riasec_i'), $f('riasec_a'), $f('riasec_s'), $f('riasec_e'), $f('riasec_c'),
                $f('riasec_kode'), $a && $a['gaya_belajar'] ? PM_GAYA_LABEL[$a['gaya_belajar']] : '',
                $f('skor_visual'), $f('skor_auditori'), $f('skor_kinestetik'), $f('kesulitan_belajar'),
                $a ? implode(', ', $a['mapel_termudah']) : '', $a ? implode(', ', $a['mapel_tersulit']) : '',
                $f('cita_cita'), $a && $a['rencana_lulus'] ? PM_RENCANA_LABEL[$a['rencana_lulus']] : '',
                $f('jurusan_tujuan'), $f('institusi_tujuan'), $f('catatan_karir'),
            ];
        }
        jsonResponse([
            'kelas'         => $kelas,
            'academic_year' => $ta,
            'semester'      => $sem,
            'nama_file'     => preg_replace('/[^A-Za-z0-9_-]+/', '-', 'Template-Asesmen-' . $kelas['name'] . '-' . str_replace('/', '-', $ta) . '-' . $sem) . '.xlsx',
            'header'        => PM_ASESMEN_HEADER,
            'rows'          => $rows,
        ]);
    });
}

/** POST profil_murid_asesmen_impor (mode pratinjau|simpan) */
function handleProfilMuridAsesmenImpor(mysqli $db): void
{
    [$user, $scope] = profilMuridGuard($db, 'POST');
    profilMuridDalamTry('profil_murid_asesmen_impor', function () use ($db, $scope, $user) {
        $body = getRequestBody();
        $mode = (string)($body['mode'] ?? 'pratinjau');
        if (!in_array($mode, ['pratinjau', 'simpan'], true)) errorResponse('Mode tidak valid (pratinjau atau simpan).', 400);
        $kelas = profilMuridKelas($db, $scope, is_scalar($body['class_id'] ?? null) ? (string)$body['class_id'] : '');
        [$ta, $sem] = profilMuridSemesterTulis($db, $body['semester'] ?? '');
        $rows = profilMuridRows($body['rows'] ?? null, PM_MAKS_ASESMEN_BARIS, PM_MAKS_ASESMEN_KOLOM);
        $idx = profilMuridKolomAsesmen($rows[0]);
        if (!isset($idx['nisn'])) errorResponse('Kolom NISN tidak ditemukan di baris pertama. Gunakan template asesmen SIMANTAP.', 400);

        $murid = rekapFetchAll($db,
            "SELECT id, name, nisn, class_id, class_name, status FROM students
             WHERE class_id = ? AND (status IS NULL OR status = '' OR UPPER(status) = 'AKTIF')", 's', [$kelas['id']]);
        $perNisn = [];
        foreach ($murid as $r) {
            $n = profilMuridNormalNomor($r['nisn'], 'nisn')['nilai'];
            if ($n !== null) $perNisn[$n] = $r;
        }
        $sudahAda = [];
        if ($murid) {
            $ids = array_map(fn($r) => (string)$r['id'], $murid);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (rekapFetchAll($db, "SELECT student_id FROM student_asesmen WHERE academic_year = ? AND semester = ? AND student_id IN ($ph)",
                                   'ss' . str_repeat('s', count($ids)), array_merge([$ta, $sem], $ids)) as $r) $sudahAda[$r['student_id']] = true;
        }

        $baris = []; $tulis = [];
        $dipakai = [];
        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            if (count(array_filter($r, fn($x) => $x !== '')) === 0) continue;
            $in = [];
            foreach ($idx as $f => $c) $in[$f] = $r[$c] ?? '';
            $item = ['baris' => $i + 1, 'nisn' => $in['nisn'], 'nama' => $in['nama'] ?? '', 'student_id' => null,
                     'status' => 'GALAT', 'pesan' => [], 'peringatan' => []];
            $no = profilMuridNormalNomor($in['nisn'], 'nisn');
            if ($no['galat'] || $no['nilai'] === null) {
                $item['pesan'][] = $no['galat'] ?? 'NISN kosong';
            } elseif (!isset($perNisn[$no['nilai']])) {
                $item['nisn'] = $no['nilai'];
                $item['pesan'][] = 'Bukan murid aktif kelas ini';
            } else {
                $m = $perNisn[$no['nilai']];
                $item['nisn'] = $no['nilai'];
                $item['nama'] = (string)$m['name'];
                $item['student_id'] = (string)$m['id'];
                if (isset($dipakai[$m['id']])) {
                    $item['pesan'][] = 'Duplikat dalam file (sudah ada di baris ' . $dipakai[$m['id']] . ')';
                } else {
                    $dipakai[$m['id']] = $i + 1;
                    $v = profilMuridValidasiAsesmen($in);
                    $item['peringatan'] = $v['peringatan'];
                    if ($v['kosong']) {
                        $item['status'] = 'LEWATI';
                        $item['pesan'][] = 'Semua kolom asesmen/karir kosong';
                    } elseif ($v['galat']) {
                        $item['pesan'] = $v['galat'];
                    } else {
                        $item['status'] = isset($sudahAda[$m['id']]) ? 'TIMPA' : 'BARU';
                        $tulis[] = [$m, $v['data']];
                    }
                }
            }
            $baris[] = $item;
        }
        $hitung = fn(string $s) => count(array_filter($baris, fn($b) => $b['status'] === $s));
        $ringkasan = ['baru' => $hitung('BARU'), 'timpa' => $hitung('TIMPA'), 'lewati' => $hitung('LEWATI'), 'galat' => $hitung('GALAT'), 'total' => count($baris)];
        $respon = ['mode' => $mode, 'kelas' => $kelas, 'academic_year' => $ta, 'semester' => $sem, 'ringkasan' => $ringkasan, 'baris' => $baris];
        if ($mode === 'pratinjau') jsonResponse($respon);

        if (!$tulis) errorResponse('Tidak ada baris valid untuk disimpan.', 400);
        $imporId = uniqid('imp_');
        $db->begin_transaction();
        try {
            foreach ($tulis as [$m, $data]) profilMuridUpsertAsesmen($db, $m, $ta, $sem, $data, 'IMPOR', $user, $imporId);
            $stmt = $db->prepare("INSERT INTO student_import_log (id, jenis, nama_file, class_id, academic_year, semester, jml_baris, jml_tulis, jml_lewati, jml_galat, oleh, oleh_nama)
                                  VALUES (?, 'ASESMEN', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $namaFile = mb_substr(profilMuridSel($body['nama_file'] ?? ''), 0, 255, 'UTF-8');
            $jTulis = count($tulis);
            $uid = (string)$user['uid']; $unama = (string)($user['displayName'] ?? '');
            $stmt->bind_param('sssssiiiiss', $imporId, $namaFile, $kelas['id'], $ta, $sem, $ringkasan['total'], $jTulis, $ringkasan['lewati'], $ringkasan['galat'], $uid, $unama);
            $stmt->execute();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        $respon['impor_id'] = $imporId;
        jsonResponse($respon);
    });
}

/** POST profil_murid_dapodik_impor (TATA_USAHA/ADMIN; mode pratinjau|simpan) */
function handleProfilMuridDapodikImpor(mysqli $db): void
{
    profilMuridWajibMetode('POST');
    $sesi = requireAuth();
    $user = loadFreshUser($db, $sesi);
    if (!in_array($user['role'], ['TATA_USAHA', 'ADMIN'], true)) errorResponse('Impor biodata Dapodik hanya untuk Tata Usaha dan Admin.', 403);
    profilMuridPastikanTabel($db);
    profilMuridDalamTry('profil_murid_dapodik_impor', function () use ($db, $user) {
        $body = getRequestBody();
        $mode = (string)($body['mode'] ?? 'pratinjau');
        if (!in_array($mode, ['pratinjau', 'simpan'], true)) errorResponse('Mode tidak valid (pratinjau atau simpan).', 400);
        $rows = profilMuridRows($body['rows'] ?? null, PM_MAKS_DAPODIK_BARIS, PM_MAKS_DAPODIK_KOLOM);
        $hdr = profilMuridDapodikHeader($rows);
        if ($hdr === null) errorResponse('Format file tidak dikenali sebagai ekspor Peserta Didik Dapodik (kolom Nama dan NISN tidak ditemukan).', 400);

        $kolomInfo = ['dikenali' => [], 'lain' => [], 'dibuang' => []];
        foreach ($hdr['kolom'] as $k) {
            if ($k['tujuan'] === 'abaikan') continue;
            $grup = $k['tujuan'] === 'buang' ? 'dibuang' : ($k['tujuan'] === 'lain' ? 'lain' : 'dikenali');
            $kolomInfo[$grup][] = $k['label'];
        }

        // Peta murid SIMANTAP: NISN -> id, NIK -> [id]
        $semua = rekapFetchAll($db, "SELECT id, name, nisn, nik, class_id, class_name, status FROM students", '', []);
        $perId = []; $perNisn = []; $perNik = [];
        foreach ($semua as $s) {
            $perId[$s['id']] = $s;
            $n = profilMuridNormalNomor($s['nisn'], 'nisn')['nilai'];
            if ($n !== null) $perNisn[$n][] = $s['id'];
            $k = preg_replace('/\D/', '', (string)$s['nik']);
            if ($k !== '') $perNik[$k][] = $s['id'];
        }

        $baris = []; $tulis = []; $dipakai = [];
        $normKelas = fn($v) => normalizeClassKey((string)$v);
        for ($i = $hdr['mulai']; $i < count($rows); $i++) {
            $p = profilMuridPetakanDapodik($hdr['kolom'], $rows[$i]);
            if ($p['kosong']) continue;
            $item = ['baris' => $i + 1, 'nisn' => $p['nisn'], 'nama' => $p['nama'], 'student_id' => null, 'nama_simantap' => null,
                     'kelas_simantap' => null, 'status' => 'GALAT', 'pesan' => $p['galat'], 'peringatan' => $p['peringatan']];
            if (!$p['galat']) {
                $cocok = [];
                if ($p['nisn'] !== null && isset($perNisn[$p['nisn']])) $cocok = $perNisn[$p['nisn']];
                elseif ($p['nik'] !== null && isset($perNik[$p['nik']])) $cocok = $perNik[$p['nik']];
                if (count($cocok) > 1) {
                    $item['status'] = 'KONFLIK';
                    $item['pesan'][] = 'Cocok dengan ' . count($cocok) . ' murid SIMANTAP';
                } elseif (!$cocok) {
                    $item['status'] = 'TIDAK_DITEMUKAN';
                    $item['pesan'][] = 'Tidak ada murid SIMANTAP dengan NISN/NIK ini';
                } else {
                    $s = $perId[$cocok[0]];
                    $item['student_id'] = (string)$s['id'];
                    $item['nama_simantap'] = (string)$s['name'];
                    $item['kelas_simantap'] = (string)$s['class_name'];
                    if (isset($dipakai[$s['id']])) {
                        $item['pesan'][] = 'Duplikat dalam file (sudah ada di baris ' . $dipakai[$s['id']] . ')';
                    } else {
                        $dipakai[$s['id']] = $i + 1;
                        $item['status'] = 'PERBARUI';
                        if ($p['nama'] !== '' && mb_strtolower(trim($p['nama'])) !== mb_strtolower(trim((string)$s['name']))) {
                            $item['peringatan'][] = 'Nama berbeda dengan SIMANTAP (nama tidak diubah)';
                        }
                        $rombel = $p['biodata']['rombel_dapodik'] ?? null;
                        if ($rombel !== null && $normKelas($rombel) !== $normKelas($s['class_name']) && $normKelas($rombel) !== $normKelas($s['class_id'])) {
                            $item['peringatan'][] = 'Rombel Dapodik (' . $rombel . ') berbeda dengan kelas SIMANTAP (kelas tidak diubah)';
                        }
                        if (!profilMuridAktif($s['status'])) $item['peringatan'][] = 'Status murid di SIMANTAP: ' . $s['status'];
                        $tulis[] = [(string)$s['id'], $p];
                    }
                }
            }
            $baris[] = $item;
        }
        $hitung = fn(string $s) => count(array_filter($baris, fn($b) => $b['status'] === $s));
        $ringkasan = ['perbarui' => $hitung('PERBARUI'), 'tidak_ditemukan' => $hitung('TIDAK_DITEMUKAN'),
                      'konflik' => $hitung('KONFLIK'), 'galat' => $hitung('GALAT'), 'total' => count($baris)];
        $peringatanFile = null;
        if ($ringkasan['total'] > 0 && $ringkasan['tidak_ditemukan'] / $ringkasan['total'] > 0.8) {
            $peringatanFile = 'Lebih dari 80% baris tidak ditemukan di SIMANTAP. Periksa apakah file ini milik sekolah Anda.';
        }
        $respon = ['mode' => $mode, 'ringkasan' => $ringkasan, 'kolom' => $kolomInfo, 'peringatan_file' => $peringatanFile, 'baris' => $baris];
        if ($mode === 'pratinjau') jsonResponse($respon);
        if (!$tulis) errorResponse('Tidak ada baris yang dapat diperbarui.', 400);

        $imporId = uniqid('imp_');
        $uid = (string)$user['uid']; $unama = (string)$user['displayName'];
        $bioKolom = array_merge(array_keys(PM_BIODATA_TEKS), array_keys(PM_BIODATA_ANGKA));
        $tujuanAda = array_column($hdr['kolom'], 'tujuan');
        $adaNik  = in_array('nik', $tujuanAda, true);
        $adaOrtu = count(array_filter($tujuanAda, fn($t) => str_starts_with($t, 'o:'))) > 0;
        $db->begin_transaction();
        try {
            $cols = array_merge(['student_id'], $bioKolom, ['ayah_json', 'ibu_json', 'wali_json', 'dapodik_lain_json', 'diimpor_oleh', 'diimpor_oleh_nama', 'impor_id']);
            $upd = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", array_slice($cols, 1)));
            $stmtB = $db->prepare("INSERT INTO student_biodata (`" . implode('`, `', $cols) . "`, diimpor_pada) VALUES ("
                . implode(',', array_fill(0, count($cols), '?')) . ", CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE $upd, diimpor_pada = CURRENT_TIMESTAMP");
            foreach ($tulis as [$sid, $p]) {
                $ortuJson = [];
                foreach (['ayah', 'ibu', 'wali'] as $o) {
                    $d = array_filter($p['ortu'][$o], fn($v) => $v !== null && $v !== '');
                    $ortuJson[$o] = $d ? json_encode($d, JSON_UNESCAPED_UNICODE) : null;
                }
                $vals = [$sid];
                foreach ($bioKolom as $c) $vals[] = $p['biodata'][$c] === null ? null : (string)$p['biodata'][$c];
                $vals[] = $ortuJson['ayah']; $vals[] = $ortuJson['ibu']; $vals[] = $ortuJson['wali'];
                $vals[] = $p['lain'] ? json_encode($p['lain'], JSON_UNESCAPED_UNICODE) : null;
                $vals[] = $uid; $vals[] = $unama; $vals[] = $imporId;
                $stmtB->bind_param(str_repeat('s', count($vals)), ...$vals);
                $stmtB->execute();

                // Kolom students (nama dan kelas tidak pernah diubah; kolom yang tidak ada di file tidak disentuh)
                $set = []; $par = [];
                $st = $p['students'];
                if ($adaNik) { $set[] = 'nik = ?'; $par[] = $p['nik']; }
                foreach (['tempat_lahir', 'tanggal_lahir'] as $c) { if (array_key_exists($c, $st)) { $set[] = "$c = ?"; $par[] = $st[$c]; } }
                if (array_key_exists('alamat', $st)) { $set[] = 'alamat = ?'; $par[] = $st['alamat']; $set[] = 'address = ?'; $par[] = $st['alamat']; }
                foreach (['jenis_kelamin', 'agama'] as $c) { if (isset($st[$c])) { $set[] = "$c = ?"; $par[] = $st[$c]; } }
                if ($adaOrtu) {
                    $ortu = null;
                    foreach (['ayah', 'ibu', 'wali'] as $o) { if (!empty($p['ortu'][$o]['nama'])) { $ortu = $p['ortu'][$o]; break; } }
                    $set[] = 'parent_name = ?'; $par[] = $ortu['nama'] ?? null;
                    $set[] = 'pekerjaan_ortu = ?'; $par[] = isset($ortu['pekerjaan']) ? mb_substr($ortu['pekerjaan'], 0, 100, 'UTF-8') : null;
                }
                if (!$set) continue;
                $par[] = $sid;
                $stmtS = $db->prepare("UPDATE students SET " . implode(', ', $set) . " WHERE id = ?");
                $stmtS->bind_param(str_repeat('s', count($par)), ...$par);
                $stmtS->execute();
            }
            $stmt = $db->prepare("INSERT INTO student_import_log (id, jenis, nama_file, jml_baris, jml_tulis, jml_lewati, jml_galat, oleh, oleh_nama)
                                  VALUES (?, 'DAPODIK', ?, ?, ?, ?, ?, ?, ?)");
            $namaFile = mb_substr(profilMuridSel($body['nama_file'] ?? ''), 0, 255, 'UTF-8');
            $jTulis = count($tulis);
            $jLewati = $ringkasan['tidak_ditemukan'] + $ringkasan['konflik'];
            $stmt->bind_param('ssiiiiss', $imporId, $namaFile, $ringkasan['total'], $jTulis, $jLewati, $ringkasan['galat'], $uid, $unama);
            $stmt->execute();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        $respon['impor_id'] = $imporId;
        jsonResponse($respon);
    });
}

// =====================================================================
// Cetak A4
// =====================================================================

function profilMuridHtmlError(int $status, string $message): void
{
    rekapSendHeaders($status);
    $extra = $status === 401 ? '<p><a href="../">Buka halaman login</a></p>' : '';
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Profil Murid - Error ' . $status . '</title>'
        . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;color:#0f172a;margin:0;padding:40px 16px}'
        . '.box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px}'
        . 'h1{font-size:18px;margin:0 0 8px}p{margin:8px 0;line-height:1.5}a{color:#047857}</style></head><body>'
        . '<div class="box"><h1>Profil murid tidak dapat ditampilkan</h1>'
        . '<p>' . rekapEsc($message) . '</p>' . $extra . '</div></body></html>';
    exit;
}

/** GET cetak_profil_murid */
function handleCetakProfilMurid(mysqli $db): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        profilMuridHtmlError(405, 'Metode tidak diizinkan. Buka cetakan melalui tombol Cetak di halaman profil.');
    }
    $sesi = getCurrentUser();
    if (!$sesi || empty($sesi['uid'])) profilMuridHtmlError(401, 'Sesi Anda telah berakhir. Silakan login ulang.');
    try {
        $me = rekapFetchAll($db, "SELECT uid, displayName, nip FROM users WHERE uid = ? LIMIT 1", 's', [$sesi['uid']]);
        if (!$me) profilMuridHtmlError(401, 'Sesi Anda telah berakhir. Silakan login ulang.');
        $scope = profilMuridScope($db, $sesi);
        if ($scope['mode'] === 'DENY') profilMuridHtmlError(403, PM_MSG_DENY);
        profilMuridPastikanTabel($db);
        $m = profilMuridAmbilMurid($db, $scope, trim((string)($_GET['student_id'] ?? '')), fn(int $s, string $msg) => profilMuridHtmlError($s, $msg));
        $data = profilMuridKumpulkan($db, $scope, $m);

        // Wali kelas murid
        $keys = array_values(array_unique(array_filter([normalizeClassKey($m['class_id']), normalizeClassKey($m['class_name'])])));
        $wali = null;
        foreach (rekapFetchAll($db, "SELECT displayName, nip, class_homeroom FROM users WHERE class_homeroom IS NOT NULL AND class_homeroom <> '' ORDER BY displayName", '', []) as $t) {
            if (in_array(normalizeClassKey($t['class_homeroom']), $keys, true)) { $wali = $t; break; }
        }
        profilMuridRender($data, rekapLoadKop($db), $wali, (string)$me[0]['displayName']);
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        error_log('[SIMANTAP] cetak_profil_murid: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        profilMuridHtmlError(500, 'Gagal menyusun profil murid. Coba lagi atau hubungi admin.');
    }
}

function profilMuridRender(array $d, array $kop, ?array $wali, string $pencetak): void
{
    $e = 'rekapEsc';
    $n = 'pantauNilaiAngka';
    $m = $d['murid'];
    $b = $d['biodata'];
    $a = $d['asesmen'][0] ?? null;
    $ak = $d['akademik'];
    $kh = $d['kehadiran']['harian'];
    $rw = $d['riwayat'];
    $semLabel = $d['periode_aktif']['semester'] === 'GENAP' ? 'Genap' : 'Ganjil';
    $isi = fn($v) => ($v === null || $v === '') ? '-' : (string)$v;
    $tgl = fn($v) => $v ? rekapTanggalIndo((string)$v) : '-';
    $logoKiri  = rekapAssetUrl($kop['kop_logo_kiri'] ?? '');
    $logoKanan = rekapAssetUrl($kop['kop_logo_kanan'] ?? '');
    $foto = rekapAssetUrl($m['pas_foto'] ?? '');
    $now = new DateTimeImmutable('now');
    $tipe = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];
    $jenisInsiden = ['VIOLATION' => 'Pelanggaran', 'LATE' => 'Terlambat', 'OUT' => 'Izin keluar', 'PARENT_CALL' => 'Panggilan ortu'];
    $ortuBaris = function (string $label, array $o) use ($e, $isi) {
        return '<tr><td>' . $e($label) . '</td><td>' . $e($isi($o['nama'] ?? null)) . '</td><td>' . $e($isi($o['nik'] ?? null))
            . '</td><td>' . $e($isi($o['pendidikan'] ?? null)) . '</td><td>' . $e($isi($o['pekerjaan'] ?? null))
            . '</td><td>' . $e($isi($o['penghasilan'] ?? null)) . '</td></tr>';
    };
    $fileTitle = preg_replace('/[^A-Za-z0-9_-]/', '_', 'Profil_Murid_' . $m['name'] . '_' . $m['class_name']);

    rekapSendHeaders(200);
    ob_start();
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($fileTitle) ?></title>
<style>
  @page { size: A4 portrait; margin: 15mm 15mm 15mm 20mm; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e2e8f0; color: #000; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; }
  .panel { max-width: 210mm; margin: 16px auto 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 12px 16px; font-size: 13px; display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
  .panel .btn { padding: 8px 14px; border-radius: 8px; border: 1px solid #059669; background: #059669; color: #fff; font-weight: bold; cursor: pointer; }
  .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 15mm 15mm 15mm 20mm; background: #fff; box-shadow: 0 4px 16px rgba(15,23,42,.15); }
  .kop { width: 100%; border-collapse: collapse; }
  .kop td { border: 0; padding: 0; vertical-align: middle; }
  .kop .logo { width: 24mm; text-align: center; }
  .kop .logo img { max-width: 22mm; max-height: 24mm; }
  .kop .teks { text-align: center; line-height: 1.25; }
  .kop .instansi, .kop .dinas { font-size: 12pt; font-weight: bold; text-transform: uppercase; }
  .kop .sekolah { font-size: 15pt; font-weight: bold; text-transform: uppercase; }
  .kop .alamat { font-size: 9pt; }
  .kop-line { border-top: 3px solid #000; border-bottom: 1px solid #000; height: 4px; margin: 6px 0 12px; }
  h1 { font-size: 11.5pt; text-align: center; margin: 0 0 10px; text-transform: uppercase; }
  h3 { font-size: 10pt; margin: 12px 0 5px; break-after: avoid; page-break-after: avoid; }
  .ident { width: 100%; border-collapse: collapse; }
  .ident td { border: 0; padding: 1px 6px 1px 0; vertical-align: top; }
  .ident .foto { width: 30mm; text-align: right; }
  .ident .foto img { width: 26mm; height: 34mm; object-fit: cover; border: 1px solid #000; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data th, table.data td { border: 1px solid #000; padding: 2px 4px; font-size: 8.5pt; vertical-align: top; }
  table.data th { background: #e5e7eb; text-align: center; }
  table.data .c { text-align: center; }
  .kv { width: 100%; border-collapse: collapse; }
  .kv td { padding: 1px 4px 1px 0; font-size: 9pt; vertical-align: top; }
  .kv td.k { width: 42mm; }
  .kurang { font-weight: bold; }
  tr { page-break-inside: avoid; break-inside: avoid; }
  .ttd { width: 100%; margin-top: 18px; border-collapse: collapse; page-break-inside: avoid; }
  .ttd td { width: 50%; text-align: center; vertical-align: top; padding: 0 8px; }
  .ttd .space { height: 20mm; }
  .ttd .nama { font-weight: bold; text-decoration: underline; }
  .footer { margin-top: 14px; font-size: 7.5pt; color: #444; }
  @media screen and (max-width: 840px) { .panel { margin: 12px 16px 0; } .sheet { width: auto; min-height: 0; margin: 12px 16px; padding: 16px; } }
  @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    table.data th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="panel no-print">
  <span>Profil Murid 360° — <b><?= $e($m['name']) ?></b>. Pilih tujuan <b>Simpan sebagai PDF</b>, kertas <b>A4</b>, matikan <b>Header dan footer</b>.</span>
  <button type="button" class="btn" onclick="window.print()">Cetak / Simpan PDF</button>
</div>
<div class="sheet">
  <table class="kop"><tr>
    <td class="logo"><?php if ($logoKiri !== ''): ?><img src="<?= $e($logoKiri) ?>" alt="" onerror="this.style.visibility='hidden'"><?php endif; ?></td>
    <td class="teks">
      <?php foreach (['kop_instansi' => 'instansi', 'kop_dinas' => 'dinas', 'kop_sekolah' => 'sekolah', 'kop_alamat' => 'alamat'] as $kk => $cls): ?>
        <?php if (($kop[$kk] ?? '') !== ''): ?><div class="<?= $cls ?>"><?= $e($kop[$kk]) ?></div><?php endif; ?>
      <?php endforeach; ?>
    </td>
    <td class="logo"><?php if ($logoKanan !== ''): ?><img src="<?= $e($logoKanan) ?>" alt="" onerror="this.style.visibility='hidden'"><?php endif; ?></td>
  </tr></table>
  <div class="kop-line"></div>
  <h1>Profil Murid 360°</h1>

  <table class="ident"><tr><td>
    <table class="kv">
      <tr><td class="k">Nama</td><td>: <?= $e($m['name']) ?></td></tr>
      <tr><td class="k">NISN / NIPD</td><td>: <?= $e($isi($m['nisn'])) ?> / <?= $e($isi($b['nipd'] ?? null)) ?></td></tr>
      <tr><td class="k">Kelas</td><td>: <?= $e($m['class_name']) ?><?= $m['aktif'] ? '' : ' (' . $e(rekapStudentStatusLabel($m['status'])) . ')' ?></td></tr>
      <tr><td class="k">Wali Kelas</td><td>: <?= $e($wali['displayName'] ?? '-') ?></td></tr>
      <tr><td class="k">Tahun Ajaran / Semester</td><td>: <?= $e($d['periode_aktif']['academic_year'] . ' / ' . $semLabel) ?></td></tr>
    </table>
  </td><td class="foto"><?php if ($foto !== ''): ?><img src="<?= $e($foto) ?>" alt="" onerror="this.style.visibility='hidden'"><?php endif; ?></td></tr></table>

  <h3>A. Identifikasi Profil Murid</h3>
  <table class="kv">
    <tr><td class="k">NIK / No. KK</td><td>: <?= $e($isi($m['nik'])) ?> / <?= $e($isi($b['no_kk'] ?? null)) ?></td></tr>
    <tr><td class="k">Tempat, tanggal lahir</td><td>: <?= $e($isi($m['tempat_lahir'])) ?>, <?= $e($tgl($m['tanggal_lahir'])) ?></td></tr>
    <tr><td class="k">Jenis kelamin / Agama</td><td>: <?= $e($isi($m['jenis_kelamin'])) ?> / <?= $e($isi($m['agama'])) ?></td></tr>
    <tr><td class="k">Anak ke / Saudara kandung</td><td>: <?= $e($isi($b['anak_ke'] ?? null)) ?> / <?= $e($isi($b['jml_saudara'] ?? null)) ?></td></tr>
    <tr><td class="k">Alamat</td><td>: <?= $e($isi($m['alamat'])) ?><?php if ($b): ?>, RT <?= $e($isi($b['rt'])) ?>/RW <?= $e($isi($b['rw'])) ?>, <?= $e($isi($b['kelurahan'])) ?>, <?= $e($isi($b['kecamatan'])) ?><?php endif; ?></td></tr>
    <tr><td class="k">HP / Email</td><td>: <?= $e($isi($b['hp'] ?? null)) ?> / <?= $e($isi($b['email'] ?? null)) ?></td></tr>
    <tr><td class="k">Tinggal / Transportasi</td><td>: <?= $e($isi($b['jenis_tinggal'] ?? null)) ?> / <?= $e($isi($b['alat_transportasi'] ?? null)) ?>, jarak <?= $e(($b['jarak_rumah_km'] ?? null) === null ? '-' : $n($b['jarak_rumah_km'])) ?> km</td></tr>
    <tr><td class="k">Kebutuhan khusus</td><td>: <?= $e($isi($b['kebutuhan_khusus'] ?? null)) ?></td></tr>
    <tr><td class="k">Bantuan</td><td>: KIP <?= $e($isi($b['penerima_kip'] ?? null)) ?>, KPS/PKH <?= $e($isi($b['penerima_kps'] ?? null)) ?>, Layak PIP <?= $e($isi($b['layak_pip'] ?? null)) ?></td></tr>
    <tr><td class="k">Sekolah asal</td><td>: <?= $e($isi($b['sekolah_asal'] ?? null)) ?></td></tr>
  </table>
  <?php if ($b): ?>
  <table class="data" style="margin-top:4px">
    <thead><tr><th style="width:12mm"></th><th>Nama</th><th>NIK</th><th>Pendidikan</th><th>Pekerjaan</th><th>Penghasilan</th></tr></thead>
    <tbody><?= $ortuBaris('Ayah', $b['ayah']) . $ortuBaris('Ibu', $b['ibu']) . ($b['wali'] ? $ortuBaris('Wali', $b['wali']) : '') ?></tbody>
  </table>
  <?php else: ?>
  <p style="font-size:9pt"><i>Biodata Dapodik belum diimpor. Orang tua/wali: <?= $e($isi($m['parent_name'])) ?> (<?= $e($isi($m['pekerjaan_ortu'])) ?>).</i></p>
  <?php endif; ?>

  <h3>B. Asesmen Non-Kognitif<?= $a ? ' (' . $e($a['academic_year'] . ' ' . ($a['semester'] === 'GENAP' ? 'Genap' : 'Ganjil')) . ')' : '' ?></h3>
  <?php if (!$a): ?><p style="font-size:9pt"><i>Belum ada asesmen.</i></p><?php else: ?>
  <table class="data">
    <thead><tr><?php foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $h): ?><th><?= $h ?> (<?= $tipe[$h] ?>)</th><?php endforeach; ?><th>Kode</th></tr></thead>
    <tbody><tr><?php foreach (['r', 'i', 'a', 's', 'e', 'c'] as $h): ?><td class="c"><?= $e($n($a['riasec_' . $h])) ?></td><?php endforeach; ?><td class="c"><b><?= $e($isi($a['riasec_kode'])) ?></b></td></tr></tbody>
  </table>
  <table class="kv" style="margin-top:4px">
    <tr><td class="k">Gaya belajar</td><td>: <?= $e($a['gaya_belajar'] ? PM_GAYA_LABEL[$a['gaya_belajar']] : '-') ?></td></tr>
    <tr><td class="k">Kesulitan belajar</td><td>: <?= $e($isi($a['kesulitan_belajar'])) ?></td></tr>
    <tr><td class="k">Mapel termudah</td><td>: <?= $e($a['mapel_termudah'] ? implode(', ', $a['mapel_termudah']) : '-') ?></td></tr>
    <tr><td class="k">Mapel tersulit</td><td>: <?= $e($a['mapel_tersulit'] ? implode(', ', $a['mapel_tersulit']) : '-') ?></td></tr>
  </table>
  <?php endif; ?>

  <h3>C. Rencana Karir</h3>
  <table class="kv">
    <tr><td class="k">Cita-cita</td><td>: <?= $e($isi($a['cita_cita'] ?? null)) ?></td></tr>
    <tr><td class="k">Rencana setelah lulus</td><td>: <?= $e(($a['rencana_lulus'] ?? null) ? PM_RENCANA_LABEL[$a['rencana_lulus']] : '-') ?></td></tr>
    <tr><td class="k">Jurusan / Institusi tujuan</td><td>: <?= $e($isi($a['jurusan_tujuan'] ?? null)) ?> / <?= $e($isi($a['institusi_tujuan'] ?? null)) ?></td></tr>
    <tr><td class="k">Catatan</td><td>: <?= $e($isi($a['catatan_karir'] ?? null)) ?></td></tr>
  </table>

  <h3>D. Akademik Semester <?= $e($semLabel) ?> (KKTP <?= $e($ak['kkm']) ?>)</h3>
  <?php $adaNilai = array_filter($ak['semester_aktif'], fn($s) => $s['ref_score'] !== null); ?>
  <?php if (!$adaNilai): ?><p style="font-size:9pt"><i>Belum ada penilaian semester ini.</i></p><?php else: ?>
  <table class="data">
    <thead><tr><th style="width:6mm">No</th><th>Mata Pelajaran</th><th style="width:16mm">Nilai</th><th style="width:22mm">Tertinggal (T/K)</th><th style="width:30mm">Keterangan</th></tr></thead>
    <tbody>
    <?php $no = 0; foreach ($adaNilai as $s): $no++; ?>
      <tr>
        <td class="c"><?= $no ?></td><td><?= $e($s['subject_name']) ?></td>
        <td class="c<?= $s['below_kkm'] ? ' kurang' : '' ?>"><?= $e($n($s['ref_score'])) ?></td>
        <td class="c"><?= (int)$s['missing_tugas'] ?> / <?= (int)$s['missing_kuis'] ?></td>
        <td><?= $s['below_kkm'] ? 'Di bawah KKTP' : 'Memenuhi KKTP' ?><?= $s['ref_is_provisional'] ? ' (sementara)' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php if ($ak['tren']['periode']): ?>
  <table class="data" style="margin-top:4px">
    <thead><tr><th>Periode</th><?php foreach ($ak['tren']['periode'] as $p): ?><th><?= $e($p) ?></th><?php endforeach; ?></tr></thead>
    <tbody><tr><td>Rata-rata nilai</td><?php foreach ($ak['tren']['rata_rata'] as $v): ?><td class="c"><?= $e($n($v)) ?></td><?php endforeach; ?></tr></tbody>
  </table>
  <?php endif; ?>

  <h3>E. Kehadiran Semester <?= $e($semLabel) ?></h3>
  <table class="data">
    <thead><tr><th>Hadir</th><th>Sakit</th><th>Izin</th><th>Alfa</th><th>% Hadir</th></tr></thead>
    <tbody><tr><td class="c"><?= (int)$kh['hadir'] ?></td><td class="c"><?= (int)$kh['sakit'] ?></td><td class="c"><?= (int)$kh['izin'] ?></td><td class="c"><?= (int)$kh['alfa'] ?></td>
      <td class="c"><?= $kh['persen_hadir'] === null ? '-' : $e($n($kh['persen_hadir'])) . '%' ?></td></tr></tbody>
  </table>

  <h3>F. Perilaku dan Kegiatan (Tahun Ajaran <?= $e($d['periode_aktif']['academic_year']) ?>)</h3>
  <table class="kv">
    <tr><td class="k">Total poin pelanggaran</td><td>: <?= (int)$m['total_poin'] ?></td></tr>
    <tr><td class="k">Catatan insiden</td><td>: <?php
      $r = array_map(fn($x) => ($jenisInsiden[$x['type']] ?? $x['type']) . ' ' . $x['jumlah'] . '×', $rw['pelanggaran']['rekap']);
      echo $e($r ? implode(', ', $r) : 'Nihil'); ?></td></tr>
    <tr><td class="k">Panggilan orang tua</td><td>: <?= count($rw['panggilan_ortu']) ?> kali</td></tr>
    <tr><td class="k">Ekstrakurikuler aktif</td><td>: <?php
      $ek = array_map(fn($x) => $x['name'], array_filter($rw['ekskul'], fn($x) => strtoupper((string)$x['status']) === 'AKTIF'));
      echo $e($ek ? implode(', ', $ek) : '-'); ?></td></tr>
  </table>
  <?php if ($rw['catatan_guru']): ?>
  <table class="data" style="margin-top:4px">
    <thead><tr><th style="width:22mm">Tanggal</th><th style="width:35mm">Mapel</th><th>Catatan guru (5 terbaru)</th></tr></thead>
    <tbody><?php foreach (array_slice($rw['catatan_guru'], 0, 5) as $c): ?>
      <tr><td><?= $e(rekapTanggalPendek($c['tanggal'])) ?></td><td><?= $e($c['subject_name']) ?></td><td><?= $e(rekapTruncate($c['catatan'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>

  <table class="ttd"><tr>
    <td>Mengetahui,<br>Guru BK<div class="space"></div><span class="nama">(.......................................)</span></td>
    <td><?= $e(rekapTanggalIndo($now->format('Y-m-d'))) ?><br>Wali Kelas<div class="space"></div>
      <span class="nama"><?= $e($wali['displayName'] ?? '(.......................................)') ?></span>
      <?php if (trim((string)($wali['nip'] ?? '')) !== ''): ?><br>NIP. <?= $e($wali['nip']) ?><?php endif; ?></td>
  </tr></table>
  <div class="footer">Dicetak dari SIMANTAP oleh <?= $e($pencetak) ?> pada <?= $e($now->format('d/m/Y H:i')) ?> (<?= $e(rekapTimezoneLabel()) ?>). Dokumen ini memuat data pribadi murid; simpan dan bagikan seperlunya.</div>
</div>
</body>
</html>
    <?php
    echo ob_get_clean();
}
