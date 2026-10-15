<?php
declare(strict_types=1);

namespace AstroPet;

require_once __DIR__ . '/Support.php';

require_once __DIR__ . '/MobileCrypto.php';

final class MobileApiError extends \RuntimeException
{
    public string $apiCode;

    public function __construct(string $apiCode)
    {
        $this->apiCode = $apiCode;
        // Server strings can contain user data, even in a field named errorCode.
        parent::__construct('Tuya-Mobil-API hat die Anfrage abgelehnt. Bei abgelaufener Sitzung im Browser erneut verbinden.');
    }
}

/** Read-only client for the private protocol in AstroPet 1.0.22. */
final class TuyaMobile
{
    private MobileCrypto $crypto;
    private \Closure $transport;
    private string $url = 'https://a1.tuyaeu.com/api.json';
    private string $sid = '';
    private string $ecode = '';
    private string $uid = '';
    private string $deviceId;
    private array $profile;
    private ?int $requestDeadline = null;
    private const ACTIONS = [
        'smartlife.m.user.username.token.get'=>'2.0', 'smartlife.m.user.uid.password.login.reg'=>'1.0',
        'm.life.home.space.list'=>'1.0', 'm.life.location.get'=>'3.4',
        'm.life.my.group.device.list'=>'2.2', 'smartlife.m.device.get'=>'4.1',
        'smartlife.m.device.dp.get'=>'1.0', 'm.life.device.ref.info.my.list'=>'7.2',
    ];

    public function __construct(array $profile, string $deviceId = '', ?callable $transport = null)
    {
        $profile['cert_sha256_hex'] ??= $profile['certificate_sha256'] ?? null;
        $profile['package'] ??= $profile['package_name'] ?? null;
        $this->crypto = new MobileCrypto($profile);
        $this->profile = $profile;
        $this->deviceId = $deviceId !== '' ? $deviceId : bin2hex(random_bytes(16));
        $this->transport = $transport === null
            ? fn(string $url, array $params): array => self::http($url, $params, $this->remainingBudget())
            : \Closure::fromCallable($transport);
    }

    /** One total monotonic budget shared by all calls on this client instance. */
    public function setRequestBudget(int $milliseconds): void
    {
        if ($milliseconds < 1 || $milliseconds > 120000) { throw new \RuntimeException('Ungültiges mobiles Zeitbudget.'); }
        $this->requestDeadline = hrtime(true) + $milliseconds * 1000000;
    }

    private function remainingBudget(): int
    {
        $remaining = $this->requestDeadline === null ? 20000 : (int) floor(($this->requestDeadline - hrtime(true)) / 1000000);
        if ($remaining < 1) { throw new \RuntimeException('Mobile Anfrage hat das Zeitbudget überschritten.'); }
        return $remaining;
    }

    public function token(string $externalUid): array
    {
        return $this->call('smartlife.m.user.username.token.get', ['countryCode'=>'49','username'=>$externalUid,'isUid'=>true], false);
    }

    public function loginUid(string $externalUid, string $password): void
    {
        $token = $this->token($externalUid);
        foreach (['publicKey','exponent','token'] as $key) {
            if (!is_string($token[$key] ?? null) && !is_int($token[$key] ?? null)) { throw new \RuntimeException('Mobile Tokenantwort ist unvollständig.'); }
        }
        $cipher = MobileCrypto::rsaPassword($password, (string) $token['publicKey'], (string) $token['exponent']);
        $result = $this->call('smartlife.m.user.uid.password.login.reg', [
            'countryCode'=>'49', 'uid'=>$externalUid, 'passwd'=>$cipher, 'token'=>$token['token'],
            'ifencrypt'=>1, 'createGroup'=>false, 'options'=>'{"group": 1}',
        ], false);
        foreach (['sid','ecode','uid'] as $field) {
            if (!is_string($result[$field] ?? null) || $result[$field] === '' || strlen($result[$field]) > 8192
                || preg_match('/[\x00-\x20\x7f]/', $result[$field])) { throw new \RuntimeException('Mobile Anmeldung lieferte keine vollständige Sitzung.'); }
        }
        $candidateUrl = $this->url;
        $domain = $result['domain']['mobileApiUrl'] ?? null;
        if (is_string($domain) && $domain !== '') { $candidateUrl = self::validatedUrl($domain); }
        $this->sid = $result['sid']; $this->ecode = $result['ecode']; $this->uid = $result['uid']; $this->url = $candidateUrl;
    }

    public function homes(): array { return $this->call('m.life.home.space.list', null); }
    public function devices(int $gid): array { return $this->call('m.life.my.group.device.list', ['gid'=>$gid], true, $gid); }
    public function products(int $gid): array { return $this->call('m.life.device.ref.info.my.list', ['gid'=>$gid,'zigbeeGroup'=>true], true, $gid); }
    public function device(string $deviceId, int $gid): array { return $this->call('smartlife.m.device.get', ['devId'=>$deviceId], true, $gid); }
    public function dps(string $deviceId, int $gid): array { return $this->call('smartlife.m.device.dp.get', ['devId'=>$deviceId], true, $gid); }

    public function exportSession(): array
    {
        return ['sid'=>$this->sid,'ecode'=>$this->ecode,'uid'=>$this->uid,'mobile_url'=>$this->url,'device_id'=>$this->deviceId];
    }

    public function importSession(array $session): void
    {
        foreach (['sid','ecode','uid','device_id'] as $key) {
            if (!is_string($session[$key] ?? null) || $session[$key] === '' || strlen($session[$key]) > 8192
                || preg_match('/[\x00-\x20\x7f]/', $session[$key])) { throw new \RuntimeException('Ungültige mobile Sitzung.'); }
        }
        $this->url = self::validatedUrl((string) ($session['mobile_url'] ?? ''));
        $this->sid = $session['sid']; $this->ecode = $session['ecode']; $this->uid = $session['uid']; $this->deviceId = $session['device_id'];
    }

