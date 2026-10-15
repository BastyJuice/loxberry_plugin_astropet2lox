<?php
declare(strict_types=1);

namespace AstroPet2Lox;

/** Bounded MQTT 3.1.1 publisher: QoS 1 acknowledgements, retained will, no subscriptions. */
final class MqttClient
{
    /** @var resource|null */
    private $socket = null;
    private bool $connected = false;
    private int $packetId = 0;
    private string $address;
    private string $username;
    private string $password;
    private array $context;
    private int $timeoutMs;
    private int $keepalive;
    private ?\Closure $connector;
    private string $clientId;

    /** Optional connector is a trusted test hook; it returns an already connected stream. */
    public function __construct(array $details, string $clientId, ?callable $connector = null)
    {
        $host = $details['brokerhost'] ?? null;
        if (!is_string($host) || $host === '' || strlen($host) > 253 || strpos($host, "\0") !== false) {
            throw new \RuntimeException('MQTT-Brokerkonfiguration ist ungültig.');
        }
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && !preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?\z/D', $host)) {
            throw new \RuntimeException('MQTT-Brokerkonfiguration ist ungültig.');
        }
        if (isset($details['tls']) && !is_bool($details['tls'])) {
            throw new \RuntimeException('MQTT-TLS-Konfiguration ist ungültig.');
        }
        $tls = ($details['tls'] ?? false) === true;
        $port = $tls ? ($details['tls_brokerport'] ?? $details['brokerport'] ?? null) : ($details['brokerport'] ?? null);
        if ((!is_int($port) && !is_string($port)) || !preg_match('/\A[0-9]{1,5}\z/D', (string) $port)
            || (int) $port < 1 || (int) $port > 65535) {
            throw new \RuntimeException('MQTT-Brokerkonfiguration ist ungültig.');
        }
        if (!self::validUtf8($clientId) || $clientId === '' || strlen($clientId) > 128) {
            throw new \RuntimeException('MQTT-Clientkennung ist ungültig.');
        }
        $this->clientId = $clientId;
        $username = $details['brokeruser'] ?? '';
        $password = $details['brokerpass'] ?? '';
        if (!is_string($username) || !self::validUtf8($username) || strlen($username) > 65535
            || !is_string($password) || strlen($password) > 65535) {
            throw new \RuntimeException('MQTT-Zugangskonfiguration ist ungültig.');
        }
        $this->username = $username;
        $this->password = $password;
        $timeout = $details['timeout_ms'] ?? 3000;
        $keepalive = $details['keepalive_seconds'] ?? 30;
        if (!is_int($timeout) || !is_int($keepalive) || $timeout < 100 || $timeout > 10000 || $keepalive < 5 || $keepalive > 300) {
            throw new \RuntimeException('MQTT-Zeitkonfiguration ist ungültig.');
        }
        $this->timeoutMs = $timeout;
        $this->keepalive = $keepalive;
        $this->context = [];
        if ($tls) {
            $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host,
                'allow_self_signed' => false, 'SNI_enabled' => true];
            $ca = $details['tls_cafile'] ?? null;
            if ($ca !== null && $ca !== '') {
                if (!is_string($ca) || substr($ca, 0, 1) !== '/' || strpos($ca, "\0") !== false || !is_file($ca) || !is_readable($ca)) {
                    throw new \RuntimeException('MQTT-Zertifikatskonfiguration ist ungültig.');
                }
                $ssl['cafile'] = $ca;
            }
            $this->context = ['ssl' => $ssl];
        }
        $this->address = ($tls ? 'tls' : 'tcp') . '://' . (strpos($host, ':') !== false ? '[' . $host . ']' : $host) . ':' . (int) $port;
        $this->connector = $connector === null ? null : \Closure::fromCallable($connector);
    }

    public function connect(string $availabilityTopic): void
    {
        self::assertTopic($availabilityTopic);
        $this->drop();
        $deadline = $this->deadline();
        try {
            if ($this->connector !== null) {
                $socket = ($this->connector)($this->address, $this->timeoutMs / 1000, $this->context);
            } else {
                $socket = @stream_socket_client($this->address, $errno, $error, $this->timeoutMs / 1000,
                    STREAM_CLIENT_CONNECT, stream_context_create($this->context));
            }
            if (!is_resource($socket) || get_resource_type($socket) !== 'stream') {
                throw new \RuntimeException('MQTT-Verbindung fehlgeschlagen.');
            }
            $this->socket = $socket;
            if (!stream_set_blocking($socket, false)) { throw new \RuntimeException('MQTT-Verbindung fehlgeschlagen.'); }
            // Clean session + retained QoS 1 Last Will. Only current readings are published.
            $flags = 0x02 | 0x04 | 0x08 | 0x20;
            $hasUser = $this->username !== '' || $this->password !== '';
            if ($hasUser) { $flags |= 0x80; }
            if ($this->password !== '') { $flags |= 0x40; }
            $body = self::mqttString('MQTT') . chr(4) . chr($flags) . pack('n', $this->keepalive)
                . self::mqttString($this->clientId) . self::mqttString($availabilityTopic) . self::mqttString('0');
            if ($hasUser) { $body .= self::mqttString($this->username); }
            if ($this->password !== '') { $body .= self::mqttString($this->password); }
            $this->sendPacket(0x10, $body, $deadline);
            [$header, $reply] = $this->readPacket($deadline);
            if ($header !== 0x20 || $reply !== "\0\0") { throw new \RuntimeException('MQTT-Anmeldung wurde nicht bestätigt.'); }
            $this->connected = true;
        } catch (\Throwable $ignored) {
            $this->drop();
            throw new \RuntimeException('MQTT-Verbindung fehlgeschlagen.');
        }
    }

    public function publish(string $topic, string $payload, bool $retain = true): void
    {
        self::assertTopic($topic);
        if (strlen($payload) > 1048576) { throw new \RuntimeException('MQTT-Nachricht ist zu groß.'); }
        $this->assertConnected();
        $deadline = $this->deadline();
        $id = $this->packetId = $this->packetId % 65535 + 1;
        try {
            $this->sendPacket($retain ? 0x33 : 0x32, self::mqttString($topic) . pack('n', $id) . $payload, $deadline);
            [$header, $reply] = $this->readPacket($deadline);
            if ($header !== 0x40 || $reply !== pack('n', $id)) {
                throw new \RuntimeException('MQTT-Nachricht wurde nicht bestätigt.');
            }
        } catch (\Throwable $ignored) {
            // An unclean close lets the broker publish availability=0 via Last Will.
            $this->drop();
            throw new \RuntimeException('MQTT-Nachricht wurde nicht bestätigt.');
        }
    }

    public function ping(): void
    {
        $this->assertConnected();
        $deadline = $this->deadline();
        try {
            $this->sendPacket(0xc0, '', $deadline);
            [$header, $reply] = $this->readPacket($deadline);
            if ($header !== 0xd0 || $reply !== '') { throw new \RuntimeException('MQTT-Verbindung antwortet nicht.'); }
        } catch (\Throwable $ignored) {
            $this->drop();
            throw new \RuntimeException('MQTT-Verbindung antwortet nicht.');
        }
    }

    /** Caller publishes retained availability=0 before this clean disconnect. */
    public function close(): void
    {
        try { if ($this->connected) { $this->sendPacket(0xe0, '', $this->deadline()); } }
        catch (\Throwable $ignored) { /* Closing never masks the worker's original error. */ }
        finally { $this->drop(); }
    }

    public function isConnected(): bool
    {
        return $this->connected && is_resource($this->socket) && !feof($this->socket);
    }

    public function __destruct() { $this->drop(); }

    private function assertConnected(): void
    {
        if (!$this->isConnected()) { $this->drop(); throw new \RuntimeException('MQTT-Verbindung fehlt.'); }
    }

    private function deadline(): int { return hrtime(true) + $this->timeoutMs * 1000000; }

    private function sendPacket(int $header, string $body, int $deadline): void
    {
        $length = strlen($body);
        $encoded = '';
        do {
            $byte = $length % 128;
            $length = intdiv($length, 128);
            $encoded .= chr($byte | ($length > 0 ? 0x80 : 0));
        } while ($length > 0);
        $packet = chr($header) . $encoded . $body;
        $offset = 0;
        while ($offset < strlen($packet)) {
            $this->ready(false, $deadline);
            $written = @fwrite($this->socket, substr($packet, $offset));
            if ($written === false || feof($this->socket)) { throw new \RuntimeException('MQTT-Schreibfehler.'); }
            // Zero writes retry only after readiness, within the original total deadline.
            if ($written === 0) { usleep(1000); continue; }
            $offset += $written;
        }
    }

    private function readPacket(int $deadline): array
    {
        $header = ord($this->readBytes(1, $deadline));
        $length = 0;
        $multiplier = 1;
        for ($i = 0; $i < 4; ++$i) {
            $digit = ord($this->readBytes(1, $deadline));
            $length += ($digit & 0x7f) * $multiplier;
            if ($length > 65536) { throw new \RuntimeException('MQTT-Antwort ist zu groß.'); }
            if (($digit & 0x80) === 0) { return [$header, $this->readBytes($length, $deadline)]; }
            $multiplier *= 128;
        }
        throw new \RuntimeException('MQTT-Antwort ist ungültig.');
    }

    private function readBytes(int $length, int $deadline): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->ready(true, $deadline);
            $part = @fread($this->socket, $length - strlen($bytes));
            if ($part === false || ($part === '' && feof($this->socket))) { throw new \RuntimeException('MQTT-Lesefehler.'); }
            if ($part === '') { usleep(1000); continue; }
            $bytes .= $part;
        }
        return $bytes;
    }

    private function ready(bool $reading, int $deadline): void
    {
        if (!is_resource($this->socket) || feof($this->socket)) { throw new \RuntimeException('MQTT-Verbindung geschlossen.'); }
        $remaining = $deadline - hrtime(true);
        if ($remaining <= 0) { throw new \RuntimeException('MQTT-Zeitbudget überschritten.'); }
        $read = $reading ? [$this->socket] : [];
        $write = $reading ? [] : [$this->socket];
        $except = [];
        $microseconds = max(1, (int) ceil($remaining / 1000));
        $ready = @stream_select($read, $write, $except, intdiv($microseconds, 1000000), $microseconds % 1000000);
        if ($ready === false || $ready === 0) { throw new \RuntimeException('MQTT-Zeitbudget überschritten.'); }
    }

    private function drop(): void
    {
        $this->connected = false;
        if (is_resource($this->socket)) { fclose($this->socket); }
        $this->socket = null;
    }

    private static function mqttString(string $value): string
    {
        if (strlen($value) > 65535) { throw new \RuntimeException('MQTT-Feld ist zu groß.'); }
        return pack('n', strlen($value)) . $value;
    }

    private static function validUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1 && !preg_match('/[\x00-\x1f\x7f]/', $value);
    }

    private static function assertTopic(string $topic): void
    {
        if ($topic === '' || strlen($topic) > 65535 || !self::validUtf8($topic) || strpbrk($topic, '+#') !== false) {
            throw new \RuntimeException('MQTT-Thema ist ungültig.');
        }
    }
}
