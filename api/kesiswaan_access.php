<?php
// kesiswaan_access.php - Otorisasi modul Kesiswaan (presensi kelas, insiden, panggilan ortu)
// Spesifikasi: docs/specs/menu-kesiswaan-wali-kelas.md
// Hanya boleh dimuat lewat api/index.php (yang sudah melewati gerbang lisensi)
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

const KESISWAAN_MSG_KELAS = 'Anda hanya dapat mengakses data kelas perwalian Anda.';

/**
 * Kunci kelas: lowercase tanpa spasi, "_", "-", dan "." (sama dengan L$ di frontend).
 */
function normalizeClassKey(?string $value): string
{
    return preg_replace('/[\s_.\-]/', '', strtolower(trim((string)$value)));
}

/**
 * Ambil ulang data user dari DB agar perubahan role/wali kelas langsung berlaku.
 */
function loadFreshUser(mysqli $db, array $sessionUser): array
{
    $uid = $sessionUser['uid'] ?? '';
    $stmt = $db->prepare("SELECT uid, displayName, role, class_homeroom, is_bk FROM users WHERE uid = ? LIMIT 1");
    $stmt->bind_param("s", $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) errorResponse('Unauthorized', 401);
    return $row;
}

/**
 * Tentukan cakupan akses Kesiswaan untuk user.
 * $feature: attendance_read | attendance_write | incident | incident_delete | parent_call
 * Hasil: ['mode' => 'ALL'|'CLASS'|'DENY', 'classKeys' => string[], 'classId' => ?string]
 */
function kesiswaanScope(mysqli $db, array $sessionUser, string $feature): array
{
    $fresh = loadFreshUser($db, $sessionUser);
    $deny  = ['mode' => 'DENY', 'classKeys' => [], 'classId' => null];
    $all   = ['mode' => 'ALL',  'classKeys' => [], 'classId' => null];

    switch ($fresh['role']) {
        case 'ADMIN':
            return $all;

        case 'KEPALA_SEKOLAH':
            // Kepala Sekolah hanya baca untuk presensi
            return $feature === 'attendance_write' ? $deny : $all;

        case 'GURU':
            if ((int)$fresh['is_bk'] === 1) {
                $bkFeatures = ['incident', 'incident_delete', 'parent_call'];
                return in_array($feature, $bkFeatures, true) ? $all : $deny;
            }
            $homeroom = trim((string)($fresh['class_homeroom'] ?? ''));
            if ($homeroom === '' || $feature === 'incident_delete') return $deny;

            $homeKey   = normalizeClassKey($homeroom);
            $classKeys = [$homeKey];
            $classId   = $homeroom;
            try {
                $res = $db->query("SELECT id, name FROM classes");
                while ($res && ($c = $res->fetch_assoc())) {
                    if (normalizeClassKey($c['id']) !== $homeKey) continue;
                    $classId     = $c['id'];
                    $classKeys[] = normalizeClassKey($c['id']);
                    $classKeys[] = normalizeClassKey($c['name']);
                }
            } catch (Throwable $e) {
                // Tabel classes belum ada: cukup pakai class_homeroom
            }
            $classKeys = array_values(array_unique(array_filter($classKeys, fn($k) => $k !== '')));
            return ['mode' => 'CLASS', 'classKeys' => $classKeys, 'classId' => $classId];

        default:
            return $deny;
    }
}

/**
 * Seperti kesiswaanScope, tetapi langsung 403 bila tidak punya akses.
 */
function requireKesiswaanScope(mysqli $db, array $sessionUser, string $feature): array
{
    $scope = kesiswaanScope($db, $sessionUser, $feature);
    if ($scope['mode'] === 'DENY') errorResponse('Anda tidak memiliki akses ke fitur Kesiswaan ini.', 403);
    return $scope;
}

/**
 * Apakah id/nama kelas termasuk cakupan.
 */
function classInScope(array $scope, ?string $classIdOrName): bool
{
    if ($scope['mode'] === 'ALL') return true;
    if ($scope['mode'] !== 'CLASS') return false;
    $key = normalizeClassKey($classIdOrName);
    return $key !== '' && in_array($key, $scope['classKeys'], true);
}

/**
 * Apakah siswa (berdasarkan kelas saat ini di tabel students) termasuk cakupan.
 */
function studentInScope(mysqli $db, array $scope, ?string $studentId): bool
{
    if ($scope['mode'] === 'ALL') return true;
    if ($scope['mode'] !== 'CLASS' || empty($studentId)) return false;
    $stmt = $db->prepare("SELECT class_id, class_name FROM students WHERE id = ? LIMIT 1");
    $stmt->bind_param("s", $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return false;
    return classInScope($scope, $row['class_id']) || classInScope($scope, $row['class_name']);
}

/**
 * Panggilan ortu termasuk cakupan bila kelas siswa saat ini atau class_name panggilan cocok.
 */
function parentCallInScope(array $scope, ?string $studentClassId, ?string $className): bool
{
    return classInScope($scope, $studentClassId) || classInScope($scope, $className);
}

/**
 * Normalisasi status presensi: ALPHA (frontend) -> ALFA (DB). Status lain -> 400.
 */
function normalizeAttendanceStatus(?string $status): string
{
    $s = strtoupper(trim((string)($status ?? 'HADIR')));
    if ($s === '') $s = 'HADIR';
    if ($s === 'ALPHA') $s = 'ALFA';
    if (!in_array($s, ['HADIR', 'SAKIT', 'IZIN', 'ALFA'], true)) {
        errorResponse('Status presensi tidak valid: ' . $s, 400);
    }
    return $s;
}

/**
 * Tahun ajaran & semester aktif dari system_config (fallback sama dengan save_journal).
 */
function activeAcademicPeriod(mysqli $db): array
{
    $config = ['active_academic_year' => '2025/2026', 'active_semester' => 'GANJIL'];
    $stmt = $db->prepare("SELECT config_key, config_value FROM system_config WHERE config_key IN ('active_academic_year', 'active_semester')");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (trim((string)$row['config_value']) !== '') $config[$row['config_key']] = $row['config_value'];
    }
    return [$config['active_academic_year'], $config['active_semester']];
}
