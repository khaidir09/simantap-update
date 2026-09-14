<?php
// rekap_bulanan.php - Cetak Rekap Bulanan Wali Kelas (halaman HTML A4 siap cetak / simpan PDF)
// Spesifikasi: docs/specs/rekap-bulanan-wali-kelas.md
// Hanya boleh dimuat lewat api/index.php (yang sudah melewati gerbang lisensi)
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

const REKAP_NAMA_BULAN = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];

// Normalisasi kunci kelas di SQL, setara normalizeClassKey()
const REKAP_KEY_SQL = "REPLACE(REPLACE(REPLACE(REPLACE(LOWER(%s), ' ', ''), '_', ''), '-', ''), '.', '')";

function rekapEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rekapSendHeaders(int $status): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

/**
 * Halaman error HTML (endpoint ini dibuka di tab baru, jadi tidak membalas JSON).
 */
function rekapHtmlError(int $status, string $message, string $extraHtml = ''): void
{
    rekapSendHeaders($status);
    if ($status === 401) {
        $extraHtml .= '<p><a href="../">Buka halaman login</a></p>';
    }
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Rekap Bulanan - Error ' . $status . '</title>'
        . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;color:#0f172a;margin:0;padding:40px 16px}'
        . '.box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px}'
        . 'h1{font-size:18px;margin:0 0 8px}p{margin:8px 0;line-height:1.5}a{color:#047857}</style></head><body>'
        . '<div class="box"><h1>Rekap bulanan tidak dapat ditampilkan</h1>'
        . '<p>' . rekapEsc($message) . '</p>' . $extraHtml . '</div></body></html>';
    exit;
}

/**
 * Rentang bulan tahun ajaran "YYYY/YYYY+1": ['YYYY-07', 'YYYY+1-06'], atau null bila format tidak dikenali.
 */
function rekapAcademicMonthRange(string $activeYear): ?array
{
    if (!preg_match('/^\s*(\d{4})\s*\/\s*(\d{4})\s*$/', $activeYear, $m)) return null;
    if ((int)$m[2] !== (int)$m[1] + 1) return null;
    return [sprintf('%04d-07', (int)$m[1]), sprintf('%04d-06', (int)$m[2])];
}

function rekapBulanIndo(string $ym): string
{
    [$y, $m] = array_map('intval', explode('-', $ym));
    return (REKAP_NAMA_BULAN[$m] ?? $ym) . ' ' . $y;
}

function rekapTanggalIndo(string $ymd): string
{
    $parts = explode('-', substr($ymd, 0, 10));
    if (count($parts) !== 3) return $ymd;
    return (int)$parts[2] . ' ' . (REKAP_NAMA_BULAN[(int)$parts[1]] ?? $parts[1]) . ' ' . (int)$parts[0];
}

function rekapTanggalPendek(?string $ymd): string
{
    $ymd = substr((string)$ymd, 0, 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) return '-';
    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

function rekapStatusLabel(?string $status): string
{
    $s = strtoupper(trim((string)$status));
    $labels = [
        'PENDING'             => 'Dilaporkan',
        'REPORTED'            => 'Dilaporkan',
        'WALI_KELAS_APPROVED' => 'Diproses Wali Kelas',
        'BP_PROCESS'          => 'Diproses BK',
        'PARENT_INVITED'      => 'Orang Tua Dipanggil',
        'RESOLVED'            => 'Selesai',
        'REJECTED'            => 'Ditolak',
    ];
    return $labels[$s] ?? (string)$status;
}

function rekapStudentStatusLabel(string $status): string
{
    $s = strtoupper(trim($status));
    $labels = ['TIDAK_AKTIF' => 'Tidak Aktif', 'KELUAR' => 'Keluar', 'LULUS' => 'Lulus', 'PINDAH' => 'Pindah'];
    return $labels[$s] ?? ucwords(strtolower(str_replace('_', ' ', $status)));
}

function rekapTruncate(?string $text, int $max = 200): string
{
    $t = trim(preg_replace('/\s+/u', ' ', (string)$text) ?? '');
    if (mb_strlen($t, 'UTF-8') > $max) $t = rtrim(mb_substr($t, 0, $max, 'UTF-8')) . '…';
    return $t;
}

function rekapIsAlphaIncident(array $inc): bool
{
    return strtoupper((string)($inc['violation_code'] ?? '')) === 'ALFA'
        || str_starts_with((string)$inc['id'], 'inc_att_');
}

/**
 * URL logo kop: path relatif root deploy diberi prefiks "../" (halaman dilayani dari api/),
 * http(s) dipakai apa adanya, skema lain diabaikan.
 */
function rekapAssetUrl(?string $path): string
{
    $p = trim((string)$path);
    if ($p === '' || str_starts_with($p, '//')) return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $p)) return '';
    return '../' . ltrim($p, '/');
}

/**
 * Kondisi SQL "kunci kelas kolom-kolom ∈ $keys" dengan parameter terikat.
 */
function rekapKeyCondition(array $columns, array $keys, string &$types, array &$params): string
{
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $parts = [];
    foreach ($columns as $col) {
        $parts[] = sprintf(REKAP_KEY_SQL, $col) . " IN ($placeholders)";
        foreach ($keys as $k) { $params[] = $k; $types .= 's'; }
    }
    return '(' . implode(' OR ', $parts) . ')';
}

