<?php
// portal_nilai.php - Portal Nilai untuk Siswa dan Orang Tua (NISN + PIN)
// Spesifikasi: docs/specs/portal-nilai-orang-tua.md
// Dimuat oleh api/index.php (action staf) dan nilai/index.php (halaman publik),
// keduanya sudah melewati gerbang install/maintenance/lisensi.
// Memakai helper dari db.php, kesiswaan_access.php, rekap_bulanan.php, dan pantau_nilai.php.
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

const PORTAL_NILAI_ALFABET     = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const PORTAL_NILAI_PIN_LEN     = 8;
const PORTAL_NILAI_SESI_DETIK  = 604800; // 7 hari
const PORTAL_NILAI_JENDELA     = 900;    // 15 menit
const PORTAL_NILAI_MAKS_NISN   = 5;
const PORTAL_NILAI_MAKS_IP     = 20;
const PORTAL_NILAI_COOKIE      = 'simantap_nilai';
const PORTAL_NILAI_MSG_DENY    = 'Anda tidak memiliki akses ke pengelolaan akses orang tua.';
const PORTAL_NILAI_SQL_AKTIF   = "(status IS NULL OR status = '' OR UPPER(status) = 'AKTIF')";
// Hash pembanding agar waktu respons login gagal setara dengan login yang diperiksa sungguhan
const PORTAL_NILAI_DUMMY_HASH  = '$2y$10$SjI/p1Pn/xGKm.QB91mIRuS6BzL1eJvkc5y./A4eRV9raSyTNkZPC';

// ============================================================
// Fungsi murni (diuji di docs/patches/uji-portal-nilai-unit.php)
// ============================================================

function portalNilaiBuatPin(): string
{
    $pin = '';
    $max = strlen(PORTAL_NILAI_ALFABET) - 1;
    for ($i = 0; $i < PORTAL_NILAI_PIN_LEN; $i++) $pin .= PORTAL_NILAI_ALFABET[random_int(0, $max)];
    return $pin;
}

function portalNilaiNormalisasiPin(?string $raw): string
{
    return strtoupper(preg_replace('/[\s\-]+/', '', (string)$raw) ?? '');
}

function portalNilaiFormatPin(string $pin): string
{
    return substr($pin, 0, 4) . '-' . substr($pin, 4);
}

/**
 * NISN hanya digit; '' bila kosong atau lebih dari 20 digit.
 */
function portalNilaiNormalisasiNisn(?string $raw): string
{
    $nisn = preg_replace('/\D+/', '', (string)$raw) ?? '';
    return strlen($nisn) > 20 ? '' : $nisn;
}

