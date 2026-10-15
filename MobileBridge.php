<?php
declare(strict_types=1);

namespace AstroPet;

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/TuyaMobile.php';
require_once __DIR__ . '/MercuryValues.php';

/** Persistent read-only Mercury connection. Account passwords never enter state. */
final class MobileBridge
{
    private const VERSION = 1;
    private const MAX_HOMES = 20;
    private const MAX_DEVICES = 50;
    private const CONNECT_ERROR = 'Geräteverbindung fehlgeschlagen. Im Browser erneut verbinden.';
    private const POLL_ERROR = 'Gerätedaten konnten nicht aktualisiert werden. Bei abgelaufener Sitzung erneut verbinden.';
    private array $profile;
    private ?\Closure $transport;
    private string $configBinding;
    private string $statePath;
    private string $lockPath;
    private int $pollInterval;
    private array $mapping;

    public function __construct(array $config, string $dir, ?array $profile = null, ?callable $transport = null)
    {
        $dir = Support::stateDir(['state_dir' => $dir]);
        $this->statePath = rtrim($dir, '/') . '/mobile-state.php';
        $this->lockPath = rtrim($dir, '/') . '/refresh.lock.php';
        $this->profile = $profile ?? self::loadProfile();
        // Validate cryptographic material locally; constructing a client sends nothing.
        new TuyaMobile($this->profile, '', $transport);
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
        $interval = $config['poll_interval_seconds'] ?? 60;
        if (!is_int($interval) || $interval < 10 || $interval > 86400) {
            throw new \RuntimeException('poll_interval_seconds muss zwischen 10 und 86400 liegen.');
        }
        $this->pollInterval = $interval;
        $this->mapping = $config['mapping'] ?? [];
        if (!is_array($this->mapping)) { throw new \RuntimeException('mapping muss ein Array sein.'); }
        // Never persist the values used to derive this configuration fingerprint.
        $bindingConfig = [];
        foreach (['http_user', 'http_password', 'astropet_email', 'astropet_password', 'mapping'] as $key) {
            $bindingConfig[$key] = $config[$key] ?? null;
        }
        $this->configBinding = hash('sha256', json_encode(
            ['config' => $bindingConfig, 'profile' => $this->profile], JSON_THROW_ON_ERROR
        ));
    }

    /** @return array{connected:bool,device_count:int,devices:array,selected_index:?int,last_error:?string} */
    public function connect(array $account): array
    {
        $uid = $account['uid'] ?? null;
        $password = $account['tuyaPassword'] ?? null;
        unset($account);
        if ((!is_string($uid) && !is_int($uid)) || (string) $uid === '' || strlen((string) $uid) > 256
            || preg_match('/[\x00-\x20\x7f]/', (string) $uid)
            || !is_string($password) || $password === '' || strlen($password) > 8192 || Support::contains($password, "\0")) {
            throw new \RuntimeException(self::CONNECT_ERROR);
        }
        $lock = $this->lock(true);
        try {
            // A new account always replaces the previous session before login begins.
            $state = $this->emptyState();
            Support::writeJson($this->statePath, $state);
            try {
                $client = $this->client();
                $client->setRequestBudget(120000);
                $client->loginUid((string) $uid, $password);
                unset($password, $uid);
                [$devices, $initialDevices] = $this->discover($client);
                $session = $client->exportSession();
                $state['session'] = $session;
                $state['binding'] = $this->sessionBinding($session);
                $state['devices'] = $devices;
                $state['selected_index'] = count($devices) === 1 ? 0 : null;
                if ($state['selected_index'] !== null) {
                    try {
                        $state['snapshot'] = $this->successfulSnapshot($state, $devices[0], $initialDevices[0], time());
                    } catch (\Throwable $ignoredException) {
                        // Discovery can omit measurements; the next status GET performs device.get.
                        $state['last_error'] = self::POLL_ERROR;
                    }
                }
                Support::writeJson($this->statePath, $state);
            } catch (\Throwable $ignoredException) {
                $state = $this->emptyState();
                $state['last_error'] = self::CONNECT_ERROR;
                Support::writeJson($this->statePath, $state);
                throw new \RuntimeException(self::CONNECT_ERROR);
            }
        } finally {
            unset($password, $uid);
            self::unlock($lock);
        }
        return $this->info();
    }

