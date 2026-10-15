<?php
declare(strict_types=1);

use AstroPet\AccountVerificationRequired;
use AstroPet2Lox\BrowserSession;
use AstroPet2Lox\Runtime;
use AstroPet2Lox\TopicCatalog;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if (!defined('LBPBINDIR')) { throw new RuntimeException('LoxBerry-Pluginpfad fehlt.'); }
    require_once LBPBINDIR . '/lib/Runtime.php';
    require_once LBPBINDIR . '/lib/BrowserSession.php';
    $L = LBSystem::readlanguage('language.ini');
    $runtime = Runtime::fromLoxBerry();
    $settings = $runtime->settings();
    $config = $runtime->config();
    $bridge = $runtime->bridge();
    if ($method === 'HEAD') { exit; }
    $session = new BrowserSession($runtime->dataDir . '/ui', $config);
} catch (Throwable $setupError) {
    http_response_code(503);
    if ($method !== 'HEAD') { echo 'AstroPet2Lox konnte nicht geladen werden. Installation und Schreibrechte der privaten Plugin-Ordner prüfen.'; }
    exit;
}

$message = '';
$errorMessage = '';
$form = $_GET['form'] ?? 'main';
if (!is_string($form) || !in_array($form, ['main', 'variables', 'logs'], true)) { $form = 'main'; }
if ($method === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!is_string($csrf) || !hash_equals($session->data['csrf'], $csrf)) {
        http_response_code(403);
        $session->close();
        echo ap2lEscape(ap2lText('SESSION_EXPIRED'));
        exit;
    }
    $action = $_POST['action'] ?? '';
    $client = null;
    try {
        if ($action === 'save_connect' || $action === 'save_settings') {
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $interval = $_POST['poll_interval_seconds'] ?? '';
            $enabled = $_POST['enabled'] ?? '';
            if (!is_string($email) || !is_string($password) || !is_string($interval)
                || !preg_match('/\A[0-9]{2,4}\z/D', $interval) || !is_string($enabled)
                || !in_array($enabled, ['', '1'], true)) { throw new RuntimeException(ap2lText('INVALID_SETTINGS')); }
            $runtime->saveSettings($email, $password, (int) $interval, $enabled === '1');
            unset($email, $password);
            $config = $runtime->config();
            $settings = $runtime->settings();
            $session->rebind($config);
            $bridge = $runtime->bridge();
            $message = ap2lText('SETTINGS_SAVED');
            if ($action === 'save_connect') {
                if (!extension_loaded('curl')) { throw new RuntimeException(ap2lText('CURL_REQUIRED')); }
                unset($session->data['pending'], $session->data['result']);
                $client = $runtime->account();
                try {
                    $account = $client->login($config['astropet_email'], $config['astropet_password']);
                    try { ap2lConnectAccount($account, $session, $runtime, $bridge); }
                    finally { unset($account); }
                    $message = ap2lText('CONNECTED_MESSAGE');
                } catch (AccountVerificationRequired $verificationRequired) {
                    $session->data['pending'] = ['email' => str_replace(' ', '', $config['astropet_email']),
                        'app_token' => $client->getSessionToken(), 'started_at' => time(), 'code_sent_at' => null];
                    $message = ap2lText('OTP_REQUIRED');
                }
            }
        } elseif ($action === 'send_code' || $action === 'verify_code') {
            if (!extension_loaded('curl')) { throw new RuntimeException(ap2lText('CURL_REQUIRED')); }
            $pending = $session->data['pending'] ?? null;
            if (!is_array($pending) || !is_string($pending['email'] ?? null) || !is_string($pending['app_token'] ?? null)
                || !is_int($pending['started_at'] ?? null) || $pending['started_at'] < time() - 1800) {
                unset($session->data['pending']); throw new RuntimeException(ap2lText('LOGIN_FIRST'));
            }
            $client = $runtime->account();
            $client->setSessionToken($pending['app_token']);
            if ($action === 'send_code') {
                if (is_int($pending['code_sent_at'] ?? null) && $pending['code_sent_at'] > time() - 60) {
                    throw new RuntimeException(ap2lText('OTP_WAIT'));
                }
                $client->requestVerificationCode($pending['email']);
                $session->data['pending']['app_token'] = $client->getSessionToken();
                $session->data['pending']['code_sent_at'] = time();
                $message = ap2lText('OTP_SENT');
            } else {
                if (!is_int($pending['code_sent_at'] ?? null)) { throw new RuntimeException(ap2lText('OTP_SEND_FIRST')); }
                $code = $_POST['code'] ?? '';
                if (!is_string($code) || !preg_match('/\A[0-9]{6}\z/D', trim($code))) {
                    throw new RuntimeException(ap2lText('OTP_INVALID'));
                }
                $account = $client->verifyCode($pending['email'], trim($code));
                unset($code);
                try { ap2lConnectAccount($account, $session, $runtime, $bridge); }
                finally { unset($account); }
                $message = ap2lText('CONNECTED_MESSAGE');
            }
        } elseif ($action === 'select_device') {
            $index = $_POST['device_index'] ?? '';
            if (!is_string($index) || !preg_match('/\A[0-9]{1,6}\z/D', $index)) {
                throw new RuntimeException(ap2lText('SELECT_REQUIRED'));
            }
            $bridge->select((int) $index);
            $bridge->refresh(true);
            $runtime->requestPublish();
            $message = ap2lText('DEVICE_SELECTED');
        } elseif ($action === 'refresh') {
            $bridge->refresh(true);
            $runtime->requestPublish();
            $message = ap2lText('REFRESHED');
        } elseif ($action === 'disconnect') {
            $runtime->disconnect();
            unset($session->data['pending'], $session->data['result']);
            $session->rotate();
            $message = ap2lText('DISCONNECTED');
        } elseif ($action === 'register_gateway') {
            $runtime->gateway()->ensure();
            $runtime->requestPublish();
            $message = ap2lText('GATEWAY_REGISTERED');
        } elseif ($action === 'logout') {
            unset($session->data['pending'], $session->data['result']);
            $session->rotate();
            $message = ap2lText('BROWSER_CLEARED');
        } else { throw new RuntimeException(ap2lText('UNKNOWN_ACTION')); }
    } catch (Throwable $error) {
        if ($client !== null && isset($session->data['pending'])) {
            $session->data['pending']['app_token'] = $client->getSessionToken();
        }
        // Account, bridge and runtime errors are deliberately bounded, safe messages.
        $errorMessage = $error instanceof RuntimeException ? $error->getMessage() : ap2lText('ACTION_FAILED');
    }
    unset($_POST['email'], $_POST['password'], $_POST['code']);
}