function rekapFetchAll(mysqli $db, string $sql, string $types, array $params): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function rekapLoadKop(mysqli $db): array
{
    $rows = rekapFetchAll($db, "SELECT config_key, config_value FROM system_config WHERE config_key LIKE ?", 's', ['kop\_%']);
    $kop = [];
    foreach ($rows as $r) $kop[$r['config_key']] = trim((string)$r['config_value']);
    return $kop;
}

/**
 * Kumpulkan seluruh data rekap satu kelas untuk satu bulan.
 */
function rekapCollectData(mysqli $db, array $classRef, array $classKeys, string $month): array
{
    $start = $month . '-01';
    $end   = (new DateTimeImmutable($start))->modify('first day of next month')->format('Y-m-d');

    // --- Presensi per siswa ---
    $types = 'ss'; $params = [$start, $end];
    $cond  = rekapKeyCondition(['class_id', 'class_name'], $classKeys, $types, $params);
    $attendance = rekapFetchAll($db,
        "SELECT student_id, MAX(student_name) AS student_name,
                SUM(status = 'HADIR') AS h, SUM(status = 'SAKIT') AS s,
                SUM(status = 'IZIN') AS i, SUM(status = 'ALFA') AS a
         FROM attendance_records
         WHERE date >= ? AND date < ? AND $cond
         GROUP BY student_id", $types, $params);

    $types = 'ss'; $params = [$start, $end];
    $cond  = rekapKeyCondition(['class_id', 'class_name'], $classKeys, $types, $params);
    $recordedDates = array_column(rekapFetchAll($db,
        "SELECT DISTINCT date FROM attendance_records WHERE date >= ? AND date < ? AND $cond ORDER BY date",
        $types, $params), 'date');

    // --- Insiden (terlambat, izin keluar, pelanggaran) ---
    $types = 'ss'; $params = [$start, $end];
    $cond  = rekapKeyCondition(['className'], $classKeys, $types, $params);
    $incidents = rekapFetchAll($db,
        "SELECT id, type, date, studentId, student_nisn, studentName, details, violation_code, points, status
         FROM incidents
         WHERE date >= ? AND date < ? AND type IN ('LATE', 'OUT', 'VIOLATION') AND $cond
         ORDER BY date ASC, studentName ASC, createdAt ASC", $types, $params);

    $violationNames = [];
    foreach (rekapFetchAll($db, "SELECT violation_code, violation_name FROM violation_types", '', []) as $vt) {
        $violationNames[strtoupper((string)$vt['violation_code'])] = $vt['violation_name'];
    }

    // --- Siswa aktif di kelas ---
    $types = ''; $params = [];
    $cond  = rekapKeyCondition(['class_id', 'class_name'], $classKeys, $types, $params);
    $activeStudents = rekapFetchAll($db,
        "SELECT id, nisn, name FROM students WHERE $cond AND UPPER(status) = 'AKTIF'", $types, $params);

    $rows = [];
    $blankRow = fn(string $name, string $nisn) => [
        'nisn' => $nisn, 'name' => $name, 'h' => 0, 's' => 0, 'i' => 0, 'a' => 0,
        'late' => 0, 'out' => 0, 'violation' => 0, 'points' => 0, 'ket' => '',
    ];
    foreach ($activeStudents as $st) {
        $rows['id:' . $st['id']] = $blankRow((string)$st['name'], (string)($st['nisn'] ?? ''));
    }

    $extraIds = [];
    foreach ($attendance as $att) {
        $rk = 'id:' . $att['student_id'];
        if (!isset($rows[$rk])) {
            $rows[$rk] = $blankRow((string)$att['student_name'], '');
            $extraIds[$att['student_id']] = true;
        }
        foreach (['h', 's', 'i', 'a'] as $f) $rows[$rk][$f] += (int)$att[$f];
    }

    // Kunci baris untuk insiden: studentId, atau nama bila studentId kosong
    $nameIndex = [];
    foreach ($rows as $rk => $r) $nameIndex[mb_strtolower(trim($r['name']), 'UTF-8')] ??= $rk;

    $late = []; $out = []; $violations = [];
    foreach ($incidents as $inc) {
        $sid = trim((string)$inc['studentId']);
        if ($sid !== '') {
            $rk = 'id:' . $sid;
            if (!isset($rows[$rk])) {
                $rows[$rk] = $blankRow((string)$inc['studentName'], (string)($inc['student_nisn'] ?? ''));
                $extraIds[$sid] = true;
                $nameIndex[mb_strtolower(trim((string)$inc['studentName']), 'UTF-8')] ??= $rk;
            }
        } else {
            $nk = mb_strtolower(trim((string)$inc['studentName']), 'UTF-8');
            $rk = $nameIndex[$nk] ?? ('name:' . $nk);
            if (!isset($rows[$rk])) {
                $rows[$rk] = $blankRow((string)$inc['studentName'], (string)($inc['student_nisn'] ?? ''));
                $rows[$rk]['ket'] = 'Data siswa tidak ditemukan';
                $nameIndex[$nk] = $rk;
            }
        }
        if ($rows[$rk]['nisn'] === '' && !empty($inc['student_nisn'])) $rows[$rk]['nisn'] = (string)$inc['student_nisn'];

        $isAlpha    = rekapIsAlphaIncident($inc);
        $isRejected = strtoupper((string)$inc['status']) === 'REJECTED';
        $points     = (int)$inc['points'];

        if (!$isRejected) {
            $rows[$rk]['points'] += $points;
            if ($inc['type'] === 'LATE') $rows[$rk]['late']++;
            elseif ($inc['type'] === 'OUT') $rows[$rk]['out']++;
            elseif ($inc['type'] === 'VIOLATION' && !$isAlpha) $rows[$rk]['violation']++;
        }

        $detail = [
            'date'    => $inc['date'],
            'name'    => $inc['studentName'],
            'details' => rekapTruncate($inc['details']),
            'points'  => $points,
            'status'  => rekapStatusLabel($inc['status']),
        ];
        if ($inc['type'] === 'LATE') {
            $late[] = $detail;
        } elseif ($inc['type'] === 'OUT') {
            $out[] = $detail;
        } elseif (!$isAlpha) {
            $code = strtoupper((string)$inc['violation_code']);
            $detail['jenis'] = $violationNames[$code] ?? ($inc['violation_code'] ?: '-');
            $violations[] = $detail;
        }
    }

    // Keterangan siswa yang tidak aktif di kelas ini (pindah, keluar, atau terhapus)
    if ($extraIds) {
        $ids = array_keys($extraIds);
        $found = rekapFetchAll($db,
            "SELECT id, nisn, name, class_id, class_name, status FROM students WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
            str_repeat('s', count($ids)), $ids);
        $byId = [];
        foreach ($found as $f) $byId[$f['id']] = $f;
        foreach ($ids as $id) {
            $rk = 'id:' . $id;
            $st = $byId[$id] ?? null;
            if (!$st) { $rows[$rk]['ket'] = 'Data siswa tidak ditemukan'; continue; }
            $rows[$rk]['name'] = (string)$st['name'];
            if (!empty($st['nisn'])) $rows[$rk]['nisn'] = (string)$st['nisn'];
            $inClass = in_array(normalizeClassKey($st['class_id']), $classKeys, true)
                    || in_array(normalizeClassKey($st['class_name']), $classKeys, true);
            if (strtoupper(trim((string)$st['status'])) !== 'AKTIF') {
                $rows[$rk]['ket'] = rekapStudentStatusLabel((string)$st['status']);
            } elseif (!$inClass) {
                $rows[$rk]['ket'] = 'Pindah ke ' . ($st['class_name'] ?: $st['class_id']);
            }
        }
    }

    $rows = array_values($rows);
    usort($rows, fn($x, $y) => strcasecmp($x['name'], $y['name']));

    $totals = ['h' => 0, 's' => 0, 'i' => 0, 'a' => 0, 'late' => 0, 'out' => 0, 'violation' => 0, 'points' => 0];
    foreach ($rows as $r) foreach ($totals as $f => $_) $totals[$f] += $r[$f];

    // --- Panggilan orang tua ---
    $calls = rekapFetchAll($db,
        "SELECT pc.id, pc.student_name, pc.class_name, pc.total_points, pc.trigger_type, pc.scheduled_date,
                pc.status, pc.created_at, s.class_id AS s_class_id, s.class_name AS s_class_name
         FROM parent_calls pc
         LEFT JOIN students s ON s.id = pc.student_id
         WHERE (pc.created_at >= ? AND pc.created_at < ?) OR (pc.scheduled_date >= ? AND pc.scheduled_date < ?)",
        'ssss', [$start, $end, $start, $end]);
    $parentCalls = [];
    foreach ($calls as $c) {
        $match = trim((string)$c['class_name']) !== ''
            ? in_array(normalizeClassKey($c['class_name']), $classKeys, true)
            : (in_array(normalizeClassKey($c['s_class_id']), $classKeys, true) || in_array(normalizeClassKey($c['s_class_name']), $classKeys, true));
        if (!$match) continue;
        $parentCalls[] = [
            'created'   => substr((string)$c['created_at'], 0, 10),
            'scheduled' => $c['scheduled_date'],
            'name'      => $c['student_name'],
            'points'    => (int)$c['total_points'],
            'trigger'   => strtoupper((string)$c['trigger_type']) === 'AUTO' ? 'Otomatis' : 'Manual',
            'status'    => rekapStatusLabel($c['status']),
        ];
    }
    usort($parentCalls, fn($x, $y) => [$x['created'], $x['name']] <=> [$y['created'], $y['name']]);

    // --- Wali kelas ---
    $homeroom = null;
    $teachers = rekapFetchAll($db,
        "SELECT displayName, nip, class_homeroom FROM users
         WHERE role = 'GURU' AND class_homeroom IS NOT NULL AND class_homeroom <> '' ORDER BY displayName ASC", '', []);
    foreach ($teachers as $t) {
        if (in_array(normalizeClassKey($t['class_homeroom']), $classKeys, true)) {
            $homeroom = ['name' => (string)$t['displayName'], 'nip' => trim((string)$t['nip'])];
            break;
        }
    }

    return [
        'students'       => $rows,
        'totals'         => $totals,
        'late'           => $late,
        'out'            => $out,
        'violations'     => $violations,
        'parent_calls'   => $parentCalls,
        'recorded_dates' => $recordedDates,
        'homeroom'       => $homeroom,
    ];
}

function rekapPercent(int $hadir, int $total): string
{
    return $total > 0 ? number_format($hadir / $total * 100, 1, ',', '') : '-';
}

function rekapTimezoneLabel(): string
{
    $map = ['Asia/Makassar' => 'WITA', 'Asia/Jakarta' => 'WIB', 'Asia/Pontianak' => 'WIB', 'Asia/Jayapura' => 'WIT'];
    return $map[date_default_timezone_get()] ?? date('T');
}

/**
 * Handler action cetak_rekap_bulanan.
 */
function handleCetakRekapBulanan(mysqli $db): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        rekapHtmlError(405, 'Metode tidak diizinkan. Buka rekap melalui tombol Cetak Rekap Bulanan.');
    }

    $sessionUser = getCurrentUser();
    if (!$sessionUser || empty($sessionUser['uid'])) {
        rekapHtmlError(401, 'Sesi Anda telah berakhir. Silakan login ulang.');
    }

    try {
        // Cek user masih ada (agar 401 tetap berupa HTML, bukan JSON dari loadFreshUser)
        $meRows = rekapFetchAll($db, "SELECT uid, displayName, nip FROM users WHERE uid = ? LIMIT 1", 's', [$sessionUser['uid']]);
        if (!$meRows) rekapHtmlError(401, 'Sesi Anda telah berakhir. Silakan login ulang.');
        $me = $meRows[0];

        $scope = kesiswaanScope($db, $sessionUser, 'attendance_read');
        if ($scope['mode'] === 'DENY') {
            rekapHtmlError(403, 'Anda tidak memiliki akses ke rekap bulanan kelas.');
        }

        // --- Kelas ---
        $rawClass = trim((string)($_GET['class_id'] ?? ''));
        if ($scope['mode'] === 'CLASS') {
            if ($rawClass === '') $rawClass = (string)$scope['classId'];
            elseif (!classInScope($scope, $rawClass)) rekapHtmlError(403, KESISWAAN_MSG_KELAS);
        } elseif ($rawClass === '') {
            rekapHtmlError(400, 'Parameter class_id wajib diisi.');
        }
        $classRef  = resolveClassRef($db, $rawClass);
        $classKeys = [normalizeClassKey($classRef['id']), normalizeClassKey($classRef['name'])];
        if ($scope['mode'] === 'CLASS') $classKeys = array_merge($classKeys, $scope['classKeys']);
        $classKeys = array_values(array_unique(array_filter($classKeys, fn($k) => $k !== '')));

        $baseUrl = 'index.php?action=cetak_rekap_bulanan&class_id=' . rawurlencode($classRef['id']);

        // --- Bulan ---
        $now          = new DateTimeImmutable('now');
        $currentMonth = $now->format('Y-m');
        [$activeYear] = activeAcademicPeriod($db);
        $range        = rekapAcademicMonthRange($activeYear);
        $autoMonth    = ($_GET['auto'] ?? '') === '1';
        $notices      = [];

        $month = trim((string)($_GET['month'] ?? ''));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            rekapHtmlError(400, 'Format bulan tidak valid (gunakan YYYY-MM).',
                '<p><a href="' . rekapEsc($baseUrl . '&month=' . $currentMonth) . '">Buka rekap bulan berjalan</a></p>');
        }
        if ($autoMonth) {
            // Bulan default dari tombol: geser ke rentang yang valid
            $clamped = min($month, $currentMonth);
            if ($range) $clamped = max($range[0], min($clamped, $range[1], $currentMonth));
            if ($clamped !== $month) {
                $notices[] = 'Bulan ' . rekapBulanIndo($month) . ' di luar periode yang dapat dicetak, sehingga ditampilkan ' . rekapBulanIndo($clamped) . '.';
                $month = $clamped;
            }
        }
        if ($month > $currentMonth) {
            rekapHtmlError(400, 'Bulan belum berjalan.',
                '<p><a href="' . rekapEsc($baseUrl . '&month=' . $currentMonth) . '">Buka rekap bulan berjalan</a></p>');
        }
        if ($range && ($month < $range[0] || $month > $range[1])) {
            [$y1, $y2] = explode('/', preg_replace('/\s+/', '', $activeYear));
            rekapHtmlError(400, "Bulan harus dalam tahun ajaran $y1/$y2 (Juli $y1 – Juni $y2).");
        }

        $monthStart  = new DateTimeImmutable($month . '-01');
        $daysInMonth = (int)$monthStart->format('t');
        $isCurrent   = $month === $currentMonth;

        // --- Hari efektif, kota, tanggal ---
        $hariEfektifRaw = trim((string)($_GET['hari_efektif'] ?? ''));
        $hariEfektif    = null;
        if ($hariEfektifRaw !== '') {
            if (!ctype_digit($hariEfektifRaw) || (int)$hariEfektifRaw < 1 || (int)$hariEfektifRaw > $daysInMonth) {
                rekapHtmlError(400, "Hari efektif harus bilangan bulat 1 sampai $daysInMonth.");
            }
            $hariEfektif = (int)$hariEfektifRaw;
        }

        $tanggalDefault = $isCurrent ? $now->format('Y-m-d') : $monthStart->format('Y-m-t');
        $tanggalRaw     = trim((string)($_GET['tanggal'] ?? ''));
        $tanggal        = $tanggalDefault;
        if ($tanggalRaw !== '') {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tanggalRaw, $tm) || !checkdate((int)$tm[2], (int)$tm[3], (int)$tm[1])) {
                rekapHtmlError(400, 'Tanggal tanda tangan tidak valid (gunakan YYYY-MM-DD).');
            }
            $tanggal = $tanggalRaw;
        }

        $kop = rekapLoadKop($db);
        $kotaRaw = $_GET['kota'] ?? null;
        $kota = $kotaRaw === null ? ($kop['kop_kota'] ?? '') : trim((string)$kotaRaw);
        $kota = mb_substr($kota, 0, 50, 'UTF-8');

        $data = rekapCollectData($db, $classRef, $classKeys, $month);

        // Wali kelas di tanda tangan: pencetak sendiri bila wali, selain itu wali kelas yang terdaftar
        if ($scope['mode'] === 'CLASS') {
            $data['homeroom'] = ['name' => (string)$me['displayName'], 'nip' => trim((string)$me['nip'])];
        }

        $recordedCount = count($data['recorded_dates']);
        if ($hariEfektif === null && $recordedCount > 0) $hariEfektif = $recordedCount;
        if ($hariEfektif !== null && $hariEfektif < $recordedCount) {
            $notices[] = "Hari efektif ($hariEfektif) lebih kecil dari hari presensi tercatat ($recordedCount). Periksa kembali isian hari efektif.";
        }

        // Tanggal Senin–Sabtu tanpa presensi (hanya pratinjau)
        $missingDates = [];
        $lastDay = $isCurrent ? (int)$now->format('j') : $daysInMonth;
        $recordedSet = array_flip($data['recorded_dates']);
        for ($d = 1; $d <= $lastDay; $d++) {
            $date = $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('n'), $d);
            if ((int)$date->format('N') === 7) continue;
            if (!isset($recordedSet[$date->format('Y-m-d')])) $missingDates[] = $date->format('Y-m-d');
        }

        $monthNum = (int)$monthStart->format('n');
        $yearNum  = (int)$monthStart->format('Y');
        $tahunAjaran = $monthNum >= 7 ? "$yearNum/" . ($yearNum + 1) : ($yearNum - 1) . "/$yearNum";

        rekapRender([
            'kop'           => $kop,
            'class'         => $classRef,
            'month'         => $month,
            'is_current'    => $isCurrent,
            'today'         => $now->format('Y-m-d'),
            'tahun_ajaran'  => $tahunAjaran,
            'semester'      => $monthNum >= 7 ? 'Ganjil' : 'Genap',
            'hari_efektif'  => $hariEfektif,
            'hari_efektif_input' => $hariEfektifRaw,
            'tanggal_input' => $tanggalRaw,
            'recorded'      => $recordedCount,
            'kota'          => $kota,
            'tanggal'       => $tanggal,
            'tanggal_default' => $tanggalDefault,
            'data'          => $data,
            'missing_dates' => $missingDates,
            'notices'       => $notices,
            'printed_by'    => (string)$me['displayName'],
            'printed_at'    => $now,
            'class_param'   => $classRef['id'],
            'month_min'     => $range[0] ?? '',
            'month_max'     => $range ? min($range[1], $currentMonth) : $currentMonth,
        ]);
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        error_log('[SIMANTAP] cetak_rekap_bulanan: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        rekapHtmlError(500, 'Gagal menyusun rekap. Coba lagi atau hubungi admin.');
    }
}

