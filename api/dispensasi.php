<?php
/**
 * Dispensasi siswa: murid meninggalkan kelas untuk kegiatan yang diakui sekolah (lomba,
 * tugas sekolah, dsb.) berdasarkan surat rekomendasi/izin dispensasi.
 *
 * - Satu baris per siswa; berlaku dari tanggal_mulai s.d. tanggal_selesai, sehari penuh
 *   (jam_ke_mulai NULL) atau jam ke-X s.d. Y.
 * - Diinput ADMIN, KEPALA_SEKOLAH, TATA_USAHA, Guru BK (semua kelas) dan wali kelas
 *   (kelas perwaliannya). Langsung berlaku tanpa persetujuan.
 * - Form jurnal mengisi status D (DISPENSASI) otomatis untuk jam yang dicakup.
 * - Presensi harian (presensi_harian.php) menganggap jam ALFA yang dicakup dispensasi
 *   sebagai DISPENSASI, yang dihitung hadir. Isi jurnal guru tidak diubah.
 *
 * Hanya boleh dimuat lewat api/index.php.
 */
if (!defined('SIMANTAP_API')) exit;

const DISPENSASI_MSG_DENY    = 'Anda tidak memiliki akses ke data dispensasi.';
const DISPENSASI_MAKS_HARI   = 31;
const DISPENSASI_MAKS_JAM    = 20;
const DISPENSASI_MAKS_UNGGAH = 5 * 1024 * 1024;
const DISPENSASI_MIME        = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

/**
 * Buat tabel bila belum ada. DDL memicu implicit commit, jadi jangan dipanggil di dalam transaksi.
 */
