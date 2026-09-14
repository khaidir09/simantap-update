<?php
// ekskul_access.php - Otorisasi modul Ekstrakurikuler (admin, kepala sekolah, guru pembina, wali kelas)
// Spesifikasi: docs/specs/pembina-ekstrakurikuler.md
// Hanya boleh dimuat lewat api/index.php (setelah kesiswaan_access.php)
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/kesiswaan_access.php';

const EKSKUL_MSG_MODUL   = 'Anda tidak memiliki akses ke modul Ekstrakurikuler.';
const EKSKUL_MSG_LIHAT   = 'Anda tidak memiliki akses ke data ekstrakurikuler ini.';
const EKSKUL_MSG_KELOLA  = 'Anda tidak memiliki akses untuk mengelola ekstrakurikuler ini.';
const EKSKUL_LABEL_TANPA_PEMBINA = 'Belum ada pembina';

/**
 * Konteks otorisasi: data user segar dari DB + cakupan kelas perwalian (khusus GURU wali).
 */
function ekskulContext(mysqli $db, array $sessionUser): array
{
    $fresh = loadFreshUser($db, $sessionUser);
    return ekskulContextFromUser($db, $fresh);
}

function ekskulContextFromUser(mysqli $db, array $fresh): array
{
    $homeroom = trim((string)($fresh['class_homeroom'] ?? ''));
    return [
        'user'          => $fresh,
        'role'          => $fresh['role'],
        'homeroomScope' => ($fresh['role'] === 'GURU' && $homeroom !== '') ? homeroomClassScope($db, $homeroom) : null,
    ];
}

/**
 * Tolak role yang sama sekali tidak memakai modul ekskul (mis. TATA_USAHA).
 */
function requireEkskulModule(array $ctx): void
{
    if (!in_array($ctx['role'], ['ADMIN', 'KEPALA_SEKOLAH', 'GURU'], true)) {
        errorResponse(EKSKUL_MSG_MODUL, 403);
    }
}

/**
 * Mutasi tidak boleh lewat GET (bundle memakai POST/DELETE).
 */
function requireMutationMethod(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        errorResponse('Metode tidak diizinkan', 405);
    }
}

/**
 * Siswa perwalian dan ekskul terkait wali. Hasil di-cache per user selama request.
 * Hasil: ['studentIds' => [id => true], 'ekskulCounts' => [ekskul_id => jumlah anggota AKTIF perwalian]]
 */
