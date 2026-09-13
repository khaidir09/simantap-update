<?php
/**
 * SIMANTAP - License Guard
 *
 * Memverifikasi lisensi instalasi yang diterbitkan Portal SIMANTAP BTIKP:
 *  - api/license.json berisi payload {npsn, installed_url, issued_at} yang
 *    ditandatangani Ed25519 oleh portal saat aktivasi.
 *  - Kunci publik portal dibake ke bundle saat build (api/license_pubkey.php),
 *    jadi lisensi tidak bisa dipalsukan tanpa kunci privat portal.
 *  - Host saat ini harus cocok dengan host pada installed_url — salinan kode
 *    yang dipindah ke domain lain harus aktivasi ulang lewat wizard /install.
 *  - Heartbeat maks 1x/hari ke portal (best-effort; offline tetap jalan)
 *    untuk pemantauan & pencabutan lisensi oleh BTIKP.
 *
 * Mode development: set define('SIMANTAP_DEV_MODE', true) di api/config.local.php.
 */

// Konfigurasi hasil build (kunci publik + URL portal). Tidak ada di repo dev.
if (is_readable(__DIR__ . '/license_pubkey.php')) {
    require_once __DIR__ . '/license_pubkey.php';
}
// Versi terpasang — file terpisah agar bisa diganti oleh patch update.
if (is_readable(__DIR__ . '/version.php')) {
    require_once __DIR__ . '/version.php';
}
// config.local.php bisa mendefinisikan SIMANTAP_DEV_MODE (db.php memuatnya juga,
// keduanya memakai require_once sehingga aman dimuat lebih dulu di sini)
if (is_readable(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

const SIMANTAP_LICENSE_FILE = __DIR__ . '/license.json';
const SIMANTAP_REVOKED_FILE = __DIR__ . '/.license_revoked';
const SIMANTAP_HEARTBEAT_STAMP = __DIR__ . '/.heartbeat';
const SIMANTAP_UPDATE_INFO_FILE = __DIR__ . '/.update_available';
const SIMANTAP_UPDATING_FLAG = __DIR__ . '/.updating';

function simantapIsDevMode(): bool {
    return defined('SIMANTAP_DEV_MODE') && SIMANTAP_DEV_MODE === true;
}

/**
 * @return array{ok: bool, reason: string, message: string, payload?: array}
 */
function simantapLicenseStatus(): array {
    if (simantapIsDevMode()) {
        return ['ok' => true, 'reason' => 'dev_mode', 'message' => 'Mode development.'];
    }

    if (!defined('LICENSE_PUBLIC_KEY') || LICENSE_PUBLIC_KEY === '') {
        return ['ok' => false, 'reason' => 'no_pubkey',
            'message' => 'Bundle tidak memiliki kunci publik lisensi. Gunakan bundle resmi dari Portal SIMANTAP BTIKP.'];
    }

    if (!is_readable(SIMANTAP_LICENSE_FILE)) {
        return ['ok' => false, 'reason' => 'no_license',
            'message' => 'Lisensi instalasi tidak ditemukan. Lakukan aktivasi melalui wizard /install.'];
    }

    $data = json_decode((string)@file_get_contents(SIMANTAP_LICENSE_FILE), true);
    $parts = explode('.', (string)($data['license'] ?? ''));
    $json = count($parts) === 2 ? base64_decode($parts[0], true) : false;
    $sig = count($parts) === 2 ? base64_decode($parts[1], true) : false;

    $invalid = ['ok' => false, 'reason' => 'invalid_license',
        'message' => 'Lisensi instalasi tidak sah. Lakukan aktivasi ulang melalui wizard /install.'];

    if ($json === false || $sig === false) return $invalid;
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return ['ok' => false, 'reason' => 'no_sodium',
            'message' => 'Ekstensi PHP sodium tidak tersedia. Hubungi penyedia hosting.'];
    }
    if (!@sodium_crypto_sign_verify_detached($sig, $json, (string)base64_decode(LICENSE_PUBLIC_KEY))) {
        return $invalid;
    }

    $payload = json_decode($json, true);
    if (!is_array($payload)) return $invalid;

    $licHost = strtolower((string)(parse_url((string)($payload['installed_url'] ?? ''), PHP_URL_HOST) ?? ''));
    $curHost = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($licHost === '' || $curHost === '' || $licHost !== $curHost) {
        return ['ok' => false, 'reason' => 'host_mismatch',
            'message' => "Lisensi terdaftar untuk domain lain ({$licHost}). "
                . 'Jika sekolah Anda baru pindah hosting/domain, lakukan aktivasi ulang '
                . 'dengan NPSN dan token Anda melalui wizard /install.',
            'payload' => $payload];
    }

    if (is_file(SIMANTAP_REVOKED_FILE)) {
        $reason = trim((string)@file_get_contents(SIMANTAP_REVOKED_FILE));
        return ['ok' => false, 'reason' => 'revoked',
            'message' => 'Lisensi aplikasi ini telah dicabut oleh BTIKP.'
                . ($reason !== '' ? " Alasan: {$reason}." : '') . ' Hubungi BTIKP Disdikbud Prov. Kalsel.'];
    }

    return ['ok' => true, 'reason' => 'valid', 'message' => 'Lisensi valid.', 'payload' => $payload];
}

/** Gerbang untuk endpoint API: blokir request bila lisensi tidak valid. */
function simantapLicenseGuard(): void {
    $st = simantapLicenseStatus();
    if ($st['ok']) {
        simantapMaybeHeartbeat();
        return;
    }
    if ($st['reason'] === 'revoked') {
        // Tetap heartbeat agar pemulihan lisensi oleh BTIKP bisa terdeteksi
        simantapMaybeHeartbeat();
        $st = simantapLicenseStatus();
        if ($st['ok']) return;
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode([
        'error' => $st['message'],
        'license_error' => $st['reason'],
    ], JSON_UNESCAPED_UNICODE));
}

/** Heartbeat best-effort ke portal, maksimal sekali per hari. */
function simantapMaybeHeartbeat(): void {
    if (!defined('SIMANTAP_PORTAL_URL') || SIMANTAP_PORTAL_URL === '') return;
    if (!function_exists('curl_init')) return;

    $last = is_file(SIMANTAP_HEARTBEAT_STAMP) ? (int)@file_get_contents(SIMANTAP_HEARTBEAT_STAMP) : 0;
    if (time() - $last < 86400) return;
    // Stempel ditulis sebelum request agar kegagalan jaringan tidak memicu
    // percobaan ulang di setiap request berikutnya.
    @file_put_contents(SIMANTAP_HEARTBEAT_STAMP, (string)time());

    $data = json_decode((string)@file_get_contents(SIMANTAP_LICENSE_FILE), true);
    $license = (string)($data['license'] ?? '');
    if ($license === '') return;

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $currentUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $ch = curl_init(rtrim(SIMANTAP_PORTAL_URL, '/') . '/api/heartbeat.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'license' => $license,
            'url' => $currentUrl,
            'app_version' => defined('SIMANTAP_APP_VERSION') ? SIMANTAP_APP_VERSION : '',
        ], JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) return; // offline → biarkan, coba lagi besok

    $res = json_decode($raw, true);
    if (!is_array($res) || empty($res['success'])) return;

    if (!empty($res['data']['revoked'])) {
        @file_put_contents(SIMANTAP_REVOKED_FILE, (string)($res['data']['reason'] ?? ''));
    } elseif (is_file(SIMANTAP_REVOKED_FILE)) {
        @unlink(SIMANTAP_REVOKED_FILE); // dipulihkan oleh BTIKP
    }

    // Info patch update dari portal → penanda untuk notif di UI
    $update = $res['data']['update'] ?? null;
    if (is_array($update) && !empty($update['version'])) {
        @file_put_contents(SIMANTAP_UPDATE_INFO_FILE, json_encode([
            'version' => (string)$update['version'],
            'notes' => (string)($update['notes'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
    } elseif (is_file(SIMANTAP_UPDATE_INFO_FILE)) {
        @unlink(SIMANTAP_UPDATE_INFO_FILE);
    }
}
