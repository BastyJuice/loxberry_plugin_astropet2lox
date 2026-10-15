<?php
declare(strict_types=1);

namespace AstroPet;

final class Support
{
    public static function contains(string $value, string $needle): bool
    {
        return $needle === '' || strpos($value, $needle) !== false;
    }

    public static function startsWith(string $value, string $prefix): bool
    {
        return $prefix === '' || strncmp($value, $prefix, strlen($prefix)) === 0;
    }

    public static function endsWith(string $value, string $suffix): bool
    {
        return $suffix === '' || substr($value, -strlen($suffix)) === $suffix;
    }

    public static function isList(array $values): bool
    {
        $index = 0;
        foreach ($values as $key => $value) {
            if ($key !== $index++) { return false; }
        }
        return true;
    }

    // PHP refuses direct HTTP access; readers strip this exact prefix as text.
    // JSON is never included, required, evaluated or unserialized.
    private const JSON_GUARD = "<?php http_response_code(404); exit;\n__halt_compiler();\n";

    public static function stateDir(array $config): string
    {
        $dir = $config['state_dir'] ?? '';
        if (!is_string($dir) || !Support::startsWith($dir, '/') || Support::contains($dir, "\0")) {
            throw new \RuntimeException('state_dir muss ein absoluter lokaler Pfad sein.');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Statusverzeichnis kann nicht angelegt werden.');
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('Statusverzeichnis ist nicht beschreibbar.');
        }
        return rtrim($dir, '/') ?: '/';
    }

    public static function readJson(string $path): ?array
    {
        self::assertPhpStatePath($path);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || !Support::startsWith($raw, self::JSON_GUARD)) {
            return null;
        }
        try {
            $data = json_decode(substr($raw, strlen(self::JSON_GUARD)), true, 64, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException $ignoredException) {
            return null;
        }
    }

    public static function writeJson(string $path, array $data): void
    {
        self::assertPhpStatePath($path);
        $json = self::JSON_GUARD
            . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        // Never write sensitive bytes into a temporary file without .php.
        $tmp = dirname($path) . '/.astropet-' . bin2hex(random_bytes(16)) . '.php';
        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Temporäre Statusdatei kann nicht angelegt werden.');
        }
        try {
            if (!@chmod($tmp, 0600) || !flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Statusdatei kann nicht gespeichert werden.');
            }
            $offset = 0;
            $length = strlen($json);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Statusdatei kann nicht gespeichert werden.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new \RuntimeException('Statusdatei kann nicht gespeichert werden.');
            }
            flock($handle, LOCK_UN);
            fclose($handle);
            $handle = null;
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('Statusdatei kann nicht gespeichert werden.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private static function assertPhpStatePath(string $path): void
    {
        if (!Support::startsWith($path, '/') || !Support::endsWith($path, '.php') || Support::contains($path, "\0")) {
            throw new \RuntimeException('Statusdateien benötigen einen absoluten lokalen Pfad mit Endung .php.');
        }
    }

}