function portalNilaiB64(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function portalNilaiB64Decode(string $s): ?string
{
    if ($s === '' || preg_match('/[^A-Za-z0-9_-]/', $s)) return null;
    $d = base64_decode(strtr($s, '-_', '+/'), true);
    return $d === false ? null : $d;
}

/**
 * Nilai cookie sesi: base64url(student_id).exp.hmac. Kunci HMAC = hash PIN siswa,
 * sehingga reset PIN membatalkan semua cookie lama.
 */
function portalNilaiBuatCookie(string $studentId, int $exp, string $pinHash): string
{
    $sig = hash_hmac('sha256', $studentId . '|' . $exp, $pinHash);
    return portalNilaiB64($studentId) . '.' . $exp . '.' . $sig;
}

/**
 * Pecah cookie tanpa memverifikasi tanda tangan: ['student_id', 'exp', 'sig'] atau null.
 */
function portalNilaiBacaCookie(?string $raw): ?array
{
    $parts = explode('.', (string)$raw);
    if (count($parts) !== 3) return null;
    [$b64, $exp, $sig] = $parts;
    $sid = portalNilaiB64Decode($b64);
    if ($sid === null || $sid === '' || !ctype_digit($exp) || !preg_match('/^[0-9a-f]{64}$/', $sig)) return null;
    return ['student_id' => $sid, 'exp' => (int)$exp, 'sig' => $sig];
}

function portalNilaiCookieValid(array $cookie, string $pinHash, int $now): bool
{
    if ($cookie['exp'] <= $now) return false;
    $expected = hash_hmac('sha256', $cookie['student_id'] . '|' . $cookie['exp'], $pinHash);
    return hash_equals($expected, $cookie['sig']);
}

function portalNilaiIsHttps(array $server): bool
{
    return (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
        || (!empty($server['HTTP_X_FORWARDED_PROTO']) && $server['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($server['SERVER_PORT']) && (int)$server['SERVER_PORT'] === 443);
}

/**
 * Direktori root deploy dari SCRIPT_NAME skrip yang berada satu tingkat di bawahnya
 * (api/index.php atau nilai/index.php): "" untuk root domain, "/simantap" untuk subfolder.
 */
function portalNilaiBasePath(string $scriptName): string
{
    $dir = str_replace('\\', '/', dirname(str_replace('\\', '/', dirname($scriptName))));
    return rtrim($dir, '/.');
}

function portalNilaiUrl(array $server): string
{
    $scheme = portalNilaiIsHttps($server) ? 'https' : 'http';
    $host   = (string)($server['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . portalNilaiBasePath((string)($server['SCRIPT_NAME'] ?? '/api/index.php')) . '/nilai/';
}

// ============================================================
// Tabel, konfigurasi, throttle
// ============================================================

function portalNilaiEnsureTables(mysqli $db): void
{
    static $done = false;
    if ($done) return;
    $db->query("CREATE TABLE IF NOT EXISTS portal_nilai_akses (
        student_id        VARCHAR(128) NOT NULL PRIMARY KEY,
        pin_hash          VARCHAR(255) NOT NULL,
        dibuat_oleh       VARCHAR(128) DEFAULT NULL,
        dibuat_oleh_nama  VARCHAR(255) DEFAULT NULL,
        dibuat_pada       DATETIME     NOT NULL,
        login_terakhir    DATETIME     DEFAULT NULL,
        jumlah_login      INT          NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS portal_nilai_percobaan (
        id          BIGINT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip          VARCHAR(45) NOT NULL,
        nisn        VARCHAR(32) NOT NULL,
        berhasil    TINYINT(1)  NOT NULL,
        dibuat_pada DATETIME    NOT NULL,
        KEY idx_ip_waktu (ip, dibuat_pada),
        KEY idx_nisn_waktu (nisn, dibuat_pada)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function portalNilaiAktif(mysqli $db): bool
{
    $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = 'portal_nilai_aktif' LIMIT 1");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row !== null && trim((string)$row['config_value']) === '1';
}

/**
 * Sisa detik kunci: gagal ke-N terakhir dalam jendela 15 menit menentukan kapan kunci terbuka.
 * $kolom: 'nisn' atau 'ip'. Hasil 0 bila tidak terkunci.
 * Kolom berhasil: 0 = gagal, 1 = berhasil, 2 = gagal yang kuncinya sudah dibuka (reset PIN / login berhasil).
 * Kunci NISN hanya menghitung 0; batas IP menghitung 0 dan 2 agar login berhasil tidak menghapus jejak per IP.
 */
function portalNilaiSisaKunci(mysqli $db, string $kolom, string $nilai, int $batas, ?int $now = null): int
{
    $now ??= time();
    $sejak = date('Y-m-d H:i:s', $now - PORTAL_NILAI_JENDELA);
    $col = $kolom === 'ip' ? 'ip' : 'nisn';
    $gagal = $kolom === 'ip' ? 'berhasil IN (0, 2)' : 'berhasil = 0';
    $offset = $batas - 1;
    $stmt = $db->prepare("SELECT dibuat_pada FROM portal_nilai_percobaan
        WHERE $col = ? AND $gagal AND dibuat_pada > ?
        ORDER BY dibuat_pada DESC, id DESC LIMIT 1 OFFSET $offset");
    $stmt->bind_param("ss", $nilai, $sejak);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return 0;
    return max(1, strtotime($row['dibuat_pada']) + PORTAL_NILAI_JENDELA - $now);
}

function portalNilaiCatatPercobaan(mysqli $db, string $ip, string $nisn, bool $berhasil): void
{
    $waktu = date('Y-m-d H:i:s');
    $nisnSimpan = $nisn !== '' ? $nisn : '-';
    $ok = $berhasil ? 1 : 0;
    $stmt = $db->prepare("INSERT INTO portal_nilai_percobaan (ip, nisn, berhasil, dibuat_pada) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssis", $ip, $nisnSimpan, $ok, $waktu);
    $stmt->execute();
    // Pembersihan oportunistik
    if (random_int(1, 100) === 1) {
        $batas = date('Y-m-d H:i:s', time() - 7 * 86400);
        $stmtDel = $db->prepare("DELETE FROM portal_nilai_percobaan WHERE dibuat_pada < ?");
        $stmtDel->bind_param("s", $batas);
        $stmtDel->execute();
    }
}

/**
 * Buka kunci NISN (reset PIN / login berhasil). Baris tidak dihapus agar tetap dihitung untuk batas per IP.
 */
function portalNilaiBukaKunci(mysqli $db, string $nisn): void
{
    if ($nisn === '') return;
    $stmt = $db->prepare("UPDATE portal_nilai_percobaan SET berhasil = 2 WHERE nisn = ? AND berhasil = 0");
    $stmt->bind_param("s", $nisn);
    $stmt->execute();
}

/**
 * Jumlah siswa aktif per NISN (untuk mendeteksi NISN ganda).
 */
function portalNilaiHitungNisn(mysqli $db, array $nisnList): array
{
    $nisnList = array_values(array_unique(array_filter($nisnList, fn($n) => $n !== '')));
    if (!$nisnList) return [];
    $ph = implode(',', array_fill(0, count($nisnList), '?'));
    $stmt = $db->prepare("SELECT nisn, COUNT(*) AS c FROM students WHERE nisn IN ($ph) AND " . PORTAL_NILAI_SQL_AKTIF . " GROUP BY nisn");
    $stmt->bind_param(str_repeat('s', count($nisnList)), ...$nisnList);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[(string)$r['nisn']] = (int)$r['c'];
    return $out;
}

/**
 * Presensi semester satu siswa (filter tanggal, sama dengan cetak_rekap_nilai_siswa).
 */
function portalNilaiPresensi(mysqli $db, string $studentId, ?array $range): array
{
    $sql = "SELECT status, COUNT(*) AS c FROM attendance_records WHERE student_id = ?";
    $types = 's'; $params = [$studentId];
    if ($range) { $sql .= " AND date BETWEEN ? AND ?"; $types .= 'ss'; $params[] = $range[0]; $params[] = $range[1]; }
    $sql .= " GROUP BY status";
    $out = ['HADIR' => 0, 'SAKIT' => 0, 'IZIN' => 0, 'ALFA' => 0];
    foreach (rekapFetchAll($db, $sql, $types, $params) as $a) {
        $st = pantauNilaiAttendanceStatus($a['status']);
        if ($st !== null) $out[$st] += (int)$a['c'];
    }
    return $out;
}

// ============================================================
// Akses staf
// ============================================================

/**
 * ADMIN -> ALL; GURU dengan class_homeroom (BK atau bukan) -> CLASS; lainnya -> DENY.
 */
function portalNilaiScope(mysqli $db, array $sessionUser): array
{
    $fresh = loadFreshUser($db, $sessionUser);
    if ($fresh['role'] === 'ADMIN') return ['mode' => 'ALL', 'classKeys' => [], 'classId' => null, 'role' => 'ADMIN'];
    if ($fresh['role'] === 'GURU') {
        $homeroom = trim((string)($fresh['class_homeroom'] ?? ''));
        if ($homeroom !== '') return homeroomClassScope($db, $homeroom) + ['role' => 'GURU'];
    }
    return ['mode' => 'DENY', 'classKeys' => [], 'classId' => null, 'role' => (string)$fresh['role']];
}

/**
 * Kelas dari parameter (wali: default kelas perwalian). $fail(int, string) menghentikan request.
 */
function portalNilaiKelas(mysqli $db, array $scope, string $rawClass, callable $fail): array
{
    $rawClass = trim($rawClass);
    if ($scope['mode'] === 'CLASS') {
        if ($rawClass === '') $rawClass = (string)$scope['classId'];
        elseif (!classInScope($scope, $rawClass)) $fail(403, KESISWAAN_MSG_KELAS);
    } elseif ($rawClass === '') {
        $fail(400, 'Parameter class_id wajib diisi.');
    }
    $classRef = resolveClassRef($db, $rawClass);
    if ($scope['mode'] === 'CLASS' && !classInScope($scope, $classRef['id'])) $fail(403, KESISWAAN_MSG_KELAS);
    return $classRef;
}

/**
 * Siswa aktif kelas beserta status akses portalnya.
 */
function portalNilaiDaftarSiswa(mysqli $db, string $classId): array
{
    portalNilaiEnsureTables($db);
    $students = pantauNilaiStudents($db, $classId);
    if (!$students) return [];

    $nisnMap = [];
    foreach ($students as $sid => $st) $nisnMap[(string)$sid] = portalNilaiNormalisasiNisn($st['nisn']);
    $jumlahNisn = portalNilaiHitungNisn($db, array_values(array_map(fn($st) => (string)$st['nisn'], $students)));

    $ids = array_map('strval', array_keys($students));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $akses = [];
    foreach (rekapFetchAll($db, "SELECT student_id, dibuat_pada, login_terakhir, jumlah_login FROM portal_nilai_akses WHERE student_id IN ($ph)",
        str_repeat('s', count($ids)), $ids) as $r) {
        $akses[(string)$r['student_id']] = $r;
    }

    $rows = [];
    foreach ($students as $sid => $st) {
        $sid = (string)$sid;
        $nisn = $nisnMap[$sid];
        $a = $akses[$sid] ?? null;
        $lockedUntil = null;
        if ($nisn === '') {
            $status = 'NISN_KOSONG';
        } elseif (($jumlahNisn[(string)$st['nisn']] ?? 1) > 1) {
            $status = 'NISN_GANDA';
        } elseif (!$a) {
            $status = 'BELUM';
        } else {
            $sisa = portalNilaiSisaKunci($db, 'nisn', $nisn, PORTAL_NILAI_MAKS_NISN);
            $status = $sisa > 0 ? 'TERKUNCI' : 'AKTIF';
            if ($sisa > 0) $lockedUntil = date('Y-m-d H:i:s', time() + $sisa);
        }
        $rows[] = [
            'student_id'     => $sid,
            'student_name'   => $st['name'],
            'nisn'           => (string)$st['nisn'],
            'pin_status'     => $status,
            'pin_created_at' => $a['dibuat_pada'] ?? null,
            'locked_until'   => $lockedUntil,
            'last_login_at'  => $a['login_terakhir'] ?? null,
            'login_count'    => $a ? (int)$a['jumlah_login'] : 0,
        ];
    }
    return $rows;
}

/**
 * Action portal_nilai_status (GET, JSON).
 */
function handlePortalNilaiStatus(mysqli $db): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        errorResponse('Metode tidak diizinkan', 405);
    }
    $user  = requireAuth();
    $scope = portalNilaiScope($db, $user);
    if ($scope['mode'] === 'DENY') errorResponse(PORTAL_NILAI_MSG_DENY, 403);
    $classRef = portalNilaiKelas($db, $scope, (string)($_GET['class_id'] ?? ''), fn(int $s, string $m) => errorResponse($m, $s));

    try {
        $students = portalNilaiDaftarSiswa($db, $classRef['id']);
        $aktif = portalNilaiAktif($db);
    } catch (Throwable $e) {
        error_log('[SIMANTAP] portal_nilai_status: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        errorResponse('Gagal memuat status akses orang tua', 500);
    }

    $summary = ['total' => count($students), 'belum' => 0, 'aktif' => 0, 'terkunci' => 0, 'tidak_bisa' => 0];
    foreach ($students as $s) {
        if ($s['pin_status'] === 'BELUM') $summary['belum']++;
        elseif ($s['pin_status'] === 'AKTIF') $summary['aktif']++;
        elseif ($s['pin_status'] === 'TERKUNCI') $summary['terkunci']++;
        else $summary['tidak_bisa']++;
    }

    jsonResponse([
        'aktif'      => $aktif,
        'portal_url' => portalNilaiUrl($_SERVER),
        'class'      => $classRef,
        'is_admin'   => $scope['role'] === 'ADMIN',
        'students'   => $students,
        'summary'    => $summary,
    ]);
}

/**
 * Action portal_nilai_set_aktif (POST, JSON, ADMIN).
 */
function handlePortalNilaiSetAktif(mysqli $db): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        errorResponse('Metode tidak diizinkan', 405);
    }
    $user = requireAuth();
    $fresh = loadFreshUser($db, $user);
    if ($fresh['role'] !== 'ADMIN') errorResponse('Hanya Admin yang dapat mengubah status portal nilai.', 403);

    $body = getRequestBody();
    if (!array_key_exists('aktif', $body) || !is_bool($body['aktif'])) {
        errorResponse('Parameter aktif wajib bernilai true atau false.', 400);
    }
    $nilai = $body['aktif'] ? '1' : '0';
    $desc  = 'Portal nilai siswa/orang tua (1 = aktif)';
    $stmt = $db->prepare("INSERT INTO system_config (config_key, config_value, description) VALUES ('portal_nilai_aktif', ?, ?)
        ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
    $stmt->bind_param("ss", $nilai, $desc);
    $stmt->execute();
    jsonResponse(['success' => true, 'aktif' => $body['aktif']]);
}

// ============================================================
// Cetak kartu akses (POST form -> HTML). PIN hanya muncul di halaman ini.
// ============================================================

function portalNilaiHtmlError(int $status, string $message): void
{
    rekapSendHeaders($status);
    header('Referrer-Policy: no-referrer');
    $extra = $status === 401 ? '<p><a href="../">Buka halaman login</a></p>' : '';
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Kartu Akses - ' . $status . '</title>'
        . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;color:#0f172a;margin:0;padding:40px 16px}'
        . '.box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px}'
        . 'h1{font-size:18px;margin:0 0 8px}p{margin:8px 0;line-height:1.5}a{color:#047857}</style></head><body>'
        . '<div class="box"><h1>' . ($status < 400 ? 'Kartu akses' : 'Kartu akses tidak dapat dibuat') . '</h1>'
        . '<p>' . rekapEsc($message) . '</p>' . $extra . '</div></body></html>';
    exit;
}

/**
 * Permintaan harus berasal dari host yang sama (Origin, atau Referer bila Origin tidak ada).
 */
function portalNilaiAsalSama(array $server): bool
{
    $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
    $src  = (string)($server['HTTP_ORIGIN'] ?? '');
    if ($src === '' || $src === 'null') $src = (string)($server['HTTP_REFERER'] ?? '');
    if ($src === '' || $host === '') return false;
    $srcHost = strtolower((string)(parse_url($src, PHP_URL_HOST) ?? ''));
    $srcPort = parse_url($src, PHP_URL_PORT);
    if ($srcPort) $srcHost .= ':' . $srcPort;
    return $srcHost === $host;
}

/**
 * Action cetak_kartu_akses.
 */
function handleCetakKartuAkses(mysqli $db): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        portalNilaiHtmlError(405, 'Metode tidak diizinkan. Buat kartu melalui tombol di panel Akses Orang Tua.');
    }
    $sessionUser = getCurrentUser();
    if (!$sessionUser || empty($sessionUser['uid'])) portalNilaiHtmlError(401, 'Sesi Anda telah berakhir. Silakan login ulang.');

    try {
        $meRows = rekapFetchAll($db, "SELECT uid, displayName FROM users WHERE uid = ? LIMIT 1", 's', [$sessionUser['uid']]);
        if (!$meRows) portalNilaiHtmlError(401, 'Sesi Anda telah berakhir. Silakan login ulang.');
        $me = $meRows[0];

        $scope = portalNilaiScope($db, $sessionUser);
        if ($scope['mode'] === 'DENY') portalNilaiHtmlError(403, PORTAL_NILAI_MSG_DENY);
        if (!portalNilaiAsalSama($_SERVER)) portalNilaiHtmlError(403, 'Permintaan ditolak karena tidak berasal dari aplikasi ini.');

        $classRef = portalNilaiKelas($db, $scope, (string)($_POST['class_id'] ?? ''), fn(int $s, string $m) => portalNilaiHtmlError($s, $m));

        $mode = (string)($_POST['mode'] ?? '');
        if (!in_array($mode, ['baru', 'reset'], true)) portalNilaiHtmlError(400, 'Mode tidak valid.');
        $ids = $_POST['student_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map(fn($v) => is_scalar($v) ? trim((string)$v) : '', $ids), fn($v) => $v !== '')));
        if ($mode === 'reset' && !$ids) portalNilaiHtmlError(400, 'Pilih minimal satu siswa untuk direset.');
        $pilih = $ids ? array_flip($ids) : null;

        // Siswa yang diproses: siswa aktif kelas ini dengan NISN valid dan tidak ganda
        $target = [];
        foreach (portalNilaiDaftarSiswa($db, $classRef['id']) as $s) {
            if ($pilih !== null && !isset($pilih[$s['student_id']])) continue;
            if (in_array($s['pin_status'], ['NISN_KOSONG', 'NISN_GANDA'], true)) continue;
            if ($mode === 'baru' && $s['pin_status'] !== 'BELUM') continue;
            $target[] = $s;
        }
        if (!$target) {
            portalNilaiHtmlError(200, $mode === 'baru'
                ? 'Tidak ada siswa yang perlu dibuatkan PIN. Semua siswa yang memenuhi syarat sudah memiliki PIN.'
                : 'Tidak ada siswa terpilih yang dapat direset PIN-nya.');
        }

        $kartu = [];
        $waktu = date('Y-m-d H:i:s');
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("REPLACE INTO portal_nilai_akses
                (student_id, pin_hash, dibuat_oleh, dibuat_oleh_nama, dibuat_pada, login_terakhir, jumlah_login)
                VALUES (?, ?, ?, ?, ?, NULL, 0)");
            foreach ($target as $s) {
                $pin  = portalNilaiBuatPin();
                $hash = password_hash($pin, PASSWORD_DEFAULT);
                $stmt->bind_param("sssss", $s['student_id'], $hash, $me['uid'], $me['displayName'], $waktu);
                $stmt->execute();
                portalNilaiBukaKunci($db, portalNilaiNormalisasiNisn($s['nisn']));
                $kartu[] = ['name' => $s['student_name'], 'nisn' => $s['nisn'], 'pin' => portalNilaiFormatPin($pin)];
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        $kop = rekapLoadKop($db);
        portalNilaiRenderKartu([
            'sekolah' => ($kop['kop_sekolah'] ?? '') !== '' ? $kop['kop_sekolah'] : 'SIMANTAP',
            'kelas'   => $classRef['name'] !== '' ? $classRef['name'] : $classRef['id'],
            'url'     => portalNilaiUrl($_SERVER),
            'mode'    => $mode,
            'kartu'   => $kartu,
            'oleh'    => (string)$me['displayName'],
            'waktu'   => new DateTimeImmutable('now'),
        ]);
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        error_log('[SIMANTAP] cetak_kartu_akses: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        portalNilaiHtmlError(500, 'Gagal membuat kartu akses. Coba lagi atau hubungi admin.');
    }
}

function portalNilaiRenderKartu(array $ctx): void
{
    $e = 'rekapEsc';
    $jumlah = count($ctx['kartu']);
    $fileTitle = preg_replace('/[^A-Za-z0-9_-]/', '_', 'Kartu_Akses_Nilai_' . $ctx['kelas']);

    rekapSendHeaders(200);
    header('Referrer-Policy: no-referrer');
    ob_start();
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($fileTitle) ?></title>
<style>
  @page { size: A4 portrait; margin: 10mm; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e2e8f0; color: #000; font-family: Arial, Helvetica, sans-serif; }
  .panel { max-width: 210mm; margin: 16px auto 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 16px; font-size: 13px; color: #0f172a; }
  .panel h2 { margin: 0 0 8px; font-size: 16px; }
  .panel .notice { margin: 8px 0; padding: 8px 10px; border-radius: 8px; background: #fef3c7; color: #78350f; line-height: 1.5; }
  .panel .btn { padding: 8px 14px; border-radius: 8px; border: 1px solid #059669; background: #059669; color: #fff; font-weight: bold; cursor: pointer; font-size: 13px; }
  .sheet { width: 210mm; margin: 16px auto; padding: 10mm; background: #fff; box-shadow: 0 4px 16px rgba(15, 23, 42, .15); }
  .grid { display: grid; grid-template-columns: 1fr 1fr; }
  .kartu { height: 54mm; border: 1px dashed #64748b; padding: 4mm 5mm; break-inside: avoid; page-break-inside: avoid; display: flex; flex-direction: column; justify-content: space-between; }
  .sekolah { font-size: 8pt; font-weight: bold; text-transform: uppercase; color: #334155; }
  .judul { font-size: 10pt; font-weight: bold; margin-top: 1mm; }
  .data { font-size: 9pt; line-height: 1.45; }
  .data b { display: inline-block; width: 13mm; font-weight: normal; color: #475569; }
  .pin { font-family: "Courier New", Courier, monospace; font-size: 17pt; font-weight: bold; letter-spacing: 1px; }
  .url { font-size: 8pt; word-break: break-all; }
  .cara { font-size: 7pt; color: #475569; }
  @media screen and (max-width: 840px) { .panel { margin: 12px 16px 0; } .sheet { width: auto; margin: 12px 16px; padding: 12px; } .grid { grid-template-columns: 1fr; } }
  @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; } }
</style>
</head>
<body>
<div class="panel no-print">
  <h2>Kartu Akses Nilai — Kelas <?= $e($ctx['kelas']) ?> (<?= $jumlah ?> kartu, <?= $ctx['mode'] === 'reset' ? 'PIN direset' : 'PIN baru' ?>)</h2>
  <p class="notice"><b>PIN hanya ditampilkan sekali.</b> Cetak atau simpan sebagai PDF sekarang; setelah halaman ini ditutup PIN tidak dapat dilihat lagi dan harus direset.
    <?php if ($ctx['mode'] === 'reset'): ?> PIN lama siswa di bawah sudah tidak berlaku.<?php endif; ?></p>
  <button type="button" class="btn" onclick="window.print()">Cetak / Simpan PDF</button>
</div>
<div class="sheet">
  <div class="grid">
    <?php foreach ($ctx['kartu'] as $k): ?>
      <div class="kartu">
        <div>
          <div class="sekolah"><?= $e($ctx['sekolah']) ?></div>
          <div class="judul">Kartu Akses Nilai Siswa</div>
        </div>
        <div class="data">
          <div><b>Nama</b> <?= $e($k['name']) ?></div>
          <div><b>Kelas</b> <?= $e($ctx['kelas']) ?></div>
          <div><b>NISN</b> <?= $e($k['nisn']) ?></div>
          <div><b>PIN</b> <span class="pin"><?= $e($k['pin']) ?></span></div>
        </div>
        <div>
          <div class="url"><?= $e($ctx['url']) ?></div>
          <div class="cara">Buka alamat di atas, lalu masukkan NISN dan PIN.</div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
<?php
    echo ob_get_clean();
}
