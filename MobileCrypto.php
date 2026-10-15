<?php
declare(strict_types=1);

namespace AstroPet;

require_once __DIR__ . '/Support.php';

/** Protocol primitives; no network calls or stored account data. */
final class MobileCrypto
{
    private string $material;
    private string $certMessage;
    private string $clientId;
    private const SIGN_KEYS = ['a','appVersion','chKey','clientId','deviceId','et','h5','h5Token','lang','lat','lon','n4h5','os','postData','requestId','sid','sp','time','ttid','v'];

    public function __construct(array $profile)
    {
        foreach (['app_id','app_secret','cert_sha256_hex','app_key','package'] as $key) {
            if (!is_string($profile[$key] ?? null) || $profile[$key] === '' || Support::contains($profile[$key], "\0")) {
                throw new \RuntimeException('Mobiles App-Profil ist unvollständig.');
            }
        }
        $cert = str_replace(':', '', $profile['cert_sha256_hex']);
        if (!preg_match('/\A[a-fA-F0-9]{64}\z/D', $cert)) { throw new \RuntimeException('Ungültiger App-Zertifikatsfingerabdruck.'); }
        $this->clientId = $profile['app_id'];
        $this->certMessage = $profile['package'] . '_' . implode(':', str_split(strtoupper($cert), 2));
        $this->material = $this->certMessage . '_' . $profile['app_key'] . '_' . $profile['app_secret'];
    }

    public function sign(string $canonical): string { return hash_hmac('sha256', $canonical, hash('sha256', $this->material, true)); }
    public function channelKey(): string { return substr(hash_hmac('sha256', $this->certMessage, $this->clientId), 8, 8); }
    public function requestKey(string $requestId, string $ecode = ''): string { return substr(hash_hmac('sha256', $this->material . ($ecode !== '' ? '_' . $ecode : ''), $requestId), 0, 16); }

    public static function canonical(array $params): string
    {
        ksort($params, SORT_STRING);
        $parts = [];
        foreach ($params as $key => $value) {
            if (!in_array($key, self::SIGN_KEYS, true) || $value === '') { continue; }
            if (!is_string($value)) { throw new \RuntimeException('Ungültiger mobiler Requestparameter.'); }
            if ($key === 'postData') {
                $md5 = md5($value);
                $value = substr($md5, 8, 8) . substr($md5, 0, 8) . substr($md5, 24, 8) . substr($md5, 16, 8);
            }
            $parts[] = $key . '=' . $value;
        }
        return implode('||', $parts);
    }

    public static function encrypt(string $key, array $payload, ?string $nonce = null): string
    {
        if (!extension_loaded('openssl') || strlen($key) !== 16) { throw new \RuntimeException('OpenSSL oder gültiger mobiler Schlüssel fehlt.'); }
        $nonce ??= random_bytes(12);
        if (strlen($nonce) !== 12) { throw new \RuntimeException('Ungültige mobile Nonce.'); }
        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false || strlen($tag) !== 16) { throw new \RuntimeException('Mobile Anfrage konnte nicht verschlüsselt werden.'); }
        return base64_encode($nonce . $cipher . $tag);
    }

    public static function decrypt(string $key, string $encoded): array
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28 || strlen($raw) > 2097152 || strlen($key) !== 16) {
            throw new \RuntimeException('Ungültige verschlüsselte Mobilantwort.');
        }
        $plain = openssl_decrypt(substr($raw, 12, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, -16));
        if ($plain === false) { throw new \RuntimeException('Mobile Antwortintegrität konnte nicht bestätigt werden.'); }
        if (Support::startsWith($plain, "\x1f\x8b")) {
            $plain = @gzdecode($plain, 2097152);
            if ($plain === false) { throw new \RuntimeException('Mobile Antwort konnte nicht entpackt werden.'); }
        }
        try { $decoded = json_decode($plain, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $ignoredException) { throw new \RuntimeException('Mobile Antwort enthält ungültiges JSON.'); }
        if (!is_array($decoded)) { throw new \RuntimeException('Mobile Antwort enthält kein JSON-Objekt.'); }
        return $decoded;
    }

    public static function responseAuth(string $result, string $time, string $key): string
    {
        return md5('result=' . $result . '||t=' . $time . '||' . $key);
    }

    /** RSA/PKCS1-v1.5 over lowercase MD5 hex. Decimal modulus conversion uses no GMP. */
    public static function rsaPassword(string $password, string $modulus, string $exponent): string
    {
        $rsa = self::der(0x30, self::derInteger(self::decimalBytes($modulus)) . self::derInteger(self::decimalBytes($exponent)));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $der = self::der(0x30, $algorithm . self::der(0x03, "\0" . $rsa));
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) { throw new \RuntimeException('Mobiler RSA-Schlüssel konnte nicht gelesen werden.'); }
        $cipher = '';
        if (!openssl_public_encrypt(md5($password), $cipher, $publicKey, OPENSSL_PKCS1_PADDING)) {
            throw new \RuntimeException('Mobile Anmeldedaten konnten nicht verschlüsselt werden.');
        }
        return bin2hex($cipher);
    }

    private static function decimalBytes(string $decimal): string
    {
        if (!preg_match('/\A[0-9]{1,2500}\z/D', $decimal)) { throw new \RuntimeException('Ungültige mobile RSA-Zahl.'); }
        $decimal = ltrim($decimal, '0');
        if ($decimal === '') { return "\0"; }
        $result = '';
        while ($decimal !== '') {
            $remainder = 0;
            $quotient = '';
            for ($i = 0, $n = strlen($decimal); $i < $n; ++$i) {
                $current = $remainder * 10 + (ord($decimal[$i]) - 48);
                $digit = intdiv($current, 256);
                $remainder = $current % 256;
                if ($digit !== 0 || $quotient !== '') { $quotient .= (string) $digit; }
            }
            $result = chr($remainder) . $result;
            $decimal = $quotient;
        }
        return $result;
    }

    private static function derInteger(string $bytes): string
    {
        if ((ord($bytes[0]) & 0x80) !== 0) { $bytes = "\0" . $bytes; }
        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $bytes): string
    {
        $length = strlen($bytes);
        if ($length < 128) { return chr($tag) . chr($length) . $bytes; }
        $encodedLength = '';
        while ($length > 0) { $encodedLength = chr($length & 255) . $encodedLength; $length >>= 8; }
        return chr($tag) . chr(0x80 | strlen($encodedLength)) . $encodedLength . $bytes;
    }
}