function dispensasiPastikanTabel(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS `student_dispensations` (
        `id` varchar(64) NOT NULL,
        `student_id` varchar(128) NOT NULL,
        `student_name` varchar(255) NOT NULL,
        `class_id` varchar(50) NOT NULL,
        `class_name` varchar(100) NOT NULL,
        `tanggal_mulai` date NOT NULL,
        `tanggal_selesai` date NOT NULL,
        `jam_ke_mulai` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'NULL = sehari penuh',
        `jam_ke_selesai` tinyint(3) UNSIGNED DEFAULT NULL,
        `kegiatan` varchar(255) NOT NULL,
        `nomor_surat` varchar(100) DEFAULT NULL,
        `surat_path` varchar(500) DEFAULT NULL COMMENT 'uploads/tu/dispensasi/<yyyymm>/<acak>.<ext>',
        `dicatat_oleh` varchar(128) NOT NULL,
        `dicatat_oleh_nama` varchar(255) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_disp_siswa_tgl` (`student_id`, `tanggal_mulai`, `tanggal_selesai`),
        KEY `idx_disp_tgl` (`tanggal_mulai`, `tanggal_selesai`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Cakupan akses: ALL (ADMIN, KEPALA_SEKOLAH, TATA_USAHA, Guru BK), CLASS (wali kelas), DENY.
 */
function dispensasiScope(mysqli $db, array $sessionUser): array
{
    $fresh = loadFreshUser($db, $sessionUser);
    $all   = ['mode' => 'ALL', 'classKeys' => [], 'classId' => null];
    $deny  = ['mode' => 'DENY', 'classKeys' => [], 'classId' => null];

    if (in_array($fresh['role'], ['ADMIN', 'KEPALA_SEKOLAH', 'TATA_USAHA'], true)) return $all;
    if ($fresh['role'] !== 'GURU') return $deny;
    if ((int)$fresh['is_bk'] === 1) return $all;
    $homeroom = trim((string)($fresh['class_homeroom'] ?? ''));
    return $homeroom === '' ? $deny : homeroomClassScope($db, $homeroom);
}

function dispensasiWajibMetode(string $metode): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $metode) {
        header('Allow: ' . $metode);
        errorResponse('Metode tidak diizinkan', 405);
    }
}

function dispensasiTanggalValid($tgl): bool
{
    if (!is_string($tgl) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tgl, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/**
 * Daftar tanggal Y-m-d dari $mulai s.d. $selesai (inklusif).
 */
function dispensasiRentangTanggal(string $mulai, string $selesai): array
{
    $out = [];
    $d = new DateTimeImmutable($mulai);
    $akhir = new DateTimeImmutable($selesai);
    while ($d <= $akhir) {
        $out[] = $d->format('Y-m-d');
        $d = $d->modify('+1 day');
    }
    return $out;
}

/**
 * Label cakupan jam, mis. "Sehari penuh" atau "Jam ke-3 s.d. 8".
 */
function dispensasiLabelCakupan(?int $mulai, ?int $selesai): string
{
    if ($mulai === null) return 'Sehari penuh';
    return $mulai === $selesai ? "Jam ke-$mulai" : "Jam ke-$mulai s.d. $selesai";
}

/**
 * Validasi isi body simpan (fungsi murni). Hasil: [data ternormalisasi, pesan galat|null].
 */
function dispensasiValidasi(array $body): array
{
    $ids = $body['student_ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_filter(array_map(fn($v) => is_scalar($v) ? trim((string)$v) : '', $ids), fn($v) => $v !== '')));
    if (empty($ids)) return [null, 'Pilih minimal satu siswa'];
    if (count($ids) > 60) return [null, 'Maksimal 60 siswa sekali simpan'];

    $mulai   = $body['tanggal_mulai'] ?? '';
    $selesai = ($body['tanggal_selesai'] ?? '') ?: $mulai;
    if (!dispensasiTanggalValid($mulai) || !dispensasiTanggalValid($selesai)) return [null, 'Tanggal tidak valid'];
    if ($selesai < $mulai) return [null, 'Tanggal selesai tidak boleh sebelum tanggal mulai'];
    if (count(dispensasiRentangTanggal($mulai, $selesai)) > DISPENSASI_MAKS_HARI) {
        return [null, 'Rentang dispensasi maksimal ' . DISPENSASI_MAKS_HARI . ' hari'];
    }

    $jamMulai = $jamSelesai = null;
    if (empty($body['sehari_penuh'])) {
        $jm = $body['jam_ke_mulai'] ?? null;
        $js = ($body['jam_ke_selesai'] ?? null) ?: $jm;
        if (!is_numeric($jm) || !is_numeric($js)) return [null, 'Isi jam ke- mulai dan selesai, atau pilih sehari penuh'];
        $jamMulai = (int)$jm;
        $jamSelesai = (int)$js;
        if ($jamMulai < 1 || $jamSelesai > DISPENSASI_MAKS_JAM || $jamSelesai < $jamMulai) {
            return [null, 'Jam ke- harus antara 1 dan ' . DISPENSASI_MAKS_JAM . ', dan jam selesai tidak sebelum jam mulai'];
        }
    }

    $kegiatan = trim((string)($body['kegiatan'] ?? ''));
    if ($kegiatan === '') return [null, 'Nama kegiatan / alasan dispensasi wajib diisi'];
    if (mb_strlen($kegiatan) > 255) return [null, 'Nama kegiatan maksimal 255 karakter'];

    $nomor = trim((string)($body['nomor_surat'] ?? ''));
    if (mb_strlen($nomor) > 100) return [null, 'Nomor surat maksimal 100 karakter'];

    $surat = trim((string)($body['surat_path'] ?? ''));
    if ($surat !== '' && !preg_match('#^uploads/tu/dispensasi/\d{6}/[a-f0-9]{32}\.(pdf|jpg|png)$#', $surat)) {
        return [null, 'Berkas surat tidak valid'];
    }

    return [[
        'student_ids'     => $ids,
        'tanggal_mulai'   => $mulai,
        'tanggal_selesai' => $selesai,
        'jam_ke_mulai'    => $jamMulai,
        'jam_ke_selesai'  => $jamSelesai,
        'kegiatan'        => $kegiatan,
        'nomor_surat'     => $nomor === '' ? null : $nomor,
        'surat_path'      => $surat === '' ? null : $surat,
    ], null];
}

function dispensasiBarisKeluaran(array $r): array
{
    $mulai   = $r['jam_ke_mulai'] === null ? null : (int)$r['jam_ke_mulai'];
    $selesai = $r['jam_ke_selesai'] === null ? null : (int)$r['jam_ke_selesai'];
    return [
        'id'                => $r['id'],
        'student_id'        => $r['student_id'],
        'student_name'      => $r['student_name'],
        'class_id'          => $r['class_id'],
        'class_name'        => $r['class_name'],
        'tanggal_mulai'     => $r['tanggal_mulai'],
        'tanggal_selesai'   => $r['tanggal_selesai'],
        'jam_ke_mulai'      => $mulai,
        'jam_ke_selesai'    => $selesai,
        'cakupan'           => dispensasiLabelCakupan($mulai, $selesai),
        'kegiatan'          => $r['kegiatan'],
        'nomor_surat'       => $r['nomor_surat'],
        'surat_path'        => $r['surat_path'],
        'dicatat_oleh_nama' => $r['dicatat_oleh_nama'],
        'created_at'        => $r['created_at'],
    ];
}

/**
 * GET ?action=dispensasi_list&dari=Y-m-d&sampai=Y-m-d[&class_id=]
 */
function handleDispensasiList(mysqli $db): void
{
    dispensasiWajibMetode('GET');
    $user  = requireAuth();
    $scope = dispensasiScope($db, $user);
    if ($scope['mode'] === 'DENY') errorResponse(DISPENSASI_MSG_DENY, 403);
    dispensasiPastikanTabel($db);

    $dari   = $_GET['dari'] ?? date('Y-m-01');
    $sampai = $_GET['sampai'] ?? date('Y-m-t');
    if (!dispensasiTanggalValid($dari) || !dispensasiTanggalValid($sampai)) errorResponse('Tanggal tidak valid');
    $kelas = trim((string)($_GET['class_id'] ?? ''));
    if ($kelas !== '' && !classInScope($scope, $kelas)) errorResponse(KESISWAAN_MSG_KELAS, 403);

    $stmt = $db->prepare(
        "SELECT * FROM student_dispensations WHERE tanggal_mulai <= ? AND tanggal_selesai >= ?
         ORDER BY tanggal_mulai DESC, class_name, student_name"
    );
    $stmt->bind_param("ss", $sampai, $dari);
    $stmt->execute();
    $items = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        if (!classInScope($scope, $r['class_id']) && !classInScope($scope, $r['class_name'])) continue;
        if ($kelas !== '' && normalizeClassKey($kelas) !== normalizeClassKey($r['class_id'])
            && normalizeClassKey($kelas) !== normalizeClassKey($r['class_name'])) continue;
        $items[] = dispensasiBarisKeluaran($r);
    }
    jsonResponse(['scope' => ['mode' => $scope['mode'], 'class_id' => $scope['classId']], 'items' => $items]);
}

/**
 * GET ?action=dispensasi_siswa&class_id= — siswa aktif satu kelas dalam cakupan.
 */
function handleDispensasiSiswa(mysqli $db): void
{
    dispensasiWajibMetode('GET');
    $user  = requireAuth();
    $scope = dispensasiScope($db, $user);
    if ($scope['mode'] === 'DENY') errorResponse(DISPENSASI_MSG_DENY, 403);
    $kelas = trim((string)($_GET['class_id'] ?? '')) ?: (string)$scope['classId'];
    if ($kelas === '' || !classInScope($scope, $kelas)) errorResponse(KESISWAAN_MSG_KELAS, 403);
    $ref = resolveClassRef($db, $kelas);

    $stmt = $db->prepare(
        "SELECT id, name, nisn FROM students
         WHERE (class_id = ? OR class_id = ? OR class_name = ?)
           AND (status IS NULL OR status = '' OR UPPER(status) = 'AKTIF')
         ORDER BY name"
    );
    $stmt->bind_param("sss", $ref['id'], $kelas, $ref['name']);
    $stmt->execute();
    jsonResponse(['class' => $ref, 'students' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

/**
 * POST ?action=dispensasi_simpan — body lihat dispensasiValidasi. Satu baris per siswa.
 */
function handleDispensasiSimpan(mysqli $db): void
{
    dispensasiWajibMetode('POST');
    $user  = requireAuth();
    $scope = dispensasiScope($db, $user);
    if ($scope['mode'] === 'DENY') errorResponse(DISPENSASI_MSG_DENY, 403);
    [$data, $galat] = dispensasiValidasi(getRequestBody());
    if ($galat !== null) errorResponse($galat);
    if ($data['surat_path'] !== null && !is_file(dirname(__DIR__) . '/' . $data['surat_path'])) {
        errorResponse('Berkas surat tidak ditemukan, unggah ulang');
    }
    dispensasiPastikanTabel($db);

    // Siswa harus ada dan termasuk cakupan; kelas diambil dari data siswa
    $ids = $data['student_ids'];
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, name, class_id, class_name FROM students WHERE id IN ($ph)");
    $stmt->bind_param(str_repeat('s', count($ids)), ...$ids);
    $stmt->execute();
    $siswa = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $s) $siswa[$s['id']] = $s;
    foreach ($ids as $sid) {
        if (!isset($siswa[$sid])) errorResponse('Siswa tidak ditemukan: ' . $sid);
        $s = $siswa[$sid];
        if (!classInScope($scope, $s['class_id']) && !classInScope($scope, $s['class_name'])) {
            errorResponse(KESISWAAN_MSG_KELAS, 403);
        }
    }

    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO student_dispensations
                (id, student_id, student_name, class_id, class_name, tanggal_mulai, tanggal_selesai,
                 jam_ke_mulai, jam_ke_selesai, kegiatan, nomor_surat, surat_path, dicatat_oleh, dicatat_oleh_nama)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $dibuat = [];
        foreach ($ids as $sid) {
            $s  = $siswa[$sid];
            $id = uniqid('disp_', true);
            $stmt->bind_param(
                "sssssssiisssss",
                $id, $sid, $s['name'], $s['class_id'], $s['class_name'], $data['tanggal_mulai'], $data['tanggal_selesai'],
                $data['jam_ke_mulai'], $data['jam_ke_selesai'], $data['kegiatan'], $data['nomor_surat'], $data['surat_path'],
                $user['uid'], $user['displayName']
            );
            $stmt->execute();
            $dibuat[] = $id;
        }
        // Presensi harian & insiden dihitung ulang: jam ALFA yang dicakup menjadi DISPENSASI
        foreach (dispensasiRentangTanggal($data['tanggal_mulai'], $data['tanggal_selesai']) as $tgl) {
            presensiSinkronHarian($db, $tgl, $ids, $user);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        error_log('[SIMANTAP] dispensasi_simpan: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        errorResponse('Gagal menyimpan dispensasi', 500);
    }
    jsonResponse(['success' => true, 'ids' => $dibuat]);
}

/**
 * POST ?action=dispensasi_hapus — body {id}.
 */
function handleDispensasiHapus(mysqli $db): void
{
    dispensasiWajibMetode('POST');
    $user  = requireAuth();
    $scope = dispensasiScope($db, $user);
    if ($scope['mode'] === 'DENY') errorResponse(DISPENSASI_MSG_DENY, 403);
    dispensasiPastikanTabel($db);

    $id = trim((string)(getRequestBody()['id'] ?? ''));
    $stmt = $db->prepare("SELECT * FROM student_dispensations WHERE id = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) errorResponse('Dispensasi tidak ditemukan', 404);
    if (!classInScope($scope, $row['class_id']) && !classInScope($scope, $row['class_name'])) {
        errorResponse(KESISWAAN_MSG_KELAS, 403);
    }

    $db->begin_transaction();
    try {
        $stmt = $db->prepare("DELETE FROM student_dispensations WHERE id = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        foreach (dispensasiRentangTanggal($row['tanggal_mulai'], $row['tanggal_selesai']) as $tgl) {
            presensiSinkronHarian($db, $tgl, [$row['student_id']], $user);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        error_log('[SIMANTAP] dispensasi_hapus: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        errorResponse('Gagal menghapus dispensasi', 500);
    }

    // Berkas surat dihapus bila tidak dipakai dispensasi lain (satu surat untuk banyak siswa)
    if (!empty($row['surat_path'])) {
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM student_dispensations WHERE surat_path = ?");
        $stmt->bind_param("s", $row['surat_path']);
        $stmt->execute();
        $sisa = (int)$stmt->get_result()->fetch_assoc()['c'];
        $berkas = dirname(__DIR__) . '/' . $row['surat_path'];
        if ($sisa === 0 && preg_match('#^uploads/tu/dispensasi/\d{6}/[a-f0-9]{32}\.(pdf|jpg|png)$#', $row['surat_path']) && is_file($berkas)) {
            @unlink($berkas);
        }
    }
    jsonResponse(['success' => true]);
}

/**
 * POST multipart ?action=dispensasi_unggah_surat — field "file" (PDF/JPG/PNG, maks 5 MB).
 * Ekstensi ditentukan dari isi berkas, bukan dari nama berkas klien.
 */
function handleDispensasiUnggahSurat(mysqli $db): void
{
    dispensasiWajibMetode('POST');
    $user  = requireAuth();
    $scope = dispensasiScope($db, $user);
    if ($scope['mode'] === 'DENY') errorResponse(DISPENSASI_MSG_DENY, 403);

    $f = $_FILES['file'] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        errorResponse('Berkas surat gagal diunggah');
    }
    if ((int)$f['size'] > DISPENSASI_MAKS_UNGGAH) errorResponse('Ukuran berkas maksimal 5 MB');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext  = DISPENSASI_MIME[$mime] ?? null;
    if ($ext === null) errorResponse('Surat harus berupa PDF, JPG, atau PNG');

    $sub = 'uploads/tu/dispensasi/' . date('Ym');
    $dir = dirname(__DIR__) . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) errorResponse('Folder unggahan tidak dapat dibuat', 500);
    foreach ([dirname($dir), $dir] as $d) {
        if (!is_file("$d/index.html")) @file_put_contents("$d/index.html", '');
    }
    $nama = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$nama")) errorResponse('Berkas surat gagal disimpan', 500);
    jsonResponse(['success' => true, 'path' => "$sub/$nama"]);
}

/**
 * GET ?action=dispensasi_jurnal&class_id=&date=Y-m-d&jam=1-2
 * Dispensasi yang mencakup jam jurnal ini, untuk mengisi status D di form jurnal.
 * Terbuka untuk semua pengguna yang login (guru mapel mana pun mengisi jurnal).
 * Hasil: {items: {student_id: {kegiatan, cakupan}}}
 */
function handleDispensasiJurnal(mysqli $db): void
{
    dispensasiWajibMetode('GET');
    requireAuth();
    $kelas = trim((string)($_GET['class_id'] ?? ''));
    $date  = (string)($_GET['date'] ?? '');
    if ($kelas === '' || !dispensasiTanggalValid($date)) jsonResponse(['items' => new stdClass()]);

    $items = [];
    foreach (presensiDispensasiTanggal($db, $date) as $sid => $daftar) {
        foreach ($daftar as $d) {
            if (normalizeClassKey($d['class_id']) !== normalizeClassKey($kelas)
                && normalizeClassKey($d['class_name']) !== normalizeClassKey($kelas)) continue;
            if (!presensiDispensasiMencakup($d, presensiAngkaJam((string)($_GET['jam'] ?? '')))) continue;
            $items[$sid] = [
                'kegiatan' => $d['kegiatan'],
                'cakupan'  => dispensasiLabelCakupan($d['jam_ke_mulai'], $d['jam_ke_selesai']),
            ];
            break;
        }
    }
    jsonResponse(['items' => $items ?: new stdClass()]);
}
