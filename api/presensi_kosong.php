<?php
// presensi_kosong.php — Tanggal yang belum ada pengisian presensi untuk Dashboard Kelas (wali kelas).
// Dipakai oleh get_homeroom_dashboard (api/endpoints_v3.php), tidak punya action sendiri.
// Dokumentasi patch: docs/patches/tanggal-belum-presensi.md
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

// Hari sekolah per minggu (system_config, diatur ADMIN lewat save_system_config):
// '5' = Senin–Jumat, '6' = Senin–Sabtu. Key belum ada/tidak valid → 6 (perilaku lama).
const PK_KEY_HARI     = 'school_days_per_week';
const PK_HARI_DEFAULT = 6;

/** Normalisasi nilai hari sekolah: 5 atau 6, selain itu 0 (tidak valid). */
function pkNormalisasiHari($nilai): int
{
    $v = is_scalar($nilai) ? trim((string)$nilai) : '';
    return in_array($v, ['5', '6'], true) ? (int)$v : 0;
}

/** Hari sekolah efektif (5 atau 6). */
function pkHariSekolah(mysqli $db): int
{
    $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1");
    $key = PK_KEY_HARI;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return pkNormalisasiHari($row['config_value'] ?? '') ?: PK_HARI_DEFAULT;
}

/** Apakah tanggal termasuk hari sekolah (Minggu selalu libur, Sabtu libur bila 5 hari). */
function pkHariMasuk(DateTimeInterface $tgl, int $hariSekolah): bool
{
    return (int)$tgl->format('N') <= $hariSekolah;
}

/** Label rentang hari untuk teks: "Senin–Jumat" / "Senin–Sabtu". */
function pkLabelHari(int $hariSekolah): string
{
    return $hariSekolah === 5 ? 'Senin–Jumat' : 'Senin–Sabtu';
}

/**
 * Rentang tanggal semester dari tahun ajaran "2026/2027".
 * GANJIL = 1 Juli – 31 Desember tahun pertama, GENAP = 1 Januari – 30 Juni tahun kedua.
 * Hasil: [mulai, selesai] (Y-m-d) atau null bila format tahun ajaran tidak dikenali.
 */
function pkRentangSemester(string $tahunAjaran, string $semester): ?array
{
    if (!preg_match('/(\d{4})\s*\/\s*(\d{4})/', $tahunAjaran, $m)) return null;
    return $semester === 'GANJIL'
        ? [$m[1] . '-07-01', $m[1] . '-12-31']
        : [$m[2] . '-01-01', $m[2] . '-06-30'];
}

/**
 * Daftar tanggal hari sekolah di semester (s.d. hari ini) yang belum ada presensi untuk kelas ini.
 *
 * - Hari sekolah mengikuti pengaturan ADMIN (school_days_per_week): 5 = Senin–Jumat, 6 = Senin–Sabtu.
 * - Hitungan dimulai dari tanggal presensi pertama di sekolah pada semester itu (bukan 1 Juli/1 Januari),
 *   agar masa libur sebelum semester dimulai tidak ikut terhitung.
 * - Tanggal tanpa presensi di SELURUH kelas dipisahkan ke `kemungkinan_libur` (hari libur, Sabtu
 *   di sekolah 5 hari, dsb.), bukan dianggap kelalaian kelas ini.
 * - Presensi kelas dicocokkan lewat class_id atau class_name (kunci dinormalisasi), sama seperti rekap bulanan.
 *
 * Hasil: ['mulai', 'sampai', 'hari_ini', 'hari_sekolah', 'hari_dicek', 'tanggal' => [...], 'kemungkinan_libur' => [...]].
 * `mulai` = null bila semester belum berjalan atau belum ada presensi sama sekali di sekolah.
 */
function presensiTanggalKosong(mysqli $db, array $classRef, string $tahunAjaran, string $semester): array
{
    $hariIni = date('Y-m-d');
    $hariSekolah = pkHariSekolah($db);
    $hasil = ['mulai' => null, 'sampai' => null, 'hari_ini' => $hariIni, 'hari_sekolah' => $hariSekolah,
              'hari_dicek' => 0, 'tanggal' => [], 'kemungkinan_libur' => []];

    $rentang = pkRentangSemester($tahunAjaran, $semester);
    if (!$rentang || $hariIni < $rentang[0]) return $hasil;
    $sampai = min($rentang[1], $hariIni);

    // Tanggal presensi pertama di sekolah pada semester ini
    $stmt = $db->prepare("SELECT MIN(date) AS mulai FROM attendance_records WHERE date BETWEEN ? AND ?");
    $stmt->bind_param('ss', $rentang[0], $sampai);
    $stmt->execute();
    $mulai = $stmt->get_result()->fetch_assoc()['mulai'] ?? null;
    if (!$mulai) return $hasil;

    // Tanggal yang punya presensi di sekolah (kelas mana pun)
    $stmt = $db->prepare("SELECT DISTINCT date FROM attendance_records WHERE date BETWEEN ? AND ?");
    $stmt->bind_param('ss', $mulai, $sampai);
    $stmt->execute();
    $res = $stmt->get_result();
    $adaSekolah = [];
    while ($r = $res->fetch_assoc()) $adaSekolah[$r['date']] = true;

    // Tanggal yang punya presensi di kelas ini
    $keys = array_values(array_unique(array_filter(
        [normalizeClassKey($classRef['id']), normalizeClassKey($classRef['name'])],
        fn($k) => $k !== ''
    )));
    $adaKelas = [];
    if ($keys) {
        $ph  = implode(',', array_fill(0, count($keys), '?'));
        $col = "REPLACE(REPLACE(REPLACE(REPLACE(LOWER(%s), ' ', ''), '_', ''), '-', ''), '.', '')";
        $sql = "SELECT DISTINCT date FROM attendance_records
                WHERE date BETWEEN ? AND ?
                  AND (" . sprintf($col, 'class_id') . " IN ($ph) OR " . sprintf($col, 'class_name') . " IN ($ph))";
        $params = array_merge([$mulai, $sampai], $keys, $keys);
        $stmt = $db->prepare($sql);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $adaKelas[$r['date']] = true;
    }

    $tgl   = new DateTimeImmutable($mulai);
    $akhir = new DateTimeImmutable($sampai);
    while ($tgl <= $akhir) {
        $d = $tgl->format('Y-m-d');
        if (pkHariMasuk($tgl, $hariSekolah)) {
            $hasil['hari_dicek']++;
            if (!isset($adaKelas[$d])) {
                if (isset($adaSekolah[$d])) $hasil['tanggal'][] = $d;
                else $hasil['kemungkinan_libur'][] = $d;
            }
        }
        $tgl = $tgl->modify('+1 day');
    }

    $hasil['mulai']  = $mulai;
    $hasil['sampai'] = $sampai;
    return $hasil;
}
