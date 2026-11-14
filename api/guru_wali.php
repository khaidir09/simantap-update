<?php
// guru_wali.php - Penugasan murid dampingan Guru Wali (tahap 1 Bimbingan Guru Wali)
// Spesifikasi: docs/specs/penugasan-guru-wali.md
// Hanya boleh dimuat lewat api/index.php (yang sudah melewati gerbang lisensi)
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

const GW_KEY_KUOTA      = 'guru_wali_kuota_peringatan';
const GW_KUOTA_DEFAULT  = 20;
const GW_MAKS_MURID     = 200;
const GW_MAKS_BARIS     = 1500;
const GW_MAKS_CATATAN   = 500;
const GW_LOCK           = 'simantap_guru_wali';
const GW_MSG_PENGELOLA  = 'Hanya Kepala Sekolah dan Admin yang dapat mengatur guru wali.';
const GW_MSG_SERVER     = 'Terjadi kesalahan pada server. Silakan coba lagi.';
const GW_MSG_SIBUK      = 'Sedang ada perubahan penugasan lain, coba lagi.';
const GW_ALASAN = [
    'MUTASI_GURU'           => 'Mutasi guru',
    'PENSIUN'               => 'Pensiun',
    'BEBAN_KERJA'           => 'Beban kerja',
    'PERMINTAAN_MURID_ORTU' => 'Permintaan murid/orang tua',
    'KOREKSI_DATA'          => 'Koreksi data',
    'MURID_NONAKTIF'        => 'Murid tidak aktif',
    'LAINNYA'               => 'Lainnya',
];
const GW_TEMPLATE_HEADER = ['NISN', 'Nama Murid', 'Kelas', 'Email Guru Wali', 'NIP Guru Wali', 'Nama Guru Wali'];

// =====================================================================
// Fungsi murni (diuji di docs/patches/uji-guru-wali-unit.php)
// =====================================================================

/** Sel teks: trim, spasi tunggal, angka bulat float -> string tanpa desimal. */
function guruWaliSel($v): string
{
    if ($v === null || is_array($v) || is_object($v) || is_bool($v)) return '';
    if (is_float($v) && floor($v) == $v && abs($v) < 1e15) $v = sprintf('%.0f', $v);
    return trim(preg_replace('/\s+/u', ' ', (string)$v) ?? '');
}

/** Validasi tanggal efektif. Hasil: null bila sah, selain itu pesan galat. */
function guruWaliValidasiTanggal($t, string $hariIni): ?string
{
    $t = is_string($t) ? trim($t) : '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000) {
        return 'Format tanggal tidak valid (YYYY-MM-DD).';
    }
    if ($t > $hariIni) return 'Tanggal tidak boleh di masa depan.';
    return null;
}

/** Tanggal satu hari sebelum $t (YYYY-MM-DD). */
function guruWaliTanggalSebelum(string $t): string
{
    return (new DateTimeImmutable($t))->modify('-1 day')->format('Y-m-d');
}

/** Kode alasan dari kode atau label (tanpa peduli huruf besar/spasi). Null bila tidak dikenal. */
function guruWaliNormalisasiAlasan($v): ?string
{
    $s = mb_strtoupper(guruWaliSel($v), 'UTF-8');
    if ($s === '') return null;
    $kode = preg_replace('/[^A-Z]+/', '_', $s);
    $kode = trim((string)$kode, '_');
    if (isset(GW_ALASAN[$kode])) return $kode;
    foreach (GW_ALASAN as $k => $label) {
        if (mb_strtoupper($label, 'UTF-8') === $s) return $k;
    }
    return null;
}

/** NISN: hanya digit; 8–9 digit dipad nol depan ke 10; notasi ilmiah Excel dipulihkan bila presisi cukup. */
function guruWaliNormalisasiNisn($v): string
{
    $s = guruWaliSel($v);
    if ($s === '') return '';
    if (preg_match('/^\d+([.,]\d+)?e\+?\d+$/i', $s)) {
        $f = (float)str_replace(',', '.', $s);
        if ($f <= 0 || $f >= 1e15) return '';
        $s = sprintf('%.0f', $f);
    }
    $digit = preg_replace('/\D/', '', $s);
    $len = strlen($digit);
    if ($len >= 8 && $len < 10) $digit = str_pad($digit, 10, '0', STR_PAD_LEFT);
    return $digit;
}

/** NIP: hanya digit. */
function guruWaliNormalisasiNip($v): string
{
    return (string)preg_replace('/\D/', '', guruWaliSel($v));
}

/** Nilai ambang kuota yang sah (1–100) atau null. */
function guruWaliKuotaValid($v): ?int
{
    if (is_int($v)) $n = $v;
    elseif (is_string($v) && preg_match('/^\s*\d+\s*$/', $v)) $n = (int)trim($v);
    elseif (is_float($v) && floor($v) == $v) $n = (int)$v;
    else return null;
    return ($n >= 1 && $n <= 100) ? $n : null;
}

/** Ambang kuota efektif: nilai sah atau bawaan 20. */
function guruWaliKuota($v): int
{
    return guruWaliKuotaValid($v) ?? GW_KUOTA_DEFAULT;
}

/**
 * Klasifikasi satu baris impor.
 * $baris: ['baris' => int, 'nisn' => ..., 'email' => ..., 'nip' => ...]
 * $ctx:   ['murid' => nisn => [row murid…], 'email' => email(lower) => row user, 'nip' => nip => [row user…],
 *          'berulang' => nisn => true, 'aktif' => student_id => row penugasan aktif]
 * Row murid: id, name, class_name, aktif(bool). Row user: uid, displayName, role. Row penugasan: id, guru_uid, guru_nama, tanggal_mulai.
 * Hasil: ['baris', 'status' (BARU|GANTI|SAMA|KOSONG|GALAT), 'pesan', 'nisn', 'student_id', 'nama_murid', 'kelas',
 *         'guru_uid', 'guru_nama', 'penugasan_lama_id', 'guru_lama_uid', 'guru_lama_nama', 'tanggal_mulai_lama']
 */
function guruWaliKlasifikasiBaris(array $baris, array $ctx): array
{
    $nisn  = guruWaliNormalisasiNisn($baris['nisn'] ?? '');
    $email = mb_strtolower(guruWaliSel($baris['email'] ?? ''), 'UTF-8');
    $nip   = guruWaliNormalisasiNip($baris['nip'] ?? '');
    $h = ['baris' => (int)($baris['baris'] ?? 0), 'status' => 'GALAT', 'pesan' => null, 'nisn' => $nisn,
          'student_id' => null, 'nama_murid' => null, 'kelas' => null, 'guru_uid' => null, 'guru_nama' => null,
          'penugasan_lama_id' => null, 'guru_lama_uid' => null, 'guru_lama_nama' => null, 'tanggal_mulai_lama' => null];
    $galat = function (string $p) use (&$h) { $h['status'] = 'GALAT'; $h['pesan'] = $p; return $h; };

    if ($email === '' && $nip === '') { $h['status'] = 'KOSONG'; $h['pesan'] = 'Kolom email dan NIP guru wali kosong'; return $h; }
    if ($nisn === '') return $galat('NISN kosong atau tidak valid');
    if (!empty($ctx['berulang'][$nisn])) return $galat('NISN muncul lebih dari sekali di file');
    $murid = $ctx['murid'][$nisn] ?? [];
    if (!$murid) return $galat('NISN tidak ditemukan');
    if (count($murid) > 1) return $galat('NISN dimiliki lebih dari satu murid');
    $m = $murid[0];
    $h['student_id'] = (string)$m['id'];
    $h['nama_murid'] = (string)$m['name'];
    $h['kelas']      = (string)($m['class_name'] ?? '');
    if (empty($m['aktif'])) return $galat('Murid tidak aktif');

    if ($email !== '') {
        $g = $ctx['email'][$email] ?? null;
        if (!$g) return $galat("Guru dengan email $email tidak ditemukan");
    } else {
        $daftar = $ctx['nip'][$nip] ?? [];
        if (!$daftar) return $galat("Guru dengan NIP $nip tidak ditemukan");
        if (count($daftar) > 1) return $galat("NIP $nip dimiliki lebih dari satu akun");
        $g = $daftar[0];
    }
    if (($g['role'] ?? '') !== 'GURU') return $galat(($email !== '' ? $email : "NIP $nip") . ' bukan akun guru');
    $h['guru_uid']  = (string)$g['uid'];
    $h['guru_nama'] = (string)$g['displayName'];

    $lama = $ctx['aktif'][$h['student_id']] ?? null;
    if (!$lama) { $h['status'] = 'BARU'; return $h; }
    $h['penugasan_lama_id'] = (string)$lama['id'];
    $h['guru_lama_uid'] = (string)$lama['guru_uid'];
    $h['guru_lama_nama'] = (string)$lama['guru_nama'];
    $h['tanggal_mulai_lama'] = (string)$lama['tanggal_mulai'];
    $h['status'] = ((string)$lama['guru_uid'] === $h['guru_uid']) ? 'SAMA' : 'GANTI';
    return $h;
}