    public function info(): array
    {
        $state = $this->readState();
        $connected = is_array($state['session'] ?? null);
        $devices = [];
        if ($connected) {
            foreach ($state['devices'] as $index => $device) {
                $devices[] = ['index' => $index, 'name' => $device['name']];
            }
        }
        return [
            'connected' => $connected,
            'device_count' => count($devices),
            'devices' => $devices,
            'selected_index' => $connected ? $state['selected_index'] : null,
            'last_error' => $state['last_error'] ?? null,
        ];
    }

    public function select(int $index): void
    {
        $lock = $this->lock(true);
        try {
            $state = $this->readState();
            if (!is_array($state['session'] ?? null) || $index < 0 || !isset($state['devices'][$index])) {
                throw new \RuntimeException('Das ausgewählte Gerät ist nicht verfügbar.');
            }
            if ($state['selected_index'] !== $index) {
                $state['selected_index'] = $index;
                $state['snapshot'] = null;
                $state['last_error'] = null;
                Support::writeJson($this->statePath, $state);
            }
        } finally { self::unlock($lock); }
    }

    public function disconnect(): void
    {
        $lock = $this->lock(true);
        try { Support::writeJson($this->statePath, $this->emptyState()); }
        finally { self::unlock($lock); }
    }

    /** One nonblocking global lock prevents status callers from queueing cloud polls. */
    public function refresh(bool $force = false): ?array
    {
        $lock = $this->lock(false);
        if ($lock === null) { return $this->snapshot(); }
        try {
            // Read only after locking: a concurrent disconnect cannot revive an old SID.
            $state = $this->readState();
            $selected = $state['selected_index'] ?? null;
            if (!is_array($state['session'] ?? null) || $selected === null) { return null; }
            $device = $state['devices'][$selected];
            $old = $this->stateSnapshot($state);
            $now = time();
            if (!$force && $old !== null && $old['last_attempt_at'] <= $now + 5
                && $now - $old['last_attempt_at'] < $this->pollInterval) { return $old; }
            try {
                $client = $this->client();
                $client->importSession($state['session']);
                $client->setRequestBudget(5500);
                $current = $client->device($device['devId'], $device['gid']);
                $state['snapshot'] = $this->successfulSnapshot($state, $device, $current, time());
                $state['last_error'] = null;
            } catch (\Throwable $ignoredException) {
                $timezoneId = $state['snapshot']['timezone_id'] ?? null;
                $state['snapshot'] = [
                    'binding' => $state['binding'], 'device_id' => $device['devId'],
                    'timezone_id' => is_string($timezoneId) ? $timezoneId : null,
                    'last_attempt_at' => time(), 'last_success_at' => $old['last_success_at'] ?? null,
                    'last_poll_ok' => false, 'online' => $old['online'] ?? null,
                    'values' => $old['values'] ?? [],
                ];
                $state['last_error'] = self::POLL_ERROR;
            }
            Support::writeJson($this->statePath, $state);
            return $this->stateSnapshot($state);
        } finally { self::unlock($lock); }
    }

    public function snapshot(): ?array
    {
        $state = $this->readState();
        return $state === null ? null : $this->stateSnapshot($state);
    }

    private function client(): TuyaMobile
    {
        return new TuyaMobile($this->profile, '', $this->transport);
    }

