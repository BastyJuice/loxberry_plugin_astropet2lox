<?php
declare(strict_types=1);

namespace AstroPet2Lox;

require_once __DIR__ . '/MqttClient.php';
require_once __DIR__ . '/MqttBridge.php';
require_once __DIR__ . '/TopicCatalog.php';
require_once __DIR__ . '/AstroPet/Support.php';

use AstroPet\Support;

/** Numeric retained snapshots with invalidation gates and persistent tombstone tracking. */
final class Publisher
{
    private string $registryPath;
    private string $lockPath;
    private string $prefix;
    private MqttClient $client;
    private int $batchTimeoutMs;

    public function __construct(MqttClient $client, string $dataDir, int $batchTimeoutMs = 15000)
    {
        if ($batchTimeoutMs < 1 || $batchTimeoutMs > 15000) {
            throw new \RuntimeException('MQTT-Batchzeitbudget ist ungültig.');
        }
        $this->client = $client;
        $this->batchTimeoutMs = $batchTimeoutMs;
        $dir = Support::stateDir(['state_dir' => $dataDir]);
        $this->registryPath = rtrim($dir, '/') . '/published-topics.php';
        $this->lockPath = rtrim($dir, '/') . '/mqtt-publisher.lock.php';
        $this->prefix = MqttBridge::topicPrefix() . '/';
    }

    public function publish(array $projection): void
    {
        $payloads = [];
        foreach ($projection as $field => $value) {
            if (!self::validField($field) || (!is_int($value) && (!is_float($value) || !is_finite($value)))) {
                throw new \RuntimeException('MQTT-Ausgabe enthält ungültige numerische Felder.');
            }
            $payloads[$field] = TopicCatalog::payload($value);
        }
        foreach (['availability', 'data_valid'] as $gate) {
            if (!isset($projection[$gate]) || !in_array($projection[$gate], [0, 1, 0.0, 1.0], true)) {
                throw new \RuntimeException('MQTT-Ausgabe enthält keine gültigen Statusfelder.');
            }
        }
        if (count($payloads) > 512) { throw new \RuntimeException('MQTT-Ausgabe enthält zu viele Felder.'); }
        $current = array_keys($payloads);
        sort($current, SORT_STRING);
        $lock = $this->lock();
        $deadline = hrtime(true) + $this->batchTimeoutMs * 1000000;
        try {
            $old = $this->fields();
            $union = array_values(array_unique(array_merge($old, $current)));
            sort($union, SORT_STRING);
            if (count($union) > 4096) { throw new \RuntimeException('MQTT-Themenregister ist zu groß.'); }
            // Record every possible retained key before the first network write.
            // A failed/crashed batch can therefore never orphan its newly published keys.
            Support::writeJson($this->registryPath, ['version' => 1, 'fields' => $union]);
            $this->publishField('data_valid', '0', $deadline);
            foreach (array_diff($old, $current) as $field) {
                $this->publishField($field, '', $deadline);
            }
            foreach ($current as $field) {
                if ($field !== 'data_valid' && $field !== 'availability') {
                    $this->publishField($field, $payloads[$field], $deadline);
                }
            }
            // Availability becomes true only after all measurements and quality flags.
            $this->publishField('availability', $payloads['availability'], $deadline);
            $this->publishField('data_valid', $payloads['data_valid'], $deadline);
            Support::writeJson($this->registryPath, ['version' => 1, 'fields' => $current]);
        } catch (\Throwable $ignored) {
            $this->invalidateAndClose();
            throw new \RuntimeException('MQTT-Daten konnten nicht vollständig veröffentlicht werden.');
        } finally { self::unlock($lock); }
    }

    public function stop(): void
    {
        try { $lock = $this->lock(); }
        catch (\Throwable $ignored) {
            // Storage failure must not prevent MQTT invalidation or closing the socket.
            $this->invalidateAndClose();
            return;
        }
        try { $this->invalidateAndClose(); }
        finally { self::unlock($lock); }
    }

    private function invalidateAndClose(): void
    {
        foreach (['data_valid', 'availability'] as $field) {
            try {
                if ($this->client->isConnected()) { $this->client->publish($this->prefix . $field, '0'); }
            } catch (\Throwable $ignored) { /* An unclean socket close also triggers availability=0 via Last Will. */ }
        }
        $this->client->close();
    }

    private function publishField(string $field, string $payload, int $deadline): void
    {
        // Every topic shares one monotonic batch deadline, including PUBACK waits.
        // A single in-flight exchange still uses MqttClient's bounded operation timeout.
        if (hrtime(true) >= $deadline) { throw new \RuntimeException('MQTT-Batchzeitbudget überschritten.'); }
        $this->client->publish($this->prefix . $field, $payload);
        if (hrtime(true) >= $deadline) { throw new \RuntimeException('MQTT-Batchzeitbudget überschritten.'); }
    }

    private function fields(): array
    {
        $state = Support::readJson($this->registryPath);
        if ($state === null) {
            if (is_file($this->registryPath)) { throw new \RuntimeException('MQTT-Themenregister ist ungültig.'); }
            return [];
        }
        if (($state['version'] ?? null) !== 1 || !is_array($state['fields'] ?? null)
            || array_values($state['fields']) !== $state['fields'] || count($state['fields']) > 4096) {
            throw new \RuntimeException('MQTT-Themenregister ist ungültig.');
        }
        foreach ($state['fields'] as $field) {
            if (!self::validField($field)) { throw new \RuntimeException('MQTT-Themenregister ist ungültig.'); }
        }
        return array_values(array_unique($state['fields']));
    }

    private static function validField($field): bool
    {
        return is_string($field) && preg_match('/\A[a-z0-9_]{1,64}\z/D', $field) === 1;
    }

    /** @return resource */
    private function lock()
    {
        $handle = @fopen($this->lockPath, 'c+b');
        if ($handle === false) { throw new \RuntimeException('MQTT-Statussperre konnte nicht geöffnet werden.'); }
        if (!@chmod($this->lockPath, 0600) || !flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('MQTT-Statussperre konnte nicht gesetzt werden.');
        }
        $guard = "<?php http_response_code(404); exit;\n";
        if (fstat($handle)['size'] === 0 && (fwrite($handle, $guard) !== strlen($guard) || !fflush($handle))) {
            self::unlock($handle);
            throw new \RuntimeException('MQTT-Statussperre konnte nicht geschützt werden.');
        }
        return $handle;
    }

    private static function unlock($handle): void
    {
        if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    }
}