/** Status efektif satu penugasan untuk tampilan. */
function guruWaliStatusEfektif(string $status, bool $muridAktif): string
{
    if ($status === 'AKTIF') return $muridAktif ? 'AKTIF' : 'SELESAI_MURID_NONAKTIF';
    return $status;
}

// =====================================================================
// Skema, util DB
// =====================================================================

const GW_SQL_MURID_AKTIF = "(s.status IS NULL OR s.status = '' OR UPPER(s.status) = 'AKTIF')";

function guruWaliPastikanTabel(mysqli $db): void
{
    static $sudah = false;
    if ($sudah) return;
    $kolom = "
        `id` varchar(40) NOT NULL,
        `student_id` varchar(128) NOT NULL,
        `guru_uid` varchar(128) NOT NULL,
        `guru_nama` varchar(255) NOT NULL,
        `kelas_saat_mulai` varchar(100) DEFAULT NULL,
        `tanggal_mulai` date NOT NULL,
        `tanggal_selesai` date DEFAULT NULL,
        `status` enum('AKTIF','SELESAI','DIBATALKAN') NOT NULL DEFAULT 'AKTIF',
        %GEN%
        `sumber` enum('MANUAL','IMPOR') NOT NULL DEFAULT 'MANUAL',
        `impor_id` varchar(40) DEFAULT NULL,
        `menggantikan_id` varchar(40) DEFAULT NULL,
        `alasan_selesai` enum('MUTASI_GURU','PENSIUN','BEBAN_KERJA','PERMINTAAN_MURID_ORTU','KOREKSI_DATA','MURID_NONAKTIF','LAINNYA') DEFAULT NULL,
        `catatan_selesai` varchar(500) DEFAULT NULL,
        `diakhiri_oleh_penugasan` varchar(40) DEFAULT NULL,
        `dibuat_oleh` varchar(128) NOT NULL,
        `dibuat_oleh_nama` varchar(255) NOT NULL,
        `dibuat_pada` datetime NOT NULL,
        `diakhiri_oleh` varchar(128) DEFAULT NULL,
        `diakhiri_oleh_nama` varchar(255) DEFAULT NULL,
        `diakhiri_pada` datetime DEFAULT NULL,
        `dibatalkan_oleh` varchar(128) DEFAULT NULL,
        `dibatalkan_oleh_nama` varchar(255) DEFAULT NULL,
        `dibatalkan_pada` datetime DEFAULT NULL,
        `alasan_batal` varchar(500) DEFAULT NULL,
        PRIMARY KEY (`id`),
        %UQ%
        KEY `idx_gw_guru` (`guru_uid`, `status`),
        KEY `idx_gw_murid` (`student_id`, `tanggal_mulai`)";
    $akhir = ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $gen = "`aktif_student_id` varchar(128) GENERATED ALWAYS AS (IF(`status` = 'AKTIF', `student_id`, NULL)) STORED,";
    $uq  = "UNIQUE KEY `uq_gw_aktif` (`aktif_student_id`),";
    try {
        $db->query("CREATE TABLE IF NOT EXISTS `guru_wali_penugasan` (" . str_replace(['%GEN%', '%UQ%'], [$gen, $uq], $kolom) . $akhir);
    } catch (Throwable $e) {
        // Server tanpa dukungan kolom generated: andalkan GET_LOCK + SELECT ... FOR UPDATE
        error_log('[SIMANTAP] guru_wali: kolom generated tidak didukung, memakai fallback: ' . $e->getMessage());
        $db->query("CREATE TABLE IF NOT EXISTS `guru_wali_penugasan` (" . str_replace(['%GEN%', '%UQ%'], ['', ''], $kolom) . $akhir);
    }
    $sudah = true;
}

/** Apakah tabel penugasan sudah ada (untuk jalur baca fitur lain; tidak membuat tabel). */
function guruWaliTabelAda(mysqli $db): bool
{
    static $ada = null;
    if ($ada === true) return true;
    $res = $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guru_wali_penugasan'");
    $ada = $res && (int)$res->fetch_assoc()['n'] > 0;
    return $ada;
}

function guruWaliFetchAll(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function guruWaliExec(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $stmt = $db->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->affected_rows;
}

function guruWaliPh(array $items): string
{
    return implode(',', array_fill(0, count($items), '?'));
}

function guruWaliHariIni(): string
{
    return date('Y-m-d');
}

function guruWaliKuotaAktif(mysqli $db): int
{
    $rows = guruWaliFetchAll($db, "SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1", 's', [GW_KEY_KUOTA]);
    return guruWaliKuota($rows ? $rows[0]['config_value'] : null);
}

function guruWaliIdBaru(string $prefix): string
{
    return str_replace('.', '', uniqid($prefix, true));
}

// --- Status transaksi + lock (agar penolakan di tengah transaksi selalu rollback + lepas lock) ---

function guruWaliKunci(mysqli $db): void
{
    $r = $db->query("SELECT GET_LOCK('" . GW_LOCK . "', 10) AS ok")->fetch_assoc();
    if ((int)($r['ok'] ?? 0) !== 1) errorResponse(GW_MSG_SIBUK, 503);
    $GLOBALS['__gw_lock'] = true;
    $db->begin_transaction();
    $GLOBALS['__gw_tx'] = true;
}

function guruWaliLepas(mysqli $db, bool $commit): void
{
    if (!empty($GLOBALS['__gw_tx'])) {
        $commit ? $db->commit() : $db->rollback();
        $GLOBALS['__gw_tx'] = false;
    }
    if (!empty($GLOBALS['__gw_lock'])) {
        $db->query("SELECT RELEASE_LOCK('" . GW_LOCK . "')");
        $GLOBALS['__gw_lock'] = false;
    }
}

/** Tolak permintaan: rollback + lepas lock bila ada, lalu kirim JSON galat. */
function guruWaliTolak(mysqli $db, int $status, string $pesan, array $extra = []): void
{
    try { guruWaliLepas($db, false); } catch (Throwable $e) { /* abaikan */ }
    jsonResponse(['error' => $pesan] + $extra, $status);
}

function guruWaliDalamTry(mysqli $db, string $action, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        try { guruWaliLepas($db, false); } catch (Throwable $e2) { /* abaikan */ }
        if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) {
            errorResponse('Murid ini baru saja ditugaskan oleh pengguna lain. Muat ulang data lalu coba lagi.', 409);
        }
        error_log('[SIMANTAP] ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        errorResponse(GW_MSG_SERVER, 500);
    }
}

function guruWaliWajibMetode(string $metode): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $metode) {
        header('Allow: ' . $metode);
        errorResponse('Metode tidak diizinkan', 405);
    }
}

/** Guard pengelola (ADMIN/KS, dibaca ulang dari DB). Hasil: aktor dari session. */
function guruWaliGuardPengelola(mysqli $db, string $metode): array
{
    guruWaliWajibMetode($metode);
    $user  = requireAuth();
    $fresh = loadFreshUser($db, $user);
    if (!in_array($fresh['role'], ['ADMIN', 'KEPALA_SEKOLAH'], true)) errorResponse(GW_MSG_PENGELOLA, 403);
    guruWaliPastikanTabel($db);
    return ['uid' => (string)$user['uid'], 'nama' => (string)($user['displayName'] ?? $fresh['displayName'])];
}

/** Guru tujuan harus ada dan berperan GURU. */
function guruWaliWajibGuru(mysqli $db, string $uid): array
{
    if ($uid === '') errorResponse('Guru wali wajib dipilih.', 400);
    $rows = guruWaliFetchAll($db, "SELECT uid, displayName, role FROM users WHERE uid = ? LIMIT 1", 's', [$uid]);
    if (!$rows) errorResponse('Guru tidak ditemukan.', 404);
    if ($rows[0]['role'] !== 'GURU') errorResponse($rows[0]['displayName'] . ' bukan akun guru.', 400);
    return $rows[0];
}

