<?php
// Gerbang aplikasi SIMANTAP.
// Belum terinstal → wizard instalasi. Terinstal → verifikasi lisensi portal,
// lalu sajikan app langsung dari root (URL bersih, tanpa /dist/).
if (!file_exists(__DIR__ . '/api/install.lock') && is_dir(__DIR__ . '/install')) {
    header('Location: install/');
    exit;
}

// Maintenance mode: update patch sedang berlangsung
if (is_file(__DIR__ . '/api/.updating')) {
    http_response_code(503);
    header('Retry-After: 30');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta http-equiv="refresh" content="15">'
        . '<title>SIMANTAP — Pembaruan</title></head>'
        . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:80px;color:#334155">'
        . '<h2>🔄 Pembaruan aplikasi sedang berlangsung</h2>'
        . '<p>Halaman akan dimuat ulang otomatis. Mohon tunggu sebentar…</p></body></html>';
    exit;
}

// Verifikasi lisensi (dev mode: SIMANTAP_DEV_MODE di api/config.local.php)
if (is_readable(__DIR__ . '/api/license_guard.php')) {
    require_once __DIR__ . '/api/license_guard.php';
    $licStatus = simantapLicenseStatus();
    if (!$licStatus['ok']) {
        if (is_dir(__DIR__ . '/install')) {
            // Wizard menampilkan mode aktivasi ulang bila lisensi tidak valid
            header('Location: install/');
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIMANTAP — Lisensi Tidak Valid</title>
<style>body{font-family:system-ui,sans-serif;background:#f1f5f9;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:40px;max-width:480px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,.06)}
h1{font-size:20px;color:#0f172a}p{color:#475569;font-size:14px;line-height:1.6}</style></head>
<body><div class="card">
<h1>⛔ Lisensi Tidak Valid</h1>
<p><?= htmlspecialchars($licStatus['message'], ENT_QUOTES, 'UTF-8') ?></p>
<p>Unggah ulang folder <code>/install</code> dari bundle resmi untuk melakukan aktivasi ulang, atau hubungi BTIKP Disdikbud Prov. Kalimantan Selatan.</p>
</div></body></html>
        <?php
        exit;
    }
}

if (is_file(__DIR__ . '/app.html')) {
    // Bundle produksi: frontend ada di root sebagai app.html
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/app.html');
    exit;
}
// Layout development (repo): frontend masih di dist/
header('Location: dist/');
exit;
