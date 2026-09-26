<?php
// nilai/index.php - Portal Nilai Siswa & Orang Tua (publik, masuk dengan NISN + PIN)
// Spesifikasi: docs/specs/portal-nilai-orang-tua.md
// Gerbang install/maintenance/lisensi sama dengan index.php di root.
error_reporting(0);
ini_set('display_errors', '0');

$ROOT = dirname(__DIR__);

function portalKirimHeader(int $status): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Robots-Tag: noindex, nofollow');
}

function portalEsc($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Kerangka halaman portal (mobile-first, tanpa JavaScript).
 */
function portalHalaman(int $status, string $judul, string $sekolah, string $isi): void
{
    portalKirimHeader($status);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . portalEsc($judul) . ' — ' . portalEsc($sekolah) . '</title><style>'
        . ':root{color-scheme:light}*{box-sizing:border-box}'
        . 'body{margin:0;background:#f1f5f9;color:#0f172a;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;font-size:15px;line-height:1.45}'
        . '.wrap{max-width:720px;margin:0 auto;padding:16px}'
        . '.top{background:#047857;color:#fff;padding:14px 16px}.top .in{max-width:720px;margin:0 auto}'
        . '.top .sk{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;opacity:.9}'
        . '.top h1{margin:2px 0 0;font-size:19px}'
        . '.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px;margin:0 0 14px}'
        . '.card h2{margin:0 0 10px;font-size:16px}'
        . 'label{display:block;font-weight:700;font-size:14px;margin:12px 0 4px}'
        . 'input[type=text]{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font-size:17px}'
        . '.btn{display:inline-block;border:0;border-radius:10px;padding:12px 16px;font-size:15px;font-weight:700;cursor:pointer;background:#047857;color:#fff;text-decoration:none}'
        . '.btn.full{width:100%;margin-top:16px}.btn.putih{background:#fff;color:#334155;border:1px solid #cbd5e1;padding:8px 12px;font-size:13px}'
        . '.msg{padding:10px 12px;border-radius:10px;margin:0 0 12px;font-weight:600}.msg.err{background:#fef2f2;color:#991b1b}.msg.info{background:#fef3c7;color:#78350f}'
        . '.muted{color:#64748b;font-size:13px}'
        . '.id{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:flex-start}'
        . '.id .nm{font-size:18px;font-weight:800}'
        . '.tog{display:inline-flex;background:#e2e8f0;border-radius:10px;padding:3px;margin-top:10px}'
        . '.tog a{padding:6px 14px;border-radius:8px;color:#475569;text-decoration:none;font-weight:700;font-size:13px}.tog a.on{background:#fff;color:#047857}'
        . '.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}'
        . '.stat{background:#f8fafc;border-radius:10px;padding:10px}.stat .l{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase}.stat .v{font-size:20px;font-weight:800}'
        . '.merah{color:#b91c1c}.hijau{color:#047857}.kuning{color:#b45309}'
        . 'table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:7px 6px;border-bottom:1px solid #e2e8f0;vertical-align:top}th{color:#64748b;font-size:11px;text-transform:uppercase}'
        . '.c{text-align:center}.scroll{overflow-x:auto}'
        . '.mp{border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin:0 0 10px}'
        . '.mp .h{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}.mp .n{font-weight:800}'
        . '.badge{display:inline-block;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:700;white-space:nowrap}'
        . '.b-hijau{background:#ecfdf5;color:#047857}.b-merah{background:#fef2f2;color:#b91c1c}.b-kuning{background:#fffbeb;color:#b45309}.b-abu{background:#f1f5f9;color:#475569}'
        . '.g{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:8px}.g div{background:#f8fafc;border-radius:8px;padding:6px}.g .l{font-size:11px;color:#64748b}.g .v{font-weight:800}'
        . 'details{margin-top:8px}summary{cursor:pointer;color:#047857;font-weight:700;font-size:13px}'
        . '.foot{font-size:12px;color:#64748b;margin:18px 0}'
        . '@media(min-width:560px){.stats{grid-template-columns:repeat(4,1fr)}.g{grid-template-columns:repeat(6,1fr)}}'
        . '</style></head><body>'
        . '<div class="top"><div class="in"><div class="sk">' . portalEsc($sekolah) . '</div><h1>Cek Nilai Siswa</h1></div></div>'
        . '<div class="wrap">' . $isi . '</div></body></html>';
    exit;
}

function portalPesan(int $status, string $sekolah, string $pesan): void
{
    portalHalaman($status, 'Cek Nilai Siswa', $sekolah, '<div class="card"><p>' . portalEsc($pesan) . '</p></div>');
}

// ---------------------------------------------------------------- gerbang
if (!file_exists($ROOT . '/api/install.lock') && is_dir($ROOT . '/install')) {
    header('Location: ../install/');
    exit;
}
if (is_file($ROOT . '/api/.updating')) {
    header('Retry-After: 30');
    portalPesan(503, 'SIMANTAP', 'Pembaruan aplikasi sedang berlangsung. Silakan coba lagi beberapa saat lagi.');
}
if (is_readable($ROOT . '/api/license_guard.php')) {
    require_once $ROOT . '/api/license_guard.php';
    $licStatus = simantapLicenseStatus();
    if (!$licStatus['ok']) portalPesan(403, 'SIMANTAP', 'Layanan tidak tersedia. Silakan hubungi sekolah.');
}

define('SIMANTAP_API', true);
require_once $ROOT . '/api/db.php';
require_once $ROOT . '/api/kesiswaan_access.php';
require_once $ROOT . '/api/rekap_bulanan.php';
require_once $ROOT . '/api/pantau_nilai.php';
require_once $ROOT . '/api/portal_nilai.php';

// ---------------------------------------------------------------- tampilan
function portalFormMasuk(int $status, string $sekolah, string $pesan = '', string $jenis = 'err', string $nisn = ''): void
{
    $isi = '<div class="card"><h2>Masuk</h2>'
        . ($pesan !== '' ? '<p class="msg ' . $jenis . '">' . portalEsc($pesan) . '</p>' : '')
        . '<form method="post" action="./" autocomplete="off">'
        . '<input type="hidden" name="aksi" value="masuk">'
        . '<label for="nisn">NISN</label>'
        . '<input type="text" id="nisn" name="nisn" inputmode="numeric" maxlength="20" required value="' . portalEsc($nisn) . '">'
        . '<label for="pin">PIN</label>'
        . '<input type="text" id="pin" name="pin" maxlength="12" autocapitalize="characters" spellcheck="false" required placeholder="XXXX-XXXX">'
        . '<button type="submit" class="btn full">Lihat Nilai</button></form>'
        . '<p class="muted">NISN dan PIN tercantum pada kartu akses dari wali kelas. Hubungi wali kelas bila kartu hilang atau PIN tidak berlaku.</p></div>';
    portalHalaman($status, 'Masuk', $sekolah, $isi);
}

function portalTombolKeluar(): string
{
    return '<form method="post" action="./"><input type="hidden" name="aksi" value="keluar">'
        . '<button type="submit" class="btn putih">Keluar</button></form>';
}

function portalKeterangan(array $subject, array $g): array
{
    if ($subject['status'] === 'BELUM_ADA_PENILAIAN') return ['Belum ada penilaian', 'b-abu'];
    if ($g['status'] === 'TUNTAS') return ['Tuntas', 'b-hijau'];
    if ($g['status'] === 'REMEDI') return ['Remedi', 'b-merah'];
    if ($g['ref_score'] !== null) return [$g['below_kkm'] ? 'Sementara, di bawah KKM' : 'Sementara', $g['below_kkm'] ? 'b-merah' : 'b-kuning'];
    return ['Belum lengkap', 'b-abu'];
}

function portalHalamanNilai(mysqli $db, array $student, string $sekolah): void
{
    $period   = getActiveAcademicPeriod($db);
    $semester = pantauNilaiSemester((string)($_GET['semester'] ?? ''), $period['semester'])
        ?? (pantauNilaiSemester('', $period['semester']) ?? 'GANJIL');
    $classId  = (string)$student['class_id'];
    $classRef = resolveClassRef($db, $classId);
    $kelas    = $classRef['name'] !== '' ? $classRef['name'] : $classId;
    $sid      = (string)$student['id'];

    $data = pantauNilaiCollect($db, $classId, $period['academic_year'], $semester);
    $row  = $data['students'][$sid] ?? null;
    if ($row === null) {
        portalHalaman(200, 'Nilai', $sekolah, '<div class="card"><p>Data nilai siswa ini tidak tersedia. Silakan hubungi sekolah.</p>' . portalTombolKeluar() . '</div>');
    }
    $presensi = portalNilaiPresensi($db, $sid, $data['range']);
    $totalPresensi = array_sum($presensi);
    $e = 'portalEsc';
    $n = 'pantauNilaiAngka';
    $presLabel = fn($s) => pantauNilaiPresensiLabel($s);

    // Hanya data milik siswa ini; catatan guru tidak pernah dirender
    $mapelKurang = (int)$row['below_kkm_count'];
    $tertinggal  = $row['missing'];

    $h = '<div class="card"><div class="id"><div>'
        . '<div class="nm">' . $e($student['name']) . '</div>'
        . '<div class="muted">NISN ' . $e($student['nisn']) . ' · Kelas ' . $e($kelas) . ' · TA ' . $e($period['academic_year']) . '</div>'
        . '<div class="tog"><a href="?semester=1" class="' . ($semester === 'GANJIL' ? 'on' : '') . '">Ganjil</a>'
        . '<a href="?semester=2" class="' . ($semester === 'GENAP' ? 'on' : '') . '">Genap</a></div>'
        . '</div>' . portalTombolKeluar() . '</div></div>';

    // --- Ringkasan
    $h .= '<div class="card"><h2>Ringkasan Semester ' . ($semester === 'GENAP' ? 'Genap' : 'Ganjil') . '</h2><div class="stats">'
        . '<div class="stat"><div class="l">Belum ada nilai</div><div class="v ' . (count($tertinggal) ? 'merah' : 'hijau') . '">' . count($tertinggal) . '</div></div>'
        . '<div class="stat"><div class="l">Mapel &lt; KKM</div><div class="v ' . ($mapelKurang ? 'merah' : 'hijau') . '">' . $mapelKurang . '</div></div>'
        . '<div class="stat"><div class="l">Kehadiran</div><div class="v">' . $e(rekapPercent($presensi['HADIR'], $totalPresensi)) . ($totalPresensi ? '%' : '') . '</div></div>'
        . '<div class="stat"><div class="l">S / I / A</div><div class="v">' . $presensi['SAKIT'] . ' / ' . $presensi['IZIN'] . ' / ' . $presensi['ALFA'] . '</div></div>'
        . '</div><p class="muted">Hadir ' . $presensi['HADIR'] . ' kali dari ' . $totalPresensi . ' presensi tercatat.</p></div>';

    // --- Tertinggal
    $h .= '<div class="card"><h2>Tugas/Kuis yang Belum Ada Nilainya</h2>';
    if (!$tertinggal) {
        $h .= '<p>Tidak ada. 👍</p>';
    } else {
        $h .= '<div class="scroll"><table><thead><tr><th>Tanggal</th><th>Mapel</th><th>Topik</th><th>Jenis</th><th>Presensi</th></tr></thead><tbody>';
        foreach ($tertinggal as $m) {
            $h .= '<tr><td>' . $e(rekapTanggalPendek($m['date'])) . '</td><td>' . $e($m['subject_name']) . '</td>'
                . '<td>' . $e(rekapTruncate($m['topic'], 120)) . '</td><td>' . ($m['type'] === 'TUGAS' ? 'Tugas' : 'Kuis') . '</td>'
                . '<td>' . $e($presLabel($m['attendance_status'])) . '</td></tr>';
        }
        $h .= '</tbody></table></div><p class="muted">Pertemuan yang sudah dinilai guru, tetapi siswa ini belum memiliki nilai. Silakan tanyakan kepada guru mapel.</p>';
    }
    $h .= '</div>';

    // --- Nilai per mapel
    $h .= '<div class="card"><h2>Nilai per Mata Pelajaran</h2>';
    if (!$data['subjects']) $h .= '<p>Belum ada penilaian pada semester ini.</p>';
    foreach ($data['subjects'] as $code => $subject) {
        $g = $row['grades'][$code];
        [$ket, $kelasBadge] = portalKeterangan($subject, $g);
        $guru = $subject['teachers'] ? implode(', ', array_column($subject['teachers'], 'name')) : '-';
        $h .= '<div class="mp"><div class="h"><div><div class="n">' . $e($subject['subject_name']) . '</div>'
            . '<div class="muted">' . $e($guru) . '</div></div><span class="badge ' . $kelasBadge . '">' . $e($ket) . '</span></div>'
            . '<div class="g">'
            . '<div><div class="l">Tugas (' . $g['tugas_count'] . ')</div><div class="v">' . $e($n($g['avg_tugas'])) . '</div></div>'
            . '<div><div class="l">Kuis (' . $g['kuis_count'] . ')</div><div class="v">' . $e($n($g['avg_kuis'])) . '</div></div>'
            . '<div><div class="l">UTS</div><div class="v">' . $e($n($g['ujian_uts'])) . '</div></div>'
            . '<div><div class="l">UAS</div><div class="v">' . $e($n($g['ujian_uas'])) . '</div></div>'
            . '<div><div class="l">Nilai Akhir</div><div class="v ' . ($g['final_score'] !== null ? ($g['below_kkm'] ? 'merah' : 'hijau') : '') . '">' . $e($n($g['final_score'])) . '</div></div>'
            . '<div><div class="l">KKM</div><div class="v">' . $e($n($data['kkm'])) . '</div></div></div>';

        $rapat = $data['meetings'][$code] ?? [];
        $sel   = $data['cells'][$code][$sid] ?? [];
        if ($rapat) {
            $h .= '<details><summary>Lihat per pertemuan (' . count($rapat) . ')</summary><div class="scroll"><table>'
                . '<thead><tr><th>Tanggal</th><th>Topik</th><th class="c">Tugas</th><th class="c">Kuis</th><th>Presensi</th></tr></thead><tbody>';
            foreach ($rapat as $i => $r) {
                $c = $sel[$i] ?? [];
                $tugas = !$r['has_tugas'] ? '·' : (!empty($c['missing_tugas']) ? '<span class="merah">belum ada</span>' : $e($n($c['tugas_score'] ?? null)));
                $kuis  = !$r['has_kuis'] ? '·' : (!empty($c['missing_kuis']) ? '<span class="merah">belum ada</span>' : $e($n($c['kuis_score'] ?? null)));
                $h .= '<tr><td>' . $e(rekapTanggalPendek($r['date'])) . '</td><td>' . $e(rekapTruncate($r['topic'], 120)) . '</td>'
                    . '<td class="c">' . $tugas . '</td><td class="c">' . $kuis . '</td><td>' . $e($presLabel($c['attendance_status'] ?? null)) . '</td></tr>';
            }
            $h .= '</tbody></table></div></details>';
        }
        $h .= '</div>';
    }
    $w = $data['weights'];
    $h .= '<p class="muted">Nilai akhir = tugas ' . (int)$w['bobot_tugas'] . '% + kuis ' . (int)$w['bobot_kuis'] . '% + ujian ' . (int)$w['bobot_ujian']
        . '%, dihitung bila nilai ujian sudah ada. "Sementara" = belum ada nilai ujian, dinilai dari rata-rata tugas dan kuis. Angka dalam kurung = jumlah nilai.</p></div>';

    $h .= '<p class="foot">Data s.d. ' . $e(rekapTanggalIndo(date('Y-m-d')) . ' ' . date('H:i') . ' ' . rekapTimezoneLabel())
        . '. Nilai dapat berubah bila guru memperbarui jurnal.</p>';

    portalHalaman(200, 'Nilai ' . $student['name'], $sekolah, $h);
}

// ---------------------------------------------------------------- alur utama
try {
    $db = getDB();
    portalNilaiEnsureTables($db);
    $kop = rekapLoadKop($db);
    $sekolah = ($kop['kop_sekolah'] ?? '') !== '' ? $kop['kop_sekolah'] : 'SIMANTAP';

    if (!portalNilaiAktif($db)) {
        portalPesan(503, $sekolah, 'Layanan cek nilai belum dibuka. Silakan hubungi wali kelas.');
    }

    $cookiePath = portalNilaiBasePath((string)($_SERVER['SCRIPT_NAME'] ?? '/nilai/index.php')) . '/nilai/';
    $https = portalNilaiIsHttps($_SERVER);
    $setCookie = function (string $value, int $expires) use ($cookiePath, $https) {
        setcookie(PORTAL_NILAI_COOKIE, $value, [
            'expires' => $expires, 'path' => $cookiePath, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    };
    $hapusCookie = fn() => $setCookie('', time() - 3600);

    // --- POST: masuk / keluar (Post/Redirect/Get)
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $aksi = (string)($_POST['aksi'] ?? '');
        if ($aksi === 'keluar') {
            $hapusCookie();
            header('Location: ./', true, 303);
            exit;
        }
        if ($aksi !== 'masuk') {
            header('Location: ./', true, 303);
            exit;
        }

        $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $nisn = portalNilaiNormalisasiNisn((string)($_POST['nisn'] ?? ''));
        $pin  = portalNilaiNormalisasiPin((string)($_POST['pin'] ?? ''));
        $nisnTampil = substr(preg_replace('/\D+/', '', (string)($_POST['nisn'] ?? '')) ?? '', 0, 20);

        $sisa = portalNilaiSisaKunci($db, 'ip', $ip, PORTAL_NILAI_MAKS_IP);
        if ($sisa === 0 && $nisn !== '') $sisa = portalNilaiSisaKunci($db, 'nisn', $nisn, PORTAL_NILAI_MAKS_NISN);
        if ($sisa > 0) {
            header('Retry-After: ' . $sisa);
            portalFormMasuk(429, $sekolah, 'Terlalu banyak percobaan. Coba lagi dalam ' . (int)ceil($sisa / 60) . ' menit.', 'err', $nisnTampil);
        }

        $hash = null;
        $sid  = null;
        if ($nisn !== '' && $pin !== '' && strlen($pin) <= 12) {
            $rows = rekapFetchAll($db, "SELECT id FROM students WHERE nisn = ? AND " . PORTAL_NILAI_SQL_AKTIF . " LIMIT 2", 's', [$nisn]);
            if (count($rows) === 1) {
                $akses = rekapFetchAll($db, "SELECT pin_hash FROM portal_nilai_akses WHERE student_id = ? LIMIT 1", 's', [(string)$rows[0]['id']]);
                if ($akses) { $hash = (string)$akses[0]['pin_hash']; $sid = (string)$rows[0]['id']; }
            }
        }
        $cocok = password_verify($pin, $hash ?? PORTAL_NILAI_DUMMY_HASH) && $hash !== null;
        portalNilaiCatatPercobaan($db, $ip, $nisn, $cocok);

        if (!$cocok) portalFormMasuk(401, $sekolah, 'NISN atau PIN salah.', 'err', $nisnTampil);

        portalNilaiBukaKunci($db, $nisn);
        $waktu = date('Y-m-d H:i:s');
        $stmt = $db->prepare("UPDATE portal_nilai_akses SET login_terakhir = ?, jumlah_login = jumlah_login + 1 WHERE student_id = ?");
        $stmt->bind_param("ss", $waktu, $sid);
        $stmt->execute();

        $exp = time() + PORTAL_NILAI_SESI_DETIK;
        $setCookie(portalNilaiBuatCookie($sid, $exp, $hash), $exp);
        header('Location: ./', true, 303);
        exit;
    }

    // --- GET: halaman nilai bila sesi valid, selain itu form masuk
    $raw = $_COOKIE[PORTAL_NILAI_COOKIE] ?? null;
    if ($raw === null || $raw === '') portalFormMasuk(200, $sekolah);

    $cookie  = portalNilaiBacaCookie((string)$raw);
    $student = null;
    if ($cookie) {
        $akses = rekapFetchAll($db, "SELECT pin_hash FROM portal_nilai_akses WHERE student_id = ? LIMIT 1", 's', [$cookie['student_id']]);
        if ($akses && portalNilaiCookieValid($cookie, (string)$akses[0]['pin_hash'], time())) {
            $st = rekapFetchAll($db, "SELECT id, name, nisn, class_id, class_name, status FROM students WHERE id = ? LIMIT 1", 's', [$cookie['student_id']]);
            $student = $st[0] ?? null;
        }
    }
    if ($student === null) {
        $hapusCookie();
        portalFormMasuk(200, $sekolah, 'Sesi berakhir. Silakan masuk kembali.', 'info');
    }

    $status = trim((string)($student['status'] ?? ''));
    if ($status !== '' && strtoupper($status) !== 'AKTIF') {
        portalHalaman(200, 'Nilai', $sekolah, '<div class="card"><p>Data nilai siswa ini tidak tersedia. Silakan hubungi sekolah.</p>' . portalTombolKeluar() . '</div>');
    }

    portalHalamanNilai($db, $student, $sekolah);
} catch (Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    error_log('[SIMANTAP] portal nilai: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    portalPesan(500, 'SIMANTAP', 'Terjadi gangguan. Silakan coba lagi beberapa saat lagi.');
}
