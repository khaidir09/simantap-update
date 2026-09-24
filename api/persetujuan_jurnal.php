<?php
// persetujuan_jurnal.php - Persetujuan massal jurnal bulanan oleh Kepala Sekolah / ADMIN
// Spesifikasi: docs/specs/persetujuan-jurnal-bulanan.md
// Hanya boleh dimuat lewat api/index.php (yang sudah melewati gerbang lisensi)
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

const PERSETUJUAN_JURNAL_MAKS_ID = 10000;
const PERSETUJUAN_JURNAL_CHUNK   = 500;

/**
 * Validasi bulan 'YYYY-MM' lalu hitung rentang tanggal yang boleh disetujui.
 * Bulan berjalan: sampai hari ini. Bulan lampau: sampai akhir bulan.
 * Memanggil errorResponse (exit) jika bulan tidak valid atau belum berjalan.
 */
function persetujuanJurnalRentang(string $month): array
{
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) || !checkdate((int)$m[2], 1, (int)$m[1])) {
        errorResponse('Format bulan tidak valid (YYYY-MM)');
    }
    $bulanIni = date('Y-m');
    if ($month > $bulanIni) {
        errorResponse('Bulan belum berjalan');
    }
    $start    = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($start));
    $isCurrent = $month === $bulanIni;
    return [
        'start'          => $start,
        'monthEnd'       => $monthEnd,
        'cutoff'         => $isCurrent ? date('Y-m-d') : $monthEnd,
        'isCurrentMonth' => $isCurrent,
    ];
}

function persetujuanJurnalPastikanTabel(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS journal_approval_batches (
        id               VARCHAR(64)  NOT NULL PRIMARY KEY,
        month            CHAR(7)      NOT NULL,
        cutoff_date      DATE         NOT NULL,
        approved_by      VARCHAR(128) NOT NULL,
        approved_by_name VARCHAR(255) NOT NULL,
        approved_at      DATETIME     NOT NULL,
        journal_count    INT          NOT NULL,
        journal_ids      LONGTEXT     NOT NULL,
        KEY idx_month (month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Ringkasan jurnal bulan $month: daftar id yang layak disetujui, rincian per guru,
 * serta jumlah yang dikecualikan. Read-only.
 */
function persetujuanJurnalPreview(mysqli $db, string $month): array
{
    $r = persetujuanJurnalRentang($month);

    $stmt = $db->prepare("SELECT id, teacherId, teacherName FROM journal_entries
        WHERE date BETWEEN ? AND ? AND isDraft = 0 AND isVerified = 0
        ORDER BY date, id");
    $stmt->bind_param("ss", $r['start'], $r['cutoff']);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    $perGuru = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = $row['id'];
        $tid = $row['teacherId'];
        if (!isset($perGuru[$tid])) {
            $perGuru[$tid] = ['teacherId' => $tid, 'teacherName' => $row['teacherName'], 'count' => 0];
        }
        $perGuru[$tid]['count']++;
    }
    $byTeacher = array_values($perGuru);
    usort($byTeacher, fn($a, $b) => strcasecmp($a['teacherName'], $b['teacherName']));

    $stmt = $db->prepare("SELECT
            COALESCE(SUM(isDraft = 1 AND isVerified = 0 AND date <= ?), 0) AS draft,
            COALESCE(SUM(isVerified = 0 AND date > ?), 0)                 AS futureDated,
            COALESCE(SUM(isVerified = 1), 0)                              AS alreadyVerified
        FROM journal_entries WHERE date BETWEEN ? AND ?");
    $stmt->bind_param("ssss", $r['cutoff'], $r['cutoff'], $r['start'], $r['monthEnd']);
    $stmt->execute();
    $cnt = $stmt->get_result()->fetch_assoc();

    return [
        'month'          => $month,
        'start'          => $r['start'],
        'cutoff'         => $r['cutoff'],
        'isCurrentMonth' => $r['isCurrentMonth'],
        'eligible'       => ['count' => count($ids), 'ids' => $ids, 'byTeacher' => $byTeacher],
        'excluded'       => ['draft' => (int)$cnt['draft'], 'futureDated' => (int)$cnt['futureDated']],
        'alreadyVerified' => (int)$cnt['alreadyVerified'],
    ];
}

/**
 * Setujui jurnal pada $ids yang masih memenuhi kriteria bulan $month.
 * Id di luar kriteria (sudah diverifikasi, draf, dihapus, bulan lain) dilewati.
 */
function persetujuanJurnalSetujui(mysqli $db, array $user, string $month, $idsRaw): array
{
    $r = persetujuanJurnalRentang($month);

    if (!is_array($idsRaw)) errorResponse('Daftar jurnal tidak valid');
    $ids = [];
    foreach ($idsRaw as $v) {
        if (!is_string($v) || $v === '' || strlen($v) > 128) errorResponse('Daftar jurnal tidak valid');
        $ids[$v] = true;
    }
    $ids = array_keys($ids);
    if (count($ids) === 0) errorResponse('Tidak ada jurnal yang dipilih');
    if (count($ids) > PERSETUJUAN_JURNAL_MAKS_ID) {
        errorResponse('Jumlah jurnal melebihi batas ' . PERSETUJUAN_JURNAL_MAKS_ID);
    }

    persetujuanJurnalPastikanTabel($db);
    $verifiedAt = date('Y-m-d H:i:s');
    $approved = [];

    $db->begin_transaction();
    try {
        foreach (array_chunk($ids, PERSETUJUAN_JURNAL_CHUNK) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $db->prepare("SELECT id FROM journal_entries
                WHERE id IN ($ph) AND isDraft = 0 AND isVerified = 0 AND date BETWEEN ? AND ?
                FOR UPDATE");
            $params = array_merge($chunk, [$r['start'], $r['cutoff']]);
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $approved[] = $row['id'];
        }

        if (count($approved) === 0) {
            $db->commit();
            return [
                'success' => true, 'month' => $month, 'approved' => 0, 'approvedIds' => [],
                'skipped' => count($ids), 'verifiedAt' => null,
            ];
        }

        foreach (array_chunk($approved, PERSETUJUAN_JURNAL_CHUNK) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $db->prepare("UPDATE journal_entries SET isVerified = 1, verifiedBy = ?, verifiedAt = ? WHERE id IN ($ph)");
            $params = array_merge([$user['uid'], $verifiedAt], $chunk);
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
        }

        $batchId = uniqid('jab_');
        $count = count($approved);
        $idsJson = json_encode($approved);
        $stmt = $db->prepare("INSERT INTO journal_approval_batches
            (id, month, cutoff_date, approved_by, approved_by_name, approved_at, journal_count, journal_ids)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssis", $batchId, $month, $r['cutoff'], $user['uid'], $user['displayName'], $verifiedAt, $count, $idsJson);
        $stmt->execute();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        errorResponse('Gagal menyetujui jurnal', 500);
    }

    return [
        'success'     => true,
        'batchId'     => $batchId,
        'month'       => $month,
        'approved'    => count($approved),
        'approvedIds' => $approved,
        'skipped'     => count($ids) - count($approved),
        'verifiedAt'  => $verifiedAt,
    ];
}