function ekskulHomeroomData(mysqli $db, array $ctx): array
{
    static $cache = [];
    $empty = ['studentIds' => [], 'ekskulCounts' => []];
    if ($ctx['homeroomScope'] === null) return $empty;

    $uid = $ctx['user']['uid'];
    if (isset($cache[$uid])) return $cache[$uid];

    $scope = $ctx['homeroomScope'];
    $studentIds = [];
    $res = $db->query("SELECT id, class_id, class_name FROM students");
    while ($res && ($s = $res->fetch_assoc())) {
        if (classInScope($scope, $s['class_id']) || classInScope($scope, $s['class_name'])) {
            $studentIds[$s['id']] = true;
        }
    }

    // Anggota yang baris siswanya sudah tidak ada memakai kelas yang tercatat di keanggotaan
    $ekskulCounts = [];
    $res = $db->query("SELECT em.extracurricular_id, em.student_id, em.class_id, em.class_name, s.id AS sid
                       FROM extracurricular_members em
                       LEFT JOIN students s ON s.id = em.student_id
                       WHERE em.status = 'AKTIF'");
    while ($res && ($m = $res->fetch_assoc())) {
        if ($m['sid'] !== null) {
            $inScope = isset($studentIds[$m['student_id']]);
        } else {
            $inScope = classInScope($scope, $m['class_id']) || classInScope($scope, $m['class_name']);
            if ($inScope) $studentIds[$m['student_id']] = true;
        }
        if ($inScope) {
            $ekskulCounts[$m['extracurricular_id']] = ($ekskulCounts[$m['extracurricular_id']] ?? 0) + 1;
        }
    }

    return $cache[$uid] = ['studentIds' => $studentIds, 'ekskulCounts' => $ekskulCounts];
}

/**
 * Level akses user terhadap satu ekskul: ADMIN | MONITOR | COACH | HOMEROOM | NONE.
 * $ekskulRow minimal berisi id dan coachId.
 */
function ekskulAccessLevel(mysqli $db, array $ctx, array $ekskulRow): string
{
    switch ($ctx['role']) {
        case 'ADMIN':
            return 'ADMIN';
        case 'KEPALA_SEKOLAH':
            return 'MONITOR';
        case 'GURU':
            $coachId = (string)($ekskulRow['coachId'] ?? '');
            if ($coachId !== '' && $coachId === (string)$ctx['user']['uid']) return 'COACH';
            $homeroom = ekskulHomeroomData($db, $ctx);
            if (isset($homeroom['ekskulCounts'][$ekskulRow['id']])) return 'HOMEROOM';
            return 'NONE';
        default:
            return 'NONE';
    }
}

/**
 * Flag izin untuk frontend.
 */
function ekskulAccessFlags(string $level): array
{
    $admin = $level === 'ADMIN';
    $coach = $level === 'COACH';
    return [
        'level'               => $level,
        'canEditInfo'         => $admin || $coach,
        'canAssignCoach'      => $admin,
        'canDelete'           => $admin,
        'canManageMembers'    => $admin || $coach,
        'canManageActivities' => $admin || $coach,
    ];
}

function loadEkskulRow(mysqli $db, string $ekskulId): ?array
{
    if ($ekskulId === '') return null;
    $stmt = $db->prepare("SELECT * FROM extracurriculars WHERE id = ? LIMIT 1");
    $stmt->bind_param("s", $ekskulId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Muat ekskul dan pastikan level user termasuk $allowedLevels.
 * Mutasi: 404 bila ekskul tidak ada. Baca: 404 hanya untuk ADMIN/KS, selain itu 403.
 * Hasil: [$ekskulRow, $level]
 */
function requireEkskulLevel(mysqli $db, array $ctx, string $ekskulId, array $allowedLevels, bool $isMutation): array
{
    requireEkskulModule($ctx);
    $row = loadEkskulRow($db, $ekskulId);
    if (!$row) {
        if ($isMutation || in_array($ctx['role'], ['ADMIN', 'KEPALA_SEKOLAH'], true)) {
            errorResponse('Ekstrakurikuler tidak ditemukan', 404);
        }
        errorResponse(EKSKUL_MSG_LIHAT, 403);
    }
    $level = ekskulAccessLevel($db, $ctx, $row);
    if (!in_array($level, $allowedLevels, true)) {
        errorResponse($isMutation ? EKSKUL_MSG_KELOLA : EKSKUL_MSG_LIHAT, 403);
    }
    return [$row, $level];
}

/**
 * Validasi pembina: kosong = belum ada pembina; selain itu wajib akun role GURU.
 * Hasil: [coachId, coachName]
 */
function resolveCoachAssignment(mysqli $db, $coachId): array
{
    $coachId = trim((string)$coachId);
    if ($coachId === '') return ['', ''];
    $stmt = $db->prepare("SELECT displayName, role FROM users WHERE uid = ? LIMIT 1");
    $stmt->bind_param("s", $coachId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || $row['role'] !== 'GURU') {
        errorResponse('Pembina harus akun guru yang terdaftar.', 400);
    }
    return [$coachId, (string)$row['displayName']];
}

/**
 * Batasi presensi ke siswa tertentu. Mendukung format map {student_id: ...} dan array [{student_id, ...}].
 */
function filterAttendanceToStudents($attendance, array $studentIds)
{
    if (!is_array($attendance) || empty($attendance)) return [];
    if (array_is_list($attendance)) {
        return array_values(array_filter($attendance, fn($item) =>
            is_array($item) && isset($item['student_id']) && isset($studentIds[$item['student_id']])));
    }
    return array_filter($attendance, fn($studentId) => isset($studentIds[$studentId]), ARRAY_FILTER_USE_KEY);
}

/**
 * Kosongkan penugasan pembina milik uid (akun dihapus atau role bukan GURU lagi).
 */
function clearCoachAssignment(mysqli $db, string $uid): void
{
    if ($uid === '') return;
    try {
        $stmt = $db->prepare("UPDATE extracurriculars SET coachId = '', coachName = '' WHERE coachId = ?");
        $stmt->bind_param("s", $uid);
        $stmt->execute();
    } catch (Throwable $e) {
        // Tabel ekskul bermasalah: jangan gagalkan operasi akun
    }
}

/**
 * Hitungan untuk menampilkan menu ekskul di sidebar (dipakai login & session).
 * Tidak pernah memanggil errorResponse; kegagalan menghasilkan 0.
 */
function ekskulMenuCounts(mysqli $db, array $sessionUser): array
{
    $counts = ['ekskul_coach_count' => 0, 'ekskul_homeroom_count' => 0];
    try {
        $uid = (string)($sessionUser['uid'] ?? '');
        $stmt = $db->prepare("SELECT uid, displayName, role, class_homeroom, is_bk FROM users WHERE uid = ? LIMIT 1");
        $stmt->bind_param("s", $uid);
        $stmt->execute();
        $fresh = $stmt->get_result()->fetch_assoc();
        if (!$fresh || $fresh['role'] !== 'GURU') return $counts;

        $ctx = ekskulContextFromUser($db, $fresh);
        $homeroom = ekskulHomeroomData($db, $ctx);
        $res = $db->query("SELECT id, coachId FROM extracurriculars");
        while ($res && ($e = $res->fetch_assoc())) {
            if ((string)$e['coachId'] !== '' && $e['coachId'] === $fresh['uid']) {
                $counts['ekskul_coach_count']++;
            } elseif (isset($homeroom['ekskulCounts'][$e['id']])) {
                $counts['ekskul_homeroom_count']++;
            }
        }
    } catch (Throwable $e) {
        return ['ekskul_coach_count' => 0, 'ekskul_homeroom_count' => 0];
    }
    return $counts;
}
