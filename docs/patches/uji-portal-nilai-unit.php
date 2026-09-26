<?php
// Uji unit fungsi murni api/portal_nilai.php (tanpa DB/HTTP). Spesifikasi §10.1.
// Jalankan dari root deploy: php docs/patches/uji-portal-nilai-unit.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/portal_nilai.php';

$pass = 0; $failed = 0;
function cek(string $id, bool $cond, string $info = ''): void
{
    global $pass, $failed;
    if ($cond) { $pass++; echo "PASS $id\n"; }
    else { $failed++; echo "FAIL $id $info\n"; }
}

// --- PIN ---
$pins = [];
$polaOk = true;
for ($i = 0; $i < 1000; $i++) {
    $p = portalNilaiBuatPin();
    $pins[$p] = true;
    if (!preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $p)) { $polaOk = false; break; }
}
cek('PIN 8 karakter dari alfabet aman', $polaOk);
cek('1000 PIN tanpa duplikat', count($pins) === 1000);
cek('normalisasi PIN', portalNilaiNormalisasiPin(' abcd-efgh ') === 'ABCDEFGH' && portalNilaiNormalisasiPin("ab cd\tef-gh") === 'ABCDEFGH');
cek('format PIN', portalNilaiFormatPin('ABCDEFGH') === 'ABCD-EFGH');
cek('normalisasi NISN', portalNilaiNormalisasiNisn(' 0012.345 678 ') === '0012345678' && portalNilaiNormalisasiNisn(str_repeat('1', 21)) === '' && portalNilaiNormalisasiNisn('abc') === '');

// --- Cookie ---
$hash  = password_hash('ABCDEFGH', PASSWORD_DEFAULT);
$hash2 = password_hash('ABCDEFGH', PASSWORD_DEFAULT); // reset: hash baru walau PIN kebetulan sama
$now = 1_800_000_000;
$raw = portalNilaiBuatCookie('s_uji.1/ä', $now + 100, $hash);
$c = portalNilaiBacaCookie($raw);
cek('cookie terbaca', $c !== null && $c['student_id'] === 's_uji.1/ä' && $c['exp'] === $now + 100, var_export($c, true));
cek('cookie valid dengan hash sama', $c && portalNilaiCookieValid($c, $hash, $now));
cek('cookie ditolak setelah reset (hash berbeda)', $c && !portalNilaiCookieValid($c, $hash2, $now));
cek('cookie kedaluwarsa ditolak', $c && !portalNilaiCookieValid($c, $hash, $now + 100));
$rusak = substr($raw, 0, -1) . (substr($raw, -1) === 'a' ? 'b' : 'a');
$cr = portalNilaiBacaCookie($rusak);
cek('tanda tangan diubah ditolak', $cr === null || !portalNilaiCookieValid($cr, $hash, $now));
[$b64, $exp, $sig] = explode('.', $raw);
$palsu = portalNilaiBacaCookie(portalNilaiB64('s_lain') . ".$exp.$sig");
cek('student_id diganti ditolak', $palsu !== null && !portalNilaiCookieValid($palsu, $hash, $now));
cek('format rusak ditolak', portalNilaiBacaCookie('') === null && portalNilaiBacaCookie('a.b') === null
    && portalNilaiBacaCookie('!!.1.' . str_repeat('a', 64)) === null && portalNilaiBacaCookie($b64 . '.x1.' . $sig) === null);

// --- URL portal ---
cek('URL root domain', portalNilaiUrl(['HTTP_HOST' => 'nilai.sch.id', 'SCRIPT_NAME' => '/api/index.php', 'HTTPS' => 'on']) === 'https://nilai.sch.id/nilai/');
cek('URL subfolder', portalNilaiUrl(['HTTP_HOST' => 'localhost', 'SCRIPT_NAME' => '/simantap/api/index.php']) === 'http://localhost/simantap/nilai/');
cek('URL dari nilai/index.php', portalNilaiBasePath('/simantap/nilai/index.php') === '/simantap' && portalNilaiBasePath('/nilai/index.php') === '');
cek('URL proxy https', portalNilaiUrl(['HTTP_HOST' => 'a.id:8443', 'SCRIPT_NAME' => '/api/index.php', 'HTTP_X_FORWARDED_PROTO' => 'https']) === 'https://a.id:8443/nilai/');

// --- Asal permintaan ---
cek('asal sama (Origin)', portalNilaiAsalSama(['HTTP_HOST' => 'a.id', 'HTTP_ORIGIN' => 'https://a.id']));
cek('asal sama (Referer + port)', portalNilaiAsalSama(['HTTP_HOST' => '127.0.0.1:8765', 'HTTP_REFERER' => 'http://127.0.0.1:8765/']));
cek('asal lain ditolak', !portalNilaiAsalSama(['HTTP_HOST' => 'a.id', 'HTTP_ORIGIN' => 'https://evil.example'])
    && !portalNilaiAsalSama(['HTTP_HOST' => 'a.id']) && !portalNilaiAsalSama(['HTTP_HOST' => 'a.id', 'HTTP_ORIGIN' => 'https://a.id.evil.example']));

echo "\nHasil: $pass lulus, $failed gagal\n";
exit($failed ? 1 : 0);