    /** @return array{0:array,1:array} */
    private function discover(TuyaMobile $client): array
    {
        $homes = $client->homes();
        if (!Support::isList($homes) || count($homes) > self::MAX_HOMES) {
            throw new \RuntimeException(self::CONNECT_ERROR);
        }
        $selected = [];
        $initial = [];
        $seen = [];
        $visitedHomes = [];
        foreach ($homes as $home) {
            if (!is_array($home)) { continue; }
            $gid = self::groupId($home['homeId'] ?? $home['gid'] ?? null);
            if ($gid === null || isset($visitedHomes[$gid])) { continue; }
            $visitedHomes[$gid] = true;
            $devices = $client->devices($gid);
            $products = $client->products($gid);
            if (!Support::isList($devices) || !Support::isList($products) || count($devices) > 1000 || count($products) > 1000) {
                throw new \RuntimeException(self::CONNECT_ERROR);
            }
            $schemas = [];
            foreach ($products as $product) {
                if (!is_array($product) || !self::identifier($product['id'] ?? null)) { continue; }
                $encoded = $product['schemaInfo']['schema'] ?? null;
                if (!is_string($encoded) || strlen($encoded) > 262144) { continue; }
                try { $schema = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException $ignoredException) { continue; }
                $schema = self::mercurySchema($schema);
                if ($schema !== null) { $schemas[$product['id']] = $schema; }
            }
            foreach ($devices as $device) {
                if (!is_array($device) || !self::identifier($device['devId'] ?? null)
                    || !self::identifier($device['productId'] ?? null) || isset($seen[$device['devId']])) { continue; }
                $schema = $schemas[$device['productId']] ?? null;
                if ($schema === null) { continue; }
                if (count($selected) >= self::MAX_DEVICES) { throw new \RuntimeException(self::CONNECT_ERROR); }
                $seen[$device['devId']] = true;
                $selected[] = [
                    'gid' => $gid, 'devId' => $device['devId'],
                    'name' => self::name($device['name'] ?? null), 'schema' => $schema,
                ];
                $initial[] = $device;
            }
        }
        return [$selected, $initial];
    }

    private function successfulSnapshot(array $state, array $selected, array $device, int $now): array
    {
        if (($device['devId'] ?? null) !== $selected['devId']
            || !is_array($device['dataPointInfo'] ?? null)
            || !is_array($device['dataPointInfo']['dps'] ?? null) || $device['dataPointInfo']['dps'] === []) {
            throw new \RuntimeException(self::POLL_ERROR);
        }
        $values = MercuryValues::values($device, $selected['schema'], $now, $this->mapping);
        $hasMeasurement = false;
        foreach ($values as $key => $value) {
            if (!is_string($key) || !preg_match('/\A[a-z0-9_]{1,64}\z/D', $key)
                || (!is_int($value) && (!is_float($value) || !is_finite($value)))) {
                throw new \RuntimeException(self::POLL_ERROR);
            }
            if (preg_match('/\Adp_[1-9][0-9]*\z/D', $key)) { $hasMeasurement = true; }
        }
        if (!$hasMeasurement) { throw new \RuntimeException(self::POLL_ERROR); }
        return [
            'binding' => $state['binding'], 'device_id' => $selected['devId'],
            'timezone_id' => is_string($device['timezoneId'] ?? null) ? $device['timezoneId'] : null,
            'last_attempt_at' => $now, 'last_success_at' => $now, 'last_poll_ok' => true,
            'online' => is_bool($device['cloudOnline'] ?? null) ? $device['cloudOnline'] : null,
            'values' => $values,
        ];
    }

    private function emptyState(): array
    {
        return [
            'version' => self::VERSION, 'config_binding' => $this->configBinding,
            'binding' => $this->configBinding, 'session' => null, 'devices' => [],
            'selected_index' => null, 'snapshot' => null, 'last_error' => null,
        ];
    }

    private function sessionBinding(array $session): string
    {
        return hash('sha256', $this->configBinding . "\0" . $session['uid']);
    }

