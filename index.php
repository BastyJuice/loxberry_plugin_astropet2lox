<?php
declare(strict_types=1);

// Use the PHP runtime already provided by the LoxBerry web server.
ini_set('display_errors', '0');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (PHP_VERSION_ID < 70400 || PHP_INT_SIZE !== 8) {
    http_response_code(503);
    if ($method !== 'HEAD') { echo 'AstroPet2Lox benötigt LoxBerry 4 mit PHP ab 7.4 und 64 Bit.'; }
    exit;
}
// Apache protects htmlauth with the existing LoxBerry administrator login.
if (!is_string($_SERVER['REMOTE_USER'] ?? null) || $_SERVER['REMOTE_USER'] === '') {
    http_response_code(401);
    if ($method !== 'HEAD') { echo 'Bitte das Plugin über die angemeldete LoxBerry-Oberfläche öffnen.'; }
    exit;
}
if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, HEAD');
    exit;
}
try {
    require_once 'loxberry_system.php';
    require_once 'loxberry_web.php';
    if (!defined('LBPBINDIR')) { throw new RuntimeException('LoxBerry-Pluginpfad fehlt.'); }
    require LBPBINDIR . '/lib/UserInterface.php';
} catch (Throwable $error) {
    http_response_code(503);
    if ($method !== 'HEAD') { echo 'AstroPet2Lox konnte nicht geladen werden. Installation und private Plugin-Ordner prüfen.'; }
}