try {
    $session->close();
    // Page views read local state. The background worker performs automatic polling.
    $settings = $runtime->settings();
    $info = $bridge->info();
    $status = $runtime->status();
    $gateway = $runtime->gateway()->info();
    $projection = is_array($status['projection'] ?? null) ? $status['projection'] : [];
    $worker = is_array($status['worker'] ?? null) ? $status['worker'] : [];
    $rows = $form === 'variables' ? TopicCatalog::rows($projection, $runtime->schema()) : [];
} catch (Throwable $setupError) {
    http_response_code(503); echo ap2lEscape(ap2lText('STATE_FAILED')); exit;
}
if ($errorMessage === '' && is_string($info['last_error'] ?? null)) { $errorMessage = $info['last_error']; }
$connected = ($info['connected'] ?? false) === true;
$devices = is_array($info['devices'] ?? null) ? $info['devices'] : [];
$selectedIndex = $info['selected_index'] ?? null;
$pending = isset($session->data['pending']);
$result = is_array($session->data['result'] ?? null) ? $session->data['result'] : [];
$csrf = ap2lEscape($session->data['csrf']);
$navbar = [];
foreach ([10 => ['main', 'TAB_SETTINGS'], 20 => ['variables', 'TAB_VARIABLES'], 30 => ['logs', 'TAB_LOGS']] as $key => [$tab, $label]) {
    $navbar[$key] = ['Name' => ap2lText($label), 'URL' => 'index.php?form=' . $tab, 'active' => $form === $tab];
}
LBWeb::lbheader('AstroPet2Lox V' . LBSystem::pluginversion(), '', 'help.html', true);
?>
<style>
.ap2l{max-width:1150px;margin:auto}.ap2l .lb-card{padding:1.4rem;margin:1rem 0}.ap2l h2{margin-top:0}.ap2l-notice{padding:1rem;border-left:4px solid #378468;background:#eaf5ef;border-radius:4px}.ap2l-error{border-color:#b54b42;background:#fff0ed}.ap2l-row{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}.ap2l-actions{margin:1rem 0;display:flex;gap:.75rem;flex-wrap:wrap}.ap2l-status{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem}.ap2l-status dt{font-size:.9rem;opacity:.75}.ap2l-status dd{margin:.25rem 0;font-weight:600}.ap2l-table{overflow:auto}.ap2l code{overflow-wrap:anywhere}.ap2l td{vertical-align:top}.ap2l input.lb-input{max-width:460px}.ap2l small{opacity:.8}.ap2l-note{max-width:80ch}.ap2l .lb-form-help{margin-bottom:1rem}
</style>
<div class="lb-content ap2l">
<?php if ($message !== ''): ?><p class="ap2l-notice" role="status"><?= ap2lEscape($message) ?></p><?php endif; ?>
<?php if ($errorMessage !== ''): ?><p class="ap2l-notice ap2l-error" role="alert"><?= ap2lEscape($errorMessage) ?></p><?php endif; ?>
<?php if ($form === 'logs'): ?>
<h2><?= ap2lEscape(ap2lText('TAB_LOGS')) ?></h2>
<?= LBWeb::loglist_html() ?>
<?php elseif ($form === 'variables'): ?>
<section class="lb-card"><h2><?= ap2lEscape(ap2lText('TAB_VARIABLES')) ?></h2>
<p class="ap2l-note"><?= ap2lEscape(ap2lText('VARIABLES_INTRO')) ?></p>
<p><?= ap2lEscape(ap2lText('VARIABLES_GATING')) ?></p>
<p><code>availability=1</code> · <code>data_valid=1</code> · <code>weight_available</code> · <code>visits_today_valid</code> · <code>litter_level_valid</code></p>
<p><?= ap2lEscape(ap2lText('NO_VALUE')) ?></p>
<div class="ap2l-table"><table class="lb-table"><thead><tr>
<?php foreach (['COL_TOPIC', 'COL_LOXONE', 'COL_DESCRIPTION', 'COL_UNIT', 'COL_VALIDITY', 'COL_VALUE'] as $key): ?><th scope="col"><?= ap2lEscape(ap2lText($key)) ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><td><code><?= ap2lEscape((string) ($row['topic'] ?? '')) ?></code></td><td><code><?= ap2lEscape((string) ($row['loxone_name'] ?? str_replace('/', '_', (string) ($row['topic'] ?? '')))) ?></code></td><td><?= ap2lEscape((string) ($row['description'] ?? '')) ?></td><td><?= ap2lEscape((string) ($row['unit'] ?? '')) ?></td><td><?= ap2lEscape((string) ($row['validity'] ?? '')) ?></td><td><?= ap2lEscape(ap2lNumber($row['value'] ?? null)) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="ap2l-note"><?= ap2lEscape(ap2lText('MEASUREMENT_AGES')) ?></p>
<p class="ap2l-note"><?= ap2lEscape(ap2lText('RAW_VALUES')) ?></p>
</section>
<?php else: ?>
<section class="lb-card"><h2><?= ap2lEscape(ap2lText('STATUS_TITLE')) ?></h2>
<dl class="ap2l-status">
<div><dt><?= ap2lEscape(ap2lText('ACCOUNT_STATUS')) ?></dt><dd><?= ap2lEscape(ap2lText($connected ? 'CONNECTED' : (($result['astropet_login_ok'] ?? false) ? 'ACCOUNT_ONLY' : 'NOT_CONNECTED'))) ?></dd></div>
<div><dt><?= ap2lEscape(ap2lText('COLLECTION')) ?></dt><dd><?= ap2lEscape(ap2lText($settings['enabled'] ? 'ENABLED' : 'PAUSED')) ?></dd></div>
<div><dt>MQTT</dt><dd><?= ap2lEscape(ap2lText(($projection['availability'] ?? 0) === 1 ? 'AVAILABLE' : 'WAITING')) ?></dd></div>
<div><dt><?= ap2lEscape(ap2lText('DEVICE_VALUES')) ?></dt><dd><?= ap2lEscape(ap2lText(($projection['data_valid'] ?? 0) === 1 ? 'VALID' : 'INVALID')) ?></dd></div>
<div><dt><?= ap2lEscape(ap2lText('POLL_AGE')) ?></dt><dd><?= ap2lEscape(ap2lNumber($projection['data_age_seconds'] ?? null)) ?> s</dd></div>
<div><dt><?= ap2lEscape(ap2lText('LAST_PUBLISH')) ?></dt><dd><?= ap2lEscape(ap2lTime($worker['last_mqtt_at'] ?? null)) ?></dd></div>
</dl>
<?php if (is_string($worker['last_error'] ?? null)): ?><p class="ap2l-notice ap2l-error"><?= ap2lEscape($worker['last_error']) ?></p><?php endif; ?>
<p class="ap2l-note"><?= ap2lEscape(ap2lText(($projection['data_valid'] ?? 0) === 1 ? 'VALUES_VALID_NOTE' : 'VALUES_INVALID_NOTE')) ?></p>
<p><a href="index.php?form=variables"><?= ap2lEscape(ap2lText('SHOW_VARIABLES')) ?></a></p>
<?php if ($connected && $selectedIndex !== null): ?><form method="post" action="index.php"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="refresh"><button class="lb-btn" type="submit"><?= ap2lEscape(ap2lText('BTN_REFRESH')) ?></button></form><?php endif; ?>
</section>
<section class="lb-card"><h2><?= ap2lEscape(ap2lText('SETTINGS_TITLE')) ?></h2>
<p class="ap2l-note"><?= ap2lEscape(ap2lText('ACCOUNT_INTRO')) ?></p>
<p><strong><?= ap2lEscape(ap2lText($settings['has_credentials'] ? 'CREDENTIALS_SAVED' : 'CREDENTIALS_MISSING')) ?></strong> <?= ap2lEscape(ap2lText('BLANK_CREDENTIALS')) ?></p>
<form method="post" action="index.php" autocomplete="off">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<div class="lb-form-row"><label class="lb-form-label" for="email"><?= ap2lEscape(ap2lText('EMAIL')) ?></label><div class="lb-form-field"><input class="lb-input" id="email" name="email" type="email" maxlength="254" value="" autocomplete="off"<?= !$settings['has_credentials'] ? ' required' : '' ?>></div></div>
<div class="lb-form-row"><label class="lb-form-label" for="password"><?= ap2lEscape(ap2lText('PASSWORD')) ?></label><div class="lb-form-field"><input class="lb-input" id="password" name="password" type="password" maxlength="1024" value="" autocomplete="new-password"<?= !$settings['has_credentials'] ? ' required' : '' ?>></div></div>
<div class="lb-form-row"><label class="lb-form-label" for="poll_interval_seconds"><?= ap2lEscape(ap2lText('INTERVAL')) ?></label><div class="lb-form-field"><input class="lb-input" id="poll_interval_seconds" name="poll_interval_seconds" type="number" min="60" max="3600" step="1" value="<?= (int) $settings['poll_interval_seconds'] ?>" required> s</div></div>
<p class="lb-form-help hint"><?= ap2lEscape(ap2lText('INTERVAL_HINT')) ?></p>
<div class="lb-form-row"><span class="lb-form-label"><?= ap2lEscape(ap2lText('COLLECTION')) ?></span><div class="lb-form-field"><label for="enabled"><input id="enabled" name="enabled" type="checkbox" value="1"<?= ($settings['enabled'] || !$settings['has_credentials']) ? ' checked' : '' ?>> <?= ap2lEscape(ap2lText('ENABLED_LABEL')) ?></label></div></div>
<div class="ap2l-actions"><button class="lb-btn lb-btn-primary" type="submit" name="action" value="save_connect"><?= ap2lEscape(ap2lText('BTN_SAVE_CONNECT')) ?></button><button class="lb-btn" type="submit" name="action" value="save_settings"><?= ap2lEscape(ap2lText('BTN_SAVE_SETTINGS')) ?></button></div>
</form>
<?php if ($pending): ?>
<p class="ap2l-notice"><?= ap2lEscape(ap2lText('OTP_REQUIRED')) ?></p>
<form method="post" action="index.php"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="send_code"><button class="lb-btn" type="submit"><?= ap2lEscape(ap2lText('BTN_SEND_CODE')) ?></button></form>
<form method="post" action="index.php"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="verify_code"><label for="code"><?= ap2lEscape(ap2lText('OTP_CODE')) ?></label><div class="ap2l-row"><input class="lb-input" id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required><button class="lb-btn lb-btn-primary" type="submit"><?= ap2lEscape(ap2lText('BTN_VERIFY_CODE')) ?></button></div></form>
<?php endif; ?>
<?php if ($pending || $result !== []): ?><form method="post" action="index.php"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="logout"><button class="lb-btn" type="submit"><?= ap2lEscape(ap2lText('BTN_BROWSER_CLEAR')) ?></button></form><?php endif; ?>
</section>
<?php if ($connected): ?>
<section class="lb-card"><h2><?= ap2lEscape(ap2lText('DEVICE_TITLE')) ?></h2>
<?php if ($devices !== []): ?>
<form method="post" action="index.php"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="select_device"><label for="device_index"><?= ap2lEscape(ap2lText('DEVICE_LABEL')) ?></label><div class="ap2l-row"><select class="lb-select" id="device_index" name="device_index" required>
<?php if ($selectedIndex === null): ?><option value="" selected disabled><?= ap2lEscape(ap2lText('SELECT_REQUIRED')) ?></option><?php endif; ?>
<?php foreach ($devices as $device): ?>
<?php if (is_int($device['index'] ?? null) && is_string($device['name'] ?? null)): ?><option value="<?= $device['index'] ?>"<?= $selectedIndex === $device['index'] ? ' selected' : '' ?>><?= ap2lEscape($device['name']) ?></option><?php endif; ?>
<?php endforeach; ?>
</select><button class="lb-btn" type="submit"><?= ap2lEscape(ap2lText('BTN_SELECT_DEVICE')) ?></button></div></form>
<p><?= ap2lEscape(ap2lText($selectedIndex === null ? 'SELECT_NOTE' : 'SELECTED_NOTE')) ?></p>
<?php else: ?><p><?= ap2lEscape(ap2lText('NO_DEVICE')) ?></p><?php endif; ?>
<form method="post" action="index.php"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="disconnect"><button class="lb-btn lb-btn-danger" type="submit"><?= ap2lEscape(ap2lText('BTN_DISCONNECT')) ?></button></form>
</section>
<?php endif; ?>
<section class="lb-card"><h2><?= ap2lEscape(ap2lText('GATEWAY_TITLE')) ?></h2>
<p><?= ap2lEscape(ap2lText(($gateway['registered'] ?? false) ? 'SUBSCRIPTION_PRESENT' : 'SUBSCRIPTION_MISSING')) ?> <code>astropet2lox/#</code></p>
<p><?= ap2lEscape(ap2lText(($gateway['gateway_enabled'] ?? false) ? 'GATEWAY_ENABLED' : 'GATEWAY_DISABLED')) ?> · <?= ap2lEscape(ap2lText(($gateway['miniserver_configured'] ?? false) ? 'MINISERVER_CONFIGURED' : 'MINISERVER_MISSING')) ?></p>
<?php if (is_string($gateway['last_error'] ?? null)): ?><p class="ap2l-notice ap2l-error"><?= ap2lEscape($gateway['last_error']) ?></p><?php endif; ?>
<p class="ap2l-note"><?= ap2lEscape(ap2lText('GATEWAY_HELP')) ?></p>
<form method="post" action="index.php"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="register_gateway"><button class="lb-btn" type="submit"><?= ap2lEscape(ap2lText('BTN_REGISTER_GATEWAY')) ?></button></form>
<p><a href="index.php?form=variables"><?= ap2lEscape(ap2lText('SHOW_VARIABLES')) ?></a></p>
</section>
<?php endif; ?>
</div>
<?php
LBWeb::lbfooter();

function ap2lText(string $key): string
{
    global $L;
    return is_string($L['APP.' . $key] ?? null) ? $L['APP.' . $key] : $key;
}
function ap2lEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ap2lNumber($value): string
{
    if (is_int($value)) { return (string) $value; }
    if (is_float($value) && is_finite($value)) {
        $text = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        return $text === '-0' ? '0' : $text;
    }
    return '—';
}
function ap2lTime($value): string
{
    return is_int($value) && $value > 0 && $value <= time() + 5 ? gmdate('Y-m-d H:i:s', $value) . ' UTC' : '—';
}
function ap2lConnectAccount(array $account, BrowserSession $session, Runtime $runtime, AstroPet\MobileBridge $bridge): void
{
    // Keep account success visible even when the following device connection fails.
    $session->data['result'] = ['astropet_login_ok' => true];
    unset($session->data['pending']);
    $session->rotate();
    if (!extension_loaded('curl') || !extension_loaded('openssl')) { throw new RuntimeException(ap2lText('CRYPTO_REQUIRED')); }
    $bridge->connect($account);
    $bridge->refresh(true);
    $runtime->requestPublish();
    $runtime->gateway()->ensure();
}