    private function call(string $action, ?array $payload, bool $session = true, ?int $gid = null): array
    {
        if (!isset(self::ACTIONS[$action])) { throw new \RuntimeException('Nicht unterstützter mobiler Leseaufruf.'); }
        if ($session && ($this->sid === '' || $this->ecode === '')) { throw new \RuntimeException('Mobile Gerätesitzung fehlt.'); }
        $requestId = self::uuid();
        $key = $this->crypto->requestKey($requestId, $session ? $this->ecode : '');
        $defaults = $this->profile['wire_defaults'] ?? [];
        $params = [
            'a'=>$action, 'v'=>self::ACTIONS[$action], 'clientId'=>$this->profile['app_id'],
            'os'=>'Android', 'appVersion'=>'1.0.22', 'channel'=>'sdk', 'osSystem'=>'13',
            'sdkVersion'=>$defaults['sdkVersion'] ?? '7.5.0',
            'deviceCoreVersion'=>$defaults['deviceCoreVersion'] ?? '7.5.0',
            'platform'=>'PHP-Bridge', 'timeZoneId'=>'Europe/Berlin', 'cp'=>'gzip', 'nd'=>'1',
            'lang'=>'de', 'ttid'=>$defaults['ttid'] ?? 'android', 'et'=>'3',
            'chKey'=>$this->crypto->channelKey(), 'deviceId'=>$this->deviceId,
            'time'=>(string) time(), 'requestId'=>$requestId,
        ];
        if ($payload !== null) { $params['postData'] = MobileCrypto::encrypt($key, $payload); }
        if ($session) { $params['sid'] = $this->sid; }
        if ($gid !== null) { $params['gid'] = (string) $gid; }
        $params['sign'] = $this->crypto->sign(MobileCrypto::canonical($params));
        $this->remainingBudget();
        try { $response = ($this->transport)($this->url, $params); }
        catch (\Throwable $ignoredException) { throw new \RuntimeException('Tuya-Mobilverbindung fehlgeschlagen.'); }
        $this->remainingBudget();
        $data = $response['data'] ?? null;
        if (($response['status'] ?? 0) !== 200 || !is_array($data)) { throw new \RuntimeException('Tuya-Mobilverbindung fehlgeschlagen.'); }
        if (($data['success'] ?? true) === false || !is_string($data['result'] ?? null)) {
            throw new MobileApiError((string) ($data['errorCode'] ?? $data['code'] ?? 'invalid_envelope'));
        }
        $t = $data['t'] ?? '';
        $responseSign = $data['sign'] ?? null;
        if (!is_string($responseSign) || !preg_match('/\A[a-fA-F0-9]{32}\z/D', $responseSign)
            || (!is_string($t) && !is_int($t)) || !preg_match('/\A[0-9]{10,16}\z/D', (string) $t)
            || !hash_equals(strtolower($responseSign), MobileCrypto::responseAuth($data['result'], (string) $t, $key))) {
            throw new \RuntimeException('Mobile Antwortsignatur ist ungültig.');
        }
        $decoded = MobileCrypto::decrypt($key, $data['result']);
        if (($decoded['success'] ?? true) === false || !empty($decoded['errorCode'])) {
            throw new MobileApiError((string) ($decoded['errorCode'] ?? $decoded['code'] ?? 'unknown'));
        }
        $result = $decoded['result'] ?? $decoded;
        if (!is_array($result)) { throw new \RuntimeException('Unerwartetes Format der mobilen API-Antwort.'); }
        $this->remainingBudget();
        return $result;
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);
    }

    private static function validatedUrl(string $url): string
    {
        $url = rtrim($url, '/');
        if (!Support::endsWith($url, '/api.json')) { $url .= '/api.json'; }
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $allowed = in_array($host, ['a1.tuyaeu.com','a1.tuyacn.com','a1.tuyaus.com','a1-us.iotbing.com','a1-in.iotbing.com'], true)
            || preg_match('/\A[a-z0-9.-]+\.(tuyaeu|tuyacn|tuyaus)\.com\z/D', $host);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !$allowed || ($parts['path'] ?? '') !== '/api.json'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('Unbekannter mobiler Tuya-Server.');
        }
        return $url;
    }

    private static function http(string $url, array $params, int $timeoutMs): array
    {
        if (!extension_loaded('curl')) { throw new \RuntimeException('PHP-cURL fehlt.'); }
        $url = self::validatedUrl($url);
        $handle = curl_init($url);
        if ($handle === false) { throw new \RuntimeException('Tuya-Mobilverbindung konnte nicht gestartet werden.'); }
        $body = '';
        curl_setopt_array($handle, [
            CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],
            CURLOPT_USERAGENT=>'AstroPet-PHP-Bridge/0.2', CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT_MS=>min(1500, $timeoutMs), CURLOPT_TIMEOUT_MS=>$timeoutMs, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($ch,string $chunk) use (&$body): int {
                if (strlen($body)+strlen($chunk)>2097152) { return 0; }
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) { curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'https'); }
        else { curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS); }
        $executed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($executed === false) { throw new \RuntimeException('Tuya-Mobilverbindung fehlgeschlagen.'); }
        try { $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $ignoredException) { throw new \RuntimeException('Tuya-Mobilserver lieferte kein gültiges JSON.'); }
        return ['status'=>$status,'data'=>$data];
    }
}
