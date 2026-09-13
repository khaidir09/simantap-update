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