/** Alasan dari body + catatan (LAINNYA wajib catatan). Menolak bila tidak valid. */
function guruWaliAmbilAlasan(mysqli $db, array $body, bool $bolehNonaktif = false): array
{
    $alasan = guruWaliNormalisasiAlasan($body['alasan'] ?? '');
    if ($alasan === null || (!$bolehNonaktif && $alasan === 'MURID_NONAKTIF')) guruWaliTolak($db, 400, 'Alasan wajib dipilih.');
    $catatan = guruWaliSel($body['catatan'] ?? '');
    if (mb_strlen($catatan, 'UTF-8') > GW_MAKS_CATATAN) guruWaliTolak($db, 400, 'Catatan maksimal ' . GW_MAKS_CATATAN . ' karakter.');
    if ($alasan === 'LAINNYA' && $catatan === '') guruWaliTolak($db, 400, 'Catatan wajib diisi untuk alasan "Lainnya".');
    return [$alasan, $catatan !== '' ? $catatan : null];
}

/** Daftar string unik dari body. */
function guruWaliDaftarId($v, int $maks, string $label): array
{
    if (!is_array($v)) errorResponse("$label wajib diisi.", 400);
    $ids = [];
    foreach ($v as $x) {
        if (!is_scalar($x)) continue;
        $x = trim((string)$x);
        if ($x !== '') $ids[$x] = true;
    }
    $ids = array_keys($ids);
    if (!$ids) errorResponse("$label wajib diisi.", 400);
    if (count($ids) > $maks) errorResponse("Maksimal $maks $label per simpan.", 400);
    return array_map('strval', $ids);
}

// =====================================================================
// Query bersama (juga dipakai profil_murid.php, endpoints, delete_user)
// =====================================================================

