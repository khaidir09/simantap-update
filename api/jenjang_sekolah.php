<?php
// jenjang_sekolah.php - Helper jenjang sekolah (SD/SMP/SMA/SMK): daftar level kelas per jenjang,
// deteksi jenjang dari nama sekolah, parser level kelas, hitungan data di luar jenjang,
// dan guard fitur PKL (khusus SMK). Tidak punya action sendiri.
// Lihat docs/specs/jenjang-sekolah-dinamis.md
if (!defined('SIMANTAP_API')) exit;

const JENJANG_MSG_PKL = 'Fitur Bimbingan PKL hanya tersedia untuk jenjang SMK.';

/** Level kelas (Romawi, urut) per jenjang. */
function jenjangDaftar(): array
{
    return [
        'SD'  => ['I', 'II', 'III', 'IV', 'V', 'VI'],
        'SMP' => ['VII', 'VIII', 'IX'],
        'SMA' => ['X', 'XI', 'XII'],
        'SMK' => ['X', 'XI', 'XII'],
    ];
}

/**
 * Tebak jenjang dari nama sekolah (dipakai hanya bila system_config.school_level belum ada/tidak valid).
 * Urutan: SMK → SMP → SD → selain itu SMA (termasuk MA/MAN dan teks kosong).
 */
function jenjangDeteksi(string $namaSekolah): string
{
    $t = mb_strtoupper($namaSekolah);
    if (preg_match('/\b(SMK|STM|SMEA)|\bMAK\b/u', $t)) return 'SMK';
    if (preg_match('/\b(SMP|SLTP|MTS)/u', $t)) return 'SMP';
    if (preg_match('/\b(SD|SDN|SDS|SDIT|MI|MIN|MIS)\b/u', $t)) return 'SD';
    return 'SMA';
}

/**
 * Jenjang efektif: nilai pengaturan bila valid, selain itu hasil deteksi. Di-cache per request.
 * @return array{jenjang:string, sumber:string, levels:string[], pkl_aktif:bool}
 */
function jenjangAktif(mysqli $db, bool $segarkan = false): array
{
    static $cache = null;
    if ($cache !== null && !$segarkan) return $cache;

    $nilai = [];
    try {
        $res = $db->query("SELECT config_key, config_value FROM system_config WHERE config_key IN ('school_level','kop_sekolah','kop_instansi')");
        while ($res && ($row = $res->fetch_assoc())) $nilai[$row['config_key']] = (string)$row['config_value'];
    } catch (Throwable $e) {
        error_log('[SIMANTAP] baca jenjang sekolah: ' . $e->getMessage());
    }

    $daftar = jenjangDaftar();
    $jenjang = strtoupper(trim($nilai['school_level'] ?? ''));
    $sumber = 'pengaturan';
    if (!isset($daftar[$jenjang])) {
        $nama = trim($nilai['kop_sekolah'] ?? '');
        if ($nama === '') $nama = trim($nilai['kop_instansi'] ?? '');
        $jenjang = jenjangDeteksi($nama);
        $sumber = 'deteksi';
    }
    return $cache = [
        'jenjang'   => $jenjang,
        'sumber'    => $sumber,
        'levels'    => $daftar[$jenjang],
        'pkl_aktif' => $jenjang === 'SMK',
    ];
}

/**
 * Level kelas (Romawi) dari teks ID/nama kelas, '' bila tidak dikenali.
 * Aturan harus identik dengan __jjParseLevel di docs/patches/apply-jenjang-sekolah-bundle.js.
 *  1. buang prefiks "KELAS "; 2. angka 1–12 di depan → Romawi ("7A" → VII);
 *  3. Romawi diikuti pemisah (spasi _ - . /) atau akhir teks;
 *  4. Romawi menempel huruf ("XIIA") hanya bila level itu termasuk $levels (agar "IPA 1" ≠ I).
 */
