<?php
// teacher_subject_access.php - Otorisasi & validasi penugasan mapel guru (teacher_subjects)
// Spesifikasi: docs/specs/penugasan-mapel-mandiri-guru.md
// Hanya boleh dimuat lewat api/index.php (setelah kesiswaan_access.php)
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/kesiswaan_access.php';

const TS_MSG_MODUL       = 'Anda tidak memiliki akses ke penugasan mata pelajaran.';
const TS_MSG_OWNER_READ  = 'Anda hanya dapat melihat penugasan mata pelajaran milik sendiri';
const TS_MSG_OWNER_WRITE = 'Anda hanya dapat mengubah penugasan mata pelajaran milik sendiri';

/**
 * User segar dari DB + cek role. Role di luar $roles ditolak 403.
 */
function tsUser(mysqli $db, array $roles): array
{
    $fresh = loadFreshUser($db, requireAuth());
    if (!in_array($fresh['role'], $roles, true)) {
        errorResponse(TS_MSG_MODUL, 403);
    }
    return $fresh;
}

/**
 * Mutasi tidak boleh lewat GET.
 */
function tsRequireMethod(array $allowed): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
        errorResponse('Metode tidak diizinkan', 405);
    }
}

/**
 * Bentuk seragam satu item: trim, alias code/name, class_ids array string unik.
 */
function tsNormalizeItem($raw): array
{
    if (!is_array($raw)) $raw = [];
    $ids = $raw['class_ids'] ?? [];
    if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
    if (!is_array($ids)) $ids = [];

    $clean = [];
    foreach ($ids as $cid) {
        if (is_array($cid) || is_object($cid)) continue;
        $cid = trim((string)$cid);
        if ($cid !== '' && !in_array($cid, $clean, true)) $clean[] = $cid;
    }

    return [
        'subject_code' => trim((string)($raw['subject_code'] ?? $raw['code'] ?? '')),
        'subject_name' => trim((string)($raw['subject_name'] ?? $raw['name'] ?? '')),
        'class_level'  => trim((string)($raw['class_level'] ?? '')),
        'class_ids'    => $clean,
    ];
}

/**
 * Baris penugasan tersimpan milik seorang guru (sudah dinormalisasi).
 */
function tsLoadRows(mysqli $db, string $teacherId): array
{
    $stmt = $db->prepare("SELECT subject_code, subject_name, class_level, class_ids FROM teacher_subjects WHERE teacher_id = ?");
    $stmt->bind_param("s", $teacherId);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = tsNormalizeItem($row);
    }
    return $rows;
}

/**
 * Tanda tangan item untuk mencocokkan item body dengan baris tersimpan (urutan kelas diabaikan).
 */
function tsSignature(array $item): string
{
    $ids = $item['class_ids'];
    sort($ids, SORT_STRING);
    return implode('|', [
        mb_strtolower($item['subject_code']),
        mb_strtolower($item['subject_name']),
        mb_strtolower($item['class_level']),
        implode(',', $ids),
    ]);
}

/**
 * Kunci duplikat: mapel + level kelas.
 */
function tsDuplicateKey(array $item): string
{
    return mb_strtolower($item['subject_code']) . '#' . mb_strtolower($item['class_level']);
}

/**
 * Master mapel: [id_lowercase => ['id' => ..., 'name' => ..., 'is_active' => bool]]
 */
function tsLoadSubjects(mysqli $db): array
{
    $map = [];
    $res = $db->query("SELECT id, name, is_active FROM subjects");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[mb_strtolower(trim($row['id']))] = [
                'id'        => $row['id'],
                'name'      => $row['name'],
                'is_active' => (int)$row['is_active'] === 1,
            ];
        }
    }
    return $map;
}

/**
 * Master kelas: [id_lowercase => ['id' => ..., 'level' => ...]] atau null bila tabel belum ada.
 */
function tsLoadClasses(mysqli $db): ?array
{
    try {
        $res = $db->query("SELECT id, level FROM classes");
    } catch (Throwable $e) {
        return null;
    }
    if (!$res) return null;

    $map = [];
    while ($row = $res->fetch_assoc()) {
        $map[mb_strtolower(trim($row['id']))] = [
            'id'    => $row['id'],
            'level' => trim((string)($row['level'] ?? '')),
        ];
    }
    return $map;
}

/**
 * Validasi daftar item dan kembalikan daftar final yang siap disimpan.
 * Item yang identik dengan baris tersimpan ($stored) dilewati aturan baru (data warisan).
 * GURU wajib mengisi level dan minimal satu kelas; ADMIN boleh mengosongkannya.
 */
