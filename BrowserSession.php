<?php
declare(strict_types=1);

namespace AstroPet2Lox;

use AstroPet\Support;

require_once __DIR__ . '/AstroPet/Support.php';

/** Short-lived login continuity. Sensitive state is never included as PHP. */
final class BrowserSession
{
    public array $data;
    private string $dir;
    private string $path;
    /** @var resource|false */
    private $lock;
    private bool $closed = false;
    private bool $https;
    private string $cookiePath;
    private const COOKIE = 'ASTROPET2LOXSID';

    public function __construct(string $dir, array $config)
    {
        if (substr($dir, 0, 1) !== '/' || strpos($dir, "\0") !== false) {
            throw new \RuntimeException('Der lokale Sitzungsordner ist ungültig.');
        }
        $this->dir = rtrim($dir, '/') . '/sessions';
        $this->https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/';
        $base = is_string($script) ? str_replace('\\', '/', dirname($script)) : '/';
        $this->cookiePath = $base === '.' ? '/' : rtrim($base, '/') . '/';
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Der lokale Sitzungsordner ist nicht beschreibbar.');
        }
        $this->lock = @fopen($this->dir . '/ui.lock.php', 'c+');
        if ($this->lock === false || !flock($this->lock, LOCK_EX)) {
            throw new \RuntimeException('Die lokale Browsersitzung ist nicht verfügbar.');
        }
        if ((fstat($this->lock)['size'] ?? 0) === 0) {
            if (fwrite($this->lock, "<?php http_response_code(404); exit;\n") === false || !fflush($this->lock)) {
                throw new \RuntimeException('Die lokale Browsersitzung ist nicht verfügbar.');
            }
        }
        @chmod($this->dir . '/ui.lock.php', 0600);
        $id = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/D', $id)) { $id = bin2hex(random_bytes(32)); }
        $this->path = $this->dir . '/' . $id . '.php';
        $saved = Support::readJson($this->path);
        $binding = self::binding($config);
        if (!is_array($saved) || !is_string($saved['binding'] ?? null) || !hash_equals($binding, $saved['binding'])
            || !is_int($saved['expires_at'] ?? null) || $saved['expires_at'] <= time()
            || !is_string($saved['csrf'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $saved['csrf'])) {
            $id = bin2hex(random_bytes(32));
            $this->path = $this->dir . '/' . $id . '.php';
            $saved = ['csrf' => bin2hex(random_bytes(32)), 'binding' => $binding];
        }
        $saved['expires_at'] = time() + 1800;
        $this->data = $saved;
        foreach (glob($this->dir . '/*.php') ?: [] as $old) {
            if ($old !== $this->path && preg_match('/\A[a-f0-9]{64}\.php\z/D', basename($old))
                && (@filemtime($old) ?: 0) < time() - 1800) { @unlink($old); }
        }
        $this->cookie($id);
    }

    private static function binding(array $config): string
    {
        $parts = [];
        foreach (['astropet_email', 'astropet_password'] as $key) {
            $parts[] = is_string($config[$key] ?? null) ? $config[$key] : '';
        }
        $parts[] = is_string($_SERVER['REMOTE_USER'] ?? null) ? $_SERVER['REMOTE_USER'] : '';
        return hash('sha256', implode("\0", $parts));
    }

    /** Settings can change credentials during an authenticated POST. */
    public function rebind(array $config): void
    {
        $binding = self::binding($config);
        if (!hash_equals($this->data['binding'], $binding)) {
            $this->data = ['binding' => $binding, 'expires_at' => time() + 1800];
            $this->rotate();
        }
    }

    private function cookie(string $id): void
    {
        setcookie(self::COOKIE, $id, ['expires' => time() + 1800, 'path' => $this->cookiePath,
            'secure' => $this->https, 'httponly' => true, 'samesite' => 'Strict']);
    }

    public function rotate(): void
    {
        $previous = $this->path;
        $id = bin2hex(random_bytes(32));
        $this->path = $this->dir . '/' . $id . '.php';
        $this->data['csrf'] = bin2hex(random_bytes(32));
        $this->cookie($id);
        @unlink($previous);
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        try { Support::writeJson($this->path, $this->data); }
        finally { $this->closed = true; flock($this->lock, LOCK_UN); fclose($this->lock); }
    }

    public function __destruct()
    {
        if (!$this->closed && is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
    }
}