function rekapRender(array $ctx): void
{
    $kop   = $ctx['kop'];
    $data  = $ctx['data'];
    $class = $ctx['class'];
    $e     = 'rekapEsc';

    $className = $class['name'] !== '' ? $class['name'] : $class['id'];
    $fileTitle = preg_replace('/[^A-Za-z0-9_-]/', '_', 'Rekap_Bulanan_' . $className . '_' . $ctx['month']);

    $logoKiri  = rekapAssetUrl($kop['kop_logo_kiri'] ?? '');
    $logoKanan = rekapAssetUrl($kop['kop_logo_kanan'] ?? '');
    $kontak = [];
    if (($kop['kop_telp'] ?? '') !== '')    $kontak[] = 'Telp. ' . $kop['kop_telp'];
    if (($kop['kop_email'] ?? '') !== '')   $kontak[] = 'Email: ' . $kop['kop_email'];
    if (($kop['kop_website'] ?? '') !== '') $kontak[] = 'Website: ' . $kop['kop_website'];
    $kopKosong = ($kop['kop_sekolah'] ?? '') === '' && ($kop['kop_instansi'] ?? '') === '' && ($kop['kop_dinas'] ?? '') === '';

    $homeroom = $data['homeroom'];
    $kepsekNama = $kop['kop_kepsek_nama'] ?? '';
    $kepsekNip  = $kop['kop_kepsek_nip'] ?? '';
    $dots = '(.......................................)';

    $nihil = fn(int $cols) => '<tr><td colspan="' . $cols . '" class="c nihil">Nihil</td></tr>';

    rekapSendHeaders(200);
    ob_start();
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($fileTitle) ?></title>
<style>
  @page { size: A4 portrait; margin: 15mm 15mm 15mm 20mm; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e2e8f0; color: #000; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; }
  .panel { max-width: 210mm; margin: 16px auto 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 16px; font-size: 13px; color: #0f172a; }
  .panel h2 { margin: 0 0 10px; font-size: 16px; }
  .panel form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
  .panel label { display: flex; flex-direction: column; gap: 4px; font-weight: bold; font-size: 12px; }
  .panel input { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 0; }
  .panel .btn { padding: 8px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; font-weight: bold; cursor: pointer; font-size: 13px; }
  .panel .btn-primary { background: #059669; border-color: #059669; color: #fff; }
  .panel .hint { margin: 10px 0 0; color: #475569; line-height: 1.5; }
  .panel .notice { margin: 8px 0 0; padding: 8px 10px; border-radius: 8px; background: #fef3c7; color: #78350f; }
  .panel .missing { margin: 8px 0 0; padding: 8px 10px; border-radius: 8px; background: #f1f5f9; color: #334155; line-height: 1.5; }
  .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 15mm 15mm 15mm 20mm; background: #fff; box-shadow: 0 4px 16px rgba(15, 23, 42, .15); }
  .kop { width: 100%; border-collapse: collapse; }
  .kop td { border: 0; padding: 0; vertical-align: middle; }
  .kop .logo { width: 24mm; text-align: center; }
  .kop .logo img { max-width: 22mm; max-height: 24mm; }
  .kop .teks { text-align: center; line-height: 1.25; }
  .kop .instansi, .kop .dinas { font-size: 12pt; font-weight: bold; text-transform: uppercase; }
  .kop .sekolah { font-size: 15pt; font-weight: bold; text-transform: uppercase; }
  .kop .alamat { font-size: 9pt; }
  .kop-line { border-top: 3px solid #000; border-bottom: 1px solid #000; height: 4px; margin: 6px 0 12px; }
  h1 { font-size: 11.5pt; text-align: center; margin: 0 0 10px; text-transform: uppercase; }
  h3 { font-size: 10pt; margin: 14px 0 6px; break-after: avoid; page-break-after: avoid; }  .ident { border-collapse: collapse; margin-bottom: 4px; }
  .ident td { border: 0; padding: 1px 6px 1px 0; font-size: 10pt; vertical-align: top; }
  .tbl-wrap { overflow-x: auto; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data th, table.data td { border: 1px solid #000; padding: 2px 4px; font-size: 8.5pt; vertical-align: top; }
  table.data th { background: #e5e7eb; text-align: center; vertical-align: middle; }
  table.data .c { text-align: center; }
  table.data .r { text-align: right; }
  table.data tfoot td { font-weight: bold; background: #f3f4f6; }
  table.data .nihil { font-style: italic; }
  thead { display: table-header-group; }
  tfoot { display: table-row-group; }
  tr { page-break-inside: avoid; break-inside: avoid; }
  .ttd { width: 100%; margin-top: 18px; border-collapse: collapse; page-break-inside: avoid; break-inside: avoid; }
  .ttd td { border: 0; width: 50%; text-align: center; vertical-align: top; font-size: 10pt; padding: 0 8px; }
  .ttd .space { height: 20mm; }
  .ttd .nama { font-weight: bold; text-decoration: underline; }
  .footer { margin-top: 14px; font-size: 7.5pt; color: #444; }
  @media screen and (max-width: 840px) {
    .panel { margin: 12px 16px 0; }
    .sheet { width: auto; min-height: 0; margin: 12px 16px; padding: 16px; }
  }
  @media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    .tbl-wrap { overflow: visible; }
    table.data th, table.data tfoot td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>
<div class="panel no-print">
  <h2>Cetak Rekap Bulanan — Kelas <?= $e($className) ?></h2>
  <form method="get" action="index.php" id="rekap-form">
    <input type="hidden" name="action" value="cetak_rekap_bulanan">
    <input type="hidden" name="class_id" value="<?= $e($ctx['class_param']) ?>">
    <label>Bulan
      <input type="month" name="month" id="rekap-month" value="<?= $e($ctx['month']) ?>" required
        <?= $ctx['month_min'] !== '' ? 'min="' . $e($ctx['month_min']) . '"' : '' ?> max="<?= $e($ctx['month_max']) ?>">
    </label>
    <label>Hari efektif
      <input type="number" name="hari_efektif" id="rekap-hari" min="1" max="31" style="width:90px"
        value="<?= $e($ctx['hari_efektif_input']) ?>" placeholder="<?= $ctx['recorded'] > 0 ? $e($ctx['recorded']) : '' ?>">
    </label>
    <label>Kota
      <input type="text" name="kota" maxlength="50" value="<?= $e($ctx['kota']) ?>" placeholder="mis. Banjarbaru">
    </label>
    <label>Tanggal tanda tangan
      <input type="date" name="tanggal" id="rekap-tanggal" value="<?= $e($ctx['tanggal_input']) ?>">
    </label>
    <button type="submit" class="btn">Terapkan</button>
    <button type="button" class="btn btn-primary" onclick="window.print()">Cetak / Simpan PDF</button>
  </form>
  <p class="hint">Pada dialog cetak, pilih tujuan <b>Simpan sebagai PDF</b>, ukuran kertas <b>A4</b>, dan matikan opsi <b>Header dan footer</b>.
    Kosongkan hari efektif atau tanggal untuk memakai nilai default (hari efektif <?= $ctx['recorded'] > 0 ? '= ' . $e($ctx['recorded']) . ' hari tercatat' : 'kosong' ?>, tanggal <?= $e(rekapTanggalIndo($ctx['tanggal_default'])) ?>).</p>
  <?php if ($kopKosong): ?>
    <p class="notice">Kop sekolah belum diatur. Minta Admin mengisi Pengaturan Kop Surat.</p>
  <?php endif; ?>
  <?php foreach ($ctx['notices'] as $n): ?>
    <p class="notice"><?= $e($n) ?></p>
  <?php endforeach; ?>
  <?php if ($ctx['missing_dates']): ?>
    <p class="missing"><b>Tanggal belum ada presensi</b> (Senin–Sabtu<?= $ctx['is_current'] ? ', s.d. hari ini' : '' ?>; abaikan bila hari libur):
      <?= $e(implode(', ', array_map(fn($d) => (int)substr($d, 8, 2), $ctx['missing_dates']))) ?> <?= $e(rekapBulanIndo($ctx['month'])) ?>.</p>
  <?php endif; ?>
</div>

<div class="sheet">
  <table class="kop">
    <tr>
      <td class="logo"><?php if ($logoKiri !== ''): ?><img src="<?= $e($logoKiri) ?>" alt="" onerror="this.style.visibility='hidden'"><?php endif; ?></td>
      <td class="teks">
        <?php if (($kop['kop_instansi'] ?? '') !== ''): ?><div class="instansi"><?= $e($kop['kop_instansi']) ?></div><?php endif; ?>
        <?php if (($kop['kop_dinas'] ?? '') !== ''): ?><div class="dinas"><?= $e($kop['kop_dinas']) ?></div><?php endif; ?>
        <?php if (($kop['kop_sekolah'] ?? '') !== ''): ?><div class="sekolah"><?= $e($kop['kop_sekolah']) ?></div><?php endif; ?>
        <?php if (($kop['kop_alamat'] ?? '') !== ''): ?><div class="alamat"><?= $e($kop['kop_alamat']) ?></div><?php endif; ?>
        <?php if ($kontak): ?><div class="alamat"><?= $e(implode(' · ', $kontak)) ?></div><?php endif; ?>
      </td>
      <td class="logo"><?php if ($logoKanan !== ''): ?><img src="<?= $e($logoKanan) ?>" alt="" onerror="this.style.visibility='hidden'"><?php endif; ?></td>
    </tr>
  </table>
  <div class="kop-line"></div>

  <h1>Rekapitulasi Bulanan Kehadiran dan Kedisiplinan Peserta Didik</h1>
  <table class="ident">
    <tr><td>Kelas</td><td>:</td><td><?= $e($className) ?></td></tr>
    <tr><td>Wali Kelas</td><td>:</td><td><?= $e($homeroom['name'] ?? '-') ?></td></tr>
    <tr><td>Bulan</td><td>:</td><td><?= $e(rekapBulanIndo($ctx['month'])) ?></td></tr>
    <tr><td>Tahun Ajaran / Semester</td><td>:</td><td><?= $e($ctx['tahun_ajaran'] . ' / ' . $ctx['semester']) ?></td></tr>
    <tr><td>Hari Efektif</td><td>:</td><td><?= $ctx['hari_efektif'] === null ? '-' : $e($ctx['hari_efektif']) . ' hari' ?></td></tr>
    <tr><td>Hari Presensi Tercatat</td><td>:</td><td><?= $e($ctx['recorded']) ?> hari</td></tr>
    <?php if ($ctx['is_current']): ?>
      <tr><td colspan="3"><i>Data s.d. tanggal <?= $e(rekapTanggalIndo($ctx['today'])) ?></i></td></tr>
    <?php endif; ?>
  </table>

  <h3>A. Rekap Kehadiran dan Kedisiplinan per Siswa</h3>
  <div class="tbl-wrap">
  <table class="data">
    <thead>
      <tr>
        <th rowspan="2" style="width:6mm">No</th>
        <th rowspan="2" style="width:19mm">NISN</th>
        <th rowspan="2">Nama Siswa</th>
        <th colspan="4">Kehadiran</th>
        <th rowspan="2" style="width:11mm">% Hadir</th>
        <th rowspan="2" style="width:12mm">Terlam&shy;bat</th>
        <th rowspan="2" style="width:11mm">Izin Keluar</th>
        <th rowspan="2" style="width:13mm">Pelang&shy;garan</th>
        <th rowspan="2" style="width:9mm">Poin</th>
        <th rowspan="2" style="width:22mm">Ket.</th>
      </tr>
      <tr><th style="width:6mm">H</th><th style="width:6mm">S</th><th style="width:6mm">I</th><th style="width:6mm">A</th></tr>
    </thead>
    <tbody>
      <?php if (!$data['students']): ?>
        <?= $nihil(13) ?>
      <?php else: foreach ($data['students'] as $no => $r): ?>
        <tr>
          <td class="c"><?= $no + 1 ?></td>
          <td class="c"><?= $e($r['nisn'] !== '' ? $r['nisn'] : '-') ?></td>
          <td><?= $e($r['name']) ?></td>
          <td class="c"><?= $r['h'] ?></td>
          <td class="c"><?= $r['s'] ?></td>
          <td class="c"><?= $r['i'] ?></td>
          <td class="c"><?= $r['a'] ?></td>
          <td class="c"><?= rekapPercent($r['h'], $r['h'] + $r['s'] + $r['i'] + $r['a']) ?></td>
          <td class="c"><?= $r['late'] ?></td>
          <td class="c"><?= $r['out'] ?></td>
          <td class="c"><?= $r['violation'] ?></td>
          <td class="c"><?= $r['points'] ?></td>
          <td><?= $e($r['ket']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <?php if ($data['students']): $t = $data['totals']; ?>
    <tfoot>
      <tr>
        <td colspan="3" class="c">Jumlah</td>
        <td class="c"><?= $t['h'] ?></td>
        <td class="c"><?= $t['s'] ?></td>
        <td class="c"><?= $t['i'] ?></td>
        <td class="c"><?= $t['a'] ?></td>
        <td class="c"><?= rekapPercent($t['h'], $t['h'] + $t['s'] + $t['i'] + $t['a']) ?></td>
        <td class="c"><?= $t['late'] ?></td>
        <td class="c"><?= $t['out'] ?></td>
        <td class="c"><?= $t['violation'] ?></td>
        <td class="c"><?= $t['points'] ?></td>
        <td></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
  </div>
  <p style="font-size:8pt;margin:4px 0 0">Keterangan: H = Hadir, S = Sakit, I = Izin, A = Alpa. Kolom Pelanggaran tidak termasuk Alpa. Poin mencakup seluruh poin pelanggaran, keterlambatan, dan Alpa. Insiden yang ditolak tidak dihitung.</p>

  <h3>B. Rincian Kejadian</h3>

  <h3 style="margin-top:6px">B.1 Siswa Terlambat</h3>
  <div class="tbl-wrap">
  <table class="data">
    <thead><tr><th style="width:8mm">No</th><th style="width:20mm">Tanggal</th><th style="width:45mm">Nama Siswa</th><th>Keterangan</th><th style="width:10mm">Poin</th><th style="width:28mm">Status</th></tr></thead>
    <tbody>
      <?php if (!$data['late']): ?><?= $nihil(6) ?><?php else: foreach ($data['late'] as $no => $d): ?>
        <tr><td class="c"><?= $no + 1 ?></td><td class="c"><?= $e(rekapTanggalPendek($d['date'])) ?></td><td><?= $e($d['name']) ?></td><td><?= $e($d['details']) ?></td><td class="c"><?= $d['points'] ?></td><td><?= $e($d['status']) ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <h3>B.2 Izin Keluar</h3>
  <div class="tbl-wrap">
  <table class="data">
    <thead><tr><th style="width:8mm">No</th><th style="width:20mm">Tanggal</th><th style="width:45mm">Nama Siswa</th><th>Keterangan</th><th style="width:28mm">Status</th></tr></thead>
    <tbody>
      <?php if (!$data['out']): ?><?= $nihil(5) ?><?php else: foreach ($data['out'] as $no => $d): ?>
        <tr><td class="c"><?= $no + 1 ?></td><td class="c"><?= $e(rekapTanggalPendek($d['date'])) ?></td><td><?= $e($d['name']) ?></td><td><?= $e($d['details']) ?></td><td><?= $e($d['status']) ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <h3>B.3 Pelanggaran</h3>
  <div class="tbl-wrap">
  <table class="data">
    <thead><tr><th style="width:8mm">No</th><th style="width:20mm">Tanggal</th><th style="width:38mm">Nama Siswa</th><th style="width:32mm">Jenis</th><th>Keterangan</th><th style="width:10mm">Poin</th><th style="width:24mm">Status</th></tr></thead>
    <tbody>
      <?php if (!$data['violations']): ?><?= $nihil(7) ?><?php else: foreach ($data['violations'] as $no => $d): ?>
        <tr><td class="c"><?= $no + 1 ?></td><td class="c"><?= $e(rekapTanggalPendek($d['date'])) ?></td><td><?= $e($d['name']) ?></td><td><?= $e($d['jenis']) ?></td><td><?= $e($d['details']) ?></td><td class="c"><?= $d['points'] ?></td><td><?= $e($d['status']) ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <h3>B.4 Panggilan Orang Tua</h3>
  <div class="tbl-wrap">
  <table class="data">
    <thead><tr><th style="width:8mm">No</th><th style="width:20mm">Tanggal Dibuat</th><th style="width:20mm">Jadwal</th><th>Nama Siswa</th><th style="width:14mm">Total Poin</th><th style="width:18mm">Pemicu</th><th style="width:28mm">Status</th></tr></thead>
    <tbody>
      <?php if (!$data['parent_calls']): ?><?= $nihil(7) ?><?php else: foreach ($data['parent_calls'] as $no => $d): ?>
        <tr><td class="c"><?= $no + 1 ?></td><td class="c"><?= $e(rekapTanggalPendek($d['created'])) ?></td><td class="c"><?= $e(rekapTanggalPendek($d['scheduled'])) ?></td><td><?= $e($d['name']) ?></td><td class="c"><?= $d['points'] ?></td><td class="c"><?= $e($d['trigger']) ?></td><td><?= $e($d['status']) ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <table class="ttd">
    <tr>
      <td>&nbsp;</td>
      <td><?= $e(($ctx['kota'] !== '' ? $ctx['kota'] . ', ' : '') . rekapTanggalIndo($ctx['tanggal'])) ?></td>
    </tr>
    <tr>
      <td>Mengetahui,<br>Kepala Sekolah</td>
      <td><br>Wali Kelas <?= $e($className) ?></td>
    </tr>
    <tr><td class="space"></td><td class="space"></td></tr>
    <tr>
      <td><?= $kepsekNama !== '' ? '<span class="nama">' . $e($kepsekNama) . '</span>' : $dots ?>
        <?php if ($kepsekNip !== ''): ?><br>NIP. <?= $e($kepsekNip) ?><?php endif; ?></td>
      <td><?= !empty($homeroom['name']) ? '<span class="nama">' . $e($homeroom['name']) . '</span>' : $dots ?>
        <?php if (!empty($homeroom['nip'])): ?><br>NIP. <?= $e($homeroom['nip']) ?><?php endif; ?></td>
    </tr>
  </table>

  <p class="footer">Dicetak dari SIMANTAP pada <?= $e(rekapTanggalIndo($ctx['printed_at']->format('Y-m-d')) . ' ' . $ctx['printed_at']->format('H:i') . ' ' . rekapTimezoneLabel()) ?> oleh <?= $e($ctx['printed_by']) ?>.</p>
</div>

<script>
  // Ganti bulan: kosongkan hari efektif dan tanggal agar default bulan baru dipakai
  (function () {
    var m = document.getElementById('rekap-month');
    if (!m) return;
    var awal = m.value;
    m.addEventListener('change', function () {
      if (m.value !== awal) {
        document.getElementById('rekap-hari').value = '';
        document.getElementById('rekap-tanggal').value = '';
      }
    });
  })();
</script>
</body>
</html>
<?php
    echo ob_get_clean();
}