/** Jumlah dampingan efektif (penugasan aktif + murid aktif) seorang guru. */
function guruWaliJumlahEfektif(mysqli $db, string $uid): int
{
    if (!guruWaliTabelAda($db)) return 0;
    $r = guruWaliFetchAll($db,
        "SELECT COUNT(*) AS n FROM guru_wali_penugasan p JOIN students s ON s.id = p.student_id
         WHERE p.guru_uid = ? AND p.status = 'AKTIF' AND " . GW_SQL_MURID_AKTIF, 's', [$uid]);
    return (int)$r[0]['n'];
}

/** student_id murid dampingan efektif seorang guru. */
function guruWaliDampinganIds(mysqli $db, string $uid): array
{
    if ($uid === '' || !guruWaliTabelAda($db)) return [];
    $rows = guruWaliFetchAll($db,
        "SELECT p.student_id FROM guru_wali_penugasan p JOIN students s ON s.id = p.student_id
         WHERE p.guru_uid = ? AND p.status = 'AKTIF' AND " . GW_SQL_MURID_AKTIF, 's', [$uid]);
    return array_map(fn($r) => (string)$r['student_id'], $rows);
}

/** Penugasan (non-batal) yang berlaku untuk murid pada tanggal tertentu (untuk tahap 2–3). */
function guruWaliPadaTanggal(mysqli $db, string $studentId, string $tanggal): ?array
{
    if (!guruWaliTabelAda($db)) return null;
    $rows = guruWaliFetchAll($db,
        "SELECT p.id, p.guru_uid, COALESCE(u.displayName, p.guru_nama) AS guru_nama, p.tanggal_mulai, p.tanggal_selesai, p.status
         FROM guru_wali_penugasan p LEFT JOIN users u ON u.uid = p.guru_uid
         WHERE p.student_id = ? AND p.status IN ('AKTIF','SELESAI')
           AND p.tanggal_mulai <= ? AND COALESCE(p.tanggal_selesai, '9999-12-31') >= ?
         ORDER BY p.tanggal_mulai DESC LIMIT 1", 'sss', [$studentId, $tanggal, $tanggal]);
    return $rows[0] ?? null;
}

/** Guru wali aktif + riwayat resmi seorang murid (untuk Profil Murid 360°). */
function guruWaliUntukProfil(mysqli $db, string $studentId, bool $muridAktif): array
{
    $hasil = ['aktif' => null, 'riwayat' => []];
    if (!guruWaliTabelAda($db)) return $hasil;
    $rows = guruWaliFetchAll($db,
        "SELECT p.id, p.guru_uid, COALESCE(u.displayName, p.guru_nama) AS guru_nama, (u.uid IS NULL) AS akun_dihapus,
                p.tanggal_mulai, p.tanggal_selesai, p.status, p.alasan_selesai, p.catatan_selesai, p.kelas_saat_mulai
         FROM guru_wali_penugasan p LEFT JOIN users u ON u.uid = p.guru_uid
         WHERE p.student_id = ? AND p.status IN ('AKTIF','SELESAI')
         ORDER BY p.tanggal_mulai DESC, p.dibuat_pada DESC", 's', [$studentId]);
    foreach ($rows as $r) {
        $ef = guruWaliStatusEfektif((string)$r['status'], $muridAktif);
        $item = [
            'guru_uid'         => (string)$r['guru_uid'],
            'guru_nama'        => (string)$r['guru_nama'],
            'akun_dihapus'     => (bool)$r['akun_dihapus'],
            'tanggal_mulai'    => (string)$r['tanggal_mulai'],
            'tanggal_selesai'  => $r['tanggal_selesai'],
            'alasan_selesai'   => $r['alasan_selesai'],
            'alasan_label'     => $r['alasan_selesai'] ? GW_ALASAN[$r['alasan_selesai']] : null,
            'catatan_selesai'  => $r['catatan_selesai'],
            'kelas_saat_mulai' => $r['kelas_saat_mulai'],
            'efektif_status'   => $ef,
        ];
        if ($ef === 'AKTIF' && $hasil['aktif'] === null) {
            $hasil['aktif'] = ['guru_uid' => $item['guru_uid'], 'guru_nama' => $item['guru_nama'], 'tanggal_mulai' => $item['tanggal_mulai']];
        }
        $hasil['riwayat'][] = $item;
    }
    return $hasil;
}

/** Nama guru wali aktif per student_id (untuk Dashboard Kelas Saya). */
function guruWaliNamaPerMurid(mysqli $db, array $studentIds): array
{
    $studentIds = array_values(array_unique(array_filter(array_map('strval', $studentIds), fn($x) => $x !== '')));
    if (!$studentIds || !guruWaliTabelAda($db)) return [];
    $rows = guruWaliFetchAll($db,
        "SELECT p.student_id, p.guru_uid, COALESCE(u.displayName, p.guru_nama) AS guru_nama
         FROM guru_wali_penugasan p LEFT JOIN users u ON u.uid = p.guru_uid
         WHERE p.status = 'AKTIF' AND p.student_id IN (" . guruWaliPh($studentIds) . ")",
        str_repeat('s', count($studentIds)), $studentIds);
    $hasil = [];
    foreach ($rows as $r) $hasil[(string)$r['student_id']] = ['uid' => (string)$r['guru_uid'], 'nama' => (string)$r['guru_nama']];
    return $hasil;
}

/**
 * Tutup penugasan menggantung (aktif tetapi murid nonaktif/terhapus). Dalam transaksi pemanggil.
 * $guruUid: batasi ke satu guru (null = semua). Hasil: jumlah ditutup.
 */
function guruWaliTutupMenggantung(mysqli $db, array $aktor, ?string $guruUid = null): int
{
    $sql = "SELECT p.id, p.tanggal_mulai FROM guru_wali_penugasan p LEFT JOIN students s ON s.id = p.student_id
            WHERE p.status = 'AKTIF' AND (s.id IS NULL OR NOT " . GW_SQL_MURID_AKTIF . ")";
    $types = ''; $params = [];
    if ($guruUid !== null) { $sql .= " AND p.guru_uid = ?"; $types = 's'; $params[] = $guruUid; }
    $sql .= " FOR UPDATE";
    $rows = guruWaliFetchAll($db, $sql, $types, $params);
    $hariIni = guruWaliHariIni();
    foreach ($rows as $r) {
        $selesai = max((string)$r['tanggal_mulai'], $hariIni);
        guruWaliExec($db,
            "UPDATE guru_wali_penugasan SET status = 'SELESAI', tanggal_selesai = ?, alasan_selesai = 'MURID_NONAKTIF',
                    diakhiri_oleh = ?, diakhiri_oleh_nama = ?, diakhiri_pada = NOW() WHERE id = ? AND status = 'AKTIF'",
            'ssss', [$selesai, $aktor['uid'], $aktor['nama'], $r['id']]);
    }
    return count($rows);
}

/**
 * Dipanggil delete_user / gtk_delete sebelum menghapus akun: 409 bila guru masih punya dampingan efektif;
 * penugasan menggantung miliknya ditutup otomatis.
 */
function guruWaliCegahHapusGuru(mysqli $db, string $uid): void
{
    if ($uid === '' || !guruWaliTabelAda($db)) return;
    $n = guruWaliJumlahEfektif($db, $uid);
    if ($n > 0) {
        jsonResponse(['error' => "Guru ini masih mendampingi $n murid aktif. Pindahkan murid dampingannya di menu Guru Wali terlebih dahulu.",
                      'jumlah_dampingan' => $n], 409);
    }
    $sesi = getCurrentUser() ?? [];
    $aktor = ['uid' => (string)($sesi['uid'] ?? 'SISTEM'), 'nama' => (string)($sesi['displayName'] ?? 'Sistem')];
    $db->begin_transaction();
    try {
        guruWaliTutupMenggantung($db, $aktor, $uid);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/** Dipanggil save_user / save_user_v2: 409 bila role GURU diubah ke peran lain selagi punya dampingan efektif. */
function guruWaliCegahUbahRole(mysqli $db, string $uid, $roleBaru): void
{
    if ($uid === '' || !is_string($roleBaru) || $roleBaru === '' || $roleBaru === 'GURU' || !guruWaliTabelAda($db)) return;
    $rows = guruWaliFetchAll($db, "SELECT role FROM users WHERE uid = ? LIMIT 1", 's', [$uid]);
    if (!$rows || $rows[0]['role'] !== 'GURU') return;
    $n = guruWaliJumlahEfektif($db, $uid);
    if ($n > 0) {
        jsonResponse(['error' => "Peran guru ini tidak dapat diubah karena masih mendampingi $n murid aktif. Pindahkan murid dampingannya di menu Guru Wali terlebih dahulu.",
                      'jumlah_dampingan' => $n], 409);
    }
}

/** Penugasan aktif per student_id (opsional FOR UPDATE). */
function guruWaliAktifPerMurid(mysqli $db, array $studentIds, bool $forUpdate): array
{
    if (!$studentIds) return [];
    $rows = guruWaliFetchAll($db,
        "SELECT p.id, p.student_id, p.guru_uid, COALESCE(u.displayName, p.guru_nama) AS guru_nama, p.tanggal_mulai
         FROM guru_wali_penugasan p LEFT JOIN users u ON u.uid = p.guru_uid
         WHERE p.status = 'AKTIF' AND p.student_id IN (" . guruWaliPh($studentIds) . ")" . ($forUpdate ? " FOR UPDATE" : ""),
        str_repeat('s', count($studentIds)), $studentIds);
    $hasil = [];
    foreach ($rows as $r) $hasil[(string)$r['student_id']] = $r;
    return $hasil;
}

/** Tanggal selesai terakhir penugasan SELESAI per student_id. */
function guruWaliSelesaiTerakhir(mysqli $db, array $studentIds): array
{
    if (!$studentIds) return [];
    $rows = guruWaliFetchAll($db,
        "SELECT student_id, MAX(tanggal_selesai) AS t FROM guru_wali_penugasan
         WHERE status = 'SELESAI' AND student_id IN (" . guruWaliPh($studentIds) . ") GROUP BY student_id",
        str_repeat('s', count($studentIds)), $studentIds);
    $hasil = [];
    foreach ($rows as $r) if ($r['t']) $hasil[(string)$r['student_id']] = (string)$r['t'];
    return $hasil;
}

/** Sisipkan penugasan baru. */
function guruWaliSisipkan(mysqli $db, string $id, array $m, array $guru, string $tanggal, array $aktor,
                          string $sumber, ?string $imporId, ?string $menggantikanId): void
{
    guruWaliExec($db,
        "INSERT INTO guru_wali_penugasan (id, student_id, guru_uid, guru_nama, kelas_saat_mulai, tanggal_mulai, status, sumber,
                impor_id, menggantikan_id, dibuat_oleh, dibuat_oleh_nama, dibuat_pada)
         VALUES (?, ?, ?, ?, ?, ?, 'AKTIF', ?, ?, ?, ?, ?, NOW())",
        'sssssssssss', [$id, (string)$m['id'], (string)$guru['uid'], (string)$guru['displayName'], (string)($m['class_name'] ?? ''),
                        $tanggal, $sumber, $imporId, $menggantikanId, $aktor['uid'], $aktor['nama']]);
}

/** Tutup penugasan aktif. */
function guruWaliTutup(mysqli $db, string $id, string $tanggalSelesai, string $alasan, ?string $catatan, array $aktor, ?string $penggantiId): void
{
    guruWaliExec($db,
        "UPDATE guru_wali_penugasan SET status = 'SELESAI', tanggal_selesai = ?, alasan_selesai = ?, catatan_selesai = ?,
                diakhiri_oleh_penugasan = ?, diakhiri_oleh = ?, diakhiri_oleh_nama = ?, diakhiri_pada = NOW()
         WHERE id = ? AND status = 'AKTIF'",
        'sssssss', [$tanggalSelesai, $alasan, $catatan, $penggantiId, $aktor['uid'], $aktor['nama'], $id]);
}

/** Peringatan kuota untuk guru-guru tertentu (setelah simpan). */
function guruWaliPeringatanKuota(mysqli $db, array $uids): array
{
    $kuota = guruWaliKuotaAktif($db);
    $hasil = [];
    foreach (array_unique($uids) as $uid) {
        $n = guruWaliJumlahEfektif($db, $uid);
        if ($n > $kuota) {
            $nama = guruWaliFetchAll($db, "SELECT displayName FROM users WHERE uid = ?", 's', [$uid]);
            $hasil[] = ['uid' => $uid, 'nama' => $nama ? $nama[0]['displayName'] : $uid, 'jumlah' => $n, 'kuota' => $kuota];
        }
    }
    return $hasil;
}

function guruWaliMuridPerId(mysqli $db, array $ids): array
{
    if (!$ids) return [];
    $rows = guruWaliFetchAll($db,
        "SELECT s.id, s.name, s.nisn, s.class_id, s.class_name, s.status, " . GW_SQL_MURID_AKTIF . " AS aktif
         FROM students s WHERE s.id IN (" . guruWaliPh($ids) . ")", str_repeat('s', count($ids)), $ids);
    $hasil = [];
    foreach ($rows as $r) { $r['aktif'] = (bool)$r['aktif']; $hasil[(string)$r['id']] = $r; }
    return $hasil;
}

// =====================================================================
// Handler action
// =====================================================================

/** GET guru_wali_ringkasan */
function handleGuruWaliRingkasan(mysqli $db): void
{
    guruWaliGuardPengelola($db, 'GET');
    guruWaliDalamTry($db, 'guru_wali_ringkasan', function () use ($db) {
        $kuota = guruWaliKuotaAktif($db);
        $total = (int)guruWaliFetchAll($db, "SELECT COUNT(*) AS n FROM students s WHERE " . GW_SQL_MURID_AKTIF)[0]['n'];
        $tanpa = (int)guruWaliFetchAll($db,
            "SELECT COUNT(*) AS n FROM students s LEFT JOIN guru_wali_penugasan p ON p.student_id = s.id AND p.status = 'AKTIF'
             WHERE " . GW_SQL_MURID_AKTIF . " AND p.id IS NULL")[0]['n'];
        $gantung = (int)guruWaliFetchAll($db,
            "SELECT COUNT(*) AS n FROM guru_wali_penugasan p LEFT JOIN students s ON s.id = p.student_id
             WHERE p.status = 'AKTIF' AND (s.id IS NULL OR NOT " . GW_SQL_MURID_AKTIF . ")")[0]['n'];
        $yatim = (int)guruWaliFetchAll($db,
            "SELECT COUNT(*) AS n FROM guru_wali_penugasan p LEFT JOIN users u ON u.uid = p.guru_uid
             WHERE p.status = 'AKTIF' AND (u.uid IS NULL OR u.role <> 'GURU')")[0]['n'];
        $jumlah = [];
        foreach (guruWaliFetchAll($db,
            "SELECT p.guru_uid, COUNT(*) AS n FROM guru_wali_penugasan p JOIN students s ON s.id = p.student_id
             WHERE p.status = 'AKTIF' AND " . GW_SQL_MURID_AKTIF . " GROUP BY p.guru_uid") as $r) $jumlah[$r['guru_uid']] = (int)$r['n'];
        $guru = [];
        foreach (guruWaliFetchAll($db, "SELECT uid, displayName, nip, email, class_homeroom, is_bk FROM users WHERE role = 'GURU' ORDER BY displayName ASC") as $u) {
            $n = $jumlah[$u['uid']] ?? 0;
            $guru[] = [
                'uid' => (string)$u['uid'], 'nama' => (string)$u['displayName'], 'nip' => $u['nip'], 'email' => $u['email'],
                'class_homeroom' => $u['class_homeroom'], 'is_bk' => (int)$u['is_bk'] === 1,
                'jumlah_dampingan' => $n, 'melebihi_kuota' => $n > $kuota,
            ];
        }
        jsonResponse([
            'kuota' => $kuota, 'total_murid_aktif' => $total, 'murid_tanpa_guru_wali' => $tanpa,
            'penugasan_menggantung' => $gantung, 'penugasan_yatim' => $yatim,
            'alasan' => array_map(fn($k, $v) => ['kode' => $k, 'label' => $v], array_keys(GW_ALASAN), GW_ALASAN),
            'guru' => $guru,
        ]);
    });
}

/** GET guru_wali_murid: murid aktif + penugasan aktifnya. */
function handleGuruWaliMurid(mysqli $db): void
{
    guruWaliGuardPengelola($db, 'GET');
    guruWaliDalamTry($db, 'guru_wali_murid', function () use ($db) {
        $where = [GW_SQL_MURID_AKTIF]; $types = ''; $params = [];
        $kelas = trim((string)($_GET['class_id'] ?? ''));
        if ($kelas !== '') {
            $ref = resolveClassRef($db, $kelas);
            $where[] = "(s.class_id = ? OR s.class_name = ?)"; $types .= 'ss'; $params[] = $ref['id']; $params[] = $ref['name'];
        }
        $st = strtoupper(trim((string)($_GET['status_penugasan'] ?? 'SEMUA')));
        if ($st === 'BELUM') $where[] = "p.id IS NULL";
        elseif ($st === 'SUDAH') $where[] = "p.id IS NOT NULL";
        elseif ($st === 'YATIM') $where[] = "p.id IS NOT NULL AND (u.uid IS NULL OR u.role <> 'GURU')";
        $guruUid = trim((string)($_GET['guru_uid'] ?? ''));
        if ($guruUid !== '') { $where[] = "p.guru_uid = ?"; $types .= 's'; $params[] = $guruUid; }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            if (mb_strlen($q, 'UTF-8') < 3) errorResponse('Kata kunci pencarian minimal 3 karakter.', 400);
            $pola = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(s.name LIKE ? OR s.nisn LIKE ?)"; $types .= 'ss'; $params[] = $pola; $params[] = $pola;
        }
        $rows = guruWaliFetchAll($db,
            "SELECT s.id, s.nisn, s.name, s.class_id, s.class_name,
                    IF(JSON_VALID(s.docs_json), JSON_UNQUOTE(JSON_EXTRACT(s.docs_json, '$.pas_foto')), NULL) AS pas_foto,
                    p.id AS p_id, p.guru_uid, COALESCE(u.displayName, p.guru_nama) AS guru_nama, p.tanggal_mulai,
                    (p.id IS NOT NULL AND (u.uid IS NULL OR u.role <> 'GURU')) AS guru_tidak_valid
             FROM students s
             LEFT JOIN guru_wali_penugasan p ON p.student_id = s.id AND p.status = 'AKTIF'
             LEFT JOIN users u ON u.uid = p.guru_uid
             WHERE " . implode(' AND ', $where) . " ORDER BY s.class_name ASC, s.name ASC", $types, $params);
        $murid = [];
        foreach ($rows as $r) {
            $murid[] = [
                'id' => (string)$r['id'], 'nisn' => (string)($r['nisn'] ?? ''), 'name' => (string)$r['name'],
                'class_id' => (string)$r['class_id'], 'class_name' => (string)$r['class_name'], 'pas_foto' => $r['pas_foto'],
                'penugasan' => $r['p_id'] ? [
                    'id' => (string)$r['p_id'], 'guru_uid' => (string)$r['guru_uid'], 'guru_nama' => (string)$r['guru_nama'],
                    'tanggal_mulai' => (string)$r['tanggal_mulai'], 'guru_tidak_valid' => (bool)$r['guru_tidak_valid'],
                ] : null,
            ];
        }
        jsonResponse(['murid' => $murid]);
    });
}

/** POST guru_wali_tugaskan */
function handleGuruWaliTugaskan(mysqli $db): void
{
    $aktor = guruWaliGuardPengelola($db, 'POST');
    $body = getRequestBody();
    $guru = guruWaliWajibGuru($db, guruWaliSel($body['guru_uid'] ?? ''));
    $ids = guruWaliDaftarId($body['student_ids'] ?? null, GW_MAKS_MURID, 'murid');
    $hariIni = guruWaliHariIni();
    $t = guruWaliSel($body['tanggal_mulai'] ?? '') ?: $hariIni;
    if ($g = guruWaliValidasiTanggal($t, $hariIni)) errorResponse(str_replace('Tanggal', 'Tanggal mulai', $g), 400);
    $ganti = ($body['ganti'] ?? false) === true;

    guruWaliDalamTry($db, 'guru_wali_tugaskan', function () use ($db, $aktor, $guru, $ids, $t, $ganti, $body) {
        guruWaliKunci($db);
        $murid = guruWaliMuridPerId($db, $ids);
        $hilang = array_values(array_diff($ids, array_keys($murid)));
        if ($hilang) guruWaliTolak($db, 400, 'Sebagian murid tidak ditemukan.', ['tidak_ditemukan' => $hilang]);
        $nonaktif = array_values(array_map(fn($m) => ['student_id' => (string)$m['id'], 'name' => (string)$m['name']],
                                           array_filter($murid, fn($m) => !$m['aktif'])));
        if ($nonaktif) guruWaliTolak($db, 400, 'Hanya murid aktif yang dapat ditugaskan.', ['nonaktif' => $nonaktif]);

        $aktif = guruWaliAktifPerMurid($db, $ids, true);
        $sama = []; $perluGanti = []; $baru = [];
        foreach ($ids as $sid) {
            $a = $aktif[$sid] ?? null;
            if (!$a) $baru[] = $sid;
            elseif ((string)$a['guru_uid'] === (string)$guru['uid']) $sama[] = ['student_id' => $sid, 'name' => (string)$murid[$sid]['name']];
            else $perluGanti[] = $sid;
        }
        if ($perluGanti && !$ganti) {
            guruWaliTolak($db, 409, 'Sebagian murid sudah memiliki guru wali lain. Konfirmasi penggantian dengan alasan.', [
                'perlu_konfirmasi' => array_map(fn($sid) => [
                    'student_id' => $sid, 'name' => (string)$murid[$sid]['name'], 'class_name' => (string)$murid[$sid]['class_name'],
                    'guru_lama_nama' => (string)$aktif[$sid]['guru_nama'], 'tanggal_mulai_lama' => (string)$aktif[$sid]['tanggal_mulai'],
                ], $perluGanti),
            ]);
        }
        $alasan = null; $catatan = null;
        if ($perluGanti) {
            [$alasan, $catatan] = guruWaliAmbilAlasan($db, $body);
            $salah = [];
            foreach ($perluGanti as $sid) {
                if ($t <= (string)$aktif[$sid]['tanggal_mulai']) $salah[] = ['student_id' => $sid, 'name' => (string)$murid[$sid]['name'], 'tanggal_mulai_lama' => (string)$aktif[$sid]['tanggal_mulai']];
            }
            if ($salah) {
                guruWaliTolak($db, 400, 'Tanggal efektif harus setelah tanggal mulai guru wali lama (' . rekapTanggalIndo($salah[0]['tanggal_mulai_lama']) . '). Jika penugasan lama salah input, gunakan Batalkan.', ['tanggal_bentrok' => $salah]);
            }
        }
        $terakhir = guruWaliSelesaiTerakhir($db, $baru);
        $bentrok = [];
        foreach ($baru as $sid) {
            if (isset($terakhir[$sid]) && $t <= $terakhir[$sid]) $bentrok[] = ['student_id' => $sid, 'name' => (string)$murid[$sid]['name'], 'tanggal_selesai_lama' => $terakhir[$sid]];
        }
        if ($bentrok) {
            guruWaliTolak($db, 400, 'Tanggal mulai harus setelah ' . rekapTanggalIndo($bentrok[0]['tanggal_selesai_lama']) . ' (akhir penugasan sebelumnya).', ['tanggal_bentrok' => $bentrok]);
        }

        $guruLama = [];
        foreach ($perluGanti as $sid) {
            $idBaru = guruWaliIdBaru('gwp_');
            guruWaliTutup($db, (string)$aktif[$sid]['id'], guruWaliTanggalSebelum($t), $alasan, $catatan, $aktor, $idBaru);
            guruWaliSisipkan($db, $idBaru, $murid[$sid], $guru, $t, $aktor, 'MANUAL', null, (string)$aktif[$sid]['id']);
            $guruLama[] = (string)$aktif[$sid]['guru_uid'];
        }
        foreach ($baru as $sid) {
            guruWaliSisipkan($db, guruWaliIdBaru('gwp_'), $murid[$sid], $guru, $t, $aktor, 'MANUAL', null, null);
        }
        guruWaliLepas($db, true);
        $kuota = guruWaliPeringatanKuota($db, [(string)$guru['uid']]);
        jsonResponse(['success' => true, 'dibuat' => count($baru), 'diganti' => count($perluGanti), 'sama' => $sama,
                      'peringatan_kuota' => $kuota[0] ?? null]);
    });
}

/** POST guru_wali_akhiri: akhiri tanpa pengganti. */
function handleGuruWaliAkhiri(mysqli $db): void
{
    $aktor = guruWaliGuardPengelola($db, 'POST');
    $body = getRequestBody();
    $ids = guruWaliDaftarId($body['penugasan_ids'] ?? null, GW_MAKS_MURID, 'penugasan');
    $hariIni = guruWaliHariIni();
    $t = guruWaliSel($body['tanggal_selesai'] ?? '') ?: $hariIni;
    if ($g = guruWaliValidasiTanggal($t, $hariIni)) errorResponse(str_replace('Tanggal', 'Tanggal selesai', $g), 400);

    guruWaliDalamTry($db, 'guru_wali_akhiri', function () use ($db, $aktor, $ids, $t, $body) {
        guruWaliKunci($db);
        [$alasan, $catatan] = guruWaliAmbilAlasan($db, $body, true);
        $rows = guruWaliFetchAll($db, "SELECT id, status, tanggal_mulai FROM guru_wali_penugasan WHERE id IN (" . guruWaliPh($ids) . ") FOR UPDATE",
                                 str_repeat('s', count($ids)), $ids);
        if (count($rows) !== count($ids)) guruWaliTolak($db, 404, 'Sebagian penugasan tidak ditemukan.');
        foreach ($rows as $r) {
            if ($r['status'] !== 'AKTIF') guruWaliTolak($db, 409, 'Hanya penugasan aktif yang dapat diakhiri. Muat ulang data.');
            if ($t < (string)$r['tanggal_mulai']) guruWaliTolak($db, 400, 'Tanggal selesai tidak boleh sebelum tanggal mulai (' . rekapTanggalIndo((string)$r['tanggal_mulai']) . ').');
        }
        foreach ($rows as $r) guruWaliTutup($db, (string)$r['id'], $t, $alasan, $catatan, $aktor, null);
        guruWaliLepas($db, true);
        jsonResponse(['success' => true, 'diakhiri' => count($rows)]);
    });
}

/** POST guru_wali_tutup_nonaktif */
function handleGuruWaliTutupNonaktif(mysqli $db): void
{
    $aktor = guruWaliGuardPengelola($db, 'POST');
    guruWaliDalamTry($db, 'guru_wali_tutup_nonaktif', function () use ($db, $aktor) {
        guruWaliKunci($db);
        $n = guruWaliTutupMenggantung($db, $aktor, null);
        guruWaliLepas($db, true);
        jsonResponse(['success' => true, 'ditutup' => $n]);
    });
}

/** POST guru_wali_batalkan */
function handleGuruWaliBatalkan(mysqli $db): void
{
    $aktor = guruWaliGuardPengelola($db, 'POST');
    $body = getRequestBody();
    $id = guruWaliSel($body['penugasan_id'] ?? '');
    if ($id === '') errorResponse('Parameter penugasan_id wajib diisi.', 400);
    $alasanBatal = guruWaliSel($body['alasan_batal'] ?? '');
    if ($alasanBatal === '') errorResponse('Alasan pembatalan wajib diisi.', 400);
    if (mb_strlen($alasanBatal, 'UTF-8') > GW_MAKS_CATATAN) errorResponse('Alasan pembatalan maksimal ' . GW_MAKS_CATATAN . ' karakter.', 400);

    guruWaliDalamTry($db, 'guru_wali_batalkan', function () use ($db, $aktor, $id, $alasanBatal) {
        guruWaliKunci($db);
        $rows = guruWaliFetchAll($db, "SELECT id, status, menggantikan_id FROM guru_wali_penugasan WHERE id = ? FOR UPDATE", 's', [$id]);
        if (!$rows) guruWaliTolak($db, 404, 'Penugasan tidak ditemukan.');
        $p = $rows[0];
        if ($p['status'] !== 'AKTIF') guruWaliTolak($db, 409, 'Hanya penugasan aktif yang dapat dibatalkan.');
        guruWaliExec($db,
            "UPDATE guru_wali_penugasan SET status = 'DIBATALKAN', dibatalkan_oleh = ?, dibatalkan_oleh_nama = ?, dibatalkan_pada = NOW(), alasan_batal = ?
             WHERE id = ?", 'ssss', [$aktor['uid'], $aktor['nama'], $alasanBatal, $id]);
        $dipulihkan = null; $pesan = null;
        if ($p['menggantikan_id']) {
            $prev = guruWaliFetchAll($db,
                "SELECT p.id, p.status, p.diakhiri_oleh_penugasan, p.guru_uid, p.guru_nama, u.role, u.displayName
                 FROM guru_wali_penugasan p LEFT JOIN users u ON u.uid = p.guru_uid WHERE p.id = ? FOR UPDATE", 's', [$p['menggantikan_id']]);
            $q = $prev[0] ?? null;
            if ($q && $q['status'] === 'SELESAI' && (string)$q['diakhiri_oleh_penugasan'] === $id && $q['role'] === 'GURU') {
                guruWaliExec($db,
                    "UPDATE guru_wali_penugasan SET status = 'AKTIF', tanggal_selesai = NULL, alasan_selesai = NULL, catatan_selesai = NULL,
                            diakhiri_oleh_penugasan = NULL, diakhiri_oleh = NULL, diakhiri_oleh_nama = NULL, diakhiri_pada = NULL
                     WHERE id = ?", 's', [$q['id']]);
                $dipulihkan = ['id' => (string)$q['id'], 'guru_nama' => (string)($q['displayName'] ?? $q['guru_nama'])];
            } else {
                $pesan = 'Penugasan sebelumnya tidak dapat dipulihkan. Murid kini belum memiliki guru wali.';
            }
        }
        guruWaliLepas($db, true);
        jsonResponse(['success' => true, 'dibatalkan' => true, 'dipulihkan' => $dipulihkan, 'pesan' => $pesan]);
    });
}

/** GET guru_wali_riwayat */
function handleGuruWaliRiwayat(mysqli $db): void
{
    guruWaliGuardPengelola($db, 'GET');
    guruWaliDalamTry($db, 'guru_wali_riwayat', function () use ($db) {
        $where = []; $types = ''; $params = [];
        if (($_GET['termasuk_batal'] ?? '') !== '1') $where[] = "p.status IN ('AKTIF','SELESAI')";
        foreach (['student_id' => 'p.student_id', 'guru_uid' => 'p.guru_uid'] as $k => $kol) {
            $v = trim((string)($_GET[$k] ?? ''));
            if ($v !== '') { $where[] = "$kol = ?"; $types .= 's'; $params[] = $v; }
        }
        $kelas = trim((string)($_GET['class_id'] ?? ''));
        if ($kelas !== '') {
            $ref = resolveClassRef($db, $kelas);
            $where[] = "(s.class_id = ? OR s.class_name = ?)"; $types .= 'ss'; $params[] = $ref['id']; $params[] = $ref['name'];
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $pola = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(s.name LIKE ? OR s.nisn LIKE ? OR p.guru_nama LIKE ? OR u.displayName LIKE ?)";
            $types .= 'ssss'; array_push($params, $pola, $pola, $pola, $pola);
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $from = " FROM guru_wali_penugasan p LEFT JOIN students s ON s.id = p.student_id LEFT JOIN users u ON u.uid = p.guru_uid";
        $total = (int)guruWaliFetchAll($db, "SELECT COUNT(*) AS n" . $from . $w, $types, $params)[0]['n'];
        $hal = max(1, (int)($_GET['halaman'] ?? 1));
        $offset = ($hal - 1) * 50;
        $rows = guruWaliFetchAll($db,
            "SELECT p.*, COALESCE(u.displayName, p.guru_nama) AS guru_nama_kini, (u.uid IS NULL) AS akun_dihapus,
                    s.name AS nama_murid, s.nisn, s.class_name AS kelas_kini, s.status AS status_murid,
                    (s.id IS NOT NULL AND " . GW_SQL_MURID_AKTIF . ") AS murid_aktif"
            . $from . $w . " ORDER BY p.tanggal_mulai DESC, p.dibuat_pada DESC LIMIT 50 OFFSET $offset", $types, $params);
        $baris = [];
        foreach ($rows as $r) {
            $baris[] = [
                'id' => (string)$r['id'], 'student_id' => (string)$r['student_id'], 'nama_murid' => $r['nama_murid'] ?? '(murid dihapus)',
                'nisn' => $r['nisn'], 'kelas_saat_mulai' => $r['kelas_saat_mulai'], 'kelas_kini' => $r['kelas_kini'],
                'status_murid' => $r['status_murid'], 'guru_uid' => (string)$r['guru_uid'], 'guru_nama' => (string)$r['guru_nama_kini'],
                'akun_dihapus' => (bool)$r['akun_dihapus'], 'tanggal_mulai' => (string)$r['tanggal_mulai'], 'tanggal_selesai' => $r['tanggal_selesai'],
                'status' => (string)$r['status'], 'efektif_status' => guruWaliStatusEfektif((string)$r['status'], (bool)$r['murid_aktif']),
                'alasan_selesai' => $r['alasan_selesai'], 'alasan_label' => $r['alasan_selesai'] ? GW_ALASAN[$r['alasan_selesai']] : null,
                'catatan_selesai' => $r['catatan_selesai'], 'sumber' => (string)$r['sumber'], 'impor_id' => $r['impor_id'],
                'menggantikan_id' => $r['menggantikan_id'],
                'dibuat_oleh_nama' => (string)$r['dibuat_oleh_nama'], 'dibuat_pada' => (string)$r['dibuat_pada'],
                'diakhiri_oleh_nama' => $r['diakhiri_oleh_nama'], 'diakhiri_pada' => $r['diakhiri_pada'],
                'dibatalkan_oleh_nama' => $r['dibatalkan_oleh_nama'], 'dibatalkan_pada' => $r['dibatalkan_pada'], 'alasan_batal' => $r['alasan_batal'],
            ];
        }
        jsonResponse(['total' => $total, 'halaman' => $hal, 'per_halaman' => 50, 'baris' => $baris]);
    });
}

/** GET guru_wali_template */
function handleGuruWaliTemplate(mysqli $db): void
{
    guruWaliGuardPengelola($db, 'GET');
    guruWaliDalamTry($db, 'guru_wali_template', function () use ($db) {
        $where = [GW_SQL_MURID_AKTIF]; $types = ''; $params = []; $label = 'Semua';
        $kelas = trim((string)($_GET['class_id'] ?? ''));
        if ($kelas !== '') {
            $ref = resolveClassRef($db, $kelas);
            $where[] = "(s.class_id = ? OR s.class_name = ?)"; $types = 'ss'; $params = [$ref['id'], $ref['name']]; $label = $ref['name'];
        }
        $rows = guruWaliFetchAll($db,
            "SELECT s.nisn, s.name, s.class_name, u.email, u.nip, COALESCE(u.displayName, p.guru_nama) AS guru_nama
             FROM students s LEFT JOIN guru_wali_penugasan p ON p.student_id = s.id AND p.status = 'AKTIF'
             LEFT JOIN users u ON u.uid = p.guru_uid
             WHERE " . implode(' AND ', $where) . " ORDER BY s.class_name ASC, s.name ASC", $types, $params);
        $baris = array_map(fn($r) => [(string)($r['nisn'] ?? ''), (string)$r['name'], (string)$r['class_name'],
                                       (string)($r['email'] ?? ''), (string)($r['nip'] ?? ''), (string)($r['guru_nama'] ?? '')], $rows);
        jsonResponse(['header' => GW_TEMPLATE_HEADER, 'baris' => $baris, 'label' => $label]);
    });
}

/** Konteks pencocokan impor (murid per NISN, user per email/NIP, penugasan aktif). */
function guruWaliKonteksImpor(mysqli $db, array $baris, bool $forUpdate): array
{
    $hitung = [];
    foreach ($baris as $b) {
        $n = guruWaliNormalisasiNisn($b['nisn'] ?? '');
        if ($n !== '') $hitung[$n] = ($hitung[$n] ?? 0) + 1;
    }
    $ctx = ['murid' => [], 'email' => [], 'nip' => [], 'berulang' => [], 'aktif' => []];
    foreach ($hitung as $n => $c) if ($c > 1) $ctx['berulang'][$n] = true;
    foreach (guruWaliFetchAll($db,
        "SELECT s.id, s.name, s.nisn, s.class_id, s.class_name, " . GW_SQL_MURID_AKTIF . " AS aktif
         FROM students s WHERE s.nisn IS NOT NULL AND s.nisn <> ''") as $m) {
        $n = guruWaliNormalisasiNisn($m['nisn']);
        if ($n === '' || !isset($hitung[$n])) continue;
        $m['aktif'] = (bool)$m['aktif'];
        $ctx['murid'][$n][] = $m;
    }
    foreach (guruWaliFetchAll($db, "SELECT uid, displayName, role, email, nip FROM users") as $u) {
        $e = mb_strtolower(trim((string)$u['email']), 'UTF-8');
        if ($e !== '') $ctx['email'][$e] = $u;
        $nip = guruWaliNormalisasiNip($u['nip'] ?? '');
        if ($nip !== '') $ctx['nip'][$nip][] = $u;
    }
    $sids = [];
    foreach ($ctx['murid'] as $list) foreach ($list as $m) $sids[] = (string)$m['id'];
    $ctx['aktif'] = guruWaliAktifPerMurid($db, $sids, $forUpdate);
    return $ctx;
}

/** POST guru_wali_impor (pratinjau atau simpan) */
function handleGuruWaliImpor(mysqli $db): void
{
    $aktor = guruWaliGuardPengelola($db, 'POST');
    $body = getRequestBody();
    $mentah = $body['baris'] ?? null;
    if (!is_array($mentah) || !$mentah) errorResponse('Data file kosong.', 400);
    if (count($mentah) > GW_MAKS_BARIS) errorResponse('File terlalu besar: maksimal ' . GW_MAKS_BARIS . ' baris data.', 400);
    $baris = [];
    foreach (array_values($mentah) as $i => $b) {
        if (!is_array($b)) $b = [];
        $baris[] = ['baris' => (int)($b['baris'] ?? ($i + 2)), 'nisn' => guruWaliSel($b['nisn'] ?? ''),
                    'email' => guruWaliSel($b['email'] ?? ''), 'nip' => guruWaliSel($b['nip'] ?? '')];
    }
    $pratinjau = ($body['pratinjau'] ?? true) !== false;
    $ganti = ($body['ganti'] ?? false) === true;
    $hariIni = guruWaliHariIni();
    $t = guruWaliSel($body['tanggal_mulai'] ?? '') ?: $hariIni;
    if ($g = guruWaliValidasiTanggal($t, $hariIni)) errorResponse(str_replace('Tanggal', 'Tanggal mulai', $g), 400);

    guruWaliDalamTry($db, 'guru_wali_impor', function () use ($db, $aktor, $baris, $pratinjau, $ganti, $t, $body) {
        if (!$pratinjau) guruWaliKunci($db);
        $ctx = guruWaliKonteksImpor($db, $baris, !$pratinjau);
        $hasil = array_map(fn($b) => guruWaliKlasifikasiBaris($b, $ctx), $baris);

        // Aturan tanggal
        $baruIds = [];
        foreach ($hasil as $h) if ($h['status'] === 'BARU') $baruIds[] = $h['student_id'];
        $terakhir = guruWaliSelesaiTerakhir($db, $baruIds);
        foreach ($hasil as &$h) {
            if ($h['status'] === 'BARU' && isset($terakhir[$h['student_id']]) && $t <= $terakhir[$h['student_id']]) {
                $h['status'] = 'GALAT'; $h['pesan'] = 'Tanggal mulai harus setelah ' . rekapTanggalIndo($terakhir[$h['student_id']]) . ' (akhir penugasan sebelumnya)';
            } elseif ($h['status'] === 'GANTI' && $t <= $h['tanggal_mulai_lama']) {
                $h['status'] = 'GALAT'; $h['pesan'] = 'Tanggal mulai harus setelah ' . rekapTanggalIndo($h['tanggal_mulai_lama']) . ' (mulai guru wali lama)';
            }
        }
        unset($h);

        $ringkasan = ['BARU' => 0, 'GANTI' => 0, 'SAMA' => 0, 'KOSONG' => 0, 'GALAT' => 0];
        $proyeksi = [];
        foreach ($hasil as $h) {
            $ringkasan[$h['status']]++;
            if ($h['status'] === 'BARU' || ($h['status'] === 'GANTI' && ($ganti || $pratinjau))) $proyeksi[$h['guru_uid']] = ($proyeksi[$h['guru_uid']] ?? 0) + 1;
        }

        if ($pratinjau) {
            $kuota = guruWaliKuotaAktif($db);
            $peringatan = [];
            foreach ($proyeksi as $uid => $tambah) {
                $n = guruWaliJumlahEfektif($db, $uid) + $tambah;
                if ($n > $kuota) {
                    $nama = '';
                    foreach ($hasil as $h) if ($h['guru_uid'] === $uid) { $nama = $h['guru_nama']; break; }
                    $peringatan[] = ['uid' => $uid, 'nama' => $nama, 'jumlah' => $n, 'kuota' => $kuota];
                }
            }
            jsonResponse(['pratinjau' => true, 'ringkasan' => $ringkasan, 'baris' => $hasil, 'peringatan_kuota' => $peringatan]);
        }

        $alasan = null; $catatan = null;
        if ($ringkasan['GANTI'] > 0 && $ganti) [$alasan, $catatan] = guruWaliAmbilAlasan($db, $body);
        $imporId = guruWaliIdBaru('gwi_');
        $dibuat = 0; $diganti = 0; $guruTujuan = [];
        $muridCache = guruWaliMuridPerId($db, array_values(array_filter(array_map(fn($h) => $h['student_id'], $hasil))));
        foreach ($hasil as &$h) {
            $h['disimpan'] = false;
            if ($h['status'] !== 'BARU' && !($h['status'] === 'GANTI' && $ganti)) continue;
            $m = $muridCache[$h['student_id']];
            $guru = ['uid' => $h['guru_uid'], 'displayName' => $h['guru_nama']];
            $idBaru = guruWaliIdBaru('gwp_');
            if ($h['status'] === 'GANTI') {
                guruWaliTutup($db, $h['penugasan_lama_id'], guruWaliTanggalSebelum($t), $alasan, $catatan, $aktor, $idBaru);
                guruWaliSisipkan($db, $idBaru, $m, $guru, $t, $aktor, 'IMPOR', $imporId, $h['penugasan_lama_id']);
                $diganti++;
            } else {
                guruWaliSisipkan($db, $idBaru, $m, $guru, $t, $aktor, 'IMPOR', $imporId, null);
                $dibuat++;
            }
            $h['disimpan'] = true;
            $guruTujuan[] = $h['guru_uid'];
        }
        unset($h);
        guruWaliLepas($db, true);
        error_log('[SIMANTAP] guru_wali_impor ' . $imporId . ' oleh ' . $aktor['uid'] . ': dibuat=' . $dibuat . ' diganti=' . $diganti
                  . ' file=' . guruWaliSel($body['nama_file'] ?? ''));
        jsonResponse(['pratinjau' => false, 'success' => true, 'impor_id' => ($dibuat + $diganti) > 0 ? $imporId : null,
                      'ringkasan' => $ringkasan, 'tersimpan' => ['dibuat' => $dibuat, 'diganti' => $diganti],
                      'baris' => $hasil, 'peringatan_kuota' => guruWaliPeringatanKuota($db, $guruTujuan)]);
    });
}

/** GET guru_wali_dampingan_saya */
function handleGuruWaliDampinganSaya(mysqli $db): void
{
    guruWaliWajibMetode('GET');
    $user = requireAuth();
    $fresh = loadFreshUser($db, $user);
    $guruUid = trim((string)($_GET['guru_uid'] ?? ''));
    if (in_array($fresh['role'], ['ADMIN', 'KEPALA_SEKOLAH'], true)) {
        // Pengelola boleh melihat dampingan guru tertentu; tanpa guru_uid -> kosong
    } elseif ($fresh['role'] === 'GURU') {
        $guruUid = (string)$fresh['uid'];
    } else {
        errorResponse('Menu Murid Dampingan hanya untuk guru.', 403);
    }
    guruWaliPastikanTabel($db);
    guruWaliDalamTry($db, 'guru_wali_dampingan_saya', function () use ($db, $guruUid) {
        $period = getActiveAcademicPeriod($db);
        $ta = $period['academic_year'];
        $sem = strtoupper($period['semester']) === 'GENAP' ? 'GENAP' : 'GANJIL';
        $guru = null; $murid = [];
        if ($guruUid !== '') {
            $g = guruWaliFetchAll($db, "SELECT uid, displayName FROM users WHERE uid = ?", 's', [$guruUid]);
            $guru = $g ? ['uid' => (string)$g[0]['uid'], 'nama' => (string)$g[0]['displayName']] : null;
            $rows = guruWaliFetchAll($db,
                "SELECT s.id, s.nisn, s.name, s.class_id, s.class_name, s.total_violation_points, p.tanggal_mulai,
                        IF(JSON_VALID(s.docs_json), JSON_UNQUOTE(JSON_EXTRACT(s.docs_json, '$.pas_foto')), NULL) AS pas_foto
                 FROM guru_wali_penugasan p JOIN students s ON s.id = p.student_id
                 WHERE p.guru_uid = ? AND p.status = 'AKTIF' AND " . GW_SQL_MURID_AKTIF . "
                 ORDER BY s.class_name ASC, s.name ASC", 's', [$guruUid]);
            $ids = array_map(fn($r) => (string)$r['id'], $rows);
            $hadir = []; $bio = []; $asm = [];
            if ($ids) {
                $ph = guruWaliPh($ids); $tp = str_repeat('s', count($ids));
                $range = getSemesterDateRange($ta, $sem);
                $sql = "SELECT student_id, SUM(status = 'HADIR') AS h, COUNT(*) AS n FROM attendance_records WHERE student_id IN ($ph)";
                $types = $tp; $params = $ids;
                if ($range) { $sql .= " AND date BETWEEN ? AND ?"; $types .= 'ss'; $params[] = $range[0]; $params[] = $range[1]; }
                foreach (guruWaliFetchAll($db, $sql . " GROUP BY student_id", $types, $params) as $r) {
                    $hadir[$r['student_id']] = (int)$r['n'] > 0 ? round((int)$r['h'] / (int)$r['n'] * 100, 1) : null;
                }
                if (function_exists('profilMuridTabelAda') && profilMuridTabelAda($db, 'student_biodata')) {
                    foreach (guruWaliFetchAll($db, "SELECT student_id FROM student_biodata WHERE student_id IN ($ph)", $tp, $ids) as $r) $bio[$r['student_id']] = true;
                }
                if (function_exists('profilMuridTabelAda') && profilMuridTabelAda($db, 'student_asesmen')) {
                    foreach (guruWaliFetchAll($db, "SELECT student_id FROM student_asesmen WHERE academic_year = ? AND semester = ? AND student_id IN ($ph)",
                                              'ss' . $tp, array_merge([$ta, $sem], $ids)) as $r) $asm[$r['student_id']] = true;
                }
            }
            // Respons Formulir Awal Murid periode aktif (instrumen_guru_wali.php)
            $instrumen = function_exists('instrumenGwCapPerMurid') ? instrumenGwCapPerMurid($db, $ids, $ta, $sem) : [];
            foreach ($rows as $r) {
                $sid = (string)$r['id'];
                $murid[] = [
                    'instrumen_cap_waktu' => $instrumen[$sid] ?? null,
                    'id' => $sid, 'student_id' => $sid, 'nisn' => (string)($r['nisn'] ?? ''), 'name' => (string)$r['name'],
                    'class_id' => (string)$r['class_id'], 'class_name' => (string)$r['class_name'], 'pas_foto' => $r['pas_foto'],
                    'tanggal_mulai' => (string)$r['tanggal_mulai'], 'total_violation_points' => (int)$r['total_violation_points'],
                    'persen_hadir' => $hadir[$sid] ?? null, 'ada_biodata' => isset($bio[$sid]), 'ada_asesmen' => isset($asm[$sid]),
                ];
            }
        }
        jsonResponse(['guru' => $guru, 'periode_aktif' => ['academic_year' => $ta, 'semester' => $sem], 'murid' => $murid,
                      'ringkasan' => ['instrumen_terisi' => count(array_filter($murid, fn($x) => $x['instrumen_cap_waktu'] !== null))]]);
    });
}
