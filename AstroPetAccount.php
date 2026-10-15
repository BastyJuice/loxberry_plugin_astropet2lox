<?php
declare(strict_types=1);

namespace AstroPet;

require_once __DIR__ . '/Support.php';

use Closure;
use JsonException;
use RuntimeException;
use Throwable;

/** The APK handles API code 102 by asking for an email login confirmation. */
final class AccountVerificationRequired extends RuntimeException
{
}

/**
 * AstroPet account login for the Android app 1.0.22 protocol.
 * This returns account credentials; it does not retrieve Mercury device data.
 * The class never prints or logs credentials, response bodies, or server messages.
 */
final class AstroPetAccount
{
    private const BASE_URL = 'https://astropet.petrhein.de/astropet/';
    private const AUTH_PREFIX = 'bd310fc6-0472-46f3-8b3f-9a07cdb284ac';
    private const MAX_RESPONSE_BYTES = 1048576;

    private Closure $transport;
    private string $sessionToken = '';

    /**
     * Optional test transport: ($method, $url, $headers, $body) =>
     * ['status' => int, 'headers' => ['lowercase-name' => string], 'data' => array].
     * Request headers are an associative array; body is a JSON string or null.
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null
            ? Closure::fromCallable([self::class, 'curlRequest'])
            : Closure::fromCallable($transport);
    }

    /** Sensitive browser-session continuity value; never send it to a client or log. */
    public function getSessionToken(): string
    {
        return $this->sessionToken;
    }

    /** Restore only a server-side session token from protected .php state storage. */
    public function setSessionToken(string $token): void
    {
        self::validateToken($token);
        $this->sessionToken = $token;
    }