function tsValidateItems(mysqli $db, string $actorRole, array $items, array $stored): array
{
    $isGuru = ($actorRole === 'GURU');

    // Multiset tanda tangan baris tersimpan: satu baris hanya dipakai sekali.
    $storedCount = [];
    foreach ($stored as $row) {
        $sig = tsSignature($row);
        $storedCount[$sig] = ($storedCount[$sig] ?? 0) + 1;
    }

    $subjects = null;
    $classes  = null;
    $final    = [];
    $dupKeys  = [];

    foreach ($items as $raw) {
        $item = tsNormalizeItem($raw);
        if ($item['subject_code'] === '') {
            errorResponse('Mata pelajaran wajib dipilih');
        }

        $sig      = tsSignature($item);
        $isLegacy = !empty($storedCount[$sig]);
        if ($isLegacy) {
            $storedCount[$sig]--;
        } else {
            if ($subjects === null) $subjects = tsLoadSubjects($db);
            $key = mb_strtolower($item['subject_code']);
            if (!isset($subjects[$key]) || ($isGuru && !$subjects[$key]['is_active'])) {
                errorResponse('Mata pelajaran ' . $item['subject_code'] . ' tidak ditemukan atau tidak aktif');
            }
            // Nama mapel selalu dari master, tidak pernah dari body.
            $item['subject_code'] = $subjects[$key]['id'];
            $item['subject_name'] = $subjects[$key]['name'];

            if ($isGuru && $item['class_level'] === '') {
                errorResponse('Level kelas wajib dipilih');
            }
            if ($isGuru && count($item['class_ids']) === 0) {
                errorResponse('Pilih minimal satu kelas yang diampu');
            }

            if (count($item['class_ids']) > 0) {
                if ($classes === null) $classes = tsLoadClasses($db);
                if ($classes === null) {
                    errorResponse('Data kelas belum tersedia');
                }
                $resolved = [];
                foreach ($item['class_ids'] as $cid) {
                    $ckey = mb_strtolower($cid);
                    if (!isset($classes[$ckey])) {
                        errorResponse('Kelas ' . $cid . ' tidak ditemukan');
                    }
                    if ($item['class_level'] !== ''
                        && mb_strtolower($classes[$ckey]['level']) !== mb_strtolower($item['class_level'])) {
                        errorResponse('Kelas ' . $cid . ' bukan level ' . $item['class_level']);
                    }
                    $resolved[] = $classes[$ckey]['id'];
                }
                $item['class_ids'] = $resolved;
            }
        }

        $dupKey = tsDuplicateKey($item);
        if (isset($dupKeys[$dupKey])) {
            // Duplikat warisan (kedua item identik dengan data tersimpan) tetap diterima.
            if (!$isLegacy || !$dupKeys[$dupKey]['legacy']) {
                $label = $item['subject_name'] !== '' ? $item['subject_name'] : $item['subject_code'];
                $level = $item['class_level'] !== '' ? $item['class_level'] : 'semua';
                errorResponse('Mapel ' . $label . ' level ' . $level . ' tercantum lebih dari sekali');
            }
        } else {
            $dupKeys[$dupKey] = ['legacy' => $isLegacy];
        }

        $final[] = $item;
    }

    return $final;
}

/**
 * Samakan gtk_pegawai.mapel/kelas_ajar dengan isi penugasan, termasuk saat kosong.
 */
function tsSyncGtk(mysqli $db, string $teacherId, array $items): void
{
    $names = [];
    $kelas = [];
    foreach ($items as $item) {
        if ($item['subject_name'] !== '' && !in_array($item['subject_name'], $names, true)) {
            $names[] = $item['subject_name'];
        }
        foreach ($item['class_ids'] as $cid) {
            $cid = str_replace('_', ' ', trim($cid));
            if ($cid !== '' && !in_array($cid, $kelas, true)) $kelas[] = $cid;
        }
    }
    $syncMapel = implode(', ', $names);
    $syncKelas = implode(', ', $kelas);

    $stmt = $db->prepare("UPDATE gtk_pegawai SET mapel=?, kelas_ajar=? WHERE id=?");
    if ($stmt) {
        $stmt->bind_param("sss", $syncMapel, $syncKelas, $teacherId);
        $stmt->execute();
    }
}

/**
 * Baris penugasan seorang guru dalam format respons get_teacher_subjects.
 */
function tsFetchForResponse(mysqli $db, string $teacherId): array
{
    $stmt = $db->prepare("SELECT ts.*, s.name as subject_name_full, s.category FROM teacher_subjects ts LEFT JOIN subjects s ON ts.subject_code COLLATE utf8mb4_unicode_ci = s.id COLLATE utf8mb4_unicode_ci WHERE ts.teacher_id = ? ORDER BY ts.subject_name ASC");
    $stmt->bind_param("s", $teacherId);
    $stmt->execute();
    $res  = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) {
        $row['class_ids'] = json_decode($row['class_ids'] ?? '[]', true) ?: [];
        $list[] = $row;
    }
    return $list;
}