    private function readState(): ?array
    {
        $state = Support::readJson($this->statePath);
        if ($state === null || ($state['version'] ?? null) !== self::VERSION
            || ($state['config_binding'] ?? null) !== $this->configBinding
            || !is_array($state['devices'] ?? null) || !Support::isList($state['devices'])
            || count($state['devices']) > self::MAX_DEVICES
            || !array_key_exists('selected_index', $state) || !array_key_exists('session', $state)) { return null; }
        // Public error strings are chosen locally, never copied from saved API data.
        if (!in_array($state['last_error'] ?? null, [null, self::CONNECT_ERROR, self::POLL_ERROR], true)) {
            $state['last_error'] = null;
        }
        if ($state['session'] === null) {
            return $state['devices'] === [] && $state['selected_index'] === null
                && ($state['binding'] ?? null) === $this->configBinding ? $state : null;
        }
        if (!is_array($state['session']) || count($state['session']) !== 5) { return null; }
        try { $this->client()->importSession($state['session']); }
        catch (\Throwable $ignoredException) { return null; }
        if (($state['binding'] ?? null) !== $this->sessionBinding($state['session'])) { return null; }
        $seen = [];
        foreach ($state['devices'] as $device) {
            if (!is_array($device) || self::groupId($device['gid'] ?? null) !== ($device['gid'] ?? null)
                || !self::identifier($device['devId'] ?? null) || isset($seen[$device['devId']])
                || !is_string($device['name'] ?? null) || self::name($device['name']) !== $device['name']
                || self::mercurySchema($device['schema'] ?? null) === null) { return null; }
            $seen[$device['devId']] = true;
        }
        $index = $state['selected_index'];
        if ($index !== null && (!is_int($index) || $index < 0 || !isset($state['devices'][$index]))) { return null; }
        return $state;
    }

    private function stateSnapshot(array $state): ?array
    {
        $index = $state['selected_index'] ?? null;
        $snapshot = $state['snapshot'] ?? null;
        if (!is_array($state['session'] ?? null) || $index === null || !is_array($snapshot)
            || ($snapshot['binding'] ?? null) !== $state['binding']
            || ($snapshot['device_id'] ?? null) !== $state['devices'][$index]['devId']
            || !is_int($snapshot['last_attempt_at'] ?? null) || $snapshot['last_attempt_at'] < 0
            || $snapshot['last_attempt_at'] > time() + 5
            || !array_key_exists('last_success_at', $snapshot)
            || ($snapshot['last_success_at'] !== null && (!is_int($snapshot['last_success_at'])
                || $snapshot['last_success_at'] < 0 || $snapshot['last_success_at'] > $snapshot['last_attempt_at']))
            || !is_bool($snapshot['last_poll_ok'] ?? null) || !array_key_exists('online', $snapshot)
            || ($snapshot['online'] !== null && !is_bool($snapshot['online']))
            || !is_array($snapshot['values'] ?? null)) { return null; }
        foreach ($snapshot['values'] as $key => $value) {
            if (!is_string($key) || !preg_match('/\A[a-z0-9_]{1,64}\z/D', $key)
                || in_array($key, ['bridge_ok', 'data_valid', 'online', 'data_age_seconds', 'last_poll_ok'], true)
                || (!is_int($value) && (!is_float($value) || !is_finite($value)))) { return null; }
        }
        // Measurement ages and day/TTL validity change even between cloud polls.
        $snapshot['values'] = MercuryValues::refreshQuality(
            $snapshot['values'], is_string($snapshot['timezone_id'] ?? null) ? $snapshot['timezone_id'] : null, time()
        );
        // Return only the documented snapshot; state metadata and sessions stay private.
        return array_intersect_key($snapshot, array_flip([
            'binding', 'device_id', 'last_attempt_at', 'last_success_at', 'last_poll_ok', 'online', 'values',
        ]));
    }