    /**
     * Returns the full account data, plus 'app-token' from response headers.
     * uid, tuyaPassword and app-token are sensitive: do not expose this result
     * through a web endpoint or put it in logs.
     * No request is made until this method is explicitly called.
     */
    public function login(string $email, string $password): array
    {
        $email = self::normalizeLikeApp($email);
        $password = self::normalizeLikeApp($password);
        if ($email === '' || $password === '') {
            throw new RuntimeException('AstroPet: E-Mail oder Passwort fehlen.');
        }

        $codeResponse = $this->request(
            'GET',
            'web/user/getCode?email=' . rawurlencode($email),
            null,
            'Kontocode'
        );
        $salt = $codeResponse['data']['data'];
        if ($salt === null) {
            $salt = '';
        }
        if (!is_string($salt)) {
            throw new RuntimeException('AstroPet: Unerwartetes Format des Kontocodes.');
        }
        $salt = self::normalizeTextLikeApp($salt);

        $body = json_encode([
            'email' => $email,
            'password' => md5($email . $password . $salt),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $loginResponse = $this->request('POST', 'web/user/login30', $body, 'Anmeldung');
        return $this->accountData($loginResponse);
    }

    /**
     * Explicitly sends the email login code used for API code 102.
     * Call only when the account owner has requested this email.
     * login() does not call this method automatically.
     */
    public function requestVerificationCode(string $email): void
    {
        $email = self::normalizeTextLikeApp($email);
        if ($email === '') {
            throw new RuntimeException('AstroPet: E-Mail für die Anmeldebestätigung fehlt.');
        }
        $this->request('PUT', 'web/user/captcha-mail?email=' . rawurlencode($email), null, 'E-Mail-Code');
    }

    /**
     * Completes the APK's email login challenge; sends only email and captcha.
     * Returns sensitive account data with the current 'app-token'.
     */
    public function verifyCode(string $email, string $code): array
    {
        // This APK path uses StringUtils.f(), preserving ASCII spaces.
        $email = self::normalizeTextLikeApp($email);
        $code = self::normalizeTextLikeApp($code);
        if ($email === '' || $code === '') {
            throw new RuntimeException('AstroPet: E-Mail oder Bestätigungscode fehlen.');
        }
        $body = json_encode(['captcha' => $code, 'email' => $email],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = $this->request('POST', 'web/user/password/valid-captcha-new', $body, 'Anmeldebestätigung');
        return $this->accountData($response);
    }

    private function accountData(array $response): array
    {
        $account = $response['data']['data'];
        if (!is_array($account)) {
            throw new RuntimeException('AstroPet: Unerwartetes Format der Kontodaten.');
        }
        $account['app-token'] = $this->sessionToken;
        return $account;
    }

    /** Exact ZDigestUtils.a() format from the supplied APK. */
    public static function makeAuthHeader(string $millis, string $uuid): string
    {
        if (!preg_match('/^[0-9]+$/D', $millis)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $uuid)) {
            throw new RuntimeException('AstroPet: Ungültige Signaturparameter.');
        }
        return md5(self::AUTH_PREFIX . $millis . $uuid) . ':' . $millis . ':' . $uuid;
    }

    private static function normalizeLikeApp(string $value): string
    {
        // StringUtils.e(): remove every ASCII space, preserve other characters.
        return str_replace(' ', '', self::normalizeTextLikeApp($value));
    }

    private static function normalizeTextLikeApp(string $value): string
    {
        // StringUtils.f(): preserve text, treating literal "null" as empty.
        if (strtolower(trim($value, "\x00..\x20")) === 'null') {
            return '';
        }
        return $value;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function request(string $method, string $path, ?string $body, string $stage): array
    {
        $millis = sprintf('%.0f', floor(microtime(true) * 1000));
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
            'medrhein-auth' => self::makeAuthHeader($millis, self::uuid()),
            // The server accepted this Android wire profile during an authorized
            // test, while a PHP os/version profile was rejected. This is protocol
            // compatibility metadata; the actual runtime remains PHP. It does not
            // establish which individual field caused the earlier rejection.
            'os-info' => json_encode([
                'os' => 'ANDROID',
                'osVersion' => '13',
                'appVersion' => '1.0.22',
                'deviceId' => '',
                'binderId' => '',
                'make' => 'PHP',
                'model' => 'Webserver',
                'language' => 'de',
                'remark' => 'google_services:false',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'app-token' => $this->sessionToken,
        ];
        try {
            $response = ($this->transport)($method, self::BASE_URL . $path, $headers, $body);
        } catch (Throwable $exception) {
            // A transport exception may contain the request or server response.
            // Do not attach it as a previous exception or copy its message.
            throw new RuntimeException('AstroPet: ' . $stage . ' konnte nicht abgerufen werden.');
        }
        if (!is_array($response) || !isset($response['status']) || !is_int($response['status'])
            || !isset($response['headers']) || !is_array($response['headers'])
            || !isset($response['data']) || !is_array($response['data'])) {
            throw new RuntimeException('AstroPet: Ungültige Transportantwort bei ' . $stage . '.');
        }
        $status = $response['status'];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('AstroPet: HTTP-Fehler ' . $status . ' bei ' . $stage . '.');
        }
        // The APK also captures rotated tokens from unsuccessful API responses.
        $token = self::responseToken($response);
        if ($token !== '') {
            $this->sessionToken = $token;
        }
        $data = $response['data'];
        if (($data['code'] ?? null) === 102) {
            throw new AccountVerificationRequired('AstroPet: Die Anmeldung muss per E-Mail-Code bestätigt werden (API-Code 102).');
        }
        // ResultCode.SUCCESS is exactly integer 200 in app 1.0.22.
        if (!isset($data['code']) || $data['code'] !== 200 || !array_key_exists('data', $data)) {
            $code = isset($data['code']) && is_int($data['code']) ? (string) $data['code'] : 'unbekannt';
            throw new RuntimeException('AstroPet: ' . $stage . ' abgelehnt (API-Code ' . $code . ').');
        }
        return $response;
    }

    private static function responseToken(array $response): string
    {
        $token = $response['headers']['app-token'] ?? '';
        if (!is_string($token)) {
            throw new RuntimeException('AstroPet: Ungültiges Format des Sitzungstokens.');
        }
        self::validateToken($token);
        return $token;
    }

    private static function validateToken(string $token): void
    {
        if (strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new RuntimeException('AstroPet: Ungültiges Format des Sitzungstokens.');
        }
    }

    private static function curlRequest(string $method, string $url, array $headers, ?string $body): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP-cURL fehlt.');
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'astropet.petrhein.de'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('Ungültiges AstroPet-Ziel.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('cURL konnte nicht gestartet werden.');
        }
        $responseHeaders = [];
        $responseBody = '';
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'AstroPet-PHP-Bridge/0.1',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (preg_match('/^HTTP\/\S+\s+\d+/', $line)) {
                    $responseHeaders = [];
                } elseif (Support::contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'https');
        } else {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        try {
            if (curl_exec($handle) === false) {
                throw new RuntimeException('AstroPet-Transport fehlgeschlagen.');
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            unset($handle);
        }
        try {
            $data = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('AstroPet lieferte keine gültige JSON-Antwort.');
        }
        if (!is_array($data)) {
            throw new RuntimeException('AstroPet lieferte kein JSON-Objekt.');
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'data' => $data];
    }
}
