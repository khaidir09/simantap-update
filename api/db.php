<?php
// Mencegah PHP mencetak error HTML (seperti <br><b>Warning...</b>) yang merusak JSON
error_reporting(0);
ini_set('display_errors', '0');

// Database configuration.
// config.local.php dihasilkan oleh installer (/install) — jika ada, nilainya menang.
// Default di bawah hanya fallback untuk lingkungan development.
if (is_readable(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'simantap_prod');
if (!defined('DB_PORT')) define('DB_PORT', 3306);

// Zona waktu dari konfigurasi installer (default WITA untuk Kalsel)
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'Asia/Makassar');
date_default_timezone_set(APP_TIMEZONE);

function getDB(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        // Menggunakan @ untuk menyembunyikan warning bawaan PHP jika koneksi gagal
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function jsonResponse(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function errorResponse(string $message, int $status = 400): void
{
    jsonResponse(['error' => $message], $status);
}

function getRequestBody(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function getCurrentUser(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return $_SESSION['user'] ?? null;
}

function requireAuth(): array
{
    $user = getCurrentUser();
    if (!$user) {
        errorResponse('Unauthorized', 401);
    }
    return $user;
}

function requireRole(array $roles): array
{
    $user = requireAuth();
    if (!in_array($user['role'], $roles)) {
        errorResponse('Forbidden', 403);
    }
    return $user;
}

// Tahun ajaran & semester aktif dari system_config (fallback ke nilai lama)
function getActiveAcademicPeriod(mysqli $db): array
{
    $period = ['academic_year' => '2025/2026', 'semester' => 'GANJIL'];
    $res = $db->query("SELECT config_key, config_value FROM system_config WHERE config_key IN ('active_academic_year','active_semester')");
    while ($res && ($row = $res->fetch_assoc())) {
        $val = trim((string)$row['config_value']);
        if ($val === '') continue;
        if ($row['config_key'] === 'active_academic_year') $period['academic_year'] = $val;
        else $period['semester'] = strtoupper($val);
    }
    return $period;
}

// Rentang tanggal semester: GANJIL = Juli–Desember tahun pertama, GENAP = Januari–Juni tahun kedua.
// Mengembalikan null jika format tahun ajaran tidak dikenali (tanpa filter tanggal).
function getSemesterDateRange(string $academicYear, string $semester): ?array
{
    if (!preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', $academicYear, $m)) return null;
    if (strtoupper($semester) === 'GENAP') return [$m[2] . '-01-01', $m[2] . '-06-30'];
    return [$m[1] . '-07-01', $m[1] . '-12-31'];
}

// Daftar siswa aktif sebuah kelas: student_id => ['name' => ..., 'pas_foto' => ...], urut nama
function getActiveClassStudents(mysqli $db, string $classId): array
{
    $stmt = $db->prepare("
        SELECT id, name, IF(JSON_VALID(docs_json), JSON_UNQUOTE(JSON_EXTRACT(docs_json, '$.pas_foto')), NULL) AS pas_foto
        FROM students
        WHERE class_id = ? AND (status IS NULL OR status = '' OR UPPER(status) = 'AKTIF')
        ORDER BY name ASC
    ");
    $stmt->bind_param("s", $classId);
    $stmt->execute();
    $res = $stmt->get_result();
    $students = [];
    while ($row = $res->fetch_assoc()) {
        $students[$row['id']] = ['name' => $row['name'], 'pas_foto' => $row['pas_foto']];
    }
    return $students;
}

// Apakah guru mengampu mapel $subjectId di kelas $classId (berdasarkan teacher_subjects).
// class_ids diutamakan; jika kosong, dicocokkan lewat class_level (sama seperti filter kelas di frontend).
function teacherTeachesClassSubject(mysqli $db, string $teacherId, string $classId, string $subjectId): bool
{
    $stmt = $db->prepare("SELECT class_level, class_ids FROM teacher_subjects WHERE teacher_id = ? AND subject_code = ?");
    $stmt->bind_param("ss", $teacherId, $subjectId);
    $stmt->execute();
    $res = $stmt->get_result();

    $classLevel = null;
    while ($row = $res->fetch_assoc()) {
        $ids = json_decode($row['class_ids'] ?? '', true);
        if (is_array($ids) && count($ids) > 0) {
            if (in_array($classId, $ids, true)) return true;
            continue;
        }
        $level = trim((string)($row['class_level'] ?? ''));
        if ($level === '') continue;
        if (str_starts_with($classId, $level . '_')) return true;
        if ($classLevel === null) {
            $stmtC = $db->prepare("SELECT level FROM classes WHERE id = ?");
            $stmtC->bind_param("s", $classId);
            $stmtC->execute();
            $classLevel = (string)($stmtC->get_result()->fetch_assoc()['level'] ?? '');
        }
        if ($classLevel !== '' && strcasecmp($classLevel, $level) === 0) return true;
    }
    return false;
}

// Guru hanya boleh mengakses nilai mapel+kelas yang diampu. Role lain tidak dibatasi di sini.
function requireGradeAccess(mysqli $db, array $user, string $classId, string $subjectId): void
{
    if (($user['role'] ?? '') !== 'GURU') return;
    if (!teacherTeachesClassSubject($db, (string)($user['uid'] ?? ''), $classId, $subjectId)) {
        errorResponse('Akses ditolak: Anda tidak mengampu mapel ini di kelas tersebut', 403);
    }
}
