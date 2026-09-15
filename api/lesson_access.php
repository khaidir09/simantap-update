<?php
// lesson_access.php - Otorisasi modul Perangkat Belajar (lesson_rpps) per guru pemilik
// Spesifikasi: docs/specs/perangkat-belajar-per-guru.md
// Hanya boleh dimuat lewat api/index.php (setelah kesiswaan_access.php dan ekskul_access.php)
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/kesiswaan_access.php';

const LESSON_MSG_MODUL     = 'Anda tidak memiliki akses ke modul Perangkat Belajar.';
const LESSON_MSG_NOT_FOUND = 'Dokumen tidak ditemukan';
const LESSON_MSG_OWNER     = 'Anda bukan pemilik dokumen ini';
const LESSON_MSG_LOCKED    = 'Dokumen sudah diverifikasi dan tidak dapat diubah';
const LESSON_TYPES         = ['RPP', 'SILABUS', 'MODUL', 'ADMINISTRASI'];

/**
 * User segar dari DB + cek role modul. Role di luar $roles ditolak 403.
 */
function lessonUser(mysqli $db, array $roles): array
{
    $fresh = loadFreshUser($db, requireAuth());
    if (!in_array($fresh['role'], $roles, true)) {
        errorResponse(LESSON_MSG_MODUL, 403);
    }
    return $fresh;
}

/**
 * Mutasi tidak boleh lewat GET (bundle memakai POST/DELETE).
 */
function lessonRequireMethod(array $allowed): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
        errorResponse('Metode tidak diizinkan', 405);
    }
}

function lessonLoad(mysqli $db, string $id): ?array
{
    if ($id === '') return null;
    $stmt = $db->prepare("SELECT id, authorId, authorName, status, type, file_url FROM lesson_rpps WHERE id = ? LIMIT 1");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function lessonIsLocked(array $row): bool
{
    return strtoupper((string)($row['status'] ?? '')) === 'VERIFIED';
}

function lessonIsOwner(array $user, array $row): bool
{
    return ($row['authorId'] ?? '') !== '' && (string)$row['authorId'] === (string)$user['uid'];
}

/**
 * Pastikan dokumen ada dan boleh diubah/dihapus oleh user.
 * GURU: harus pemilik dan belum VERIFIED. ADMIN: hanya untuk hapus ($allowAdmin).
 */
function lessonAssertMutable(mysqli $db, array $user, string $id, bool $allowAdmin = false): array
{
    $row = lessonLoad($db, $id);
    if (!$row) errorResponse(LESSON_MSG_NOT_FOUND, 404);
    if ($allowAdmin && $user['role'] === 'ADMIN') return $row;
    if ($user['role'] !== 'GURU' || !lessonIsOwner($user, $row)) errorResponse(LESSON_MSG_OWNER, 403);
    if (lessonIsLocked($row)) errorResponse(LESSON_MSG_LOCKED, 409);
    return $row;
}

function lessonSafeId(string $id): string
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $id);
}

/**
 * fileUrl hanya boleh menunjuk ke folder unggahan dokumen itu sendiri.
 */
function lessonValidFileUrl(string $id, string $fileUrl): bool
{
    $prefix = 'uploads/tu/lesson/' . lessonSafeId($id) . '/';
    return strpos($fileUrl, $prefix) === 0
        && strpos($fileUrl, '..') === false
        && strpos($fileUrl, '\\') === false
        && strlen($fileUrl) > strlen($prefix);
}

/**
 * Hapus folder unggahan dokumen. Path dibangun dari id tersanitasi, bukan dari body/file_url.
 */
function lessonDeleteFiles(string $id): void
{
    $safeId = lessonSafeId($id);
    if ($safeId === '' || trim($safeId, '_') === '') return;
    $dir = __DIR__ . '/../uploads/tu/lesson/' . $safeId;
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        if (is_file($path) && !@unlink($path)) {
            error_log('SIMANTAP lesson: gagal menghapus file ' . $path);
        }
    }
    if (!@rmdir($dir)) {
        error_log('SIMANTAP lesson: gagal menghapus folder ' . $dir);
    }
}

/**
 * Index authorId agar filter per guru tetap cepat. Idempoten; kegagalan diabaikan.
 */
function lessonEnsureIndex(mysqli $db): void
{
    try {
        $res = $db->query("SHOW INDEX FROM lesson_rpps WHERE Key_name = 'idx_lesson_author'");
        if ($res && $res->num_rows === 0) {
            $db->query("ALTER TABLE lesson_rpps ADD INDEX idx_lesson_author (authorId)");
        }
    } catch (Throwable $e) {
        error_log('SIMANTAP lesson: gagal membuat index authorId - ' . $e->getMessage());
    }
}