function jenjangParseLevel(?string $teks, array $levels): string
{
    static $romawi = ['XII', 'VIII', 'XI', 'VII', 'III', 'IX', 'IV', 'VI', 'II', 'X', 'V', 'I'];
    static $angka = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    $t = strtoupper(trim((string)$teks));
    $t = preg_replace('/^KELAS\s+/', '', $t);
    if ($t === '') return '';

    if (preg_match('/^(1[0-2]|[1-9])(?![0-9])/', $t, $m)) return $angka[(int)$m[1]];

    foreach ($romawi as $r) {
        if (!str_starts_with($t, $r)) continue;
        $s = substr($t, strlen($r), 1);
        if ($s === '' || preg_match('/[\s_\-.\/]/', $s)) return $r;
    }
    foreach ($romawi as $r) {
        if (!str_starts_with($t, $r)) continue;
        $s = substr($t, strlen($r), 1);
        if (preg_match('/[A-Z]/', $s) && !preg_match('/[IVX]/', $s) && in_array($r, $levels, true)) return $r;
    }
    return '';
}

/** Apakah $level (sudah diparse) di luar daftar $levels. Level kosong tidak dihitung. */
function jenjangDiLuar(string $level, array $levels): bool
{
    return $level !== '' && !in_array($level, $levels, true);
}

/**
 * Jumlah data yang levelnya di luar jenjang $jenjangBaru (untuk peringatan saat ganti jenjang).
 * @return array{kelas:int, penugasan:int, siswa:int}
 */
function jenjangHitungTidakCocok(mysqli $db, string $jenjangBaru): array
{
    $levels = jenjangDaftar()[$jenjangBaru] ?? [];
    $hasil = ['kelas' => 0, 'penugasan' => 0, 'siswa' => 0];

    try {
        $res = $db->query("SELECT id, name, level FROM classes");
        while ($res && ($r = $res->fetch_assoc())) {
            $lv = jenjangParseLevel($r['level'], $levels)
                ?: jenjangParseLevel($r['id'], $levels)
                ?: jenjangParseLevel($r['name'], $levels);
            if (jenjangDiLuar($lv, $levels)) $hasil['kelas']++;
        }
    } catch (Throwable $e) {
        // Tabel classes dibuat saat get_classes pertama; belum ada berarti tidak ada kelas.
    }

    try {
        $res = $db->query("SELECT class_level FROM teacher_subjects WHERE class_level IS NOT NULL AND TRIM(class_level) <> ''");
        while ($res && ($r = $res->fetch_assoc())) {
            $mentah = strtoupper(trim((string)$r['class_level']));
            $lv = jenjangParseLevel($mentah, $levels) ?: $mentah;
            if (jenjangDiLuar($lv, $levels)) $hasil['penugasan']++;
        }
    } catch (Throwable $e) {
        error_log('[SIMANTAP] hitung penugasan di luar jenjang: ' . $e->getMessage());
    }

    try {
        $res = $db->query("SELECT class_id, class_name FROM students WHERE LOWER(status) = 'aktif'");
        while ($res && ($r = $res->fetch_assoc())) {
            $lv = jenjangParseLevel($r['class_id'], $levels) ?: jenjangParseLevel($r['class_name'], $levels);
            if (jenjangDiLuar($lv, $levels)) $hasil['siswa']++;
        }
    } catch (Throwable $e) {
        error_log('[SIMANTAP] hitung siswa di luar jenjang: ' . $e->getMessage());
    }

    return $hasil;
}

/**
 * Guard aksi pkl_*: tolak bila jenjang bukan SMK. Halaman cetak ($html) menjawab dengan HTML.
 * Login tetap dicek lebih dulu agar sesi habis menghasilkan 401, bukan pesan PKL.
 */
function jenjangWajibPkl(mysqli $db, bool $html = false): void
{
    if ($html) {
        $sesi = getCurrentUser();
        if (!$sesi || empty($sesi['uid'])) pklHtmlError(401, 'Sesi Anda telah berakhir. Silakan login ulang.');
        if (!jenjangAktif($db)['pkl_aktif']) pklHtmlError(403, JENJANG_MSG_PKL);
        return;
    }
    requireAuth();
    if (!jenjangAktif($db)['pkl_aktif']) errorResponse(JENJANG_MSG_PKL, 403);
}