    /** Keep only schema fields needed for typed numeric projection. */
    private static function mercurySchema($schema): ?array
    {
        if (!is_array($schema) || !Support::isList($schema) || count($schema) > 256) { return null; }
        $result = [];
        $codes = [];
        foreach ($schema as $row) {
            if (!is_array($row) || !isset($row['id']) || (!is_int($row['id']) && !is_string($row['id']))
                || !preg_match('/\A[1-9][0-9]{0,8}\z/D', (string) $row['id'])
                || !is_string($row['code'] ?? null) || !preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,59}\z/D', $row['code'])
                || ($row['type'] ?? 'obj') !== 'obj') { continue; }
            $property = $row['property'] ?? null;
            if (is_string($property)) {
                try { $property = json_decode($property, true, 16, JSON_THROW_ON_ERROR); }
                catch (\JsonException $ignoredException) { continue; }
            }
            if (!is_array($property) || !in_array($property['type'] ?? null, ['value', 'bool', 'enum', 'bitmap'], true)) { continue; }
            $property = array_intersect_key($property, array_flip(['type', 'unit', 'min', 'max', 'scale', 'step', 'range', 'maxlen']));
            foreach ($property as $key => $value) {
                if ($key === 'range') {
                    if (!is_array($value) || !Support::isList($value) || count($value) > 256) { return null; }
                    foreach ($value as $item) { if (!is_string($item) && !is_int($item)) { return null; } }
                } elseif (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) { return null; }
            }
            $result[] = ['id' => $row['id'], 'code' => $row['code'], 'type' => 'obj', 'property' => $property];
            if ($property['type'] === 'value') { $codes[strtolower($row['code'])] = true; }
        }
        return isset($codes['cat_weight'], $codes['excretion_times_day']) ? $result : null;
    }

    private static function identifier($value): bool
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/D', $value) === 1;
    }

    private static function groupId($value): ?int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $value)) { return null; }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private static function name($value): string
    {
        if (!is_string($value) || strlen($value) > 2048) { return 'Gerät'; }
        $value = preg_replace('/[\x00-\x1f\x7f]/u', '', $value);
        if ($value === null || trim($value) === '') { return 'Gerät'; }
        preg_match('/\A.{1,160}/us', trim($value), $match);
        return $match[0] ?? 'Gerät';
    }

    private static function loadProfile(): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            if (!defined('ASTROPET_CONFIG_INCLUDE')) { define('ASTROPET_CONFIG_INCLUDE', true); }
            $profile = require __DIR__ . '/app-profile.php';
        } catch (\Throwable $ignoredException) {
            throw new \RuntimeException('Mobiles App-Profil konnte nicht geladen werden.');
        } finally { while (ob_get_level() > $level) { ob_end_clean(); } }
        if (!is_array($profile)) { throw new \RuntimeException('Mobiles App-Profil ist unvollständig.'); }
        return $profile;
    }

    /** @return resource|null */
    private function lock(bool $blocking)
    {
        $handle = @fopen($this->lockPath, 'c+b');
        if ($handle === false) { throw new \RuntimeException('Statussperre konnte nicht geöffnet werden.'); }
        if (!@chmod($this->lockPath, 0600)) { fclose($handle); throw new \RuntimeException('Statussperre konnte nicht geschützt werden.'); }
        if (!flock($handle, LOCK_EX | ($blocking ? 0 : LOCK_NB))) {
            fclose($handle);
            if (!$blocking) { return null; }
            throw new \RuntimeException('Statussperre konnte nicht gesetzt werden.');
        }
        $guard = "<?php http_response_code(404); exit;\n";
        if (fstat($handle)['size'] === 0 && (fwrite($handle, $guard) !== strlen($guard) || !fflush($handle))) {
            self::unlock($handle);
            throw new \RuntimeException('Statussperre konnte nicht geschützt werden.');
        }
        return $handle;
    }

    private static function unlock($handle): void
    {
        if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    }
}
