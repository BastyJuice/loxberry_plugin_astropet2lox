<?php
declare(strict_types=1);

namespace AstroPet2Lox;

/** Obtain existing LoxBerry broker credentials without exposing or duplicating them. */
final class MqttBridge
{
    public static function topicPrefix(): string { return 'astropet2lox'; }

    public static function details(): array
    {
        // LoxBerry caches general.json in long-running PHP processes. Refresh it
        // so a daemon can reconnect after broker credentials/settings change.
        if (array_key_exists('cfgwasread', $GLOBALS)) { $GLOBALS['cfgwasread'] = false; }
        if (!function_exists('mqtt_connectiondetails')) {
            $path = stream_resolve_include_path('loxberry_io.php');
            if ($path === false) {
                $home = getenv('LBHOME') ?: getenv('LBHOMEDIR') ?: '/opt/loxberry';
                $path = rtrim($home, '/') . '/libs/phplib/loxberry_io.php';
            }
            if (!is_file($path)) { throw new \RuntimeException('LoxBerry-MQTT-Modul fehlt.'); }
            $level = ob_get_level();
            ob_start();
            try { require_once $path; }
            catch (\Throwable $error) { throw new \RuntimeException('LoxBerry-MQTT-Konfiguration konnte nicht geladen werden.'); }
            finally { while (ob_get_level() > $level) { ob_end_clean(); } }
        }
        try { $raw = @\mqtt_connectiondetails(); }
        catch (\Throwable $error) { throw new \RuntimeException('LoxBerry-MQTT-Konfiguration konnte nicht geladen werden.'); }
        if (!is_array($raw) || !is_string($raw['brokerhost'] ?? null) || $raw['brokerhost'] === '') {
            throw new \RuntimeException('MQTT-Broker ist in LoxBerry noch nicht eingerichtet.');
        }
        $details = [
            'brokerhost' => $raw['brokerhost'], 'brokerport' => self::port($raw['brokerport'] ?? null),
            'brokeruser' => $raw['brokeruser'] ?? '', 'brokerpass' => $raw['brokerpass'] ?? '',
            'tls' => self::enabled($raw['tls'] ?? false),
        ];
        if (!is_string($details['brokeruser']) || !is_string($details['brokerpass'])) {
            throw new \RuntimeException('LoxBerry-MQTT-Zugangskonfiguration ist ungültig.');
        }
        if ($details['tls']) {
            $details['tls_brokerport'] = self::port($raw['tls_brokerport'] ?? $details['brokerport']);
            // Use the supplied local/self-signed CA; verify every encrypted connection.
            $details['tls_verify'] = true;
            $ca = $raw['tls_cafile'] ?? '';
            if (!is_string($ca) || ($ca !== '' && (strpos($ca, '/') !== 0 || strpos($ca, "\0") !== false || !is_file($ca) || !is_readable($ca)))) {
                throw new \RuntimeException('LoxBerry-MQTT-Zertifikatskonfiguration ist ungültig.');
            }
            if ($ca !== '') { $details['tls_cafile'] = $ca; }
        }
        return $details;
    }

    private static function port($port): int
    {
        if ((!is_int($port) && !is_string($port)) || !preg_match('/\A[0-9]{1,5}\z/D', (string) $port)
            || (int) $port < 1 || (int) $port > 65535) {
            throw new \RuntimeException('LoxBerry-MQTT-Port ist ungültig.');
        }
        return (int) $port;
    }

    private static function enabled($value): bool
    {
        return $value === true || $value === 1 || (is_string($value)
            && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'enabled'], true));
    }
}
